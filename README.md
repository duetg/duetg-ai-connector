# DuetG AI Connector

Connect WordPress AI Client to any OpenAI-compatible AI API provider.

## Description

DuetG AI Connector allows WordPress AI Client to connect to any AI service that provides an OpenAI-compatible API, such as:

* [Ollama](https://ollama.com/) (local AI)
* [LM Studio](https://lmstudio.ai/) (local AI)
* [MiniMax](https://www.minimax.io/) (text + image generation)
* [Moonshot](https://www.moonshot.ai/)
* [DeepSeek](https://www.deepseek.com/)
* [SiliconFlow](https://siliconflow.cn/)
* And any other OpenAI-compatible API provider

### Features

* Text generation with customizable Base URL and model
* Image generation support
* Dedicated MiniMax image generation (image-01, image-01-live) with automatic API bridging
* Works with any OpenAI-compatible API
* Simple configuration through WordPress admin
* **Compatible with [WordPress AI plugin](https://wordpress.org/plugins/ai/)**

## Requirements

* PHP 7.4 or higher
* WordPress 7.0 or higher (uses built-in Connectors API)
* (Optional) WordPress AI plugin for enhanced integration

## Installation

1. Upload the `duetg-ai-connector` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure your API key at **Settings > Connectors**
4. Go to **Settings > Custom AI** to configure your Base URL and model
5. (Optional) Go to **Tools > Test AI** to verify your configuration

## Frequently Asked Questions

### How do I enable debug logging?

To enable debug logging, add the following to your `wp-config.php`:

```php
define('DUETGAICON_DEBUG', true);
```

When enabled, debug information will be written to your server's debug log (usually `wp-content/debug.log`). This includes:
* Request/response details for AI API calls
* Provider registration status
* Model handler information

**Note**: Disable debug logging in production environments to avoid performance impact and log file growth.

### Does this plugin work without WordPress 7.0?

No, this plugin requires WordPress 7.0 or higher because it uses the built-in Connectors API for API key management.

### Why do the number of suggestions and notes sometimes not match?

When using Review Notes, you may notice that the number of suggestions returned by the AI does not exactly match the number of notes displayed in the editor.

**This is expected behavior** and has two causes:

1. **Multi-category suggestions**: Some AI models return a single suggestion that applies to multiple review categories (e.g., `review_type: "seo, accessibility"`). The plugin preserves these as-is, so one suggestion may appear under multiple note categories in WordPress AI Client.

2. **Model response format**: The AI model controls the number of suggestions it returns, and WordPress AI Client determines how to display and categorize them. The plugin correctly forwards the model's response without modifying the count.

If you need more consistent results, consider using a model that reliably returns structured JSON with distinct suggestions per category.

### How do I find the Base URL for my AI provider?

* Ollama (local): `http://localhost:11434/v1`
* LM Studio (local): `http://localhost:1234/v1`
* MiniMax: `https://api.minimax.io/v1`
* Moonshot: `https://api.moonshot.ai/v1`
* DeepSeek: `https://api.deepseek.com/v1`
* SiliconFlow: `https://api.siliconflow.cn/v1`
* Other providers: Check their documentation

### Does this plugin support MiniMax image generation?

Yes. MiniMax image models such as `image-01` and `image-01-live` work out of the box. Set your Base URL to a MiniMax endpoint (e.g., `https://api.minimax.io/v1` or `https://api.minimax.cn/v1`) and the Model Name to an image model (any name starting with `image-`). The plugin automatically detects the combination and bridges the four API differences (endpoint path, `response_format` enum, response top-level shape, image delivery channel ordering) so responses look identical to OpenAI's.

Works with any MiniMax region — detection only checks for the `minimax` substring in the Base URL.

### Do I need an API key?

Some providers require an API key. For local installations (like Ollama) that don't require authentication, you can enter any dummy string (e.g., "not-required") as the API key.

### Why do local reasoning/thinking models sometimes timeout?

Local reasoning models (like Gemma 4, QwQ, etc.) running on Ollama generate long "thinking" chains before producing their final answer. This process can take 30-60 seconds or more, which can trigger cURL's low speed limit timeout (30 seconds by default).

**Cloud models generally work well** - most cloud API providers (DeepSeek, MiniMax, Moonshot, etc.) respond quickly without timeout issues. If a cloud model frequently times out, it may have unusually long thinking chains - try switching to a different model.

**Recommended solutions for local models:**

1. **Use non-reasoning models** for local AI features. For Ollama, models like `qwen2.5:7b`, `llama3.2:3b`, or `phi3` work well without the timeout issue.

2. **Configure Ollama to keep models loaded:**
   ```bash
   export OLLAMA_KEEP_ALIVE=-1  # Keep model in memory
   ```

If using reasoning models, be aware that WordPress AI features may be slower or timeout. The thinking behavior is controlled by the model, not by the plugin.

### How do I use a local AI provider (like Ollama or LM Studio)?

By default, WordPress blocks requests to localhost and private IP addresses for security (SSRF protection). If you're using a local AI provider, you can disable this protection by adding to your `wp-config.php`:

```php
define('DUETGAICON_ALLOW_LOCAL_URLS', true);
```

**Warning**: Disabling SSRF protection allows requests to private/local IPs. Only enable this if you trust your local AI provider and your server is not directly accessible from the internet.

This setting applies to both text and image models when using local AI providers.

**Tip**: When `DUETGAICON_ALLOW_LOCAL_URLS` is enabled, a **Network Connectivity Test** tool appears on the Test AI page (Tools > Test AI). You can use it to verify that your WordPress server can reach your local AI provider before running actual AI feature tests. This is especially useful for debugging connection issues with local Ollama or LM Studio installations.

### How do I use this in my code?

```php
use WordPress\AiClient\AiClient;

$registry = AiClient::defaultRegistry();

// Text Generation
$model = $registry->getProviderModel('duetgaicon_text', 'gpt-4');
$result = $model->generateTextResult([
    new \WordPress\AiClient\Messages\DTO\UserMessage([
        new \WordPress\AiClient\Messages\DTO\MessagePart('Your prompt here')
    ])
]);
echo $result->toText();

// Image Generation
$model = $registry->getProviderModel('duetgaicon_image', 'dall-e-3');
$result = $model->generateImageResult([
    new \WordPress\AiClient\Messages\DTO\UserMessage([
        new \WordPress\AiClient\Messages\DTO\MessagePart('Your prompt here')
    ])
]);
$files = $result->toImageFiles();
```

## Changelog

### 0.3.5
* Added image refinement (modify a previously generated image with an additional prompt) by routing to OpenAI's `images/edits` endpoint with multipart/form-data when a reference file is attached
* Extended image metadata input modalities to support both text-only and text+image prompt shapes so the official WordPress AI plugin's `is_supported_for_image_generation()` returns true for refinement requests
* Added MiniMax-aware dispatch for refinement (MiniMax does not expose `images/edits`): refinement requests go to MiniMax's `image_generation` endpoint with the reference carried inline
* Fixed MiniMax refinement wire format to use the API's `subject_reference` field (with `type: character` and the image as a base64 Data URL); the previous `image: [...]` payload was silently ignored by MiniMax, which caused refined images to look unrelated to the original

### 0.3.4
* Added support for MiniMax image generation (image-01, image-01-live) via a dual-filter handler that matches MiniMax Base URL plus image-model prefix and bridges the four OpenAI/MiniMax API differences (endpoint path, response_format enum, response top-level shape, image delivery channel ordering)
* Refactored MiniMax URL detection to a single substring check so future MiniMax regions and CDN domains work without a plugin update
* Removed dead getModelId() overrides from text and image models (no longer required after the SDK moved to $this->metadata()->getId())
* Removed unused jQuery dependency from the Test AI admin page (the page is vanilla JS)
* Bumped "Tested up to" to WordPress 7.1
* Updated Stable tag to match plugin version

### 0.3.3
* Fixed PHP 8.x "Undefined variable $prompt" warning on Test AI page initial load
* Fixed PHP 8.x "file_exists() path too long" warning emitted when image API returns b64_json larger than 4 KB
* Extended the b64_json workaround to the URL→base64 download path (triggered when providers ignore response_format and return a URL)
* Renamed internal PHP namespace from WordPress\CustomAiProvider to WordPress\DuetGAIConnector
* Updated @package docblock annotations to match the new namespace

### 0.3.2
* Fixed logo not appearing by removing manual connector registration (use registerProvider())
* Fixed image generation display (bypassed esc_url() for inline base64 data URIs)
* Fixed isConfigured() to properly validate API key presence
* Renamed all provider IDs from custom_text/custom_image to duetgaicon_text/duetgaicon_image
* Added debug logging for provider registration status
* Updated code examples in documentation to reflect new provider ID prefix
* Removed WordPress AI Integration section from readme
* Removed Model Capabilities section from readme

### 0.3.1
* Added isLocalUrl() helper method for detecting localhost/private IP URLs
* Added explicit error message when local AI URL is configured but DUETGAICON_ALLOW_LOCAL_URLS is disabled
* Fixed PHP syntax error (stray ); in TestPage.php)
* Fixed PHPCS: use wp_kses_post for error output to allow HTML formatting
* Fixed PHPCS: use sprintf with translators comment for i18n-friendly error messages
* Network Connectivity Test button now uses primary styling
* Refactored duplicate local URL detection code into Helper::isLocalUrl()

### 0.3.0
* Renamed plugin to DuetG AI Connector (from Custom AI Provider)
* Added Content Classification feature (AI-powered suggestions for post tags and categories)
* Added Meta Description Generation feature
* Added WordPress Connector Registry integration for WordPress 7.0+ compatibility
* Added multi-provider response format normalization for MiniMax, Kimi, GLM, and Tencent Hunyuan
* Added automatic JSON keyword injection for DashScope (qwen/glm) models
* Improved JSON extraction using balanced brace counting instead of non-greedy regex
* Added detailed debug logging with URL sanitization
* Added Network Connectivity Test feature to Test AI page for debugging local Ollama connections
* Expanded DUETGAICON_ALLOW_LOCAL_URLS to apply to entire plugin (text and image models)
* Added FAQ section explaining local reasoning model timeout issues
* Removed WordPress AI version requirements from documentation
* Cleaned up debug logging code in CustomTextGenerationModel
* Fixed PHPCS: sanitize test_url before use in form field
* Network Connectivity Test now hidden when DUETGAICON_ALLOW_LOCAL_URLS is disabled
* Fixed Network Connectivity Test button styling (now uses primary style)
* Added Network Connectivity Test documentation to local AI provider FAQ

### 0.2.3
* Fixed OutputNotEscaped error for image URL in TestPage.php

### 0.2.2
* Fixed namespace declaration order in Settings.php (moved before ABSPATH check)
* Fixed JS file version to use JS file's own mtime instead of plugin.php
* Added function_exists() wrapper to custom_ai_debug() to prevent conflicts
* Fixed duplicate docblock comment in ThinkingTagHelper
* Fixed typo in CustomImageGenerationModel comment ("if not setting" → "if not set")
* Added URL sanitization in debug logs to filter sensitive params (api_key, token, etc.)
* Changed JSON regex from greedy to non-greedy matching for better accuracy
* Fixed dirname level bug in TestPage.php (dirname level 3 → 2)
* Fixed array_map key preservation bug in debug logging
* Inlined URL sanitization logic to avoid nested function definition

### 0.2.1
* Fixed missing resource version in wp_enqueue_script()
* Fixed unsescaped output in test page
* Added direct file access protection to Settings.php
* Added SSRF protection for image URLs (blocks localhost/private IPs by default)
* Added DUETGAICON_ALLOW_LOCAL_URLS constant to enable local image URLs when needed
* Updated to WordPress AI plugin (removed "Experiments" branding)

### 0.2.0
* Added compatibility with WordPress AI plugin (0.6.0+)
* Added Alt Text Generation support (requires VLM model)
* Added Image Prompt Generation support
* Added Review Notes feature
* Added Title Generation support
* Added Content Summarization support
* Added Excerpt Generation support
* Added thinking/reasoning support for models like DeepSeek, Qwen, MiniMax, Kimi
* Improved JSON response parsing for better compatibility
* Added debug logging (controlled via WP_DEBUG)

### 0.1.0
* Initial release
* Support for text generation
* Support for image generation

## License

GNU General Public License v2.0 or later - see [LICENSE](LICENSE) file for details.
