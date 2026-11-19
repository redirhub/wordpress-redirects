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

	// Redirects of other plugins: open the dashboard's import in a new tab and, once it says it is
	// ready (it may ask to sign in first), hand it the import file. Only that tab, on the
	// dashboard's own origin, gets it. A blocked tab leaves the download.
	app.querySelectorAll('[data-handoff]').forEach(function (form) {
		var start = form.querySelector('[data-handoff-start]');
		var status = form.querySelector('[data-handoff-status]');
		var url = form.getAttribute('data-handoff');
		var origin = new URL(url, window.location.href).origin;
		var tab = null;
		var file = null;
		var say = function (text) {
			if (status && text) {
				status.textContent = text;
			}
		};
		var deliver = function (event) {
			if (!tab || event.source !== tab || event.origin !== origin || !event.data || event.data.type !== 'import-handoff:ready') {
				return;
			}
			file.then(function (csv) {
				tab.postMessage({
					type: 'import-handoff:file',
					name: form.getAttribute('data-handoff-name'),
					from: form.getAttribute('data-handoff-from'),
					site: window.location.host,
					csv: csv
				}, origin);
				say(strings.handoffSent);
			}).catch(function () {
				say(strings.handoffFailed);
			});
		};
		window.addEventListener('message', deliver);
		start.addEventListener('click', function () {
			tab = window.open(url, '_blank');
			if (!tab) {
				say(strings.handoffBlocked);
				return;
			}
			say(strings.handoffOpening);
			file = window.fetch(form.getAttribute('action'), { method: 'POST', body: new FormData(form), credentials: 'same-origin' }).then(function (response) {
				var type = response.headers.get('Content-Type') || '';
				if (!response.ok || type.indexOf('text/csv') !== 0) {
					throw new Error('no file');
				}
				return response.text();
			});
			file.catch(function () {
				say(strings.handoffFailed);
			});
		});
	});
})();
