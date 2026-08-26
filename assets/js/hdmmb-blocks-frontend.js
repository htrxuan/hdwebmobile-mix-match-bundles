(function (wp, wc) {
	'use strict';

	if (!wp || !wp.plugins || !wp.element || !wc || !wc.blocksCheckout) {
		return;
	}

	var registerPlugin = wp.plugins.registerPlugin;
	var createElement = wp.element.createElement;
	var ExperimentalOrderMeta = wc.blocksCheckout.ExperimentalOrderMeta;

	function BundleContents(props) {
		var cartItem = props.cartItem || {};
		var extensions = cartItem.extensions || {};
		var bundleContents = extensions.hdmmb ? extensions.hdmmb.bundle_contents : null;

		if (!bundleContents) {
			return null;
		}

		return createElement(
			'div',
			{ className: 'wc-block-components-product-metadata hdmmb-bundle-contents' },
			createElement('strong', null, 'Bundle Contents: '),
			bundleContents
		);
	}

	function render() {
		return createElement(
			ExperimentalOrderMeta,
			null,
			function (fillProps) {
				return createElement(BundleContents, fillProps);
			}
		);
	}

	registerPlugin('hdmmb-bundle-contents', {
		render: render,
		scope: 'woocommerce-checkout',
	});
})(window.wp, window.wc);
