<?php
/**
 * MiniMax Image Generation Handler
 *
 * Bridges the gap between the SDK's OpenAI-compatible image generation
 * contract and MiniMax's image_generation endpoint, which differs in:
 *
 *   1. Endpoint path:
 *      - OpenAI:    /v1/images/generations
 *      - MiniMax:   /v1/image_generation
 *
 *   2. response_format enum:
 *      - OpenAI:    "url" | "b64_json"
 *      - MiniMax:   "url" | "base64"
 *
 *   3. Response top-level shape:
 *      - OpenAI:    { data: [{url}|{b64_json}, ...] }
 *      - MiniMax:   { data: { image_urls: [...], image_base64: [...] } }
 *
 * Detection is intentionally dual-keyed: this handler only applies when
 * BOTH the Base URL contains the substring `minimax` (case-insensitive)
 * AND the model id matches MiniMax's image-model prefix. MiniMax image
 * model names are intentionally short ("image-01", "image-01-live"), so
 * URL-only matching would risk false positives against other providers'
 * models that happen to share the prefix; model-only matching would risk
 * false positives when the user accidentally points an OpenAI-style
 * endpoint at a MiniMax-shaped model. Requiring both signals eliminates
 * both classes of mistake.
 *
 * The URL check is intentionally a single substring (`minimax`) rather
 * than a curated allowlist of MiniMax domains. The Base URL is a
 * user-supplied setting — if a user types `my-minimax-cdn.example` and
 * pairs it with an `image-*` model, they have explicitly opted into
 * MiniMax-shaped request handling and the plugin honours that intent.
 * This also means future MiniMax regions (`minimax.eu`, `minimax.us`,
 * `cdn.minimax-cdn.com`, …) work automatically without a plugin update.
 *
 * This handler is stateless and safe to instantiate once per request.
 *
 * @package DuetGAIConnector\Models\ImageGeneration
 */

namespace WordPress\DuetGAIConnector\Models\ImageGeneration;

/**
 * MiniMax Image Generation Handler
 */
class MiniMaxImageHandler
{
    /**
     * Image-model prefix used by all current MiniMax image models
     * (`image-01`, `image-01-live`, future `image-02-*`, ...).
     *
     * @var string
     */
    private const MINIMAX_IMAGE_MODEL_PREFIX = 'image-';

    /**
     * Endpoint path remap for image generation.
     *
     * The SDK's AbstractOpenAiCompatibleImageGenerationModel always POSTs
     * to "images/generations"; MiniMax expects "image_generation". We
     * remap only when (a) the handler applies and (b) the SDK is asking
     * for the image generation path — leaving any other future calls
     * untouched.
     *
     * @var string
     */
    private const MINIMAX_IMAGE_PATH = 'image_generation';

    /**
     * Default image generation path used by the SDK (OpenAI convention).
     *
     * @var string
     */
    private const OPENAI_IMAGE_PATH = 'images/generations';

    /**
     * Response format value MiniMax expects when we want inline base64.
     *
     * @var string
     */
    private const MINIMAX_RESPONSE_FORMAT_BASE64 = 'base64';

    /**
     * Whether this handler applies to the given request.
     *
     * Dual-filter: BOTH the Base URL must contain the substring
     * `minimax` (case-insensitive) AND the model id must start with
     * MiniMax's image-model prefix.
     *
     * A null/empty base URL or model id never matches — the handler must
     * not silently transform OpenAI traffic.
     *
     * @param string      $modelId The configured image model id.
     * @param string|null $baseUrl The configured Base URL (may be null).
     * @return bool
     */
    public function applies(string $modelId, ?string $baseUrl): bool
    {
        if ($modelId === '' || $baseUrl === null || $baseUrl === '') {
            return false;
        }

        return $this->urlMatchesMinimax($baseUrl) && $this->modelMatchesMinimaxImage($modelId);
    }

    /**
     * Remap the image-generation endpoint path if this handler applies.
     *
     * Returns the unchanged path when either (a) this handler does not
     * apply or (b) the path is not the SDK's image-generation path —
     * leaving future SDK calls (e.g. a hypothetical edits endpoint)
     * untouched.
     *
     * @param string      $path The path the SDK wants to call.
     * @param string|null $baseUrl The configured Base URL.
     * @param string      $modelId The configured model id.
     * @return string Possibly remapped path.
     */
    public function remapPath(string $path, ?string $baseUrl, string $modelId): string
    {
        if (!$this->applies($modelId, $baseUrl)) {
            return $path;
        }
        if ($path !== self::OPENAI_IMAGE_PATH) {
            return $path;
        }
        return self::MINIMAX_IMAGE_PATH;
    }

    /**
     * Adjust request params for MiniMax's image_generation contract.
     *
     * Specifically swaps `response_format` from OpenAI's "b64_json" to
     * MiniMax's "base64". Returns the params unchanged when this handler
     * does not apply.
     *
     * @param array       $params  Params prepared by the SDK parent.
     * @param string|null $baseUrl The configured Base URL.
     * @param string      $modelId The configured model id.
     * @return array Params ready to send.
     */
    public function prepareParams(array $params, ?string $baseUrl, string $modelId): array
    {
        if (!$this->applies($modelId, $baseUrl)) {
            return $params;
        }
        $params['response_format'] = self::MINIMAX_RESPONSE_FORMAT_BASE64;
        return $params;
    }

    /**
     * Transform MiniMax's nested image response into the SDK's flat
     * `{data: [{url}|{b64_json}, ...]}` shape.
     *
     * Returns the response unchanged when this handler does not apply OR
     * the response is already in OpenAI shape. The caller is responsible
     * for re-encoding the body into a new Response object before
     * delegating back to the SDK parent.
     *
     * Behaviour matrix:
     *
     *   { data: { image_base64: [...], image_urls: [...] } }
     *     -> { data: [{b64_json} | {url}, ...] }   (interleaved)
     *
     *   { data: { image_base64: [...] } }
     *     -> { data: [{b64_json}, ...] }
     *
     *   { data: { image_urls: [...] } }
     *     -> { data: [{url}, ...] }
     *
     *   { data: [...] }   (already flat — leave alone)
     *     -> unchanged
     *
     *   anything else      -> unchanged (let parent surface an error)
     *
     * @param array       $response The decoded response body.
     * @param string|null $baseUrl  The configured Base URL.
     * @param string      $modelId  The configured model id.
     * @return array
     */
    public function transformResponse(array $response, ?string $baseUrl, string $modelId): array
    {
        if (!$this->applies($modelId, $baseUrl)) {
            return $response;
        }
        if (!isset($response['data']) || !is_array($response['data'])) {
            return $response;
        }
        // If `data` is already a list of choice-shaped objects, the response
        // is in OpenAI shape — leave it alone.
        if (array_is_list($response['data'])) {
            return $response;
        }

        $choices = [];
        $base64List = isset($response['data']['image_base64']) && is_array($response['data']['image_base64'])
            ? array_values($response['data']['image_base64'])
            : [];
        $urlList = isset($response['data']['image_urls']) && is_array($response['data']['image_urls'])
            ? array_values($response['data']['image_urls'])
            : [];

        // MiniMax returns parallel arrays in the same order. If both are
        // present, interleave base64-first then url so a downstream
        // consumer that expects [b64, b64, url, url] doesn't get surprised.
        // When only one channel is present, emit that channel alone.
        foreach ($base64List as $b64) {
            if (is_string($b64) && $b64 !== '') {
                $choices[] = ['b64_json' => $b64];
            }
        }
        foreach ($urlList as $url) {
            if (is_string($url) && $url !== '') {
                $choices[] = ['url' => $url];
            }
        }

        if (empty($choices)) {
            // No usable images found — return the original structure so the
            // SDK parent's "data must be an array" error path can surface
            // a meaningful message instead of swallowing the MiniMax keys.
            return $response;
        }

        $response['data'] = $choices;
        return $response;
    }

    /**
     * Case-insensitive substring check that the Base URL identifies as
     * MiniMax.
     *
     * Single-substring match on `minimax` rather than a curated allowlist:
     * the Base URL is a user-supplied setting, so any URL the user types
     * that contains `minimax` and is paired with an `image-*` model is
     * treated as MiniMax-shaped traffic. This auto-covers current
     * (`api.minimax.cn`, `api.minimax.io`, legacy `api.minimaxi.com`) and
     * future (`api.minimax.eu`, `cdn.minimax-cdn.com`, …) endpoints
     * without a plugin update.
     *
     * @param string $baseUrl
     * @return bool
     */
    private function urlMatchesMinimax(string $baseUrl): bool
    {
        return strpos(strtolower($baseUrl), 'minimax') !== false;
    }

    /**
     * Check whether the model id looks like a MiniMax image model.
     *
     * Currently all MiniMax image models share the `image-` prefix. If
     * MiniMax later introduces an image model that breaks this convention,
     * add an explicit allowlist here.
     *
     * @param string $modelId
     * @return bool
     */
    private function modelMatchesMinimaxImage(string $modelId): bool
    {
        return strpos($modelId, self::MINIMAX_IMAGE_MODEL_PREFIX) === 0;
    }
}
