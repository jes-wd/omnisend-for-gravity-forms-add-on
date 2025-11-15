<?php
/**
 * Omnisend WooCommerce Subscription Manager
 *
 * Handles subscription status changes and manages Omnisend contact properties
 * based on subscription status and creation date.
 *
 * Features:
 * - Tracks subscription status in custom properties based on product type
 * - Property names: 'woocommerce_subscription_status_{product_type}'
 * - Product types: 'glp_1', 'nad', 'misc' (formatted from ACF field, falls back to 'glp_1')
 * - When a subscription becomes active and was created within the last 14 days:
 *   - Sets property to 'active'
 * - When a subscription is cancelled and user has no other active or on-hold subscriptions:
 *   - Sets property to 'cancelled'
 * - When a subscription is put on hold:
 *   - Sets property to 'on-hold'
 *
 * Business Logic:
 * - Only updates to 'active' if subscription was created within 14 days
 * - Only updates to 'cancelled' if user has no other active or on-hold subscriptions
 * - Always updates to 'on-hold' when subscription is paused
 * - Only runs on live site (freyameds.com) - skips on staging/development
 * - Uses ACF field 'freya_product_type' on products with values: 'glp-1', 'vitality', 'misc'
 * - Falls back to 'glp_1' if no product type is found
 *
 * @package OmnisendGravityFormsPlugin
 */

if (!defined('ABSPATH')) {
	exit;
}

class Omnisend_WC_Subscription_Manager
{
	/**
	 * Contact property name for subscription status (base name)
	 */
	const SUBSCRIPTION_STATUS_PROPERTY_BASE = 'woocommerce_subscription_status';

	/**
	 * Number of days to check for new subscriptions
	 */
	const NEW_SUBSCRIPTION_DAYS = 14;

	/**
	 * Initialize the subscription manager
	 */
	public function __construct()
	{
		$this->init_hooks();
	}

	/**
	 * Initialize WooCommerce subscription hooks
	 */
	private function init_hooks()
	{
		// Hook into subscription status changes
		add_action('woocommerce_subscription_status_updated', array($this, 'handle_subscription_status_change'), 10, 3);
	}

	/**
	 * Check if we're running on the live site
	 *
	 * @return bool True if on live site (freyameds.com), false otherwise
	 */
	private function is_live_site()
	{
		$site_url = get_site_url();
		$parsed_url = parse_url($site_url);
		$domain = isset($parsed_url['host']) ? $parsed_url['host'] : '';
		
		// Check if domain is exactly freyameds.com (no subdomain)
		$is_live = ($domain === 'freyameds.com');
				
		return $is_live;
	}

	/**
	 * Get the product type from the first product in the subscription
	 *
	 * @param WC_Subscription $subscription The subscription object
	 * @return string The formatted product type or empty string if not found
	 */
	private function get_subscription_product_type($subscription)
	{
		$items = $subscription->get_items();
		
		if (empty($items)) {
			error_log('[Omnisend Subscription Manager] No items found in subscription');
			return '';
		}

		// Get the first product
		$first_item = reset($items);
		
		// Check if the item has a get_product method
		if (!method_exists($first_item, 'get_product')) {
			error_log('[Omnisend Subscription Manager] Item does not have get_product method');
			return '';
		}
		
		$product = $first_item->get_product();
		
		if (!$product) {
			error_log('[Omnisend Subscription Manager] No product found for first item');
			return '';
		}

		// Get the ACF custom field value
		$product_type = get_field('freya_product_type', $product->get_id());
		
		if (empty($product_type)) {
			error_log('[Omnisend Subscription Manager] No freya_product_type field found for product ID: ' . $product->get_id() . ' - falling back to glp_1');
			$product_type = 'glp-1'; // Fallback to glp-1
		} else {
			error_log('[Omnisend Subscription Manager] Found product type: ' . $product_type . ' for product ID: ' . $product->get_id());
		}

		// Format the product type according to requirements
		$formatted_type = $this->format_product_type($product_type);
		
		error_log('[Omnisend Subscription Manager] Formatted product type: ' . $formatted_type);
		
		return $formatted_type;
	}

	/**
	 * Format product type according to requirements
	 *
	 * @param string $product_type The raw product type
	 * @return string The formatted product type
	 */
	private function format_product_type($product_type)
	{
		// Replace dashes with underscores
		$formatted = str_replace('-', '_', $product_type);
		
		// Replace "vitality" with "nad"
		$formatted = str_replace('vitality', 'nad', $formatted);
		
		return $formatted;
	}

	/**
	 * Handle subscription status changes
	 *
	 * @param WC_Subscription|int $subscription The subscription object or ID
	 * @param string $new_status The new status
	 * @param string $old_status The old status
	 */
	public function handle_subscription_status_change($subscription, $new_status, $old_status)
	{
		error_log('[Omnisend Subscription Manager] Status change triggered - New: ' . $new_status . ', Old: ' . $old_status);

		// Only run on live site (freyameds.com)
		if (!$this->is_live_site()) {
			error_log('[Omnisend Subscription Manager] Not running on live site - skipping subscription status update');
			return;
		}

		// Only process if Omnisend SDK is available
		if (!class_exists('Omnisend\SDK\V1\Contact') || !class_exists('Omnisend\SDK\V1\Omnisend')) {
			error_log('[Omnisend Subscription Manager] Omnisend SDK not available');
			return;
		}

		// Handle case where subscription is passed as ID instead of object
		if (is_numeric($subscription)) {
			error_log('[Omnisend Subscription Manager] Subscription passed as ID: ' . $subscription);
			$subscription = wcs_get_subscription($subscription);
		}

		// Validate subscription object
		if (!$subscription || !is_a($subscription, 'WC_Subscription')) {
			error_log('[Omnisend Subscription Manager] Invalid subscription object - Type: ' . gettype($subscription));
			return;
		}

		error_log('[Omnisend Subscription Manager] Processing subscription ID: ' . $subscription->get_id());

		// Get the customer email
		$customer_email = $subscription->get_billing_email();
		if (empty($customer_email)) {
			error_log('[Omnisend Subscription Manager] No billing email found for subscription ID: ' . $subscription->get_id());
			return;
		}

		error_log('[Omnisend Subscription Manager] Customer email: ' . $customer_email);

		// Handle subscription status changes
		if (in_array($new_status, array('active', 'on-hold', 'cancelled'))) {
			error_log('[Omnisend Subscription Manager] Processing subscription status change to: ' . $new_status);
			$this->update_subscription_status_property($subscription, $customer_email, $new_status);
		} else {
			error_log('[Omnisend Subscription Manager] Status not handled: ' . $new_status);
		}
	}

	/**
	 * Update subscription status property in Omnisend contact
	 *
	 * @param WC_Subscription $subscription The subscription object
	 * @param string $customer_email The customer email
	 * @param string $new_status The new subscription status
	 */
	private function update_subscription_status_property($subscription, $customer_email, $new_status)
	{
		error_log('[Omnisend Subscription Manager] Updating subscription status property for: ' . $customer_email . ' to: ' . $new_status);

		// Get the product type to append to property name
		$product_type = $this->get_subscription_product_type($subscription);
		$property_name = self::SUBSCRIPTION_STATUS_PROPERTY_BASE;
		
		if (!empty($product_type)) {
			$property_name .= '_' . $product_type;
		}
		
		error_log('[Omnisend Subscription Manager] Using property name: ' . $property_name);

		// Determine the status to set based on business logic
		$status_to_set = $this->determine_subscription_status($subscription, $customer_email, $new_status);
		
		error_log('[Omnisend Subscription Manager] Determined status to set: ' . $status_to_set);

		$current_host = str_replace('www.', '', sanitize_text_field($_SERVER['HTTP_HOST']));
		if ($current_host !== 'freyameds.com') {
			error_log('[Omnisend Subscription Manager] Not running on staging site - skipping property update');
			return;
		}

		// Update the contact property
		$this->update_contact_property($customer_email, $property_name, $status_to_set);
	}

	/**
	 * Determine what subscription status should be set based on business logic
	 *
	 * @param WC_Subscription $subscription The subscription object
	 * @param string $customer_email The customer email
	 * @param string $new_status The new subscription status
	 * @return string The status to set in the contact property
	 */
	private function determine_subscription_status($subscription, $customer_email, $new_status)
	{
		// For active status, check if subscription was created within 14 days
		if ($new_status === 'active') {
			$created_date = $subscription->get_date_created();
			$created_timestamp = $created_date->getTimestamp();
			$current_timestamp = current_time('timestamp');
			$days_since_creation = ($current_timestamp - $created_timestamp) / DAY_IN_SECONDS;

			error_log('[Omnisend Subscription Manager] Subscription created: ' . $created_date->format('Y-m-d H:i:s'));
			error_log('[Omnisend Subscription Manager] Days since creation: ' . round($days_since_creation, 2));

			if ($days_since_creation <= self::NEW_SUBSCRIPTION_DAYS) {
				error_log('[Omnisend Subscription Manager] Subscription is within ' . self::NEW_SUBSCRIPTION_DAYS . ' days - setting status to active');
				return 'active';
			} else {
				error_log('[Omnisend Subscription Manager] Subscription is older than ' . self::NEW_SUBSCRIPTION_DAYS . ' days - not updating status');
				return null; // Don't update if subscription is too old
			}
		}

		// For cancelled status, check if user has other active subscriptions
		if ($new_status === 'cancelled') {
			$has_active_subscriptions = $this->user_has_active_subscriptions($customer_email, $subscription->get_id());
			
			if (!$has_active_subscriptions) {
				error_log('[Omnisend Subscription Manager] No other active subscriptions found - setting status to cancelled');
				return 'cancelled';
			} else {
				error_log('[Omnisend Subscription Manager] Other active subscriptions found - not setting cancelled status');
				return null; // Don't set cancelled if user has other active subscriptions
			}
		}

		// For on-hold status, always update
		if ($new_status === 'on-hold') {
			error_log('[Omnisend Subscription Manager] Setting status to on-hold');
			return 'on-hold';
		}

		return null;
	}

	/**
	 * Check if user has any other active or on-hold subscriptions
	 *
	 * @param string $customer_email The customer email
	 * @param int $exclude_subscription_id The subscription ID to exclude from the check
	 * @return bool True if user has other active or on-hold subscriptions
	 */
	private function user_has_active_subscriptions($customer_email, $exclude_subscription_id)
	{
		// Get all active and on-hold subscriptions for this customer
		$subscriptions = wcs_get_subscriptions(array(
			'customer' => $customer_email,
			'status' => array('active', 'on-hold'),
			'limit' => -1,
		));

		error_log('[Omnisend Subscription Manager] Found ' . count($subscriptions) . ' active/on-hold subscriptions for: ' . $customer_email);

		// Filter out the current subscription
		foreach ($subscriptions as $key => $subscription) {
			if ($subscription->get_id() == $exclude_subscription_id) {
				unset($subscriptions[$key]);
				error_log('[Omnisend Subscription Manager] Excluded current subscription ID: ' . $exclude_subscription_id);
			} else {
				error_log('[Omnisend Subscription Manager] Found other subscription ID: ' . $subscription->get_id() . ' with status: ' . $subscription->get_status());
			}
		}

		$has_other_subscriptions = !empty($subscriptions);
		error_log('[Omnisend Subscription Manager] User has other active/on-hold subscriptions: ' . ($has_other_subscriptions ? 'Yes' : 'No'));

		return $has_other_subscriptions;
	}

	/**
	 * Update contact property in Omnisend
	 *
	 * @param string $customer_email The customer email
	 * @param string $property_name The property name
	 * @param string $property_value The property value
	 */
	private function update_contact_property($customer_email, $property_name, $property_value)
	{
		// Skip if no value to set
		if ($property_value === null ) {
			error_log('[Omnisend Subscription Manager] Skipping property update - no value to set');
			return;
		}

		error_log('[Omnisend Subscription Manager] update_contact_property called for: ' . $customer_email);
		error_log('[Omnisend Subscription Manager] Property: ' . $property_name . ' = ' . $property_value);

		try {
			// Get existing contact to preserve current data
			$client = \Omnisend\SDK\V1\Omnisend::get_client(
				OMNISEND_GRAVITY_ADDON_NAME,
				OMNISEND_GRAVITY_ADDON_VERSION
			);

			$existing_contact_response = $client->get_contact_by_email($customer_email);
			$existing_contact = null;

			if (!$existing_contact_response->get_wp_error()->has_errors()) {
				$existing_contact = $existing_contact_response->get_contact();
				// error_log('[Omnisend Subscription Manager] Retrieved existing contact with properties: ' . print_r($existing_contact->get_custom_properties(), true));
			} else {
				// error_log('[Omnisend Subscription Manager] Could not retrieve existing contact: ' . $existing_contact_response->get_wp_error()->get_error_message());
			}

			// Create new contact object
			$contact = new \Omnisend\SDK\V1\Contact();
			$contact->set_email($customer_email);

			// Copy existing properties if available
			if ($existing_contact) {
				$existing_properties = $existing_contact->get_custom_properties();
				foreach ($existing_properties as $key => $value) {
					$contact->add_custom_property($key, $value, false); // Don't clean up existing keys
				}
			}

			// Add or update the subscription status property
			$contact->add_custom_property($property_name, $property_value);
			error_log('[Omnisend Subscription Manager] Set property: ' . $property_name . ' = ' . $property_value);

			// Send updated contact to Omnisend
			$response = $client->create_contact($contact);

			if ($response->get_wp_error()->has_errors()) {
				error_log('[Omnisend Subscription Manager] Error updating contact: ' . $response->get_wp_error()->get_error_message());
			} else {
				error_log('[Omnisend Subscription Manager] Successfully updated contact for ' . $customer_email . ' with property ' . $property_name . ' = ' . $property_value);
			}

		} catch (Exception $e) {
			error_log('[Omnisend Subscription Manager] Exception in update_contact_property: ' . $e->getMessage());
			error_log('[Omnisend Subscription Manager] Exception trace: ' . $e->getTraceAsString());
		}
	}
}
