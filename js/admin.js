jQuery(document).ready(function($) {
    // Handle adding new condition fields
    $(document).on('click', '.gform-settings-field-repeater-add-link', function(e) {
        e.preventDefault();
        
        var container = $(this).closest('.gform-settings-field-repeater-container');
        var items = container.find('.gform-settings-field-repeater-item');
        var newIndex = items.length;
        
        // Get field choices from localized data
        var fieldChoices = [];
        if (typeof omnisendFieldChoices !== 'undefined' && omnisendFieldChoices.fieldChoices) {
            fieldChoices = omnisendFieldChoices.fieldChoices;
        } else {
            // Fallback: try to get from existing items
            var firstFieldSelect = container.find('.omnisend-conditions-field-id').first();
            if (firstFieldSelect.length) {
                firstFieldSelect.find('option').each(function() {
                    fieldChoices.push({
                        value: $(this).val(),
                        label: $(this).text()
                    });
                });
            } else {
                // Default choices if no existing items
                fieldChoices = [
                    { value: '-1', label: 'Choose Field' }
                ];
            }
        }
        
        // Create the new item HTML
        var newItemHtml = createConditionItemHtml(newIndex, fieldChoices);
        container.find('.gform-settings-field-repeater-add-link').closest('.gform-settings-field').before(newItemHtml);
    });
    
    // Handle removing condition fields
    $(document).on('click', '.gform-settings-field-repeater-item-remove-link', function(e) {
        e.preventDefault();
        
        var item = $(this).closest('.gform-settings-field-repeater-item');
        item.remove();
        
        // Reindex remaining items
        var container = item.closest('.gform-settings-field-repeater-container');
        container.find('.gform-settings-field-repeater-item').each(function(index) {
            var itemElement = $(this);
            itemElement.find('.gform-settings-field-repeater-item-index').text(index + 1);
            itemElement.find('.gform-settings-field-repeater-item-remove-link').attr('data-index', index);
            
            // Update input names and IDs
            itemElement.find('select, input').each(function() {
                var element = $(this);
                var oldName = element.attr('name');
                var oldId = element.attr('id');
                
                if (oldName) {
                    var newName = oldName.replace(/\[\d+\]/, '[' + index + ']');
                    element.attr('name', newName);
                }
                
                if (oldId) {
                    var newId = oldId.replace(/_\d+_/, '_' + index + '_');
                    element.attr('id', newId);
                }
            });
            
            // Update labels
            itemElement.find('label').each(function() {
                var label = $(this);
                var forAttr = label.attr('for');
                if (forAttr) {
                    var newFor = forAttr.replace(/_\d+_/, '_' + index + '_');
                    label.attr('for', newFor);
                }
            });
        });
    });
    
    // Handle save button click
    $(document).on('click', '.omnisend-save-conditions-btn', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var statusSpan = button.siblings('.omnisend-save-status');
        var container = button.closest('.gform-settings-field-repeater-container');
        var formId = container.data('form-id');
        
        // Disable button and show loading
        button.prop('disabled', true).text('Saving...');
        statusSpan.html('<span style="color: #0073aa;">Saving conditions...</span>');
        
        // Collect all condition data
        var conditions = [];
        container.find('.gform-settings-field-repeater-item').each(function() {
            var item = $(this);
            var fieldId = item.find('select[name*="[field_id]"]').val();
            var operator = item.find('select[name*="[operator]"]').val();
            var value = item.find('input[name*="[value]"]').val();
            
            console.log('Found condition:', { fieldId: fieldId, operator: operator, value: value });
            
            if (fieldId && fieldId !== '-1' && operator) {
                conditions.push({
                    field_id: fieldId,
                    operator: operator,
                    value: value
                });
            }
        });
        
        // Debug logging
        console.log('Sending conditions:', conditions);
        console.log('Form ID:', formId);
        console.log('AJAX URL:', omnisendFieldChoices.ajaxUrl);
        
        // Send AJAX request
        $.ajax({
            url: omnisendFieldChoices.ajaxUrl,
            type: 'POST',
            data: {
                action: 'save_omnisend_conditions',
                nonce: omnisendFieldChoices.nonce,
                form_id: formId,
                conditions: conditions
            },
            success: function(response) {
                console.log('AJAX Response:', response);
                if (response.success) {
                    statusSpan.html('<span style="color: #46b450;">✓ ' + response.data + '</span>');
                    setTimeout(function() {
                        statusSpan.html('');
                    }, 3000);
                } else {
                    var errorMsg = response.data || 'Unknown error';
                    console.error('Save failed:', errorMsg);
                    statusSpan.html('<span style="color: #dc3232;">✗ Error: ' + errorMsg + '</span>');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {xhr: xhr, status: status, error: error});
                statusSpan.html('<span style="color: #dc3232;">✗ Error: Failed to save conditions</span>');
            },
            complete: function() {
                button.prop('disabled', false).text('Save Conditions');
            }
        });
    });
    
    function createConditionItemHtml(index, fieldChoices) {
        var html = '<div class="gform-settings-field-repeater-item">';
        html += '<div class="gform-settings-field-repeater-item-header">';
        html += '<span class="gform-settings-field-repeater-item-index">' + (index + 1) + '</span>';
        html += '<a href="#" class="gform-settings-field-repeater-item-remove-link" data-index="' + index + '">Remove</a>';
        html += '</div>';
        html += '<div class="gform-settings-field-repeater-item-content">';
        
        // Field dropdown
        html += '<div class="gform-settings-field">';
        html += '<label for="omnisend_conditions_' + index + '_field_id">Field</label>';
        html += '<select id="omnisend_conditions_' + index + '_field_id" name="omnisend_conditions[' + index + '][field_id]" class="omnisend-conditions-field-id">';
        for (var i = 0; i < fieldChoices.length; i++) {
            html += '<option value="' + fieldChoices[i].value + '">' + fieldChoices[i].label + '</option>';
        }
        html += '</select>';
        html += '</div>';
        
        // Operator dropdown
        html += '<div class="gform-settings-field">';
        html += '<label for="omnisend_conditions_' + index + '_operator">Operator</label>';
        html += '<select id="omnisend_conditions_' + index + '_operator" name="omnisend_conditions[' + index + '][operator]" class="omnisend-conditions-operator">';
        html += '<option value="is">is</option>';
        html += '<option value="is_not">is not</option>';
        html += '<option value="contains">contains</option>';
        html += '<option value="not_contains">does not contain</option>';
        html += '<option value="empty">is empty</option>';
        html += '<option value="not_empty">is not empty</option>';
        html += '</select>';
        html += '</div>';
        
        // Value input
        html += '<div class="gform-settings-field">';
        html += '<label for="omnisend_conditions_' + index + '_value">Value</label>';
        html += '<input type="text" id="omnisend_conditions_' + index + '_value" name="omnisend_conditions[' + index + '][value]" value="" class="omnisend-conditions-value" />';
        html += '</div>';
        
        html += '</div>';
        html += '</div>';
        
        return html;
    }
}); 