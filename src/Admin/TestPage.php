<?php
/**
 * Admin test page for DuetG AI Connector
 *
 * @package DuetGAIConnector\Admin
 */

namespace WordPress\DuetGAIConnector\Admin;

use WordPress\DuetGAIConnector\Settings\Settings;
use WordPress\DuetGAIConnector\Provider\CustomTextProvider;
use WordPress\DuetGAIConnector\Provider\CustomImageProvider;
use WordPress\DuetGAIConnector\Helper;

if (!defined('ABSPATH')) {
    exit;
}

class TestPage
{
    /**
     * Get API key status
     *
     * @param string $type 'text' or 'image'
     * @return string|null
     */
    private static function get_api_key_status(string $type): ?string
    {
        $key = $type === 'text'
            ? Settings::get_text_api_key()
            : Settings::get_image_api_key();
        return !empty($key) ? $key : null;
    }

    /**
     * Render the test page
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $plugin_url = plugin_dir_url(dirname(__DIR__, 2) . '/duetg-ai-connector.php');
        $js_url = $plugin_url . 'assets/js/test-page.js';
        // test-page.js is vanilla JS — no jQuery dependency.
        wp_enqueue_script(
            'duetgaicon-test-page',
            $js_url,
            array(),
            filemtime(dirname(__DIR__, 2) . '/assets/js/test-page.js'),
            true
        );
        wp_localize_script(
            'duetgaicon-test-page',
            'customAiTestPage',
            array(
                'textPlaceholder'        => __('Enter your text prompt...', 'duetg-ai-connector'),
                'imagePlaceholder'       => __('Describe the image you want to generate...', 'duetg-ai-connector'),
                'imageRefinePlaceholder' => __('Describe how you want to refine the reference image...', 'duetg-ai-connector'),
            )
        );

        $provider_type = 'text';
        $prompt = '';
        $result = null;
        $error = null;
        $reference_data_uri = null;
        $reference_filename = '';

        if (isset($_POST['test_submit']) && check_admin_referer('duetgaicon_test_action')) {
            $provider_type = isset($_POST['provider_type']) ? sanitize_text_field(wp_unslash($_POST['provider_type'])) : 'text';
            $prompt = isset($_POST['prompt']) ? sanitize_text_field(wp_unslash($_POST['prompt'])) : '';

            // For image refinement we need both a prompt and a reference image.
            $needs_reference = ($provider_type === 'image_refine');
            $reference_data_uri = null;
            $reference_filename = '';

            if ($needs_reference) {
                // Validate upload: check error code, size, and verify MIME type
                // server-side instead of trusting the client-provided type.
                $upload_ok = !empty($_FILES['reference_image']['tmp_name'])
                    && isset($_FILES['reference_image']['error'])
                    && $_FILES['reference_image']['error'] === UPLOAD_ERR_OK
                    && is_uploaded_file($_FILES['reference_image']['tmp_name']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- is_uploaded_file() validates the tmp_name

                if ($upload_ok) {
                    // Enforce 10 MB size limit to prevent memory exhaustion
                    // from file_get_contents() + base64_encode().
                    $max_size = 10 * 1024 * 1024; // 10 MB
                    $file_size = filesize($_FILES['reference_image']['tmp_name']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- is_uploaded_file() validated above
                    if ($file_size !== false && $file_size > $max_size) {
                        $error = __('Reference image must be smaller than 10 MB.', 'duetg-ai-connector');
                    } else {
                        $bytes = file_get_contents($_FILES['reference_image']['tmp_name']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- is_uploaded_file() validated the tmp_name above

                        // Determine MIME type server-side via wp_check_filetype(),
                        // falling back to client-provided type only if necessary.
                        $reference_filename = !empty($_FILES['reference_image']['name']) ? sanitize_file_name($_FILES['reference_image']['name']) : 'reference.png';
                        $filetype = wp_check_filetype($reference_filename);
                        $mime = !empty($filetype['type']) ? $filetype['type'] : 'image/png';

                        if ($bytes !== false && $bytes !== '') {
                            $reference_data_uri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
                        }
                    }
                }
            }

            if (empty($prompt)) {
                $error = __('Please enter a prompt.', 'duetg-ai-connector');
            } elseif ($needs_reference && $reference_data_uri === null) {
                $error = __('Please upload a reference image for image refinement.', 'duetg-ai-connector');
            } else {
                try {
                    if (!class_exists('WordPress\AiClient\AiClient')) {
                        $error = __('WordPress AI Client is not available.', 'duetg-ai-connector');
                    } else {
                        $registry = \WordPress\AiClient\AiClient::defaultRegistry();

                        if ($provider_type === 'text') {
                            if (!$registry->hasProvider('duetgaicon_text')) {
                                $error = __('Text provider not registered.', 'duetg-ai-connector');
                            } elseif (!$registry->isProviderConfigured('duetgaicon_text')) {
                                $error = __('Text provider not configured. Please add API key via Settings > Connectors.', 'duetg-ai-connector');
                            } elseif (Helper::isLocalUrl(Settings::getTextBaseUrl()) && (!defined('DUETGAICON_ALLOW_LOCAL_URLS') || !DUETGAICON_ALLOW_LOCAL_URLS)) {
                                $error = sprintf(
                                    // translators: %s is a PHP constant definition, e.g. define('DUETGAICON_ALLOW_LOCAL_URLS', true);
                                    __('Your Base URL points to a local/private IP address. To use a local AI provider, add %s to your wp-config.php.', 'duetg-ai-connector'),
                                    '<code>define(\'DUETGAICON_ALLOW_LOCAL_URLS\', true);</code>'
                                );
                            } else {
                                $model = $registry->getProviderModel('duetgaicon_text', CustomTextProvider::getModelId());
                                $result = $model->generateTextResult([
                                    new \WordPress\AiClient\Messages\DTO\UserMessage([
                                        new \WordPress\AiClient\Messages\DTO\MessagePart($prompt)
                                    ])
                                ]);
                            }
                        } else {
                            // 'image' or 'image_refine' — both route through the image provider.
                            if (!$registry->hasProvider('duetgaicon_image')) {
                                $error = __('Image provider not registered.', 'duetg-ai-connector');
                            } elseif (!$registry->isProviderConfigured('duetgaicon_image')) {
                                $error = __('Image provider not configured. Please add API key via Settings > Connectors.', 'duetg-ai-connector');
                            } elseif (Helper::isLocalUrl(Settings::getImageBaseUrl()) && (!defined('DUETGAICON_ALLOW_LOCAL_URLS') || !DUETGAICON_ALLOW_LOCAL_URLS)) {
                                $error = sprintf(
                                    // translators: %s is a PHP constant definition, e.g. define('DUETGAICON_ALLOW_LOCAL_URLS', true);
                                    __('Your Base URL points to a local/private IP address. To use a local AI provider, add %s to your wp-config.php.', 'duetg-ai-connector'),
                                    '<code>define(\'DUETGAICON_ALLOW_LOCAL_URLS\', true);</code>'
                                );
                            } else {
                                $model = $registry->getProviderModel('duetgaicon_image', CustomImageProvider::getModelId());

                                $parts = [new \WordPress\AiClient\Messages\DTO\MessagePart($prompt)];
                                if ($reference_data_uri !== null) {
                                    $parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart(
                                        new \WordPress\AiClient\Files\DTO\File($reference_data_uri)
                                    );
                                }

                                $result = $model->generateImageResult([
                                    new \WordPress\AiClient\Messages\DTO\UserMessage($parts)
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Helper::debug('Test page error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                    $error = __('An error occurred while processing the request. Check debug logs for details.', 'duetg-ai-connector');
                    if (defined('DUETGAICON_DEBUG') && DUETGAICON_DEBUG) {
                        $error .= ' ' . $e->getMessage();
                    }
                }
            }
        }

        $text_base_url = Settings::getTextBaseUrl();
        $text_model = CustomTextProvider::getModelId();
        $image_base_url = Settings::getImageBaseUrl();
        $image_model = CustomImageProvider::getModelId();

        $test_url_raw = isset($_POST['test_url']) ? sanitize_text_field(wp_unslash($_POST['test_url'])) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Test AI', 'duetg-ai-connector'); ?></h1>

            <?php if ($error): ?>
                <div class="notice notice-error" style="margin-top: 20px;">
                    <p><strong><?php esc_html_e('Error', 'duetg-ai-connector'); ?>:</strong> <?php echo wp_kses_post($error); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($result): ?>
                <div class="notice notice-success" style="margin-top: 20px;">
                    <p><strong><?php esc_html_e('Success!', 'duetg-ai-connector'); ?></strong></p>
                    <?php if ($provider_type === 'text'): ?>
                        <?php $text = $result->toText(); ?>
                        <pre style="background: #f0f0f0; padding: 10px; overflow-x: auto; max-height: 300px;"><?php echo esc_html($text); ?></pre>
                    <?php else: ?>
                        <?php
                        $files = $result->toImageFiles();
                        if (!empty($files)):
                            foreach ($files as $file):
                                if ($file->isInline()) {
                                    $dataUri = $file->getDataUri();
                                    if ($dataUri) {
                                        // Data URIs are safe here - they contain base64-encoded image data
                                        // generated by the AI library, not user input. PHPCS warning is a false positive.
                                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        echo '<div style="margin-top: 10px;"><img src="' . $dataUri . '" style="max-width: 500px; height: auto; border: 1px solid #ccc;" /></div>';
                                    }
                                } elseif ($file->isRemote()) {
                                    echo '<div style="margin-top: 10px;"><img src="' . esc_url($file->getUrl()) . '" style="max-width: 500px; height: auto; border: 1px solid #ccc;" /></div>';
                                }
                            endforeach;
                        else:
                            ?><p><?php esc_html_e('No image files returned.', 'duetg-ai-connector'); ?></p><?php
                        endif;
                        ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Provider Status', 'duetg-ai-connector'); ?></h2>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Type', 'duetg-ai-connector'); ?></th>
                            <th><?php esc_html_e('Base URL', 'duetg-ai-connector'); ?></th>
                            <th><?php esc_html_e('Model', 'duetg-ai-connector'); ?></th>
                            <th><?php esc_html_e('API Key Status', 'duetg-ai-connector'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e('Text Generation', 'duetg-ai-connector'); ?></strong></td>
                            <td><?php echo esc_html($text_base_url); ?></td>
                            <td><?php echo esc_html($text_model); ?></td>
                            <td>
                                <?php
                                $text_api_key = self::get_api_key_status('text');
                                if ($text_api_key) {
                                    echo '<span style="color: green;">&#10004; ' . esc_html__('Configured', 'duetg-ai-connector') . '</span>';
                                } else {
                                    echo '<span style="color: red;">&#10008; ' . esc_html__('Not Configured', 'duetg-ai-connector') . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e('Image Generation', 'duetg-ai-connector'); ?></strong></td>
                            <td><?php echo esc_html($image_base_url); ?></td>
                            <td><?php echo esc_html($image_model); ?></td>
                            <td>
                                <?php
                                $image_api_key = self::get_api_key_status('image');
                                if ($image_api_key) {
                                    echo '<span style="color: green;">&#10004; ' . esc_html__('Configured', 'duetg-ai-connector') . '</span>';
                                } else {
                                    echo '<span style="color: red;">&#10008; ' . esc_html__('Not Configured', 'duetg-ai-connector') . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Test AI', 'duetg-ai-connector'); ?></h2>
                <form method="post" enctype="multipart/form-data" style="margin-top: 15px;">
                    <?php wp_nonce_field('duetgaicon_test_action'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="provider_type"><?php esc_html_e('Provider Type', 'duetg-ai-connector'); ?></label>
                            </th>
                            <td>
                                <select name="provider_type" id="provider_type">
                                    <option value="text" <?php selected($provider_type, 'text'); ?>><?php esc_html_e('Text Generation', 'duetg-ai-connector'); ?></option>
                                    <option value="image" <?php selected($provider_type, 'image'); ?>><?php esc_html_e('Image Generation', 'duetg-ai-connector'); ?></option>
                                    <option value="image_refine" <?php selected($provider_type, 'image_refine'); ?>><?php esc_html_e('Image Refinement', 'duetg-ai-connector'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="prompt"><?php esc_html_e('Prompt', 'duetg-ai-connector'); ?></label>
                            </th>
                            <td>
                                <textarea name="prompt" id="prompt" rows="4" class="large-text" placeholder="<?php echo $provider_type === 'text' ? esc_attr__('Enter your text prompt...', 'duetg-ai-connector') : ($provider_type === 'image_refine' ? esc_attr__('Describe how you want to refine the reference image...', 'duetg-ai-connector') : esc_attr__('Describe the image you want to generate...', 'duetg-ai-connector')); ?>"><?php echo $prompt !== '' ? esc_textarea($prompt) : ''; ?></textarea>
                            </td>
                        </tr>
                        <tr id="reference_image_row" style="display: <?php echo $provider_type === 'image_refine' ? 'table-row' : 'none'; ?>;">
                            <th scope="row">
                                <label for="reference_image"><?php esc_html_e('Reference Image', 'duetg-ai-connector'); ?></label>
                            </th>
                            <td>
                                <input type="file" name="reference_image" id="reference_image" accept="image/png,image/jpeg,image/webp,image/gif" />
                                <p class="description"><?php esc_html_e('Required for image refinement. The provider will modify this image based on the prompt.', 'duetg-ai-connector'); ?></p>
                                <?php if ($reference_filename !== ''): ?>
                                    <p class="description"><?php echo esc_html(sprintf(
                                        // translators: %s is the original filename of the uploaded reference image.
                                        __('Last uploaded: %s', 'duetg-ai-connector'),
                                        $reference_filename
                                    )); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(esc_html__('Generate', 'duetg-ai-connector'), 'primary', 'test_submit'); ?>
                </form>

            </div>

            <?php if (defined('DUETGAICON_ALLOW_LOCAL_URLS') && DUETGAICON_ALLOW_LOCAL_URLS): ?>
            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('Network Connectivity Test', 'duetg-ai-connector'); ?></h2>
                <p><?php esc_html_e('Test if your WordPress environment can reach a specific URL. Useful for debugging local AI connections.', 'duetg-ai-connector'); ?></p>
                <form method="post" style="margin-top: 15px;">
                    <?php wp_nonce_field('duetgaicon_network_test_action'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="test_url"><?php esc_html_e('URL to Test', 'duetg-ai-connector'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="test_url" id="test_url" class="large-text" value="<?php echo $test_url_raw ? esc_url($test_url_raw) : 'http://127.0.0.1:11434/v1/models'; ?>" placeholder="http://127.0.0.1:11434/v1/models" />
                                <p class="description"><?php esc_html_e('Enter the full URL including path to test connectivity.', 'duetg-ai-connector'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(esc_html__('Test Connection', 'duetg-ai-connector'), 'primary', 'network_test_submit', false); ?>
                </form>

                <?php
                if (isset($_POST['network_test_submit']) && check_admin_referer('duetgaicon_network_test_action')) {
                    $test_url = isset($_POST['test_url']) ? esc_url_raw(wp_unslash($_POST['test_url'])) : '';

                    if (empty($test_url)) {
                        echo '<div class="notice notice-error" style="margin-top: 15px;"><p>' . esc_html__('Please enter a URL to test.', 'duetg-ai-connector') . '</p></div>';
                    } else {
                        $is_local_url = Helper::isLocalUrl($test_url);

                        $request_args = array(
                            'timeout' => 10,
                            'headers' => array(
                                'Accept' => 'application/json',
                            ),
                        );

                        if ($is_local_url) {
                            $request_args['sslverify'] = false;
                        }

                        $response = wp_remote_get($test_url, $request_args);

                        if (is_wp_error($response)) {
                            echo '<div class="notice notice-error" style="margin-top: 15px;">';
                            echo '<p><strong>' . esc_html__('Connection Failed', 'duetg-ai-connector') . ':</strong> ' . esc_html($response->get_error_message()) . '</p>';
                            echo '<p><strong>' . esc_html__('Possible Cause', 'duetg-ai-connector') . ':</strong> ';
                            if (strpos($response->get_error_message(), 'A valid URL') !== false || strpos($response->get_error_message(), 'blocked') !== false) {
                                esc_html_e('WordPress SSRF protection is blocking this URL. Localhost/private IPs are blocked by default.', 'duetg-ai-connector');
                            } else {
                                esc_html_e('The server may be unreachable or the URL may be invalid.', 'duetg-ai-connector');
                            }
                            echo '</p></div>';
                        } else {
                            $code = wp_remote_retrieve_response_code($response);
                            $body = wp_remote_retrieve_body($response);

                            echo '<div class="notice notice-success" style="margin-top: 15px;">';
                            echo '<p><strong>' . esc_html__('Connection Successful!', 'duetg-ai-connector') . '</strong></p>';
                            echo '<p>' . esc_html__('HTTP Status Code', 'duetg-ai-connector') . ': ' . esc_html($code) . '</p>';

                            if ($code == 200) {
                                $data = json_decode($body, true);
                                if (isset($data['models']) && is_array($data['models'])) {
                                    echo '<p><strong>' . esc_html__('Available Models:', 'duetg-ai-connector') . '</strong></p>';
                                    echo '<ul style="margin: 10px 0;">';
                                    foreach ($data['models'] as $model) {
                                        echo '<li>' . esc_html($model['name'] ?? json_encode($model)) . '</li>';
                                    }
                                    echo '</ul>';
                                } else {
                                    echo '<p>' . esc_html__('Response Body Preview:', 'duetg-ai-connector') . '</p>';
                                    echo '<pre style="background: #f0f0f0; padding: 10px; max-height: 200px; overflow-y: auto;">' . esc_html(substr($body, 0, 500)) . '</pre>';
                                }
                            }
                            echo '</div>';
                        }
                    }
                }
                ?>
            </div>
            <?php endif; ?>

            <div class="card" style="max-width: 100%; margin-top: 20px;">
                <h2><?php esc_html_e('How to Use', 'duetg-ai-connector'); ?></h2>
                <p><?php esc_html_e('To use this provider in your code:', 'duetg-ai-connector'); ?></p>
                <pre style="background: #f0f0f0; padding: 10px; overflow-x: auto;">// Text Generation
$registry = AiClient::defaultRegistry();
$model = $registry->getProviderModel('duetgaicon_text', '<?php echo esc_html($text_model); ?>');
$result = $model->generateTextResult([
    new \WordPress\AiClient\Messages\DTO\UserMessage([
        new \WordPress\AiClient\Messages\DTO\MessagePart('Your prompt here')
    ])
]);
echo $result->toText();

// Image Generation
$model = $registry->getProviderModel('duetgaicon_image', '<?php echo esc_html($image_model); ?>');
$result = $model->generateImageResult([
    new \WordPress\AiClient\Messages\DTO\UserMessage([
        new \WordPress\AiClient\Messages\DTO\MessagePart('Your prompt here')
    ])
]);
$files = $result->toImageFiles();
foreach ($files as $file) {
    // For inline images (base64)
    if ($file->isInline()) {
        $dataUri = $file->getDataUri();
        echo '&lt;img src="' . esc_url($dataUri) . '" /&gt;';
    }
    // For remote images (URL)
    if ($file->isRemote()) {
        echo '&lt;img src="' . esc_url($file->getUrl()) . '" /&gt;';
    }
}</pre>
            </div>
        </div>
        <?php
    }
}