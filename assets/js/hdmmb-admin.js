(function ($) {
	'use strict';

	function toggleFields() {
		var enabled = $('#_hdmmb_enabled').is(':checked');
		$('.show_if_hdmmb_enabled').toggle(enabled);

		var pricingMode = $('input[name="_hdmmb_pricing_mode"]:checked').val();
		$('.show_if_pricing_fixed').closest('.form-field').toggle(pricingMode === 'fixed');
		$('.show_if_pricing_discount').closest('.form-field').toggle(pricingMode === 'discount_percent');

		var sourceType = $('input[name="_hdmmb_source_type"]:checked').val();
		$('.show_if_source_category').toggle(sourceType === 'category');
		$('.show_if_source_manual').toggle(sourceType === 'manual');
	}

	$(document).on('change', '#_hdmmb_enabled, input[name="_hdmmb_pricing_mode"], input[name="_hdmmb_source_type"]', toggleFields);

	$(document).ready(function () {
		toggleFields();
	});

})(jQuery);
