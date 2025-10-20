<?php
/**
 * Omnisend Gravity forms add-on
 *
 * @package OmnisendGravityFormsPlugin
 */

use Omnisend\SDK\V1\Contact;

if (!defined('ABSPATH')) {
	exit;
}

GFForms::include_addon_framework();

class OmnisendAddOn extends GFAddOn
{

	protected $_version = OMNISEND_GRAVITY_ADDON_VERSION; // phpcs:ignore
	protected $_min_gravityforms_version = '1.9'; // phpcs:ignore
	protected $_slug = 'omnisend-for-gravity-forms-add-on'; // phpcs:ignore
	protected $_path = 'omnisend-for-gravity-forms/class-omnisend-addon-bootstrap.php'; // phpcs:ignore
	protected $_full_path = __FILE__; // phpcs:ignore
	protected $_title = 'Omnisend for Gravity Forms'; // phpcs:ignore
	protected $_short_title = 'Omnisend'; // phpcs:ignore

	private static $_instance = null; // phpcs:ignore


	public function minimum_requirements()
	{
		return array(
			'plugins' => array(
				'omnisend/class-omnisend-core-bootstrap.php' => 'Email Marketing by Omnisend',
			),
			array($this, 'omnisend_custom_requirement_callback'),
		);
	}

	public function omnisend_custom_requirement_callback($meets_requirements)
	{

		if (!is_plugin_active('omnisend/class-omnisend-core-bootstrap.php')) {
			return $meets_requirements;
		}

		if (!class_exists('Omnisend\SDK\V1\Omnisend')) {
			$meets_requirements['meets_requirements'] = false;
			$meets_requirements['errors'][]           = 'Your Email Marketing by Omnisend is not up to date. Please update plugins';
			return $meets_requirements;
		}

		if (!Omnisend\SDK\V1\Omnisend::is_connected()) {
			$meets_requirements['meets_requirements'] = false;
			$meets_requirements['errors'][]           = 'Your Email Marketing by Omnisend is not configured properly. Please configure it firstly';
		}
		return $meets_requirements;
	}

	/**
	 * Get an instance of this class.
	 *
	 * @return OmnisendAddOn
	 */
	public static function get_instance()
	{
		if (self::$_instance == null) {
			self::$_instance = new OmnisendAddOn();
		}

		return self::$_instance;
	}

	/**
	 * Handles hooks and loading of language files.
	 */
	public function init()
	{
		parent::init();
		add_action('gform_after_submission', array($this, 'after_submission'), 10, 2);
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

		// // Add partial entries hooks
		add_action('gform_partialentries_post_entry_saved', array($this, 'handle_partial_entry_saved'), 10, 2);
		add_action('gform_partialentries_post_entry_updated', array($this, 'handle_partial_entry_updated'), 10, 2);

		// Add WC_Queue hook for processing delayed Omnisend contact creation
		add_action('omnisend_process_delayed_contact_creation', array($this, 'process_delayed_omnisend_contact'), 10, 1);
	}

	/**
	 * Initialize AJAX hooks.
	 */
	public function init_ajax()
	{
		parent::init_ajax();
		add_action('wp_ajax_save_omnisend_conditions', array($this, 'ajax_save_conditions'));
		add_action('wp_ajax_nopriv_save_omnisend_conditions', array($this, 'ajax_save_conditions'));
	}

	/**
	 * AJAX handler for saving conditions.
	 */
	public function ajax_save_conditions()
	{
		// Check nonce for security
		if (!wp_verify_nonce($_POST['nonce'], 'omnisend_conditions_nonce')) {
			wp_send_json_error('Security check failed');
		}

		// Check permissions - check for Gravity Forms capabilities
		if (!current_user_can('gravityforms_edit_forms') && !current_user_can('gform_full_access') && !current_user_can('administrator')) {
			wp_send_json_error('Insufficient permissions');
		}

		$form_id = intval($_POST['form_id']);
		if (!$form_id) {
			wp_send_json_error('Invalid form ID');
		}

		$form = GFFormsModel::get_form_meta($form_id);
		if (!$form) {
			wp_send_json_error('Form not found');
		}

		// Get current settings
		$settings = $this->get_form_settings($form);

		// Handle omnisend_conditions field
		if (isset($_POST['conditions']) && is_array($_POST['conditions'])) {
			$conditions = array();
			foreach ($_POST['conditions'] as $condition) {
				if (!empty($condition['field_id']) && $condition['field_id'] !== '-1' && !empty($condition['operator'])) {
					$conditions[] = array(
						'field_id' => sanitize_text_field($condition['field_id']),
						'operator' => sanitize_text_field($condition['operator']),
						'value' => sanitize_text_field($condition['value']),
					);
				}
			}
			$settings['omnisend_conditions'] = $conditions;
		} else {
			$settings['omnisend_conditions'] = array();
		}

		// Debug logging
		// error_log( 'Omnisend AJAX Save - Form ID: ' . $form_id );
		// error_log( 'Omnisend AJAX Save - Settings: ' . print_r( $settings, true ) );

		// Save the updated settings
		$result = $this->save_form_settings($form, $settings);

		// error_log( 'Omnisend AJAX Save - Result: ' . ( $result ? 'true' : 'false' ) );

		if ($result === false) {
			wp_send_json_error('Failed to save settings');
		}

		wp_send_json_success('Conditions saved successfully');
	}

	/**
	 * Get the HTML for the conditions field.
	 */
	private function get_conditions_html()
	{
		$form     = $this->get_current_form();
		$settings = $this->get_form_settings($form);
		$value    = isset($settings['omnisend_conditions']) ? $settings['omnisend_conditions'] : array();

		if (!is_array($value)) {
			$value = array();
		}

		$fields_data = $this->get_form_fields(); // Use regular call since we fixed the value issue
		$all_fields  = $fields_data['allFields'];
		$choices     = array(
			array(
				'value' => '-1',
				'label' => 'Choose Field',
			),
		);
		$choices     = array_merge($choices, $all_fields);

		$html = '<div class="gform-settings-field">';
		$html .= '<div class="gform-settings-field-repeater-container" data-form-id="' . esc_attr($form['id']) . '">';

		if (!empty($value)) {
			foreach ($value as $index => $condition) {
				$html .= '<div class="gform-settings-field-repeater-item">';
				$html .= '<div class="gform-settings-field-repeater-item-header">';
				$html .= '<span class="gform-settings-field-repeater-item-index">' . ($index + 1) . '</span>';
				$html .= '<a href="#" class="gform-settings-field-repeater-item-remove-link" data-index="' . esc_attr($index) . '">' . esc_html__('Remove', 'omnisend-for-gravity-forms') . '</a>';
				$html .= '</div>';
				$html .= '<div class="gform-settings-field-repeater-item-content">';
				$html .= '<div class="gform-settings-field">';
				$html .= '<label for="omnisend_conditions_' . esc_attr($index) . '_field_id">' . esc_html__('Field', 'omnisend-for-gravity-forms') . '</label>';
				$html .= '<select id="omnisend_conditions_' . esc_attr($index) . '_field_id" name="omnisend_conditions[' . esc_attr($index) . '][field_id]" class="omnisend-conditions-field-id">';
				foreach ($choices as $choice) {
					$selected = selected(isset($condition['field_id']) ? $condition['field_id'] : '', $choice['value'], false);
					$html .= '<option value="' . esc_attr($choice['value']) . '" ' . $selected . '>' . esc_html($choice['label']) . '</option>';
				}
				$html .= '</select>';
				$html .= '</div>';

				$html .= '<div class="gform-settings-field">';
				$html .= '<label for="omnisend_conditions_' . esc_attr($index) . '_operator">' . esc_html__('Operator', 'omnisend-for-gravity-forms') . '</label>';
				$html .= '<select id="omnisend_conditions_' . esc_attr($index) . '_operator" name="omnisend_conditions[' . esc_attr($index) . '][operator]" class="omnisend-conditions-operator">';
				foreach (array(
					array('label' => esc_html__('is', 'omnisend-for-gravity-forms'), 'value' => 'is'),
					array('label' => esc_html__('is not', 'omnisend-for-gravity-forms'), 'value' => 'is_not'),
					array('label' => esc_html__('contains', 'omnisend-for-gravity-forms'), 'value' => 'contains'),
					array('label' => esc_html__('does not contain', 'omnisend-for-gravity-forms'), 'value' => 'not_contains'),
					array('label' => esc_html__('is empty', 'omnisend-for-gravity-forms'), 'value' => 'empty'),
					array('label' => esc_html__('is not empty', 'omnisend-for-gravity-forms'), 'value' => 'not_empty'),
				) as $option) {
					$selected = selected(isset($condition['operator']) ? $condition['operator'] : '', $option['value'], false);
					$html .= '<option value="' . esc_attr($option['value']) . '" ' . $selected . '>' . esc_html($option['label']) . '</option>';
				}
				$html .= '</select>';
				$html .= '</div>';

				$html .= '<div class="gform-settings-field">';
				$html .= '<label for="omnisend_conditions_' . esc_attr($index) . '_value">' . esc_html__('Value', 'omnisend-for-gravity-forms') . '</label>';
				$html .= '<input type="text" id="omnisend_conditions_' . esc_attr($index) . '_value" name="omnisend_conditions[' . esc_attr($index) . '][value]" value="' . esc_attr(isset($condition['value']) ? $condition['value'] : '') . '" class="omnisend-conditions-value" />';
				$html .= '</div>';
				$html .= '</div>';
				$html .= '</div>';
			}
		}

		$html .= '<div class="gform-settings-field">';
		$html .= '<a href="#" class="gform-settings-field-repeater-add-link">' . esc_html__('Add Condition', 'omnisend-for-gravity-forms') . '</a>';
		$html .= '</div>';

		// Add save button
		$html .= '<div class="gform-settings-field">';
		$html .= '<button type="button" class="button button-primary omnisend-save-conditions-btn">' . esc_html__('Save Conditions', 'omnisend-for-gravity-forms') . '</button>';
		$html .= '<span class="omnisend-save-status"></span>';
		$html .= '</div>';

		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Save custom settings including omnisend_conditions field.
	 */
	public function save_custom_settings($settings, $form)
	{
		// Handle omnisend_conditions field
		if (isset($_POST['omnisend_conditions']) && is_array($_POST['omnisend_conditions'])) {
			$conditions = array();
			foreach ($_POST['omnisend_conditions'] as $condition) {
				if (!empty($condition['field_id']) && $condition['field_id'] !== '-1' && !empty($condition['operator'])) {
					$conditions[] = array(
						'field_id' => sanitize_text_field($condition['field_id']),
						'operator' => sanitize_text_field($condition['operator']),
						'value' => sanitize_text_field($condition['value']),
					);
				}
			}
			$settings['omnisend_conditions'] = $conditions;
		} else {
			$settings['omnisend_conditions'] = array();
		}

		return $settings;
	}

	/**
	 * Enqueue admin scripts for the settings page.
	 */
	public function enqueue_admin_scripts()
	{
		$screen = get_current_screen();
		if ($screen && strpos($screen->id, 'gf_edit_forms') !== false) {
			wp_enqueue_script(
				'omnisend-gravity-forms-admin',
				plugins_url('/js/admin.js', __FILE__),
				array('jquery'),
				OMNISEND_GRAVITY_ADDON_VERSION,
				true
			);

			// Get the current form and field choices
			$form = $this->get_current_form();
			if ($form) {
				$fields_data = $this->get_form_fields(); // Use regular call since we fixed the value issue
				$all_fields  = $fields_data['allFields'];
				$choices     = array(
					array(
						'value' => '-1',
						'label' => 'Choose Field',
					),
				);
				$choices     = array_merge($choices, $all_fields);

				wp_localize_script(
					'omnisend-gravity-forms-admin',
					'omnisendFieldChoices',
					array(
						'fieldChoices' => $choices,
						'ajaxUrl' => admin_url('admin-ajax.php'),
						'nonce' => wp_create_nonce('omnisend_conditions_nonce'),
					)
				);
			}

			wp_enqueue_style(
				'omnisend-gravity-forms-admin',
				plugins_url('/css/admin.css', __FILE__),
				array(),
				OMNISEND_GRAVITY_ADDON_VERSION
			);
		}
	}


	public function get_form_fields($flatten_checkboxes = false)
	{
		$form           = $this->get_current_form();
		$all_fields     = array();
		$consent_fields = array();

		foreach ($form['fields'] as $field) {
			// Skip HTML fields
			if (isset($field->type) && $field->type === 'html') {
				continue;
			}

			$inputs = $field->get_entry_inputs();

			if ($inputs) {
				$choices = array();

				foreach ($inputs as $input) {
					if (rgar($input, 'isHidden')) {
						continue;
					}
					$choices[] = array(
						'value' => $input['id'],
						'label' => GFCommon::get_label($field, $input['id'], true),
					);
				}

				if (!empty($choices)) {
					// Original structure for field mapping
					$all_fields[] = array(
						'choices' => $choices,
						'label' => GFCommon::get_label($field),
						'value' => $field->id, // Add the field ID as the value
					);
				}

				if ($field->type === 'consent') {
					$consent_fields[] = array(
						'choices' => $choices,
						'label' => GFCommon::get_label($field),
					);
				}
			} else {
				$all_fields[] = array(
					'value' => $field->id,
					'label' => GFCommon::get_label($field),
				);

				if ($field->type === 'consent') {
					$consent_fields[] = array(
						'value' => $field->id,
						'label' => GFCommon::get_label($field),
					);
				}
			}
		}

		return array(
			'allFields' => $all_fields,
			'consentFields' => $consent_fields,
		);
	}

	public function form_settings_fields($form)
	{
		$fields_data    = $this->get_form_fields();
		$all_fields     = $fields_data['allFields'];
		$consent_fields = $fields_data['consentFields'];

		$choices[] = array(
			'value' => '-1',
			'label' => 'Choose Field',
		);

		$all_fields_choices     = array_merge($choices, $all_fields);
		$consent_fields_choices = array_merge($choices, $consent_fields);

		// Add: Condition settings for Omnisend sending
		$condition_fields = array(
			'title' => esc_html__('Omnisend Send Conditions', 'omnisend-for-gravity-forms'),
			'fields' => array(
				array(
					'label' => esc_html__('Add conditions to prevent sending to Omnisend based on user answers. If any condition matches, the contact will NOT be sent to Omnisend.', 'omnisend-for-gravity-forms'),
					'type' => 'html',
					'name' => 'omnisend_conditions_description',
					'html' => '<div class="gform-settings-field">' . esc_html__('If any of the following conditions are met, the contact will NOT be sent to Omnisend.', 'omnisend-for-gravity-forms') . '</div>',
				),
				array(
					'label' => esc_html__('Conditions', 'omnisend-for-gravity-forms'),
					'type' => 'html',
					'name' => 'omnisend_conditions',
					'html' => $this->get_conditions_html(),
				),
			),
		);

		$settings = array(
			array(
				'title' => esc_html__('Welcome Email', 'omnisend-for-gravity-forms'),
				'fields' => array(
					array(
						'label' => esc_html__('Check this to automatically send your custom welcome email, created in Omnisend, to subscribers joining through Gravity Forms.', 'omnisend-for-gravity-forms'),
						'type' => 'checkbox',
						'name' => 'send_welcome_email_checkbox',
						'choices' => array(
							array(
								'label' => esc_html__('Send a welcome email to new subscribers', 'omnisend-for-gravity-forms'),
								'name' => 'send_welcome_email',
							),
						),
					),
					array(
						'type' => 'welcome_automation_details',
						'name' => 'welcome_automation_details',
					),
				),
			),
			$condition_fields,
			array(
				'title' => esc_html__('Omnisend Field Mapping', 'omnisend-for-gravity-forms'),

				'fields' => array(
					array(
						'type' => 'field_mapping_details',
						'name' => 'field_mapping_details',
					),
					array(
						'label' => esc_html__('Email', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'email',
						'validation_callback' => function ($field, $value) {
							if ($value <= 0) {
								$field->set_error(esc_html__('Email is required', 'omnisend-for-gravity-forms'));
							}
						},
						'choices' => $all_fields_choices,
					),
					array(
						'label' => esc_html__('Address', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'address',
						'choices' => $all_fields_choices,
					),
					array(
						'label' => esc_html__('City', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'city',
						'choices' => $all_fields_choices,
					),
					array(
						'label' => esc_html__('State', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'state',
						'choices' => $all_fields_choices,
					),
					array(
						'label' => esc_html__('Country', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'country',
						'choices' => $all_fields_choices,
					),
					array(
						'label' => esc_html__('First Name', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'first_name',
						'choices' => $all_fields_choices,
					),
					array(
						'label' => esc_html__('Last Name', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'last_name',
						'choices' => $all_fields_choices,
					),
					array(
						'label' => esc_html__('Phone Number', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'phone_number',
						'choices' => $all_fields_choices,
					),
					array(
						'label' => esc_html__('Birthday', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'birthday',
						'choices' => $all_fields_choices,
					),
					array(
						'label' => esc_html__('Postal Code', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'postal_code',
						'choices' => $all_fields_choices,
					),
					array(
						'label' => esc_html__('Email Consent', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'email_consent',
						'choices' => $consent_fields_choices,
					),
					array(
						'label' => esc_html__('Phone Consent', 'omnisend-for-gravity-forms'),
						'type' => 'select',
						'name' => 'phone_consent',
						'choices' => $consent_fields_choices,
					),

				),
			),
		);

		return $settings;
	}

	/**
	 * Evaluate Omnisend send conditions.
	 *
	 * @param array $entry
	 * @param array $form
	 * @param array $settings
	 * @return bool True if any condition matches (should NOT send to Omnisend), false otherwise.
	 */
	private function should_skip_omnisend($entry, $form, $settings)
	{
		// // error_log('entry: ' . print_r( $entry, true ) );
		// // error_log('settings: ' . print_r( $settings, true ) );
		if (empty($settings['omnisend_conditions']) || !is_array($settings['omnisend_conditions'])) {
			return false;
		}

		foreach ($settings['omnisend_conditions'] as $condition) {
			$field_id = isset($condition['field_id']) ? $condition['field_id'] : '';
			$operator = isset($condition['operator']) ? $condition['operator'] : '';
			$value    = isset($condition['value']) ? $condition['value'] : '';

			if (empty($field_id) || empty($operator)) {
				continue;
			}

			// Find the field in the form
			$field = null;
			foreach ($form['fields'] as $form_field) {
				if ($form_field->id == $field_id) {
					$field = $form_field;
					break;
				}
			}

			$field_value = '';

			if ($field && ($field->type === 'checkbox' || $field->type === 'multi_choice')) {
				// For checkbox/multi_choice fields, check all input fields
				$inputs = $field->get_entry_inputs();
				// // error_log( "Field $field_id inputs: " . print_r( $inputs, true ) );

				if ($inputs) {
					$checkbox_values = array();
					foreach ($inputs as $input) {
						$input_id = $input['id'];
						// // error_log( "Checking input $input_id: " . ( isset( $entry[ $input_id ] ) ? $entry[ $input_id ] : 'not set' ) );
						if (isset($entry[$input_id]) && !empty($entry[$input_id])) {
							$checkbox_values[] = $entry[$input_id];
						}
					}
					$field_value = $checkbox_values;
				}
			} else {
				// For regular fields, get the value directly
				$field_value = isset($entry[$field_id]) ? $entry[$field_id] : '';
			}

			// // error_log( "Checking condition - Field ID: $field_id, Field Type: " . ( $field ? $field->type : 'unknown' ) . ", Operator: $operator, Expected Value: $value, Actual Value: " . print_r( $field_value, true ) );

			switch ($operator) {
				case 'is':
					if (is_array($field_value)) {
						if (in_array($value, $field_value)) {
							// // error_log( "Condition matched: $field_id is $value" );
							return true;
						}
					} else {
						if ($field_value == $value) {
							// // error_log( "Condition matched: $field_id is $value" );
							return true;
						}
					}
					break;
				case 'is_not':
					if (is_array($field_value)) {
						if (!in_array($value, $field_value)) {
							// error_log( "Condition matched: $field_id is not $value" );
							return true;
						}
					} else {
						if ($field_value != $value) {
							// error_log( "Condition matched: $field_id is not $value" );
							return true;
						}
					}
					break;
				case 'contains':
					if (is_array($field_value)) {
						foreach ($field_value as $val) {
							if (strpos((string) $val, (string) $value) !== false) {
								// error_log( "Condition matched: $field_id contains $value" );
								return true;
							}
						}
					} else {
						if (strpos((string) $field_value, (string) $value) !== false) {
							// error_log( "Condition matched: $field_id contains $value" );
							return true;
						}
					}
					break;
				case 'not_contains':
					if (is_array($field_value)) {
						$found = false;
						foreach ($field_value as $val) {
							if (strpos((string) $val, (string) $value) !== false) {
								$found = true;
								break;
							}
						}
						if (!$found) {
							// error_log( "Condition matched: $field_id does not contain $value" );
							return true;
						}
					} else {
						if (strpos((string) $field_value, (string) $value) === false) {
							// error_log( "Condition matched: $field_id does not contain $value" );
							return true;
						}
					}
					break;
				case 'empty':
					if (empty($field_value)) {
						// error_log( "Condition matched: $field_id is empty" );
						return true;
					}
					break;
				case 'not_empty':
					if (!empty($field_value)) {
						// error_log( "Condition matched: $field_id is not empty" );
						return true;
					}
					break;
			}
		}

		return false;
	}

	/**
	 * Performing a custom action at the end of the form submission process.
	 *
	 * @param array $entry The entry currently being processed.
	 * @param array $form The form currently being processed.
	 */
	public function after_submission($entry, $form)
	{
		if (!class_exists('Omnisend\SDK\V1\Omnisend')) {
			return;
		}

		try {
			$contact  = new Contact();
			$settings = $this->get_form_settings($form);


			if (empty($settings)) {
				return;
			}

			// error_log( 'Omnisend send conditions: ' . print_r( $settings['omnisend_conditions'], true ) );

			// Check Omnisend send conditions
			if ($this->should_skip_omnisend($entry, $form, $settings)) {
				// error_log('skipping omnisend');
				return; // Do not send to Omnisend if any condition matches
			}
			// error_log('sending to omnisend');

			$fields_to_process = array(
				'email',
				'address',
				'country',
				'city',
				'state',
				'first_name',
				'last_name',
				'birthday',
				'phone_number',
				'postal_code',
				'email_consent',
				'phone_consent',
			);

			$email         = '';
			$phone_number  = '';
			$postal_code   = '';
			$address       = '';
			$country       = '';
			$city          = '';
			$state         = '';
			$first_name    = '';
			$last_name     = '';
			$birthday      = '';
			$email_consent = false;
			$phone_consent = false;

			foreach ($fields_to_process as $field) {

				if (isset($settings[$field]) && $settings[$field] != '-1') {
					if (in_array($field, array('email_consent', 'phone_consent'))) {
						if ($entry[$settings[$field]] == '1') {
							${$field} = true;
						}
					} else {
						${$field} = $entry[$settings[$field]];
					}
				}
			}

			if ($email == '') {
				return; // Email is not mapped. Skipping Omnisend contact creation.
			}

			$contact->set_email($email);

			if ($phone_number != '') {
				$contact->set_phone($phone_number);
			}

			$contact->set_first_name($first_name);
			$contact->set_last_name($last_name);
			$contact->set_birthday($birthday);
			$contact->set_postal_code($postal_code);
			$contact->set_address($address);
			$contact->set_state($state);
			$contact->set_country($country);
			$contact->set_city($city);
			$contact->add_tag('gravity_forms');
			$contact->add_tag('gravity_forms ' . $form['title']);

			if ($email_consent) {
				$contact->set_email_consent('gravity-forms');
				$contact->set_email_opt_in('gravity-forms');
			}

			if ($phone_consent) {
				$contact->set_phone_consent('gravity-forms'); // todo looks a bit strange. Maybe one function is enough?
				$contact->set_phone_opt_in('gravity-forms');
			}

			if (isset($settings['send_welcome_email']) && $settings['send_welcome_email'] == '1') {
				$contact->set_welcome_email(true);
			}

			$this->mapCustomProperties($form, $entry, $settings, $contact);

			$response = \Omnisend\SDK\V1\Omnisend::get_client(OMNISEND_GRAVITY_ADDON_NAME, OMNISEND_GRAVITY_ADDON_VERSION)->create_contact($contact);
			if ($response->get_wp_error()->has_errors()) {
				// error_log( 'Error in after_submission: ' . $response->get_wp_error()->get_error_message()); // phpcs:ignore
				return;
			}

			$this->enableWebTracking($email, $phone_number);

		} catch (Exception $e) {
			// todo check if it is possible to get exception? If not remove handling.
			// error_log( 'Error in after_submission: ' . $e->getMessage() ); // phpcs:ignore
		}
	}

	private function mapCustomProperties($form, $entry, $settings, Contact $contact)
	{
		$prefix = 'gravity_forms_';
		foreach ($form['fields'] as $field) {
			$field_id    = $field['id'];
			$field_label = $field['label'];

			if (!in_array($field_id, $settings) || $settings[array_search($field_id, $settings)] === '-1') {
				// Replace spaces with underscores, remove invalid characters, lowercase.
				$safe_label = strtolower(str_replace(' ', '_', $field_label));

				if ($field['type'] !== 'checkbox') {
					// Check if the value is set and not empty.
					if (!empty($entry[$field_id])) {
						$contact->add_custom_property($prefix . $safe_label, $entry[$field_id]);
					}
				} else {
					$selected_choices = array();
					if (isset($field['inputs']) && is_array($field['inputs'])) {
						foreach ($field['inputs'] as $input) {
							$choice_id = $input['id'];
							if (!empty($entry[$choice_id])) {
								$selected_choices[] = $input['label'];
							}
						}
					}
					// Only add to customProperties if selectedChoices is not empty.
					if (!empty($selected_choices)) {
						$contact->add_custom_property($prefix . $safe_label, $selected_choices);
					}
				}
			}
		}
	}

	public function get_menu_icon()
	{
		return file_get_contents($this->get_base_path() . '/images/menu-icon.svg'); // phpcs:ignore
	}

	private function enableWebTracking($email, $phone)
	{
		$identifiers = array_filter(
			array(
				'email' => sanitize_email($email),
				'phone' => sanitize_text_field($phone),
			)
		);

		$path_to_script = plugins_url('/js/snippet.js', __FILE__);

		wp_enqueue_script('omnisend-snippet-script', $path_to_script, array(), '1.0.0', true);
		wp_localize_script('omnisend-snippet-script', 'omnisendIdentifiers', $identifiers);
	}

	public function settings_welcome_automation_details($field, $echo = true)
	{ // phpcs:ignore
		echo '<div class="gform-settings-field">' . esc_html__('After checking this, don’t forget to design your welcome email in Omnisend.', 'omnisend-for-gravity-forms') . '</div>';
		echo '<a target="_blank" href="https://support.omnisend.com/en/articles/1061818-welcome-email-automation">' . esc_html__('Learn more about Welcome automation', 'omnisend-for-gravity-forms') . '</a>';
	}


	public function settings_field_mapping_details()
	{
		echo '<div class="gform-settings-field">' . esc_html__('Field mapping lets you align your form fields with Omnisend. It\'s important to match them correctly, so the information collected through Gravity Forms goes into the right place in Omnisend.', 'omnisend-for-gravity-forms') . '</div>';

		echo '<img width="900" src="' . plugins_url('/images/omnisend-field-mapping.png', __FILE__) . '" alt="Omnisend Field Mapping" />'; // phpcs:ignore

		echo '<div class="alert gforms_note_info">' . esc_html__('Having trouble? Explore our help article.', 'omnisend-for-gravity-forms') . '<br/><a target="_blank" href="https://support.omnisend.com/en/articles/8617559-integration-with-gravity-forms">' . esc_html__('Learn more', 'omnisend-for-gravity-forms') . '</a></div>';
	}

	/**
	 * Handle partial entry saved event.
	 *
	 * @param array $partial_entry The partial entry object.
	 * @param array $form The current form object.
	 */
	public function handle_partial_entry_saved($partial_entry, $form)
	{
		$this->process_partial_entry_for_omnisend($partial_entry, $form);
	}

	/**
	 * Handle partial entry updated event.
	 *
	 * @param array $partial_entry The partial entry object.
	 * @param array $form The current form object.
	 */
	public function handle_partial_entry_updated($partial_entry, $form)
	{
		$this->process_partial_entry_for_omnisend($partial_entry, $form);
	}

	/**
	 * Process partial entry for Omnisend integration.
	 * Now schedules a WC_Queue job instead of processing immediately.
	 *
	 * @param array $partial_entry The partial entry object.
	 * @param array $form The current form object.
	 */
	private function process_partial_entry_for_omnisend($partial_entry, $form)
	{
		// Debug: Log entry into the function
		error_log('[Omnisend] process_partial_entry_for_omnisend called. Form ID: ' . (isset($form['id']) ? $form['id'] : 'N/A'));

		// Check if this is form ID 5
		if ($form['id'] != 5) {
			error_log('[Omnisend] Skipping: Not form ID 5. Current form ID: ' . (isset($form['id']) ? $form['id'] : 'N/A'));
			return;
		}

		$fields_to_check = array(
			'116' => array('medical form tag 1', 'medical form tag 2'),
		);

		// Check if freya_draft_token_5 cookie is set and construct continue form URL
		$continue_form_url = '';


		foreach ($fields_to_check as $field_id => $tags) {
			error_log("[Omnisend] Checking field $field_id for tags: " . implode(', ', $tags));

			if (empty($partial_entry[$field_id])) {
				error_log("[Omnisend] Field $field_id is empty in partial entry. Skipping.");
				continue;
			}

			// if the page is not yet up to the page 116 is on, skip the processing
			if ($field_id == '116' && empty($partial_entry['113'])) {
				error_log("[Omnisend] Field 116 requires field 113 to be set. Skipping.");
				continue;
			}

			$email = sanitize_email($partial_entry[$field_id]);
			if (!is_email($email)) {
				error_log("[Omnisend] Value for field $field_id is not a valid email: " . $partial_entry[$field_id]);
				continue;
			}

			// Process each tag for this field
			foreach ($tags as $tag) {
				// Check if this email/tag combination has already been added to Omnisend using a transient
				$transient_key = 'omnisend_email_added_' . md5($email . '|' . $tag);
				if (get_transient($transient_key)) {
					error_log("[Omnisend] Transient already set for $email and tag $tag (key: $transient_key). Skipping.");
					continue; // Email/tag already processed
				}

				if (empty($continue_form_url)) {
					if (isset($_COOKIE['freya_draft_token_5']) && !empty($_COOKIE['freya_draft_token_5'])) {
						$draft_token = sanitize_text_field($_COOKIE['freya_draft_token_5']);
						$page_url    = get_permalink(71301);
						if ($page_url) {
							$continue_form_url = add_query_arg('gf_token', $draft_token, $page_url);
							error_log('[Omnisend] Continue form URL constructed: ' . $continue_form_url);
						} else {
							error_log('[Omnisend] Page URL for continue form not found.');
						}
					} else {
						error_log('[Omnisend] freya_draft_token_5 cookie not set or empty.');
					}
				}

				// Prepare data for the background job
				$contact_data = array(
					'email' => $email,
					'tag' => $tag,
					'transient_key' => $transient_key,
					'form_id' => $form['id'],
					'field_id' => $field_id,
					'continue_form_url' => $continue_form_url,
				);

				// error_log('[Omnisend] Prepared contact_data: ' . print_r($contact_data, true));

				// Schedule WC_Queue job for 5 seconds in the future
				if (class_exists('WC_Queue') && function_exists('WC')) {
					error_log('[Omnisend] Scheduling WC_Queue job for contact: ' . $email . ' with tag: ' . $tag);
					$queue = WC()->queue();
					$queue->schedule_single(
						time() + 5, // 5 seconds from now
						'omnisend_process_delayed_contact_creation',
						array($contact_data),
						'omnisend-partial-entries'
					);
				} else {
					error_log('[Omnisend] WC_Queue not available. Processing contact immediately for: ' . $email . ' with tag: ' . $tag);
					// Fallback to immediate processing if WC_Queue is not available
					$this->process_delayed_omnisend_contact($contact_data);
				}
			}
		}
	}

	/**
	 * Process delayed Omnisend contact creation via WC_Queue.
	 *
	 * @param array $contact_data The contact data to process.
	 */
	public function process_delayed_omnisend_contact($contact_data)
	{
		// Validate required data
		if (empty($contact_data['email']) || empty($contact_data['tag']) || empty($contact_data['transient_key'])) {
			error_log('Omnisend delayed processing: Missing required contact data');
			return;
		}

		// Check if this email/tag combination has already been processed
		if (get_transient($contact_data['transient_key'])) {
			return; // Email/tag already processed
		}

		// Check if Omnisend SDK is available
		if (!class_exists('Omnisend\SDK\V1\Contact')) {
			error_log('Omnisend delayed processing: Contact class not available');
			return;
		}

		try {
			// Create contact object
			$contact = new Contact();
			$contact->set_email($contact_data['email']);
			$contact->add_tag($contact_data['tag']);

			// Set email consent
			$contact->set_email_consent('gravity-forms');
			$contact->set_email_opt_in('gravity-forms');

			// Add continue_form_url as custom property if available
			if (!empty($contact_data['continue_form_url'])) {
				$contact->add_custom_property('continue_form_url', $contact_data['continue_form_url']);
			}

			// Send to Omnisend
			$response = \Omnisend\SDK\V1\Omnisend::get_client(OMNISEND_GRAVITY_ADDON_NAME, OMNISEND_GRAVITY_ADDON_VERSION)->create_contact($contact);

			if ($response->get_wp_error()->has_errors()) {
				error_log('Omnisend delayed processing error: ' . $response->get_wp_error()->get_error_message());
				return;
			}

			// Set transient to prevent duplicate processing for this email/tag (expires in 24 hours)
			set_transient($contact_data['transient_key'], true, 24 * HOUR_IN_SECONDS);

			// Enable web tracking
			$this->enableWebTracking($contact_data['email'], '');

			error_log('Omnisend delayed processing: Successfully added contact ' . $contact_data['email'] . ' with tag ' . $contact_data['tag']);

		} catch (Exception $e) {
			error_log('Omnisend delayed processing exception: ' . $e->getMessage());
		}
	}
}
