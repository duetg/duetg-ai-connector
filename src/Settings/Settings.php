<?php
/**
 * Settings for DuetG AI Connector
 *
 * @package DuetGAIConnector\Settings
 */

namespace WordPress\DuetGAIConnector\Settings;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

/**
 * Settings class for managing connector configuration
 */
class Settings
{
    // Default API Configuration
    //
    // The two Base URL constants intentionally use api.openai.com as the
    // out-of-the-box default. They are *only* the fallback read by
    // getTextBaseUrl() / getImageBaseUrl() when the user has not saved a
    // value yet; every request still goes through the WordPress AI Client,
    // so Plugin Check's AIProvider.DirectIntegration sniff should not flag
    // them. We suppress the false positive with a scoped phpcs:disable block.
    // phpcs:disable PluginCheck.CodeAnalysis.AIProvider.DirectIntegration
    public const DEFAULT_TEXT_BASE_URL = 'https://api.openai.com/v1';
    public const DEFAULT_TEXT_MODEL = 'gpt-4';
    public const DEFAULT_IMAGE_BASE_URL = 'https://api.openai.com/v1';
    public const DEFAULT_IMAGE_MODEL = 'dall-e-3';
    // phpcs:enable PluginCheck.CodeAnalysis.AIProvider.DirectIntegration

    // Cached values to reduce get_option() calls
    private static ?string $cachedTextModel = null;
    private static ?string $cachedTextBaseUrl = null;
    private static ?string $cachedImageModel = null;
    private static ?string $cachedImageBaseUrl = null;

    // Text Generation Settings
    public const TEXT_ENABLED_OPTION = 'connectors_ai_duetgaicon_text_enabled';
    public const TEXT_BASE_URL_OPTION = 'connectors_ai_duetgaicon_text_base_url';
    public const TEXT_MODEL_OPTION = 'connectors_ai_duetgaicon_text_model';
    public const TEXT_API_KEY_OPTION = 'connectors_ai_duetgaicon_text_api_key';

    // Image Generation Settings
    public const IMAGE_ENABLED_OPTION = 'connectors_ai_duetgaicon_image_enabled';
    public const IMAGE_BASE_URL_OPTION = 'connectors_ai_duetgaicon_image_base_url';
    public const IMAGE_MODEL_OPTION = 'connectors_ai_duetgaicon_image_model';
    public const IMAGE_API_KEY_OPTION = 'connectors_ai_duetgaicon_image_api_key';

    /**
     * Initialize settings
     */
    public static function init(): void
    {
        // Register settings for REST API
        self::register_settings();

        // Auto-clear cache when options are updated externally (REST API, WP-CLI, etc.)
        $options = [
            self::TEXT_MODEL_OPTION,
            self::TEXT_BASE_URL_OPTION,
            self::IMAGE_MODEL_OPTION,
            self::IMAGE_BASE_URL_OPTION,
        ];
        foreach ($options as $opt) {
            add_action("update_option_{$opt}", [self::class, 'resetCache']);
        }
    }

    /**
     * Register WordPress settings
     */
    private static function register_settings(): void
    {
        // Text Generation Settings
        register_setting('connectors', self::TEXT_ENABLED_OPTION, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
            'show_in_rest' => true,
        ]);

        register_setting('connectors', self::TEXT_BASE_URL_OPTION, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => self::DEFAULT_TEXT_BASE_URL,
            'show_in_rest' => true,
        ]);

        register_setting('connectors', self::TEXT_MODEL_OPTION, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => self::DEFAULT_TEXT_MODEL,
            'show_in_rest' => true,
        ]);

        // Image Generation Settings
        register_setting('connectors', self::IMAGE_ENABLED_OPTION, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
            'show_in_rest' => true,
        ]);

        register_setting('connectors', self::IMAGE_BASE_URL_OPTION, [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => self::DEFAULT_IMAGE_BASE_URL,
            'show_in_rest' => true,
        ]);

        register_setting('connectors', self::IMAGE_MODEL_OPTION, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => self::DEFAULT_IMAGE_MODEL,
            'show_in_rest' => true,
        ]);
    }

    /**
     * Pass API keys to AI Client registry
     * API keys are stored via WordPress Connectors and retrieved from database
     */
    public static function pass_api_keys_to_ai_client(): void
    {
        if (!class_exists(AiClient::class)) {
            return;
        }

        try {
            $registry = AiClient::defaultRegistry();

            // Text generation API key
            $text_api_key = self::get_text_api_key();
            if (!empty($text_api_key) && $registry->hasProvider('duetgaicon_text')) {
                $registry->setProviderRequestAuthentication(
                    'duetgaicon_text',
                    new ApiKeyRequestAuthentication($text_api_key)
                );
            }

            // Image generation API key
            $image_api_key = self::get_image_api_key();
            if (!empty($image_api_key) && $registry->hasProvider('duetgaicon_image')) {
                $registry->setProviderRequestAuthentication(
                    'duetgaicon_image',
                    new ApiKeyRequestAuthentication($image_api_key)
                );
            }
        } catch (\Exception $e) {
            Helper::debug('Failed to pass API keys to AI Client', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get text API key
     * Priority: Constant > Environment Variable > Database Option
     */
    public static function get_text_api_key(): string
    {
        // 1. Check constant
        if (defined('DUETGAICON_TEXT_API_KEY')) {
            return constant('DUETGAICON_TEXT_API_KEY');
        }

        // 2. Check environment variable
        $env = getenv('DUETGAICON_TEXT_API_KEY');
        if ($env !== false && $env !== '') {
            return $env;
        }

        // 3. Fallback to database
        return get_option(self::TEXT_API_KEY_OPTION, '');
    }

    /**
     * Get image API key
     * Priority: Constant > Environment Variable > Database Option
     */
    public static function get_image_api_key(): string
    {
        // 1. Check constant
        if (defined('DUETGAICON_IMAGE_API_KEY')) {
            return constant('DUETGAICON_IMAGE_API_KEY');
        }

        // 2. Check environment variable
        $env = getenv('DUETGAICON_IMAGE_API_KEY');
        if ($env !== false && $env !== '') {
            return $env;
        }

        // 3. Fallback to database
        return get_option(self::IMAGE_API_KEY_OPTION, '');
    }

    /**
     * Get text model - returns configured model or empty string
     */
    public static function getTextModel(): string
    {
        if (self::$cachedTextModel === null) {
            self::$cachedTextModel = get_option(self::TEXT_MODEL_OPTION, self::DEFAULT_TEXT_MODEL);
        }
        return self::$cachedTextModel;
    }

    /**
     * Get text base URL - returns configured URL or empty string
     */
    public static function getTextBaseUrl(): string
    {
        if (self::$cachedTextBaseUrl === null) {
            self::$cachedTextBaseUrl = get_option(self::TEXT_BASE_URL_OPTION, self::DEFAULT_TEXT_BASE_URL);
        }
        return self::$cachedTextBaseUrl;
    }

    /**
     * Get image model - returns configured model or empty string
     */
    public static function getImageModel(): string
    {
        if (self::$cachedImageModel === null) {
            self::$cachedImageModel = get_option(self::IMAGE_MODEL_OPTION, self::DEFAULT_IMAGE_MODEL);
        }
        return self::$cachedImageModel;
    }

    /**
     * Get image base URL - returns configured URL or empty string
     */
    public static function getImageBaseUrl(): string
    {
        if (self::$cachedImageBaseUrl === null) {
            self::$cachedImageBaseUrl = get_option(self::IMAGE_BASE_URL_OPTION, self::DEFAULT_IMAGE_BASE_URL);
        }
        return self::$cachedImageBaseUrl;
    }

    /**
     * Reset cached values - useful for testing or when options are updated
     */
    public static function resetCache(): void
    {
        self::$cachedTextModel = null;
        self::$cachedTextBaseUrl = null;
        self::$cachedImageModel = null;
        self::$cachedImageBaseUrl = null;
    }
}
