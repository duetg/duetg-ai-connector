<?php
/**
 * Verification script for text generation utility classes.
 *
 * Exercises ThinkingTagHelper, JsonResponseExtractor, and
 * ReviewNotesNormalizer in isolation (no WordPress, no SDK) to confirm:
 *   1. ThinkingTagHelper::clean() correctly extracts/strips thinking tags
 *   2. ThinkingTagHelper::strip() removes tags without extracting
 *   3. JsonResponseExtractor balanced-brace extraction works correctly
 *   4. ReviewNotesNormalizer normalizes various AI output shapes
 *
 * Usage: php tests/verify-text-utilities.php
 *
 * Exits with 0 on success, 1 on any failed assertion.
 */

// Minimal ABSPATH guard so the autoloader can load outside WP.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once __DIR__ . '/../src/Models/TextGeneration/ThinkingTagHelper.php';
require_once __DIR__ . '/../src/Models/TextGeneration/JsonResponseExtractor.php';
require_once __DIR__ . '/../src/Models/TextGeneration/ReviewNotesNormalizer.php';

use WordPress\DuetGAIConnector\Models\TextGeneration\ThinkingTagHelper;
use WordPress\DuetGAIConnector\Models\TextGeneration\JsonResponseExtractor;
use WordPress\DuetGAIConnector\Models\TextGeneration\ReviewNotesNormalizer;

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

// =====================================================================
// ThinkingTagHelper::clean()
// =====================================================================

// --- Basic thinking tags ---
$result = ThinkingTagHelper::clean('<think>I need to think</think>Hello world');
check_eq('clean: extracts thinking content', 'I need to think', $result['thinking']);
check_eq('clean: returns clean content', 'Hello world', $result['content']);

// --- <thinking> variant ---
$result = ThinkingTagHelper::clean('<thinking>Deep thought</thinking>Answer here');
check_eq('clean: handles <thinking> variant', 'Deep thought', $result['thinking']);
check_eq('clean: content after <thinking>', 'Answer here', $result['content']);

// --- No thinking tags ---
$result = ThinkingTagHelper::clean('Just plain text');
check_eq('clean: no tags → empty thinking', '', $result['thinking']);
check_eq('clean: no tags → trimmed content', 'Just plain text', $result['content']);

// --- Multiple thinking blocks ---
$result = ThinkingTagHelper::clean('<think>First thought</think>Middle<think>Second thought</think>End');
check('clean: multiple blocks → thinking contains both', strpos($result['thinking'], 'First thought') !== false);
check('clean: multiple blocks → thinking contains second', strpos($result['thinking'], 'Second thought') !== false);
check_eq('clean: multiple blocks → content is MiddleEnd', 'MiddleEnd', $result['content']);

// --- Thinking with whitespace ---
$result = ThinkingTagHelper::clean("<think>\n  Some thinking\n</think>\n\nActual response");
check_eq('clean: trims thinking whitespace', 'Some thinking', $result['thinking']);
check_eq('clean: trims content whitespace', 'Actual response', $result['content']);

// --- Empty thinking tags ---
$result = ThinkingTagHelper::clean('<think></think>Response');
check_eq('clean: empty tags → empty thinking', '', $result['thinking']);
check_eq('clean: empty tags → content preserved', 'Response', $result['content']);

// --- Only thinking tags, no content ---
$result = ThinkingTagHelper::clean('<think>All thinking, no answer</think>');
check_eq('clean: only thinking → content empty', '', $result['content']);
check_eq('clean: only thinking → thinking extracted', 'All thinking, no answer', $result['thinking']);

// --- Nested HTML-like content inside thinking ---
$result = ThinkingTagHelper::clean('<think>Let me check <b>this</b> and <i>that</i></think>The answer');
check('clean: HTML inside thinking preserved', strpos($result['thinking'], '<b>this</b>') !== false);
check_eq('clean: content after HTML-containing thinking', 'The answer', $result['content']);

// --- Unicode / multibyte content ---
$result = ThinkingTagHelper::clean('<think>思考中文内容</think>这是回答');
check_eq('clean: Chinese thinking extracted', '思考中文内容', $result['thinking']);
check_eq('clean: Chinese content preserved', '这是回答', $result['content']);

// =====================================================================
// ThinkingTagHelper::strip()
// =====================================================================

check_eq('strip: removes <think> tags', 'Hello world', ThinkingTagHelper::strip('<think>reasoning</think>Hello world'));
check_eq('strip: removes <thinking> tags', 'Response', ThinkingTagHelper::strip('<thinking>deep thought</thinking>Response'));
check_eq('strip: no tags unchanged', 'plain text', ThinkingTagHelper::strip('plain text'));
check_eq('strip: multiple blocks removed', 'AB', ThinkingTagHelper::strip('<think>1</think>A<think>2</think>B'));
check_eq('strip: empty after stripping all', '', ThinkingTagHelper::strip('<think>only thinking</think>'));

// =====================================================================
// JsonResponseExtractor — balanced brace extraction
// =====================================================================

$extractor = new JsonResponseExtractor();
$normalizer = new ReviewNotesNormalizer();

// --- Direct valid JSON ---
$valid_json = '{"suggestions": [{"review_type": "seo", "text": "Add meta", "priority": 1}]}';
$result = $extractor->extract($valid_json, $normalizer);
check('extract: valid JSON decoded', $result !== null);
check('extract: valid JSON has suggestions', isset($result['suggestions']));
check_eq('extract: valid JSON suggestion count', 1, count($result['suggestions']));

// --- JSON in markdown code block ---
$markdown_json = "Here's the result:\n```json\n{\"suggestions\": [{\"review_type\": \"accessibility\", \"text\": \"Add alt text\", \"priority\": 2}]}\n```";
$result = $extractor->extract($markdown_json, $normalizer);
check('extract: markdown JSON decoded', $result !== null);
check('extract: markdown JSON has suggestions', isset($result['suggestions']));

// --- JSON array ---
$json_array = '[{"review_type": "seo", "text": "Improve title", "priority": 1}]';
$result = $extractor->extract($json_array, $normalizer);
check('extract: JSON array decoded', $result !== null);
check('extract: JSON array has suggestions', isset($result['suggestions']));

// --- Nested braces in JSON values ---
$nested = '{"suggestions": [{"review_type": "code", "text": "Use {curly} braces properly", "priority": 1}]}';
$result = $extractor->extract($nested, $normalizer);
check('extract: nested braces in strings handled', $result !== null);
check_eq('extract: nested brace suggestion text', 'Use {curly} braces properly', $result['suggestions'][0]['text']);

// --- JSON with escaped quotes ---
$escaped = '{"suggestions": [{"review_type": "seo", "text": "Use \\"proper\\" quoting", "priority": 1}]}';
$result = $extractor->extract($escaped, $normalizer);
check('extract: escaped quotes handled', $result !== null);

// --- Non-JSON text → extractor wraps as a single suggestion (fallback by design) ---
$plain = 'This is just a plain text response with no JSON at all.';
$result = $extractor->extract($plain, $normalizer);
check('extract: non-JSON fallback wraps as suggestion', $result !== null && isset($result['suggestions']));
check_eq('extract: non-JSON fallback count', 1, count($result['suggestions']));
check('extract: non-JSON fallback text preserved', strpos($result['suggestions'][0]['text'], 'plain text response') !== false);

// --- Empty string ---
$result = $extractor->extract('', $normalizer);
check_eq('extract: empty string returns null', null, $result);

// =====================================================================
// ReviewNotesNormalizer::normalize()
// =====================================================================

// --- Standard format ---
$data = ['suggestions' => [['review_type' => 'seo', 'text' => 'Add meta', 'priority' => 1]]];
$result = $normalizer->normalize($data);
check('normalize: standard format passes through', isset($result['suggestions']));
check_eq('normalize: standard format count', 1, count($result['suggestions']));

// --- Array of suggestions without wrapper ---
$data = [['review_type' => 'accessibility', 'text' => 'Add alt text', 'priority' => 2]];
$result = $normalizer->normalize($data);
check('normalize: bare array wrapped in suggestions', isset($result['suggestions']));
check_eq('normalize: bare array count', 1, count($result['suggestions']));

// --- Single suggestion object ---
$data = ['review_type' => 'seo', 'text' => 'Fix title', 'priority' => 1];
$result = $normalizer->normalize($data);
check('normalize: single object wrapped', isset($result['suggestions']));
check_eq('normalize: single object count', 1, count($result['suggestions']));

// --- Suggestions with missing priority ---
$data = ['suggestions' => [['review_type' => 'seo', 'text' => 'No priority here']]];
$result = $normalizer->normalize($data);
check('normalize: missing priority defaults', isset($result['suggestions'][0]['priority']));

// --- Empty suggestions ---
$data = ['suggestions' => []];
$result = $normalizer->normalize($data);
check_eq('normalize: empty suggestions preserved', [], $result['suggestions']);

// =====================================================================
// ReviewNotesNormalizer::extractJsonFromText()
// =====================================================================

// --- JSON preceded by thinking ---
$text_with_thinking = "<think>Let me analyze</think>\n{\"suggestions\": [{\"review_type\": \"seo\", \"text\": \"Test\", \"priority\": 1}]}";
$result = $normalizer->extractJsonFromText($text_with_thinking);
check('extractJsonFromText: after thinking tags', $result !== null);
check('extractJsonFromText: has suggestions', isset($result['suggestions']));

// --- JSON with prefix text ---
$text_with_prefix = "Here are my suggestions:\n{\"suggestions\": [{\"review_type\": \"seo\", \"text\": \"Test\", \"priority\": 1}]}";
$result = $normalizer->extractJsonFromText($text_with_prefix);
check('extractJsonFromText: with prefix text', $result !== null);

// =====================================================================
// Summary
// =====================================================================

echo "\n";
if (empty($failures)) {
    echo "ALL PASS ({$tests_run} assertions)\n";
    exit(0);
} else {
    echo "FAILED " . count($failures) . " of {$tests_run} assertions:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
