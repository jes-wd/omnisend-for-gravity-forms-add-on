<?php
/**
 * Test Subscription Processing Setup
 * 
 * This script tests the subscription processing setup before running
 * the full batch processing script.
 * 
 * Usage: wp eval-file test-subscription-processing.php
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    die('Direct access not allowed');
}

echo "🧪 Testing subscription processing setup...\n\n";

// Test 1: Check if we're on the live site
$site_url = get_site_url();
$parsed_url = parse_url($site_url);
$domain = isset($parsed_url['host']) ? $parsed_url['host'] : '';

if ($domain !== 'freyameds.com') {
    echo "❌ FAIL: Not on live site. Current domain: {$domain}\n";
    exit(1);
} else {
    echo "✅ PASS: Running on live site ({$domain})\n";
}

// Test 2: Check WooCommerce Subscriptions
if (!class_exists('WC_Subscriptions')) {
    echo "❌ FAIL: WooCommerce Subscriptions plugin not active\n";
    exit(1);
} else {
    echo "✅ PASS: WooCommerce Subscriptions plugin active\n";
}

// Test 3: Check Omnisend SDK
if (!class_exists('Omnisend\SDK\V1\Contact') || !class_exists('Omnisend\SDK\V1\Omnisend')) {
    echo "❌ FAIL: Omnisend SDK not available\n";
    exit(1);
} else {
    echo "✅ PASS: Omnisend SDK available\n";
}

// Test 4: Check ACF
if (!function_exists('get_field')) {
    echo "❌ FAIL: ACF plugin not active\n";
    exit(1);
} else {
    echo "✅ PASS: ACF plugin active\n";
}

// Test 5: Get subscription count
$total_subscriptions = wcs_get_subscriptions(array(
    'status' => 'any',
    'limit' => -1,
    'return' => 'ids'
));

$total_count = count($total_subscriptions);
echo "✅ PASS: Found {$total_count} subscriptions\n";

// Test 6: Test a few sample subscriptions
echo "\n🔍 Testing sample subscriptions...\n";

$sample_subscriptions = wcs_get_subscriptions(array(
    'status' => 'any',
    'limit' => 5,
    'orderby' => 'date',
    'order' => 'ASC'
));

foreach ($sample_subscriptions as $subscription) {
    $subscription_id = $subscription->get_id();
    $customer_email = $subscription->get_billing_email();
    $status = $subscription->get_status();
    
    echo "  📋 Subscription #{$subscription_id}:\n";
    echo "    • Email: " . ($customer_email ?: 'N/A') . "\n";
    echo "    • Status: {$status}\n";
    
    // Test product type detection
    $items = $subscription->get_items();
    if (!empty($items)) {
        $first_item = reset($items);
        if (method_exists($first_item, 'get_product')) {
            $product = $first_item->get_product();
            if ($product) {
                $product_type = get_field('freya_product_type', $product->get_id());
                $formatted_type = $product_type ? str_replace('-', '_', str_replace('vitality', 'nad', $product_type)) : 'glp_1';
                echo "    • Product Type: " . ($product_type ?: 'N/A (fallback to glp_1)') . " → {$formatted_type}\n";
                echo "    • Property Name: woocommerce_subscription_status_{$formatted_type}\n";
            }
        }
    }
    echo "\n";
}

// Test 7: Test Omnisend connection
echo "🔗 Testing Omnisend connection...\n";
try {
    $client = \Omnisend\SDK\V1\Omnisend::get_client(
        'Omnisend for Gravity Forms Add-On',
        '9.9.9'
    );
    
    if (\Omnisend\SDK\V1\Omnisend::is_connected()) {
        echo "✅ PASS: Omnisend connection successful\n";
    } else {
        echo "❌ FAIL: Omnisend not connected\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ FAIL: Omnisend connection error: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 8: Memory and performance check
echo "\n💾 System check...\n";
echo "  • Memory limit: " . ini_get('memory_limit') . "\n";
echo "  • Max execution time: " . ini_get('max_execution_time') . " seconds\n";
echo "  • Current memory usage: " . round(memory_get_usage() / 1024 / 1024, 2) . " MB\n";

// Estimate processing time
$estimated_time_per_subscription = 0.5; // seconds
$estimated_total_time = ($total_count * $estimated_time_per_subscription) / 3600; // hours

echo "\n📊 Processing estimates:\n";
echo "  • Total subscriptions: {$total_count}\n";
echo "  • Estimated time per subscription: {$estimated_time_per_subscription}s\n";
echo "  • Estimated total time: " . round($estimated_total_time, 1) . " hours\n";
echo "  • Estimated batches: " . ceil($total_count / 100) . " (100 per batch)\n";

echo "\n🎉 All tests passed! Ready to run the full processing script.\n";
echo "💡 Run: wp eval-file process-subscription-properties.php\n";
