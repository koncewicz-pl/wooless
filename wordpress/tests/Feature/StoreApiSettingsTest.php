<?php

if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir());
}

if (! function_exists('add_action')) {
    function add_action(...$args): void {}
}

if (! function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['__wp_options'][$name] ?? $default;
    }
}

if (! function_exists('rest_ensure_response')) {
    function rest_ensure_response(mixed $data): mixed
    {
        return $data;
    }
}

require_once __DIR__.'/../../web/app/plugins/wooless/includes/class-wc-store-api-settings.php';

beforeEach(function () {
    $GLOBALS['__wp_options'] = [];
});

test('settings expose furgonetka test mode as true when plugin is connected to sandbox', function () {
    $GLOBALS['__wp_options']['furgonetka_test_mode'] = '1';

    $settings = (new WC_Store_API_Settings())->get_settings();

    expect($settings['furgonetka_test_mode'])->toBeTrue();
});

test('settings expose furgonetka test mode as false when plugin is connected to production', function () {
    $GLOBALS['__wp_options']['furgonetka_test_mode'] = '';

    $settings = (new WC_Store_API_Settings())->get_settings();

    expect($settings['furgonetka_test_mode'])->toBeFalse();
});

test('settings default furgonetka test mode to false when option does not exist', function () {
    $settings = (new WC_Store_API_Settings())->get_settings();

    expect($settings['furgonetka_test_mode'])->toBeFalse()
        ->and($settings['furgonetka_deliveryToType'])->toBe([]);
});
