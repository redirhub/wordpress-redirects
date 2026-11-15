/* Tools → RedirHub. No dependencies. */
(function () {
	'use strict';
	var strings = window.redirhubRedirects || {};
	var app = document.getElementById('rh-app');
	if (!app) {
		return;
	}

	// Notices WordPress and other plugins print above the page (the update nag included) go into
	// the page's content, under its header.
	var slot = app.querySelector('[data-notices]');
	if (slot) {
		document.querySelectorAll('#wpbody-content > .update-nag, #wpbody-content > .notice, #wpbody-content > .error, #wpbody-content > .updated').forEach(function (notice) {
			slot.appendChild(notice);
		});
	}

	// Ask before disconnecting: the redirects stop at once.
	app.querySelectorAll('[data-confirm]').forEach(function (button) {
		button.addEventListener('click', function (event) {
			if (!window.confirm(strings.confirmDisconnect || 'Disconnect?')) {
				event.preventDefault();
			}
		});
	});

	// A button that starts a download, the test or the connection says so, and is not pressed twice.
	app.querySelectorAll('form').forEach(function (form) {
		form.addEventListener('submit', function () {
			var button = form.querySelector('button[type="submit"][data-busy]');
			if (button) {
				button.setAttribute('aria-busy', 'true');
				var label = Array.prototype.find.call(button.childNodes, function (node) {
					return node.nodeType === 3 && node.textContent.trim() !== '';
				});
				if (label) {
					label.textContent = button.getAttribute('data-busy');
				}
			}
		});
	});

	// Messages close.
	app.querySelectorAll('[data-dismiss]').forEach(function (button) {
		button.addEventListener('click', function () {
			button.closest('[data-alert]').remove();
		});
	});

	// "Change" opens the connection form.
	app.querySelectorAll('[data-change-toggle]').forEach(function (button) {
		var form = app.querySelector('[data-change-form]');
		button.addEventListener('click', function () {
			var open = form.hasAttribute('hidden');
			form.toggleAttribute('hidden', !open);
			button.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) {
				var field = form.querySelector('input[type="text"], input[type="password"]');
				if (field) {
					field.focus();
				}
			}
		});
	});
})();
