<?php
/**
 * Script to delete omnisend_last_sync meta from orders on a given date
 * 
 * Usage: 
 *   1. Set $target_date variable below (YYYY-MM-DD format)
 *   2. Run: wp eval-file wp-content/plugins/freya-omnisend/delete-omnisend-sync-meta.php
 */

// ============================================
// CONFIGURATION: Set the date here (YYYY-MM-DD format)
// ============================================
$target_date = '2025-11-08'; // Example: '2024-01-15'
// ============================================

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    // This script should be run via wp eval-file, which loads WordPress
    die('This script must be run via wp eval-file');
}

// Check if WooCommerce is active
if (!function_exists('wc_get_orders')) {
    die('WooCommerce is not active. This script requires WooCommerce.');
}

// Use the date from the configuration variable
$date = $target_date;

// Validate date format
if (empty($date)) {
    die("Error: \$target_date must be set in the script. Please set it to a date in YYYY-MM-DD format (e.g., '2024-01-15')\n");
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    die("Invalid date format. Please use YYYY-MM-DD format (e.g., 2024-01-15)\n");
}

$meta_key = 'omnisend_last_sync';
$deleted_count = 0;
$processed_count = 0;

// Parse the date
$date_obj = DateTime::createFromFormat('Y-m-d', $date);
if (!$date_obj) {
    die("Invalid date: $date\n");
}

$start_date = $date_obj->format('Y-m-d 00:00:00');
$end_date = $date_obj->format('Y-m-d 23:59:59');

echo "Deleting '$meta_key' meta from orders created on $date...\n";
echo "Date range: $start_date to $end_date\n\n";

// Query orders created on the specified date
$orders = wc_get_orders(array(
    'limit' => -1,
    'date_paid' => $start_date . '...' . $end_date,
    'return' => 'ids',
));

if (empty($orders)) {
    echo "No orders found for date: $date\n";
    exit(0);
}

echo "Found " . count($orders) . " order(s) for date $date\n\n";

// Process each order
foreach ($orders as $order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        continue;
    }
    
    $processed_count++;
    
    // Check if meta exists
    $meta_value = $order->get_meta($meta_key, true);
    if ($meta_value !== '') {
        // Save a backup of the existing meta value before deletion
        $order->update_meta_data($meta_key . '_backup', $meta_value);
        // Delete the meta
        $order->delete_meta_data($meta_key);
        $order->save();
        $deleted_count++;
        
        echo "Order #{$order_id}: Deleted '$meta_key' meta (was: " . ($meta_value ?: 'empty') . ")\n";
    } else {
        echo "Order #{$order_id}: No '$meta_key' meta found (skipped)\n";
    }
}

echo "\n";
echo "========================================\n";
echo "Summary:\n";
echo "========================================\n";
echo "Orders processed: $processed_count\n";
echo "Meta entries deleted: $deleted_count\n";
echo "Orders skipped (no meta): " . ($processed_count - $deleted_count) . "\n";
echo "========================================\n";

