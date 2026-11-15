/* RedirHub Redirects: Tools → RedirHub. No dependencies. */
(function () {
	'use strict';
	var strings = window.redirhubRedirects || {};

	// Ask before disconnecting: the redirects stop at once.
	document.querySelectorAll('.rh [data-confirm]').forEach(function (button) {
		button.addEventListener('click', function (event) {
			if (!window.confirm(strings.confirmDisconnect || 'Disconnect?')) {
				event.preventDefault();
			}
		});
	});

	// A button that starts a download or the test says it is working, and is not pressed twice.
	document.querySelectorAll('.rh form').forEach(function (form) {
		form.addEventListener('submit', function () {
			var button = form.querySelector('button[type="submit"][data-busy]');
			if (button) {
				button.setAttribute('aria-busy', 'true');
				button.textContent = button.getAttribute('data-busy');
			}
		});
	});
})();
