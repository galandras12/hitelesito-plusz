jQuery(function ($) {
	'use strict';

	// Szerepkör mátrix szegmensek aktív állapotának frissítése kattintásra.
	$(document).on('change', '.h2f-segment input[type="radio"]', function () {
		var $segment = $(this).closest('.h2f-segmented');
		$segment.find('.h2f-segment').removeClass('is-active');
		$(this).closest('.h2f-segment').addClass('is-active');
	});

	// Kód másolása vágólapra.
	$(document).on('click', '.h2f-copy-btn', function () {
		var text = $(this).data('copy');
		var $btn = $(this);
		var originalText = $btn.text();

		function done() {
			$btn.text('Másolva!');
			setTimeout(function () {
				$btn.text(originalText);
			}, 1500);
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done);
		} else {
			var $tmp = $('<textarea>').val(text).appendTo('body').select();
			document.execCommand('copy');
			$tmp.remove();
			done();
		}
	});
});
