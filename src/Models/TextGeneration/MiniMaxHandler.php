<?php
/**
 * MiniMax Model Handler
 *
 * Handles MiniMax-M2, MiniMax-MoE, and other MiniMax models
 * that use non-standard response formats.
 *
 * @package DuetGAIConnector\Models\TextGeneration
 */

namespace WordPress\DuetGAIConnector\Models\TextGeneration;

use WordPress\DuetGAIConnector\Helper;

/**
 * MiniMax Model Handler
 */
class MiniMaxHandler implements ModelHandlerInterface
{
    /**
     * MiniMax model name prefixes
     *
     * @var array<string>
     */
    private const MINIMAX_PREFIXES = [
        'MiniMax-',
    ];

    /**
     * Check if this handler applies to the given model
     *
     * @param string $modelId
     * @return bool
     */
    public function applies(string $modelId): bool
    {
        foreach (self::MINIMAX_PREFIXES as $prefix) {
            if (stripos($modelId, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Transform request parameters for MiniMax API
     *
     * @param array $params
     * @return array
     */
    public function transformRequest(array $params): array
    {
        // Parameters that MiniMax ignores
        $ignoredParams = ['presence_penalty', 'frequency_penalty', 'logit_bias'];

        // Parameters that might cause issues
        $problematicParams = ['tools', 'tool_choice', 'tool_calls'];

        // Remove problematic and ignored parameters for MiniMax
        foreach (array_merge($ignoredParams, $problematicParams) as $param) {
            unset($params[$param]);
        }

        // Ensure temperature is in valid range (0.0, 1.0]
        if (isset($params['temperature'])) {
            $temp = floatval($params['temperature']);
            if ($temp <= 0 || $temp > 1) {
                $params['temperature'] = 1.0;
            }
        }

        // Disable thinking/reasoning by default
        // This fixes "The n parameter must be 1 when enable_thinking is true" error
        if (!isset($params['extra_body'])) {
            $params['extra_body'] = [];
        }
        $params['extra_body']['enable_thinking'] = false;
        $params['extra_body']['reasoning_split'] = false;

        // Force n=1 for MiniMax models that don't support multi-candidate generation
        // even with thinking disabled (e.g., MiniMax-M2.7)
        $params['n'] = 1;

        // Transform response_format for MiniMax API requirements
        // MiniMax doesn't support json_schema format, only json_object
        if (isset($params['response_format']) && is_array($params['response_format'])) {
            if (isset($params['response_format']['json_schema']) || ($params['response_format']['type'] ?? '') === 'json_schema') {
                $params['response_format'] = [
                    'type' => 'json_object',
                ];
            }
        }

        return $params;
    }

    /**
     * Transform response data from MiniMax API
     *
     * @param array $response
     * @return array
     */
    public function transformResponse(array $response): array
    {
        Helper::debug('MiniMax transformResponse called', [
            'has_choices' => isset($response['choices']),
            'choices_count' => isset($response['choices']) ? count($response['choices']) : 0,
        ]);

        if (!isset($response['choices']) || !is_array($response['choices'])) {
            return $response;
        }

        foreach ($response['choices'] as &$choice) {
            if (isset($choice['message']) && is_array($choice['message'])) {
                $choice['message'] = $this->transformMessage($choice['message']);
            }
        }

        return $response;
    }

    /**
     * Transform message data - handles reasoning_details, thinking tags, and structured text formats
     *
     * @param array $message
     * @return array
     */
    private function transformMessage(array $message): array
    {
        // Check for reasoning_details (when reasoning_split=true worked)
        if (isset($message['reasoning_details']) && is_array($message['reasoning_details'])) {
            $reasoningTexts = [];
            foreach ($message['reasoning_details'] as $reasoning) {
                if (isset($reasoning['text']) && is_string($reasoning['text'])) {
                    $reasoningTexts[] = $reasoning['text'];
                }
            }
            if (!empty($reasoningTexts)) {
                // Map to reasoning_content for WordPress to recognize as thinking
                $message['reasoning_content'] = implode("\n\n", $reasoningTexts);
            }
            // Remove reasoning_details so parent doesn't process it
            unset($message['reasoning_details']);
        }

        // If reasoning_split didn't work (no reasoning_details), clean content using thinking tags
        if (isset($message['content'])) {
            $originalContent = $message['content'];
            $result = ThinkingTagHelper::clean($message['content']);

            $message['content'] = $result['content'];
            // Only update reasoning_content if we actually found thinking content
            if (!empty($result['thinking']) && !isset($message['reasoning_content'])) {
                $message['reasoning_content'] = $result['thinking'];
            }

            // Try to parse structured text formats (like taxonomy suggestions)
            // This handles MiniMax's XML format
            $parsed = $this->tryParseStructuredText($message['content']);
            if ($parsed !== null) {
                $message['content'] = $parsed;
            }

            // Debug: log the transformation
            Helper::debug('MiniMax transformMessage', [
                'had_reasoning_details' => isset($message['reasoning_details']),
                'original_content_preview' => substr($originalContent, 0, 100),
                'thinking_found' => !empty($result['thinking']),
                'thinking_preview' => substr($result['thinking'], 0, 50),
                'content_after_clean' => substr($message['content'], 0, 100),
                'parsed_from_xml' => $parsed !== null,
            ]);
        }

        // Handle 'reasoning' field (some MiniMax models)
        if (isset($message['reasoning']) && !isset($message['reasoning_content'])) {
            $message['reasoning_content'] = $message['reasoning'];
        }

        return $message;
    }

    /**
     * Try to parse structured text formats like taxonomy suggestions
     *
     * MiniMax returns text in XML format like:
     * <taxonomy>
     * <term confidence="0.95">Education</term>
     * <term confidence="0.90">Obituary</term>
     * <term confidence="0.85">Social Media</term>
     * </taxonomy>
     *
     * @param string $content
     * @return string|null JSON string if parsed, null otherwise
     */
    private function tryParseStructuredText(string $content): ?string
    {
        $suggestions = [];

        // Pattern 1: <term confidence="y.y">xxx</term> - confidence as attribute, term as text
        if (preg_match_all('/<term\s+confidence="([\d.]+)"[^>]*>([^<]+)<\/term>/', $content, $termMatches, PREG_SET_ORDER)) {
            foreach ($termMatches as $match) {
                $suggestions[] = [
                    'term' => trim($match[2]),
                    'confidence' => (float) $match[1],
                ];
            }
        }

        // Pattern 2: <term name="xxx" confidence="y.y"/> - self-closing with attributes
        if (empty($suggestions) && preg_match_all('/<term\s+name="([^"]+)"\s+confidence="([\d.]+)"\s*\/>/', $content, $termMatches, PREG_SET_ORDER)) {
            foreach ($termMatches as $match) {
                $suggestions[] = [
                    'term' => trim($match[1]),
                    'confidence' => (float) $match[2],
                ];
            }
        }

        // Pattern 3: <term>xxx</term><confidence>y.y</confidence> - separate elements
        if (empty($suggestions) && preg_match_all('/<term>([^<]+)<\/term>\s*<confidence>([\d.]+)<\/confidence>/', $content, $termMatches, PREG_SET_ORDER)) {
            foreach ($termMatches as $match) {
                $suggestions[] = [
                    'term' => trim($match[1]),
                    'confidence' => (float) $match[2],
                ];
            }
        }

        // Pattern 4: Simple <term>xxx</term> without confidence
        if (empty($suggestions) && preg_match_all('/<term>([^<]+)<\/term>/', $content, $termMatches)) {
            foreach ($termMatches[1] as $term) {
                $suggestions[] = [
                    'term' => trim($term),
                    'confidence' => 0.5, // default
                ];
            }
        }

        if (!empty($suggestions)) {
            return json_encode(['suggestions' => $suggestions]);
        }

        return null;
    }
}
