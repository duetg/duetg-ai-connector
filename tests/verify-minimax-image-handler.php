<?php
/**
 * Verification script for MiniMaxImageHandler.
 *
 * Exercises the handler in isolation (no WordPress, no SDK) to confirm:
 *   1. The dual-filter applies() accepts the right combinations
 *   2. applies() rejects every wrong combination
 *   3. remapPath() only fires for the OpenAI image-generation path on MiniMax
 *   4. prepareParams() only swaps response_format on MiniMax
 *   5. transformResponse() converts every documented MiniMax shape into
 *      OpenAI's flat shape, and never corrupts non-MiniMax data
 *
 * Usage: php tests/verify-minimax-image-handler.php
 *
 * Exits with 0 on success, 1 on any failed assertion.
 */

// Minimal ABSPATH guard so the autoloader + handler can load outside WP.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// array_is_list() is PHP 8.1+ native. WordPress 6.5+ polyfills it in
// wp-includes/compat.php, so production runtime is always covered. This
// standalone test harness runs outside WordPress, so we polyfill here too
// to keep the verification script portable across PHP 7.4 / 8.0 CI hosts.
if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}

require_once __DIR__ . '/../src/Models/ImageGeneration/MiniMaxImageHandler.php';

use WordPress\DuetGAIConnector\Models\ImageGeneration\MiniMaxImageHandler;

$failures = [];
$tests_run = 0;

function check(string $label, bool $condition): void
{
    global $failures, $tests_run;
    $tests_run++;
    if (!$condition) {
        $failures[] = $label;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

function check_eq(string $label, $expected, $actual): void
{
    global $failures, $tests_run;
    $tests_run++;
    $expected_repr = var_export($expected, true);
    $actual_repr = var_export($actual, true);
    if ($expected_repr !== $actual_repr) {
        $failures[] = $label;
        fwrite(STDERR, "FAIL: {$label}\n  expected: {$expected_repr}\n  actual:   {$actual_repr}\n");
    }
}

$h = new MiniMaxImageHandler();

// ---------------------------------------------------------------------
// applies() — dual-filter happy paths
// ---------------------------------------------------------------------
check('applies: minimax.cn + image-01',          $h->applies('image-01', 'https://api.minimax.cn/v1'));
check('applies: minimax.io + image-01-live',    $h->applies('image-01-live', 'https://api.minimax.io/v1'));
check('applies: minimaxi.com (legacy) + image-01', $h->applies('image-01', 'https://api.minimaxi.com/v1'));
check('applies: subdomain of minimax.io',       $h->applies('image-01', 'https://api.us.minimax.io/v1'));
check('applies: case-insensitive domain match', $h->applies('image-01', 'https://API.MINIMAX.IO/v1'));
check('applies: bare host without scheme',      $h->applies('image-01', 'api.minimax.cn/v1'));
check('applies: model prefix image-2 future',   $h->applies('image-2-foo', 'https://api.minimax.io/v1'));

// Future-region auto-coverage: any URL containing `minimax` is treated as
// MiniMax-shaped traffic, so new regions / CDNs work without a plugin update.
check('applies: future region minimax.eu',      $h->applies('image-01', 'https://api.minimax.eu/v1'));
check('applies: hypothetical CDN minimax-cdn',  $h->applies('image-01', 'https://cdn.minimax-cdn.com/v1'));
check('applies: user-supplied my-minimax.example',
                                                $h->applies('image-01', 'https://my-minimax.example/v1'));

// ---------------------------------------------------------------------
// applies() — defense in depth: must reject misconfigurations
// ---------------------------------------------------------------------
check('rejects: image-01 on OpenAI URL',         !$h->applies('image-01', 'https://api.openai.com/v1'));
check('rejects: image-01 on Ollama URL',          !$h->applies('image-01', 'http://localhost:11434/v1'));
check('rejects: image-01 on SiliconFlow URL',    !$h->applies('image-01', 'https://api.siliconflow.cn/v1'));
check('rejects: MiniMax text model on MiniMax URL',  !$h->applies('MiniMax-Text-01', 'https://api.minimax.cn/v1'));
check('rejects: gpt-image-1 on MiniMax URL',     !$h->applies('gpt-image-1', 'https://api.minimax.cn/v1'));
check('rejects: empty model id',                 !$h->applies('', 'https://api.minimax.cn/v1'));
check('rejects: empty base url',                 !$h->applies('image-01', ''));
check('rejects: null base url',                  !$h->applies('image-01', null));
check('rejects: empty model + empty url',        !$h->applies('', ''));

// Documented behaviour: substring match is intentionally permissive —
// the Base URL is user-supplied, so any URL containing `minimax` plus an
// `image-*` model is treated as MiniMax-shaped traffic. This is by design:
// defending against typos in user-entered URLs is out of scope. The dual
// filter still defends against the other axis (wrong model on a real
// MiniMax URL).
check('substring: minimax substring anywhere still matches',
                                                  $h->applies('image-01', 'https://my-minimax.cn.evil.com/v1'));

// ---------------------------------------------------------------------
// remapPath()
// ---------------------------------------------------------------------
check_eq('remap: OpenAI path -> MiniMax path on MiniMax CN',
    'image_generation',
    $h->remapPath('images/generations', 'https://api.minimax.cn/v1', 'image-01'));

check_eq('remap: OpenAI path -> MiniMax path on MiniMax IO',
    'image_generation',
    $h->remapPath('images/generations', 'https://api.minimax.io/v1', 'image-01-live'));

check_eq('remap: OpenAI path unchanged on OpenAI',
    'images/generations',
    $h->remapPath('images/generations', 'https://api.openai.com/v1', 'dall-e-3'));

check_eq('remap: OpenAI path unchanged on MiniMax URL but wrong model',
    'images/generations',
    $h->remapPath('images/generations', 'https://api.minimax.cn/v1', 'MiniMax-Text-01'));

check_eq('remap: unknown path unchanged on MiniMax',
    'some/future/endpoint',
    $h->remapPath('some/future/endpoint', 'https://api.minimax.cn/v1', 'image-01'));

// ---------------------------------------------------------------------
// prepareParams() — response_format swap
// ---------------------------------------------------------------------
$openaiParams = ['model' => 'image-01', 'prompt' => 'a cat', 'response_format' => 'b64_json'];
check_eq('params: MiniMax swaps b64_json -> base64',
    'base64',
    $h->prepareParams($openaiParams, 'https://api.minimax.cn/v1', 'image-01')['response_format']);

$preservedParams = $h->prepareParams($openaiParams, 'https://api.openai.com/v1', 'image-01');
check_eq('params: non-MiniMax leaves response_format alone',
    'b64_json',
    $preservedParams['response_format']);
check_eq('params: non-MiniMax leaves other keys alone',
    'a cat',
    $preservedParams['prompt']);

// Edge: even if MiniMax applies, response_format already happens to be "base64"
$already = $h->prepareParams(['response_format' => 'url'], 'https://api.minimax.cn/v1', 'image-01');
check_eq('params: MiniMax overrides url with base64',
    'base64',
    $already['response_format']);

// ---------------------------------------------------------------------
// transformResponse() — MiniMax nested shape -> OpenAI flat shape
// ---------------------------------------------------------------------

// Case 1: both channels present (the most common case for image-01 with
//         response_format=base64 OR url, MiniMax populates the requested
//         channel only — but we accept either and both)
$both = [
    'data' => [
        'image_base64' => ['AAAA', 'BBBB'],
        'image_urls'   => ['https://x.example/i1.png', 'https://x.example/i2.png'],
    ],
    'metadata' => ['some_field' => 1],
];
$out = $h->transformResponse($both, 'https://api.minimax.cn/v1', 'image-01');
check_eq('resp both: data is now a list',                  true, is_array($out['data']) && array_is_list($out['data']));
check_eq('resp both: choice count',                        4, count($out['data']));
check_eq('resp both: first choice is base64 AAAA',         'AAAA', $out['data'][0]['b64_json']);
check_eq('resp both: second choice is base64 BBBB',        'BBBB', $out['data'][1]['b64_json']);
check_eq('resp both: third choice is url i1',              'https://x.example/i1.png', $out['data'][2]['url']);
check_eq('resp both: fourth choice is url i2',             'https://x.example/i2.png', $out['data'][3]['url']);
check_eq('resp both: preserves sibling keys',              1, $out['metadata']['some_field']);

// Case 2: base64-only (the case we expect when response_format=base64)
$b64Only = ['data' => ['image_base64' => ['AAAA', 'BBBB']]];
$out2 = $h->transformResponse($b64Only, 'https://api.minimax.io/v1', 'image-01');
check_eq('resp b64-only: choice count',                    2, count($out2['data']));
check_eq('resp b64-only: first b64_json',                  'AAAA', $out2['data'][0]['b64_json']);
check_eq('resp b64-only: second b64_json',                 'BBBB', $out2['data'][1]['b64_json']);
check('resp b64-only: no url keys leak',                  !isset($out2['data'][0]['url']));

// Case 3: url-only (the case we expect when response_format=url)
$urlOnly = ['data' => ['image_urls' => ['https://a/1.png']]];
$out3 = $h->transformResponse($urlOnly, 'https://api.minimax.cn/v1', 'image-01');
check_eq('resp url-only: choice count',                    1, count($out3['data']));
check_eq('resp url-only: url value',                       'https://a/1.png', $out3['data'][0]['url']);
check('resp url-only: no b64_json leak',                  !isset($out3['data'][0]['b64_json']));

// Case 4: data key genuinely missing — handler must not invent one
$noData = ['metadata' => ['foo' => 'bar']];
$out4 = $h->transformResponse($noData, 'https://api.minimax.cn/v1', 'image-01');
check_eq('resp truly-missing-data: returned unchanged',
    $noData,
    $out4);

// Case 5: data is already a list (someone passed OpenAI shape)
$alreadyFlat = ['data' => [['url' => 'https://x']]];
$out5 = $h->transformResponse($alreadyFlat, 'https://api.minimax.cn/v1', 'image-01');
check_eq('resp already-flat: returned unchanged',
    $alreadyFlat,
    $out5);

// Case 6: both channels empty
$empty = ['data' => ['image_base64' => [], 'image_urls' => []]];
$out6 = $h->transformResponse($empty, 'https://api.minimax.cn/v1', 'image-01');
check_eq('resp both-empty: original structure preserved',
    $empty,
    $out6);

// Case 7: non-string entries in arrays are filtered out
$mixed = ['data' => ['image_base64' => ['GOOD', null, '', 'ALSO_GOOD']]];
$out7 = $h->transformResponse($mixed, 'https://api.minimax.cn/v1', 'image-01');
check_eq('resp mixed: filters null and empty',             2, count($out7['data']));
check_eq('resp mixed: keeps GOOD',                         'GOOD', $out7['data'][0]['b64_json']);
check_eq('resp mixed: keeps ALSO_GOOD',                    'ALSO_GOOD', $out7['data'][1]['b64_json']);

// Case 8: non-MiniMax config — must not touch the response
$opaque = ['data' => ['image_urls' => ['https://x']]];
$out8 = $h->transformResponse($opaque, 'https://api.openai.com/v1', 'image-01');
check_eq('resp non-MiniMax: returned unchanged',
    $opaque,
    $out8);

// Case 9: MiniMax URL but wrong model — must not touch the response (dual filter)
$opaque2 = ['data' => ['image_urls' => ['https://x']]];
$out9 = $h->transformResponse($opaque2, 'https://api.minimax.cn/v1', 'MiniMax-Text-01');
check_eq('resp dual-filter reject: returned unchanged',
    $opaque2,
    $out9);

// ---------------------------------------------------------------------
// End-to-end shape check: can the transformed response actually round-trip
// through json_encode + json_decode without losing the flat structure?
// ---------------------------------------------------------------------
$roundtrip = json_decode(
    json_encode($h->transformResponse($both, 'https://api.minimax.cn/v1', 'image-01')),
    true
);
check('roundtrip: data is a list of choice objects',
    is_array($roundtrip['data']) && array_is_list($roundtrip['data']) && count($roundtrip['data']) === 4);

// ---------------------------------------------------------------------
// prepareEditBody() — refinement payload for MiniMax
// ---------------------------------------------------------------------
// Per the MiniMax image_generation API spec, image-to-image requests must
// use the `subject_reference` field with shape
//   [{ type: "character", image_file: <URL or base64 Data URL> }]
// (the only currently-supported reference type). The legacy `image: [...]`
// field is silently ignored by MiniMax, which causes the model to fall back
// to plain text-to-image — the symptom users see is "the refined image has
// nothing to do with the reference".

$pngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
$dataUri   = 'data:image/png;base64,' . $pngBase64;

$mBody = $h->prepareEditBody(
    $pngBase64,
    'image/png',
    'Make the sky red',
    'image-01',
    'base64',
    'https://api.minimax.cn/v1'
);
check('edit body: returns a non-null array for MiniMax', is_array($mBody));
check('edit body: uses subject_reference field (not legacy "image")',
    isset($mBody['subject_reference']) && !isset($mBody['image']));
check('edit body: subject_reference is a list', is_array($mBody['subject_reference']) && array_is_list($mBody['subject_reference']));
check('edit body: exactly one subject reference entry',
    is_array($mBody['subject_reference']) && count($mBody['subject_reference']) === 1);
check('edit body: reference entry has type=character',
    $mBody['subject_reference'][0]['type'] === 'character');
check('edit body: reference image_file is the Data URI',
    $mBody['subject_reference'][0]['image_file'] === $dataUri);
check('edit body: model id is preserved',                       $mBody['model'] === 'image-01');
check('edit body: prompt is preserved',                         $mBody['prompt'] === 'Make the sky red');
check('edit body: response_format is preserved',                $mBody['response_format'] === 'base64');

// MIME default — when caller doesn't know the MIME, the data URI must
// still declare one so MiniMax doesn't reject the payload.
$mBodyNoMime = $h->prepareEditBody(
    $pngBase64,
    '',
    'Make it blue',
    'image-01',
    'base64',
    'https://api.minimax.cn/v1'
);
check('edit body: empty MIME falls back to image/png',
    strpos($mBodyNoMime['subject_reference'][0]['image_file'], 'data:image/png;base64,') === 0);

// JSON roundtrip — the body must serialise + parse cleanly via
// json_encode/json_decode (what wp_remote_post ends up doing).
$roundtripBody = json_decode(json_encode($mBody), true);
check('edit body: roundtrips through JSON encode/decode',         $roundtripBody === $mBody);
check('edit body: roundtripped subject_reference image_file intact',
    $roundtripBody['subject_reference'][0]['image_file'] === $dataUri);

// Non-MiniMax must fall through unchanged.
$nullBody = $h->prepareEditBody(
    $pngBase64,
    'image/png',
    'Make it green',
    'dall-e-3',
    'b64_json',
    'https://api.openai.com/v1'
);
check('edit body: returns null for non-MiniMax provider',        $nullBody === null);

$nullBodyUrl = $h->prepareEditBody(
    $pngBase64,
    'image/png',
    'Make it green',
    'image-01',
    'b64_json',
    'https://api.openai.com/v1' // wrong model, wrong URL
);
check('edit body: returns null when only URL is MiniMax',        $nullBodyUrl === null);

$nullBodyModel = $h->prepareEditBody(
    $pngBase64,
    'image/png',
    'Make it green',
    'MiniMax-Text-01',
    'b64_json',
    'https://api.minimax.cn/v1' // wrong model, MiniMax URL
);
check('edit body: returns null when only model is MiniMax',       $nullBodyModel === null);

// ---------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------
echo "\n";
echo "Ran {$tests_run} assertions.\n";
if (!empty($failures)) {
    echo "FAILED: " . count($failures) . "\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
echo "ALL PASS.\n";
exit(0);
