<?php
/**
 * Helper utilities for DuetG AI Connector
 *
 * @package DuetGAIConnector
 */

namespace WordPress\DuetGAIConnector;

/**
 * Debug logging helper - only logs when DUETGAICON_DEBUG is enabled
 */
class Helper
{
    /**
     * Debug logging helper - only logs when DUETGAICON_DEBUG is enabled
     *
     * @param string $message The message to log
     * @param mixed $data Optional data to include (lazy JSON encoding, only when debug is enabled)
     * @param int $maxDataLength Maximum length for data serialization (0 = no limit)
     */
    public static function debug($message, $data = null, $maxDataLength = 0)
    {
        // Only log when DUETGAICON_DEBUG is explicitly enabled
        if (!defined('DUETGAICON_DEBUG') || !DUETGAICON_DEBUG) {
            return;
        }

        // Sanitize URLs in data to prevent sensitive info leakage
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($value) && preg_match('/^https?:\/\//i', $value)) {
                    $data[$key] = preg_replace(
                        '/([?&])(api_key|key|token|secret|auth|password|passwd)=[^&]*/i',
                        '$1$2=[REDACTED]',
                        $value
                    );
                }
            }
        }

        $log_message = 'DuetG AI Connector: ' . $message;
        if ($data !== null) {
            // Lazy serialization - only encode when debug is enabled
            $encoded = json_encode($data);
            if ($maxDataLength > 0 && strlen($encoded) > $maxDataLength) {
                $encoded = substr($encoded, 0, $maxDataLength) . '...[truncated]';
            }
            $log_message .= ' - ' . $encoded;
        }
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log($log_message);
    }

    /**
     * Check if a URL points to a local/private host (localhost, 127.0.0.1, ::1, or private IP range)
     *
     * @param string $url The URL to check
     * @return bool True if the URL is local/private
     */
    public static function isLocalUrl($url)
    {
        $parsed = wp_parse_url($url);
        if (empty($parsed['host'])) {
            return false;
        }

        // Check for localhost variants
        // wp_parse_url() preserves brackets for IPv6 (e.g. '[::1]'), so we
        // must check both bare and bracketed forms.
        $localhosts = ['localhost', '127.0.0.1', '::1', '[::1]'];
        if (in_array($parsed['host'], $localhosts, true)) {
            return true;
        }

        // If it's an IP address, check if it's a private/reserved IP
        // filter_var returns false for non-IP strings (like domain names)
        // Trim brackets for IPv6 before passing to filter_var.
        $bare_ip = trim($parsed['host'], '[]');
        if (filter_var($bare_ip, FILTER_VALIDATE_IP) !== false) {
            return filter_var($bare_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        // Domain names are never local URLs
        return false;
    }
}
