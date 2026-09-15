<?php
/**
 * Verification script for image-refinement support.
 *
 * Exercises the parts of CustomImageGenerationModel that don't require a
 * real WordPress request cycle:
 *   1. CustomImageModelMetadataDirectory exposes text+image input modalities
 *      so the upstream `is_supported_for_image_generation()` check returns
 *      true when a reference image is attached.
 *   2. `extractPromptText()` returns the text part of a user message that
 *      also contains a file part (the parent SDK's preparePromptParam
 *      would throw on this same input).
 *   3. `extractReferenceFile()` picks the first image file from a message
 *      with mixed parts.
 *   4. `buildMultipartBody()` produces a body that contains every field,
 *      terminates each part with CRLF, and emits a syntactically valid
 *      boundary close.
 *   5. `mimeToExtension()` maps the common image MIME types.
 *
 * The parts that DO require WordPress / a live HTTP endpoint
 * (sendImageEditRequest dispatch, the actual HTTP call) are not exercised
 * here — those are smoke-tested via the WordPress Test AI page.
 *
 * Usage: php tests/verify-image-refinement.php
 *
 * Exits with 0 on success, 1 on any failed assertion.
 */

// Minimal ABSPATH guard so the SDK + autoloader can load outside WP.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// array_is_list() polyfill — same as the MiniMax harness, this script runs
// outside WordPress on PHP 7.4 / 8.0 hosts that lack the native function.
if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}

// Stub WordPress functions that the SDK + our autoloader touch at parse /
// class-construction time.
if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string { return $text; }
}
if (!function_exists('_e')) {
    function _e(string $text, string $domain = ''): void { echo $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = ''): string { return $text; }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = ''): string { return $text; }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $text): string { return trim($text); }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
}
if (!function_exists('get_option')) {
    function get_option(string $name, $default = false) { return $default; }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string { return dirname($file) . '/'; }
}

// Pull in the WP AI Client SDK from the test env FIRST so its autoloader
// is registered before we touch any of our plugin classes. The harness
// intentionally loads the SDK out of a WordPress install so the version
// tested matches what the plugin will see in production.
$sdk_autoload = '/Users/duetg/Studio/test/wp-includes/php-ai-client/autoload.php';
if (file_exists($sdk_autoload)) {
    require_once $sdk_autoload;
} else {
    fwrite(STDERR, "SKIP: SDK autoloader not found at {$sdk_autoload}\n");
    exit(0);
}

require_once __DIR__ . '/../src/autoload.php';

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\DuetGAIConnector\Metadata\CustomImageModelMetadataDirectory;

$failures = [];
$tests_run = 0;

function check(string $label, bool $condition): void
{
    global $failures, $tests_run;
    $tests_run++;
    if (!$condition) {
        $failures[] = $label;
        fwrite(STDERR, "FAIL: {$label}\n");
    } else {
        fwrite(STDOUT, "ok:   {$label}\n");
    }
}

// Use reflection to drive the private extractPromptText /
// extractReferenceFile / buildMultipartBody methods on the model without
// having to fully wire a provider / registry. The instantiation skips the
// constructor (which requires ModelMetadata + ProviderMetadata); none of
// these helpers touch either property.
$modelReflection = new ReflectionClass(
    \WordPress\DuetGAIConnector\Models\ImageGeneration\CustomImageGenerationModel::class
);

$modelStub = $modelReflection->newInstanceWithoutConstructor();

$extractPromptText = $modelReflection->getMethod('extractPromptText');
$extractPromptText->setAccessible(true);

$extractReferenceFile = $modelReflection->getMethod('extractReferenceFile');
$extractReferenceFile->setAccessible(true);

$buildMultipartBody = $modelReflection->getMethod('buildMultipartBody');
$buildMultipartBody->setAccessible(true);

$mimeToExtension = $modelReflection->getMethod('mimeToExtension');
$mimeToExtension->setAccessible(true);

// Test 1: Metadata exposes text+image input modalities so the official AI
// plugin's is_supported_for_image_generation() check returns true for
// refinement requests.
$directory  = new CustomImageModelMetadataDirectory();
$metadata   = $directory->getModelMetadata('dall-e-3');

$imageOptionValues = [];
foreach ($metadata->getSupportedOptions() as $option) {
    if ($option->getName()->equals(OptionEnum::inputModalities())) {
        foreach ($option->getSupportedValues() as $variant) {
            $imageOptionValues[] = array_map(
                static fn(ModalityEnum $m) => $m->value,
                $variant
            );
        }
    }
}

check(
    'image metadata declares imageGeneration capability',
    in_array(CapabilityEnum::imageGeneration(), $metadata->getSupportedCapabilities(), true)
);
check(
    'image metadata accepts text-only input modality variant',
    in_array(['text'], $imageOptionValues, true)
);
check(
    'image metadata accepts text+image input modality variant',
    in_array(['text', 'image'], $imageOptionValues, true)
);

// Test 2: extractPromptText returns the text part of a message even when
// the message also contains a file part. The parent SDK's preparePromptParam
// throws in this same situation, which is why we override.
$pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
$dataUri   = 'data:image/png;base64,' . $pngBase64;
$file      = new File($dataUri);

$messages = [
    new UserMessage([
        new MessagePart('Make the sky red'),
        new MessagePart($file),
    ]),
];

check(
    'extractPromptText returns the prompt even when followed by a file part',
    $extractPromptText->invoke($modelStub, $messages) === 'Make the sky red'
);

check(
    'extractPromptText returns null for empty messages',
    $extractPromptText->invoke($modelStub, []) === null
);

$modelMessage = [
    new Message(MessageRoleEnum::model(), [new MessagePart('reply')]),
];
check(
    'extractPromptText returns null when the only message is from the model',
    $extractPromptText->invoke($modelStub, $modelMessage) === null
);

// Test 3: extractReferenceFile picks the first image File from a user
// message.
check(
    'extractReferenceFile returns the reference image when present',
    $extractReferenceFile->invoke($modelStub, $messages) === $file
);

$textOnlyMessages = [
    new UserMessage([new MessagePart('Just a prompt')]),
];
check(
    'extractReferenceFile returns null when no file part is present',
    $extractReferenceFile->invoke($modelStub, $textOnlyMessages) === null
);

// Test 4: buildMultipartBody emits all fields and terminates cleanly.
$fields = [
    'model'         => 'dall-e-3',
    'prompt'        => 'Make the sky red',
    'response_format' => 'b64_json',
    'image'         => base64_decode($pngBase64, true),
    'mime'          => 'image/png',
];

$body = $buildMultipartBody->invoke($modelStub, $fields);

check(
    'multipart body contains model field',
    strpos($body, 'name="model"') !== false && strpos($body, 'dall-e-3') !== false
);
check(
    'multipart body contains prompt field',
    strpos($body, 'name="prompt"') !== false && strpos($body, 'Make the sky red') !== false
);
check(
    'multipart body contains response_format field',
    strpos($body, 'name="response_format"') !== false && strpos($body, 'b64_json') !== false
);
check(
    'multipart body contains image part with image/png Content-Type',
    strpos($body, 'name="image"') !== false && strpos($body, 'Content-Type: image/png') !== false
);
check(
    'multipart body contains the raw image bytes',
    strpos($body, base64_decode($pngBase64, true)) !== false
);
check(
    'multipart body uses CRLF line endings',
    strpos($body, "\r\n") !== false
);
check(
    'multipart body terminates with closing boundary',
    preg_match('/--[A-Za-z0-9_-]+--\r\n$/', $body) === 1
);

// Test 5: mimeToExtension maps the common image MIME types.
check('mimeToExtension: png', $mimeToExtension->invoke($modelStub, 'image/png') === 'png');
check('mimeToExtension: jpeg', $mimeToExtension->invoke($modelStub, 'image/jpeg') === 'jpg');
check('mimeToExtension: webp', $mimeToExtension->invoke($modelStub, 'image/webp') === 'webp');
check('mimeToExtension: gif', $mimeToExtension->invoke($modelStub, 'image/gif') === 'gif');
check('mimeToExtension: unknown falls back to png', $mimeToExtension->invoke($modelStub, 'application/octet-stream') === 'png');

echo "\n";
echo "Tests run: {$tests_run}\n";
echo "Failures:  " . count($failures) . "\n";

exit(count($failures) === 0 ? 0 : 1);