<?php
/**
 * Custom Image Generation Model
 *
 * @package DuetGAIConnector\Models\ImageGeneration
 */

namespace WordPress\DuetGAIConnector\Models\ImageGeneration;

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\DuetGAIConnector\Settings\Settings;
use WordPress\DuetGAIConnector\Helper;

/**
 * Custom Image Generation Model for OpenAI-compatible APIs
 *
 * This model allows connecting to any OpenAI-compatible image generation API,
 * such as Ollama, Stable Diffusion endpoints, or other custom image APIs.
 *
 * Provider-specific quirks (currently only MiniMax) are encapsulated in
 * {@see MiniMaxImageHandler} and consulted at three points: endpoint path
 * remap, request param adjustment, and response shape normalisation.
 */
class CustomImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel
{
    /**
     * Lazily-initialised MiniMax image handler.
     *
     * @var MiniMaxImageHandler|null
     */
    private $minimaxImageHandler;

    /**
     * Get the base URL for API requests
     *
     * @return string
     */
    private function getBaseUrl(): string
    {
        return rtrim(Settings::getImageBaseUrl(), '/');
    }

    /**
     * Get (or lazily create) the MiniMax image handler.
     *
     * Lazy because most requests will not be MiniMax, and the handler
     * is stateless so a single instance can be reused safely.
     *
     * @return MiniMaxImageHandler
     */
    private function getMinimaxImageHandler(): MiniMaxImageHandler
    {
        if ($this->minimaxImageHandler === null) {
            $this->minimaxImageHandler = new MiniMaxImageHandler();
        }
        return $this->minimaxImageHandler;
    }

    /**
     * Create a request object for the provider's API
     *
     * The model ID is sourced from metadata (set by CustomImageModelMetadataDirectory
     * from Settings::getImageModel()); the SDK parent's prepareGenerateImageParams()
     * populates $data['model'] with $this->metadata()->getId() before this is called,
     * so no override is needed here.
     *
     * If the configured endpoint is MiniMax (dual-filter: MiniMax domain in
     * Base URL AND MiniMax-style model id), the OpenAI-style
     * `images/generations` path is remapped to MiniMax's `image_generation`.
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
        // Get base URL from settings
        $base_url = $this->getBaseUrl();

        // Apply MiniMax-specific endpoint remap (if applicable).
        $model_id = $this->metadata()->getId();
        $path = $this->getMinimaxImageHandler()->remapPath($path, $base_url, $model_id);

        return new Request($method, $base_url . '/' . ltrim($path, '/'), $headers, $data);
    }

    /**
     * Prepare generate image parameters
     *
     * Default behaviour: force `b64_json` for OpenAI-compatible APIs that
     * ignore the configured response_format (SiliconFlow, Ollama, etc.).
     *
     * For MiniMax endpoints, swap to MiniMax's `base64` enum value via
     * {@see MiniMaxImageHandler::prepareParams()}.
     *
     * @param array $prompt The prompt messages
     * @return array The prepared parameters
     */
    protected function prepareGenerateImageParams(array $prompt): array
    {
        $params = parent::prepareGenerateImageParams($prompt);

        // Force b64_json format - some providers ignore the response_format parameter
        // and always return URL, but we need base64 for WordPress AI compatibility
        $params['response_format'] = 'b64_json';

        // Provider-specific overrides (e.g., MiniMax uses "base64" instead of "b64_json").
        $params = $this->getMinimaxImageHandler()->prepareParams(
            $params,
            $this->getBaseUrl(),
            $this->metadata()->getId()
        );

        return $params;
    }

    /**
     * Parse response choice to candidate
     *
     * Override to handle URL responses by downloading and converting to base64.
     * Some providers (like SiliconFlow) may ignore the response_format parameter
     * and always return a URL instead of base64.
     *
     * @param array $choiceData The choice data from API response
     * @param int $index The index of the choice
     * @param string $expectedMimeType Expected MIME type
     * @return Candidate The parsed candidate
     */
    protected function parseResponseChoiceToCandidate(array $choiceData, int $index, string $expectedMimeType = 'image/png'): Candidate
    {
        // First, try the standard way (parent class logic)
        if (isset($choiceData['url']) && is_string($choiceData['url'])) {
            // Provider returned URL - check if we can use it directly
            // If b64_json was requested but URL was returned, try to convert
            $imageFile = $this->createFileFromUrl($choiceData['url'], $expectedMimeType);
        } elseif (isset($choiceData['b64_json']) && is_string($choiceData['b64_json'])) {
            $imageFile = $this->createFileFromBase64($choiceData['b64_json'], $expectedMimeType);
        } else {
            // No url or b64_json - throw error like parent
            throw new \WordPress\AiClient\Providers\Http\Exception\ResponseException(
                sprintf('Invalid image data at index %d: must contain url or b64_json', (int) $index)
            );
        }

        $parts = [new MessagePart($imageFile)];
        $message = new Message(MessageRoleEnum::model(), $parts);
        return new Candidate($message, FinishReasonEnum::stop());
    }

    /**
     * Parse the response from the API endpoint to a generative AI result.
     *
     * Override to handle provider-specific response shapes before
     * delegating to the SDK parent's standard parsing. Currently:
     *
     *   - MiniMax returns `{data: {image_urls[], image_base64[]}}` rather
     *     than OpenAI's `{data: [{url}|{b64_json}]}`. The handler flattens
     *     this into the SDK's expected shape so the existing
     *     {@see self::parseResponseChoiceToCandidate()} can run unchanged.
     *
     * When the handler does not apply (or the response is already in
     * OpenAI shape) we delegate straight to the parent.
     *
     * @param Response $response        The HTTP response to parse.
     * @param string   $expectedMimeType The expected MIME type.
     * @return GenerativeAiResult
     */
    protected function parseResponseToGenerativeAiResult(Response $response, string $expectedMimeType = 'image/png'): GenerativeAiResult
    {
        $base_url = $this->getBaseUrl();
        $model_id = $this->metadata()->getId();
        $handler  = $this->getMinimaxImageHandler();

        // Fast path: handler doesn't apply, or response body is empty/non-array.
        $data = $response->getData();
        if (!$handler->applies($model_id, $base_url) || !is_array($data)) {
            return parent::parseResponseToGenerativeAiResult($response, $expectedMimeType);
        }

        $transformed = $handler->transformResponse($data, $base_url, $model_id);

        // If the handler didn't actually change anything (e.g., MiniMax
        // returned an error envelope, or data was already flat), delegate
        // unchanged so the parent's error reporting stays accurate.
        if ($transformed === $data) {
            return parent::parseResponseToGenerativeAiResult($response, $expectedMimeType);
        }

        $rewrittenBody = json_encode($transformed);
        if ($rewrittenBody === false) {
            // Encoding the (already-decoded) data failed — fall back to the
            // original response and let the parent surface a meaningful error.
            return parent::parseResponseToGenerativeAiResult($response, $expectedMimeType);
        }

        $rewritten = new Response(
            $response->getStatusCode(),
            $response->getHeaders(),
            $rewrittenBody
        );

        Helper::debug('MiniMax image response transformed to OpenAI shape', [
            'model_id' => $model_id,
            'choices_count' => isset($transformed['data']) && is_array($transformed['data']) ? count($transformed['data']) : 0,
        ]);

        return parent::parseResponseToGenerativeAiResult($rewritten, $expectedMimeType);
    }

    /**
     * Create a File from URL, converting to base64 if necessary
     *
     * Some providers return URLs even when b64_json is requested.
     * This method attempts to convert URLs to base64 for WordPress AI compatibility.
     *
     * @param string $url The image URL
     * @param string $expectedMimeType Expected MIME type
     * @return File The file object
     * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException
     */
    private function createFileFromUrl(string $url, string $expectedMimeType): File
    {
        // Validate URL to prevent SSRF attacks
        $this->validateImageUrl($url);

        // Try to fetch the image and convert to base64
        $response = wp_remote_get($url, ['timeout' => 30]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            if (!empty($body)) {
                return $this->createFileFromBase64(base64_encode($body), $expectedMimeType);
            }
        }

        // If we couldn't fetch, return as URL file (might work if URL is accessible)
        // Log the failure for debugging
        if (is_wp_error($response)) {
            Helper::debug('Image download failed', [
                'url' => substr($url, 0, 200),
                'error' => $response->get_error_message()
            ]);
        } else {
            Helper::debug('Image download failed', [
                'url' => substr($url, 0, 200),
                'error' => 'HTTP ' . wp_remote_retrieve_response_code($response)
            ]);
        }

        return new File($url, $expectedMimeType);
    }

    /**
     * Create a File from raw base64 data.
     *
     * Wraps the base64 as a Data URI to bypass an upstream bug in
     * \WordPress\AiClient\Files\DTO\File::detectAndProcessFile(), which
     * probes input in this order:
     *   1. URL          (isUrl)
     *   2. Data URI     (preg_match)
     *   3. file_exists() <- emits "file name is longer than the maximum
     *                       allowed path length on this platform (4096)"
     *                       on PHP 8.x when the raw base64 string is
     *                       > 4096 bytes
     *   4. Base64 regex
     *
     * A raw base64 string larger than 4 KB trips the file_exists() check
     * (which never finds a real path) and emits a Warning even though the
     * final base64 regex matches. Wrapping as a Data URI matches step 2
     * and skips file_exists entirely. The resulting File is functionally
     * equivalent (same fileType=inline, same base64Data, same mimeType)
     * for any standard base64 input (the only kind base64_encode() or
     * API responses produce).
     *
     * Tracked upstream as php-ai-client issue #258. When the upstream
     * File constructor gates file_exists() on strlen() <= PHP_MAXPATHLEN,
     * this helper can be replaced with direct `new File($base64, $mime)`
     * calls and removed.
     *
     * @param string $base64   Standard base64 (no data URI prefix, no
     *                         newlines). Typically the output of
     *                         base64_encode() or a b64_json API response.
     * @param string $mimeType MIME type to record on the File.
     * @return File
     */
    private function createFileFromBase64(string $base64, string $mimeType): File
    {
        $dataUri = 'data:' . $mimeType . ';base64,' . $base64;
        return new File($dataUri, $mimeType);
    }

    /**
     * Validate image URL to prevent SSRF attacks
     *
     * By default, blocks private IP ranges, localhost, and non-HTTP protocols.
     * Override by defining DUETGAICON_ALLOW_LOCAL_URLS in wp-config.php.
     *
     * @param string $url The URL to validate
     * @throws \WordPress\AiClient\Providers\Http\Exception\ResponseException
     */
    private function validateImageUrl(string $url): void
    {
        // Allow override via constant (for local development/testing)
        if (defined('DUETGAICON_ALLOW_LOCAL_URLS') && DUETGAICON_ALLOW_LOCAL_URLS) {
            return;
        }

        // Validate protocol - only allow HTTP and HTTPS
        if (!preg_match('#^https?://#i', $url)) {
            throw new \WordPress\AiClient\Providers\Http\Exception\ResponseException(
                'Image URL must use HTTP or HTTPS protocol'
            );
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            throw new \WordPress\AiClient\Providers\Http\Exception\ResponseException(
                'Invalid image URL: could not parse host'
            );
        }

        // Block localhost variations
        $localhosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0', '::'];
        if (in_array(strtolower($host), $localhosts, true)) {
            throw new \WordPress\AiClient\Providers\Http\Exception\ResponseException(
                'Image URL must not point to localhost'
            );
        }

        // Block private and reserved IP addresses
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && filter_var($host, FILTER_VALIDATE_IP) !== false) {
            throw new \WordPress\AiClient\Providers\Http\Exception\ResponseException(
                'Image URL must not point to a private or reserved IP address'
            );
        }

        // Block hostnames that resolve to private IPs (basic check)
        // This is a best-effort check since DNS resolution can be slow
        if (defined('DUETGAICON_CHECK_DNS') && DUETGAICON_CHECK_DNS) {
            $dns_result = gethostbynamel($host);
            if ($dns_result !== false) {
                foreach ($dns_result as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
                        && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                        throw new \WordPress\AiClient\Providers\Http\Exception\ResponseException(
                            'Image URL resolves to a private or reserved IP address'
                        );
                    }
                }
            }
        }
    }
}
