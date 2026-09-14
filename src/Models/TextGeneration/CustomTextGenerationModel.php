<?php
/**
 * Custom Text Generation Model
 *
 * @package DuetGAIConnector\Models\TextGeneration
 */

namespace WordPress\DuetGAIConnector\Models\TextGeneration;

use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\DuetGAIConnector\Settings\Settings;
use WordPress\DuetGAIConnector\Models\TextGeneration\ThinkingTagHelper;
use WordPress\DuetGAIConnector\Models\TextGeneration\ReviewNotesNormalizer;
use WordPress\DuetGAIConnector\Helper;

/**
 * Custom Text Generation Model for OpenAI-compatible APIs
 *
 * This model allows connecting to any OpenAI-compatible text generation API,
 * such as Ollama, LM Studio, MiniMax, or other custom endpoints.
 */
class CustomTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * Review Notes normalizer instance
     *
     * @var ReviewNotesNormalizer
     */
    private $reviewNotesNormalizer;

    /**
     * Get the base URL for API requests
     *
     * @return string
     */
    private function getBaseUrl(): string
    {
        return rtrim(Settings::getTextBaseUrl(), '/');
    }

    /**
     * Get model handler if available
     *
     * The model ID comes from metadata (set by CustomTextModelMetadataDirectory
     * from Settings::getTextModel()), which is the same value the SDK parent's
     * prepareGenerateTextParams() uses to populate $data['model'].
     *
     * @return ModelHandlerInterface|null
     */
    private function getModelHandler(): ?ModelHandlerInterface
    {
        return ModelHandlerRegistry::getHandler($this->metadata()->getId());
    }

    /**
     * Get Review Notes normalizer instance
     *
     * @return ReviewNotesNormalizer
     */
    private function getReviewNotesNormalizer(): ReviewNotesNormalizer
    {
        if ($this->reviewNotesNormalizer === null) {
            $this->reviewNotesNormalizer = new ReviewNotesNormalizer();
        }
        return $this->reviewNotesNormalizer;
    }

    /**
     * Create a request object for the provider's API
     *
     * @param HttpMethodEnum $method
     * @param string $path
     * @param array $headers
     * @param mixed $data
     * @return Request
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        // The model ID comes from metadata (set by CustomTextModelMetadataDirectory
        // from Settings::getTextModel()). The SDK parent's prepareGenerateTextParams()
        // already populates $data['model'] with $this->metadata()->getId(), so no
        // override is needed here — we only source it for debug logging.
        $model_id = $this->metadata()->getId();

        // Apply model-specific handler if available (e.g., MiniMax)
        $handler = $this->getModelHandler();
        Helper::debug('Handler check', [
            'model_id' => $model_id,
            'handler_found' => $handler !== null,
            'handler_class' => $handler !== null ? get_class($handler) : null,
        ]);
        if ($handler !== null && is_array($data)) {
            $data = $handler->transformRequest($data);
        } else {
            // For all other models, set n=1 to avoid "n parameter must be 1 when enable_thinking is true" error
            // This is required for models like Qwen, DeepSeek, etc. that have thinking enabled by default
            if (is_array($data) && (!isset($data['n']) || $data['n'] > 1)) {
                $data['n'] = 1;
            }

            // Transform response_format json_schema to json_object for APIs that don't support it
            // Many OpenAI-compatible APIs (Kimi, DeepSeek on SiliconFlow, etc.) don't support json_schema format
            if (isset($data['response_format']) && is_array($data['response_format'])) {
                if (isset($data['response_format']['json_schema'])) {
                    $data['response_format'] = ['type' => 'json_object'];
                } elseif (isset($data['response_format']['type']) && $data['response_format']['type'] === 'json_schema') {
                    $data['response_format'] = ['type' => 'json_object'];
                }

                // Many APIs (DashScope/qwen/glm, etc.) require the word "json" in messages
                // when using response_format json_object, so ensure it's present
                $data = $this->ensureJsonKeywordInMessages($data);
            }
        }

        // Fix response_format for APIs that don't support JSON output properly
        // Many OpenAI-compatible APIs don't properly support response_format
        // Get base URL from settings
        $base_url = $this->getBaseUrl();

        // Debug logging - log final request details
        if (defined('DUETGAICON_DEBUG') && DUETGAICON_DEBUG) {
            Helper::debug('Request', [
                'path' => $path,
                'model' => $model_id,
                'url' => $base_url . '/' . ltrim($path, '/'),
            ]);
        }

        return new Request($method, $base_url . '/' . ltrim($path, '/'), $headers, $data);
    }

    /**
     * Parse response choice to candidate
     *
     * Override to handle model-specific response formats
     *
     * @param array $choiceData
     * @param int $index
     * @return \WordPress\AiClient\Results\DTO\Candidate
     */
    protected function parseResponseChoiceToCandidate(array $choiceData, int $index): \WordPress\AiClient\Results\DTO\Candidate
    {
        // Debug: log raw response data
        if (defined('DUETGAICON_DEBUG') && DUETGAICON_DEBUG) {
            Helper::debug('Response choice[' . $index . ']', [
                'content' => isset($choiceData['message']['content']) ? substr($choiceData['message']['content'], 0, 500) : null,
                'finish_reason' => $choiceData['finish_reason'] ?? null,
            ]);
        }

        // Apply model-specific handler if available
        $handler = $this->getModelHandler();
        if ($handler !== null) {
            $choiceData = $handler->transformResponse(['choices' => [$choiceData]]);
            if (isset($choiceData['choices'][0])) {
                $choiceData = $choiceData['choices'][0];
            }
        } else {
            // For models without a specific handler (e.g., DeepSeek on SiliconFlow),
            // clean thinking tags from content
            if (isset($choiceData['message']) && is_array($choiceData['message'])) {
                $content = $choiceData['message']['content'] ?? '';

                // Always check for thinking tags in content (regardless of reasoning_content value)
                // and extract them to reasoning_content
                if (!empty($content)) {
                    $result = ThinkingTagHelper::clean($content);

                    // Always update content with cleaned version (regardless of whether thinking was found)
                    // This ensures any leading/trailing whitespace or thinking tags are removed
                    $choiceData['message']['content'] = $result['content'];
                    // Only update reasoning_content if we found thinking AND it's not already set
                    if (!empty($result['thinking']) && !isset($choiceData['message']['reasoning_content'])) {
                        $choiceData['message']['reasoning_content'] = $result['thinking'];
                    }
                }
            }
        }

        // Always copy message content fields to top level (for both handler and non-handler cases)
        if (isset($choiceData['message']) && is_array($choiceData['message'])) {
            // Copy content and reasoning_content to top level for easier access later
            // Always trim content to remove leading/trailing whitespace
            if (isset($choiceData['message']['content'])) {
                $choiceData['content'] = trim($choiceData['message']['content']);
            }
            if (isset($choiceData['message']['reasoning_content'])) {
                $choiceData['reasoning_content'] = trim($choiceData['message']['reasoning_content']);
            }
        }

        // Check if content is not valid JSON but looks like it should be JSON
        // Try to extract JSON from the text response - but ONLY for Review Notes requests
        if (isset($choiceData['content']) && is_string($choiceData['content'])) {
            $content = $choiceData['content'];

            // If the response looks like JSON (starts with [ or {), try to parse and normalize it
            if (preg_match('/^\s*[\[{]/', $content)) {
                if (defined('DUETGAICON_DEBUG') && DUETGAICON_DEBUG) {
                    Helper::debug('Detected JSON-like response, extracting and normalizing');
                }
                $json_extracted = $this->getReviewNotesNormalizer()->extractJsonFromText($content);
                if ($json_extracted !== null) {
                    $json_content = json_encode($json_extracted);
                    $choiceData['content'] = $json_content;
                    if (isset($choiceData['message']) && is_array($choiceData['message'])) {
                        $choiceData['message']['content'] = $json_content;
                    }
                }
            }
        }

        return parent::parseResponseChoiceToCandidate($choiceData, $index);
    }

    /**
     * Ensure the word "json" appears in messages for GLM models
     *
     * GLM requires the word "json" in messages when using response_format json_object.
     * This modifies the user message to include "json" if not already present.
     *
     * @param array $data
     * @return array
     */
    private function ensureJsonKeywordInMessages(array $data): array
    {
        if (!isset($data['messages']) || !is_array($data['messages'])) {
            return $data;
        }

        $jsonKeyword = 'json';
        $found = false;

        foreach ($data['messages'] as &$msg) {
            // Handle content that might be string or array (for multi-modal)
            $content = $msg['content'] ?? '';
            if (is_array($content)) {
                // For array content (multi-modal), check each part
                foreach ($content as $part) {
                    if (isset($part['text']) && is_string($part['text'])) {
                        if (stripos($part['text'], $jsonKeyword) !== false) {
                            $found = true;
                            break 2;
                        }
                    }
                }
            } elseif (is_string($content)) {
                if (stripos($content, $jsonKeyword) !== false) {
                    $found = true;
                    break;
                }
            }
        }
        unset($msg); // Unset reference

        // If no message contains "json", append a note to the last message
        if (!$found) {
            $lastIndex = count($data['messages']) - 1;
            if ($lastIndex >= 0) {
                $lastContent = $data['messages'][$lastIndex]['content'] ?? '';
                if (is_string($lastContent)) {
                    $data['messages'][$lastIndex]['content'] = $lastContent . "\n\nPlease respond in JSON format.";
                } elseif (is_array($lastContent)) {
                    // For array content, add a text part
                    $lastContent[] = ['type' => 'text', 'text' => "\n\nPlease respond in JSON format."];
                    $data['messages'][$lastIndex]['content'] = $lastContent;
                }
            }
        }

        return $data;
    }

}
