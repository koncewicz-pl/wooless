<?php
/**
 * WooCommerce log handler that writes to stderr.
 *
 * Ensures all WooCommerce logs are captured by the Docker container
 * logging driver instead of being written to files on disk.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Log_Handler_Stderr extends WC_Log_Handler {

    /**
     * Handle a log entry by writing it to stderr.
     *
     * @param int    $timestamp Log timestamp.
     * @param string $level     emergency|alert|critical|error|warning|notice|info|debug.
     * @param string $message   Log message.
     * @param array  $context   Additional information for log handlers.
     *
     * @return bool True if value was handled.
     */
    public function handle($timestamp, $level, $message, $context): bool
    {
        $source = $context['source'] ?? 'woocommerce';
        $formatted = sprintf(
            '[%s] %s.%s: %s',
            gmdate('Y-m-d H:i:s', $timestamp),
            $source,
            strtoupper($level),
            $message
        );

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log($formatted);

        return true;
    }
}
