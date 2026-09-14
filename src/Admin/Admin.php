<?php
/**
 * Admin settings page for DuetG AI Connector
 *
 * @package DuetGAIConnector\Admin
 */

namespace WordPress\DuetGAIConnector\Admin;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

use WordPress\DuetGAIConnector\Settings\Settings;

/**
 * Admin class for rendering the settings page
 */
class Admin
{
    /**
     * Render the settings page
     */
    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings if form was submitted
        if (isset($_POST['duetgaicon_save']) && check_admin_referer('duetgaicon_save_action')) {
            if (isset($_POST[Settings::TEXT_BASE_URL_OPTION])) {
                update_option(Settings::TEXT_BASE_URL_OPTION, esc_url_raw(wp_unslash($_POST[Settings::TEXT_BASE_URL_OPTION])));
            }
            if (isset($_POST[Settings::TEXT_MODEL_OPTION])) {
                update_option(Settings::TEXT_MODEL_OPTION, sanitize_text_field(wp_unslash($_POST[Settings::TEXT_MODEL_OPTION])));
            }
            if (isset($_POST[Settings::IMAGE_BASE_URL_OPTION])) {
                update_option(Settings::IMAGE_BASE_URL_OPTION, esc_url_raw(wp_unslash($_POST[Settings::IMAGE_BASE_URL_OPTION])));
            }
            if (isset($_POST[Settings::IMAGE_MODEL_OPTION])) {
                update_option(Settings::IMAGE_MODEL_OPTION, sanitize_text_field(wp_unslash($_POST[Settings::IMAGE_MODEL_OPTION])));
            }
            // Reset cached values so next read gets fresh data
            Settings::resetCache();
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'duetg-ai-connector') . '</p></div>';
        }

        // Get current values
        $text_base_url = Settings::getTextBaseUrl();
        $text_model = Settings::getTextModel();
        $image_base_url = Settings::getImageBaseUrl();
        $image_model = Settings::getImageModel();

        // Check if configured
        $text_configured = !empty($text_base_url) && !empty($text_model);
        $image_configured = !empty($image_base_url) && !empty($image_model);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <div class="notice notice-info">
                <p><strong><?php esc_html_e('API Key Configuration', 'duetg-ai-connector'); ?></strong></p>
                <p><?php esc_html_e('Please configure your API Key in the Settings > Connectors page. The API Key is required for the provider to be activated.', 'duetg-ai-connector'); ?></p>
            </div>

            <?php if (!$text_configured): ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('Text Generation is not configured. Please enter your Base URL and Model Name below.', 'duetg-ai-connector'); ?></p>
            </div>
            <?php endif; ?>

            <?php if (!$image_configured): ?>
            <div class="notice notice-warning">
                <p><?php esc_html_e('Image Generation is not configured. Please enter your Base URL and Model Name below.', 'duetg-ai-connector'); ?></p>
            </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('duetgaicon_save_action'); ?>
                <input type="hidden" name="duetgaicon_save" value="1">

                <h2><?php esc_html_e('Text Generation', 'duetg-ai-connector'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(Settings::TEXT_BASE_URL_OPTION); ?>"><?php esc_html_e('Base URL', 'duetg-ai-connector'); ?></label>
                        </th>
                        <td>
                            <input type="url"
                                name="<?php echo esc_attr(Settings::TEXT_BASE_URL_OPTION); ?>"
                                id="<?php echo esc_attr(Settings::TEXT_BASE_URL_OPTION); ?>"
                                value="<?php echo esc_attr($text_base_url); ?>"
                                class="regular-text"
                                placeholder="https://api.openai.com/v1">
                            <p class="description"><?php esc_html_e('The base URL for the text generation API. Use your OpenAI-compatible provider URL — the placeholder above shows the default.', 'duetg-ai-connector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(Settings::TEXT_MODEL_OPTION); ?>"><?php esc_html_e('Model Name', 'duetg-ai-connector'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                name="<?php echo esc_attr(Settings::TEXT_MODEL_OPTION); ?>"
                                id="<?php echo esc_attr(Settings::TEXT_MODEL_OPTION); ?>"
                                value="<?php echo esc_attr($text_model); ?>"
                                class="regular-text"
                                placeholder="gpt-4">
                            <p class="description"><?php esc_html_e('The model identifier for text generation (e.g., gpt-4, gpt-3.5-turbo).', 'duetg-ai-connector'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Image Generation', 'duetg-ai-connector'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(Settings::IMAGE_BASE_URL_OPTION); ?>"><?php esc_html_e('Base URL', 'duetg-ai-connector'); ?></label>
                        </th>
                        <td>
                            <input type="url"
                                name="<?php echo esc_attr(Settings::IMAGE_BASE_URL_OPTION); ?>"
                                id="<?php echo esc_attr(Settings::IMAGE_BASE_URL_OPTION); ?>"
                                value="<?php echo esc_attr($image_base_url); ?>"
                                class="regular-text"
                                placeholder="https://api.openai.com/v1">
                            <p class="description"><?php esc_html_e('The base URL for the image generation API. Use your OpenAI-compatible provider URL — the placeholder above shows the default.', 'duetg-ai-connector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="<?php echo esc_attr(Settings::IMAGE_MODEL_OPTION); ?>"><?php esc_html_e('Model Name', 'duetg-ai-connector'); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                name="<?php echo esc_attr(Settings::IMAGE_MODEL_OPTION); ?>"
                                id="<?php echo esc_attr(Settings::IMAGE_MODEL_OPTION); ?>"
                                value="<?php echo esc_attr($image_model); ?>"
                                class="regular-text"
                                placeholder="dall-e-3">
                            <p class="description"><?php esc_html_e('The model identifier for image generation (e.g., dall-e-3, dall-e-2).', 'duetg-ai-connector'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(esc_html__('Save Changes', 'duetg-ai-connector')); ?>
            </form>
        </div>
        <?php
    }
}
