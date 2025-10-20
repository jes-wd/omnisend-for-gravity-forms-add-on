<?php
/**
 * Process All Subscriptions for Omnisend Properties
 * 
 * This script processes all WooCommerce subscriptions and sets the appropriate
 * Omnisend contact properties based on subscription status and product type.
 * 
 * USAGE:
 *   wp eval-file process-subscription-properties.php
 * 
 * REQUIREMENTS:
 *   - Must run on live site (freyameds.com)
 *   - WooCommerce Subscriptions plugin active
 *   - Omnisend SDK available
 *   - ACF plugin for product type fields
 * 
 * FEATURES:
 *   - Processes subscriptions in batches (100 at a time)
 *   - Orders subscriptions from oldest to newest
 *   - Handles large datasets (12,000+ subscriptions)
 *   - Memory management and garbage collection
 *   - Progress tracking with time estimates
 *   - Comprehensive error handling and logging
 *   - Respects same business logic as real-time handler
 * 
 * EXPECTED RUNTIME:
 *   - ~2-3 hours for 12,000 subscriptions
 *   - Depends on Omnisend API response times
 *   - Can be safely interrupted and restarted
 */

// Set memory limit FIRST before any other operations
ini_set('memory_limit', '1024M'); // Increased to 1GB for better handling

// Prevent direct access
if (!defined('ABSPATH')) {
    die('Direct access not allowed');
}

// Configuration
const BATCH_SIZE = 100; // Process 100 subscriptions at a time
const MEMORY_LIMIT = '1024M'; // Set memory limit for processing
const NEW_SUBSCRIPTION_DAYS = 14;
const PROCESSING_LIMIT = 8000; // Set to 0 for no limit, or specify number to limit processing (e.g., 50 for testing)
const USERMETA_KEY = 'omnisend_subscription_processed'; // Key to track processed subscriptions
const DRY_RUN = false; // Set to true to log actions without making actual Omnisend API calls

// Check if we're on the live site (allow staging for dry run mode)
$site_url = get_site_url();
$parsed_url = parse_url($site_url);
$domain = isset($parsed_url['host']) ? $parsed_url['host'] : '';

if ($domain !== 'freyameds.com') {
    if (DRY_RUN) {
        echo "⚠️  Running on staging site ({$domain}) in DRY RUN mode - this is allowed for testing\n";
    } else {
        echo "❌ This script can only run on the live site (freyameds.com). Current domain: {$domain}\n";
        echo "💡 Tip: Set DRY_RUN = true to test on staging sites\n";
        exit(1);
    }
}

// Check if required plugins are active
if (!class_exists('WC_Subscriptions')) {
    echo "❌ WooCommerce Subscriptions plugin is not active\n";
    exit(1);
}

if (!class_exists('Omnisend\SDK\V1\Contact') || !class_exists('Omnisend\SDK\V1\Omnisend')) {
    echo "❌ Omnisend SDK is not available\n";
    exit(1);
}

echo "🚀 Starting subscription property processing...\n";
echo "📊 Server specs: 16GB RAM, 6 vCPU\n";
echo "⚙️  Batch size: " . BATCH_SIZE . " subscriptions\n";
echo "💾 Memory limit: " . MEMORY_LIMIT . "\n";
echo "🔢 Processing limit: " . (PROCESSING_LIMIT > 0 ? PROCESSING_LIMIT . " subscriptions" : "No limit") . "\n";
echo "🔄 Reprocessing prevention: " . (PROCESSING_LIMIT > 0 ? "Disabled (testing mode)" : "Enabled") . "\n";
echo "🧪 Dry run mode: " . (DRY_RUN ? "ENABLED (no actual API calls)" : "Disabled") . "\n\n";

// Note: We don't get total count upfront to avoid memory issues with large datasets
echo "📈 Processing subscriptions in batches without pre-counting (memory efficient)\n\n";

// Initialize counters
$processed = 0;
$updated = 0;
$skipped = 0;
$errors = 0;
$already_processed = 0;
$contact_not_found = 0;
$contact_found = 0;
$start_time = time();

// Process subscriptions in batches
$offset = 13550;
$batch_number = 1;
$has_more_subscriptions = true;

while ($has_more_subscriptions) {
    echo "🔄 Processing batch {$batch_number} (starting from offset {$offset})...\n";
    
    // Get batch of subscriptions (exclude pending subscriptions)
    $subscriptions = wcs_get_subscriptions(array(
        'subscription_status' => array('active', 'on-hold', 'cancelled'),
        'subscriptions_per_page' => BATCH_SIZE,
        'offset' => $offset,
        'orderby' => 'start_date',
        'order' => 'ASC' // Oldest first
    ));
    
    $batch_count = count($subscriptions);
    echo "📋 Found {$batch_count} subscriptions in batch {$batch_number}\n";
    
    if (empty($subscriptions)) {
        echo "✅ No more subscriptions found. Processing complete.\n";
        $has_more_subscriptions = false;
        break;
    }
    
    // Process each subscription in the batch
    foreach ($subscriptions as $subscription) {
        $processed++;
        
        echo "  🔍 Processing subscription #{$subscription->get_id()} (email: " . $subscription->get_billing_email() . ")\n";
        
        // Check processing limit
        if (PROCESSING_LIMIT > 0 && $processed > PROCESSING_LIMIT) {
            echo "🛑 Processing limit reached ({$processed} subscriptions). Stopping.\n";
            break 2; // Break out of both foreach and while loops
        }
        
        // Check if already processed (only if not in testing mode)
        if (PROCESSING_LIMIT === 0 && is_subscription_already_processed($subscription)) {
            $already_processed++;
            echo "  🔄 Already processed: Subscription #{$subscription->get_id()}\n";
            continue;
        }
        
        try {
            $result = process_single_subscription($subscription);
            
            if ($result['status'] === 'updated') {
                $updated++;
                $contact_found++; // Increment contact found counter for successful updates
                $dry_run_prefix = DRY_RUN ? "[DRY RUN] " : "";
                echo "  ✅ {$dry_run_prefix}Updated: Subscription #{$subscription->get_id()} - {$result['property']} = {$result['value']}\n";
                
                // Mark as processed (only if not in testing mode and not in dry run)
                if (PROCESSING_LIMIT === 0 && !DRY_RUN) {
                    mark_subscription_as_processed($subscription);
                }
            } elseif ($result['status'] === 'skipped') {
                $skipped++;
                // Track specific skip reasons
                if (strpos($result['reason'], 'Contact does not exist') !== false) {
                    $contact_not_found++;
                }
                echo "  ⏭️  Skipped: Subscription #{$subscription->get_id()} - {$result['reason']}\n";
            }
            
        } catch (Exception $e) {
            $errors++;
            echo "  ❌ Error: Subscription #{$subscription->get_id()} - " . $e->getMessage() . "\n";
        }
        
        // Clear memory every 50 subscriptions
        if ($processed % 50 === 0) {
            wp_cache_flush();
            gc_collect_cycles();
        }
    }
    
    // Progress update
    $elapsed = time() - $start_time;
    $avg_time_per_subscription = $processed > 0 ? $elapsed / $processed : 0;
    
    echo "📊 Progress: {$processed} processed | Updated: {$updated} | Skipped: {$skipped} | Already processed: {$already_processed} | Found in Omnisend: {$contact_found} | Contact not found: {$contact_not_found} | Errors: {$errors}\n";
    echo "⏱️  Elapsed: " . format_time($elapsed) . " | Avg time per subscription: " . round($avg_time_per_subscription, 2) . "s\n\n";
    
    $offset += BATCH_SIZE;
    $batch_number++;
    
    // Small delay to prevent server overload
    usleep(100000); // 0.1 second
}

// Final results
$total_time = time() - $start_time;
echo "🎉 Processing complete!\n";
if (DRY_RUN) {
    echo "🧪 DRY RUN MODE - No actual API calls were made\n";
}
echo "📊 Final Results:\n";
echo "  • Total processed: {$processed}\n";
echo "  • Updated: {$updated}" . (DRY_RUN ? " (simulated)" : "") . "\n";
echo "  • Skipped: {$skipped}\n";
echo "  • Already processed: {$already_processed}\n";
echo "  • Found in Omnisend: {$contact_found}\n";
echo "  • Contact not found in Omnisend: {$contact_not_found}\n";
echo "  • Errors: {$errors}\n";
echo "  • Total time: " . format_time($total_time) . "\n";
echo "  • Average time per subscription: " . round($total_time / $processed, 2) . " seconds\n";

/**
 * Check if subscription has already been processed
 */
function is_subscription_already_processed($subscription) {
    $subscription_id = $subscription->get_id();
    $customer_email = $subscription->get_billing_email();
    
    if (empty($customer_email)) {
        return false;
    }
    
    // Get user by email
    $user = get_user_by('email', $customer_email);
    if (!$user) {
        return false;
    }
    
    // Check if subscription ID is in the processed list
    $processed_subscriptions = get_user_meta($user->ID, USERMETA_KEY, true);
    if (empty($processed_subscriptions)) {
        return false;
    }
    
    $processed_array = is_array($processed_subscriptions) ? $processed_subscriptions : array($processed_subscriptions);
    return in_array($subscription_id, $processed_array);
}

/**
 * Mark subscription as processed
 */
function mark_subscription_as_processed($subscription) {
    $subscription_id = $subscription->get_id();
    $customer_email = $subscription->get_billing_email();
    
    if (empty($customer_email)) {
        return false;
    }
    
    // Get user by email
    $user = get_user_by('email', $customer_email);
    if (!$user) {
        return false;
    }
    
    // Get existing processed subscriptions
    $processed_subscriptions = get_user_meta($user->ID, USERMETA_KEY, true);
    $processed_array = is_array($processed_subscriptions) ? $processed_subscriptions : array();
    
    // Add current subscription if not already present
    if (!in_array($subscription_id, $processed_array)) {
        $processed_array[] = $subscription_id;
        update_user_meta($user->ID, USERMETA_KEY, $processed_array);
    }
    
    return true;
}

/**
 * Process a single subscription
 */
function process_single_subscription($subscription) {
    $subscription_id = $subscription->get_id();
    $customer_email = $subscription->get_billing_email();
    $subscription_status = $subscription->get_status();
    
    if (empty($customer_email)) {
        return array(
            'status' => 'skipped',
            'reason' => 'No billing email'
        );
    }
    
    // Get product type
    $product_type = get_subscription_product_type($subscription);
    $property_name = 'woocommerce_subscription_status' . (!empty($product_type) ? '_' . $product_type : '');
    
    // Determine status to set based on business logic
    $status_to_set = determine_subscription_status($subscription, $customer_email, $subscription_status);
    
    if ($status_to_set === null) {
        return array(
            'status' => 'skipped',
            'reason' => 'Status not applicable (old subscription or has other active subscriptions)'
        );
    }
    
    // Check if contact exists in Omnisend first
    if (!contact_exists_in_omnisend($customer_email)) {
        return array(
            'status' => 'skipped',
            'reason' => 'Contact does not exist in Omnisend'
        );
    }
    
    // Update the contact property
    $success = update_contact_property($customer_email, $property_name, $status_to_set);
    
    if ($success) {
        return array(
            'status' => 'updated',
            'property' => $property_name,
            'value' => $status_to_set
        );
    } else {
        throw new Exception('Failed to update Omnisend contact');
    }
}

/**
 * Get product type from subscription
 */
function get_subscription_product_type($subscription) {
    $items = $subscription->get_items();
    
    if (empty($items)) {
        return '';
    }
    
    $first_item = reset($items);
    
    if (!method_exists($first_item, 'get_product')) {
        return '';
    }
    
    $product = $first_item->get_product();
    
    if (!$product) {
        return '';
    }
    
    // Get ACF field value
    $product_type = get_field('freya_product_type', $product->get_id());
    
    if (empty($product_type)) {
        $product_type = 'glp-1'; // Fallback
    }
    
    // Format the product type
    return format_product_type($product_type);
}

/**
 * Format product type
 */
function format_product_type($product_type) {
    $formatted = str_replace('-', '_', $product_type);
    $formatted = str_replace('vitality', 'nad', $formatted);
    return $formatted;
}

/**
 * Determine subscription status based on business logic
 */
function determine_subscription_status($subscription, $customer_email, $new_status) {
    // Only handle specific statuses
    if (!in_array($new_status, array('active', 'on-hold', 'cancelled'))) {
        return null;
    }
    
    // For active status, always update with real status
    if ($new_status === 'active') {
        return 'active';
    }
    
    // For cancelled status, check if user has other active subscriptions
    if ($new_status === 'cancelled') {
        $has_active_subscriptions = user_has_active_subscriptions($customer_email, $subscription);
        
        if (!$has_active_subscriptions) {
            return 'cancelled';
        } else {
            return null; // Don't set cancelled if user has other active subscriptions
        }
    }
    
    // For on-hold status, always update
    if ($new_status === 'on-hold') {
        return 'on-hold';
    }
    
    return null;
}

/**
 * Check if user has other active subscriptions with the same product type
 */
function user_has_active_subscriptions($customer_email, $exclude_subscription) {
    // Get user ID from email
    $user = get_user_by('email', $customer_email);
    if (!$user) {
        return false;
    }
    
    // Get the product type of the subscription we're excluding
    $exclude_product_type = get_subscription_product_type($exclude_subscription);
    if (empty($exclude_product_type)) {
        return false;
    }
    
    $subscriptions = wcs_get_subscriptions(array(
        'customer_id' => $user->ID,
        'subscription_status' => array('active', 'on-hold'),
        'subscriptions_per_page' => -1,
    ));
    
    // Filter out subscriptions that don't match the product type or are the excluded subscription
    foreach ($subscriptions as $key => $subscription) {
        // Skip the current subscription
        if ($subscription->get_id() == $exclude_subscription->get_id()) {
            unset($subscriptions[$key]);
            continue;
        }
        
        // Check if this subscription has the same product type
        $subscription_product_type = get_subscription_product_type($subscription);
        if ($subscription_product_type !== $exclude_product_type) {
            unset($subscriptions[$key]);
        }
    }
    
    return !empty($subscriptions);
}

/**
 * Check if contact exists in Omnisend
 */
function contact_exists_in_omnisend($customer_email) {
    try {
        $client = \Omnisend\SDK\V1\Omnisend::get_client(
            'Omnisend for Gravity Forms Add-On',
            '9.9.9'
        );
        
        $response = $client->get_contact_by_email($customer_email);
        
        // Check for WP errors first
        if ($response->get_wp_error()->has_errors()) {
            if (DRY_RUN) {
                echo "    🧪 [DRY RUN] Contact does not exist (WP error): {$customer_email}\n";
            }
            return false;
        }
        
        // Try to get the contact, but handle potential null values
        $contact = $response->get_contact();
        
        // Check if contact is null or empty
        if (empty($contact) || $contact === null) {
            if (DRY_RUN) {
                echo "    🧪 [DRY RUN] Contact does not exist (null contact): {$customer_email}\n";
            }
            return false;
        }
        
        // Contact exists
        if (DRY_RUN) {
            echo "    🧪 [DRY RUN] Contact exists: {$customer_email}\n";
        }
        
        return true;
        
    } catch (Exception $e) {
        if (DRY_RUN) {
            echo "    🧪 [DRY RUN] Error checking contact existence: {$customer_email} - " . $e->getMessage() . "\n";
        }
        return false;
    } catch (TypeError $e) {
        // Handle the specific TypeError from the SDK
        if (DRY_RUN) {
            echo "    🧪 [DRY RUN] SDK TypeError (contact likely doesn't exist): {$customer_email} - " . $e->getMessage() . "\n";
        }
        return false;
    }
}

/**
 * Update contact property in Omnisend
 */
function update_contact_property($customer_email, $property_name, $property_value) {
    // In dry run mode, just log what would be done and return success
    if (DRY_RUN) {
        echo "    🧪 [DRY RUN] Would update Omnisend contact: {$customer_email}\n";
        echo "    🧪 [DRY RUN] Property: {$property_name} = {$property_value}\n";
        return true;
    }
    
    try {
        // Get existing contact to preserve current data
        $client = \Omnisend\SDK\V1\Omnisend::get_client(
            'Omnisend for Gravity Forms Add-On',
            '9.9.9'
        );
        
        $existing_contact_response = $client->get_contact_by_email($customer_email);
        $existing_contact = null;
        
        if (!$existing_contact_response->get_wp_error()->has_errors()) {
            $existing_contact = $existing_contact_response->get_contact();
        }
        
        // Create new contact object
        $contact = new \Omnisend\SDK\V1\Contact();
        $contact->set_email($customer_email);
        
        // Copy existing properties if available
        if ($existing_contact) {
            $existing_properties = $existing_contact->get_custom_properties();
            foreach ($existing_properties as $key => $value) {
                $contact->add_custom_property($key, $value, false);
            }
        }
        
        // Add or update the subscription status property
        $contact->add_custom_property($property_name, $property_value);
        
        // Send updated contact to Omnisend
        $response = $client->create_contact($contact);
        
        return !$response->get_wp_error()->has_errors();
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Format time in human readable format
 */
function format_time($seconds) {
    if ($seconds < 60) {
        return $seconds . 's';
    } elseif ($seconds < 3600) {
        return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}

echo "✅ Script loaded successfully. Ready to process subscriptions.\n";
