(function () {
	'use strict';

	if (typeof hdmmbParams === 'undefined') {
		return;
	}

	var form = document.querySelector('.hdmmb-picker');
	if (!form) {
		return;
	}

	var pickQty       = parseInt(hdmmbParams.pick_qty, 10) || 0;
	var progressEl    = form.querySelector('.hdmmb-picker__progress');
	var pricePreviewEl = form.querySelector('.hdmmb-picker__price-preview');
	var submitButton  = form.querySelector('.hdmmb-picker__submit');
	var items         = form.querySelectorAll('.hdmmb-picker__item');
	var debounceTimer = null;

	function getSelection() {
		var selection = {};
		items.forEach(function (item) {
			var input = item.querySelector('.hdmmb-picker__stepper-input');
			var qty   = parseInt(input.value, 10) || 0;
			if (qty > 0) {
				selection[item.getAttribute('data-product-id')] = qty;
			}
		});
		return selection;
	}

	function getTotalQty(selection) {
		var total = 0;
		Object.keys(selection).forEach(function (id) {
			total += selection[id];
		});
		return total;
	}

	function updateProgress() {
		var selection = getSelection();
		var total     = getTotalQty(selection);

		progressEl.textContent = hdmmbParams.i18n.progress
			.replace('%1$d', total)
			.replace('%2$d', pickQty);

		submitButton.disabled = total !== pickQty;

		clearTimeout(debounceTimer);
		debounceTimer = setTimeout(function () {
			fetchPricePreview(selection, total);
		}, 300);
	}

	function fetchPricePreview(selection, total) {
		if (total === 0) {
			pricePreviewEl.innerHTML = '';
			return;
		}

		var body = new URLSearchParams();
		body.append('action', 'hdmmb_get_bundle_price');
		body.append('nonce', hdmmbParams.nonce);
		body.append('bundle_id', hdmmbParams.product_id);
		Object.keys(selection).forEach(function (id) {
			body.append('selection[' + id + ']', selection[id]);
		});

		fetch(hdmmbParams.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (response) {
				if (response && response.success) {
					pricePreviewEl.innerHTML = response.data.price_html;
				}
			});
	}

	items.forEach(function (item) {
		var input    = item.querySelector('.hdmmb-picker__stepper-input');
		var minusBtn = item.querySelector('.hdmmb-picker__stepper-minus');
		var plusBtn  = item.querySelector('.hdmmb-picker__stepper-plus');
		var maxStock = item.getAttribute('data-max-stock');
		maxStock     = maxStock ? parseInt(maxStock, 10) : null;

		minusBtn.addEventListener('click', function () {
			var qty = parseInt(input.value, 10) || 0;
			if (qty > 0) {
				input.value = qty - 1;
				updateProgress();
			}
		});

		plusBtn.addEventListener('click', function () {
			var qty = parseInt(input.value, 10) || 0;
			if (maxStock === null || qty < maxStock) {
				input.value = qty + 1;
				updateProgress();
			}
		});
	});

	updateProgress();

})();
