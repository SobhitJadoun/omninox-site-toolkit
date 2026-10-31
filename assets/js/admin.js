/**
 * Master Toolkit — no-build admin app.
 *
 * Talks to the same REST API as the React build in /build. If a compiled
 * bundle exists, PHP loads that instead and this file is never enqueued.
 */
(function () {
	'use strict';

	var root = document.getElementById('omninox-root') || document.getElementById('wpmt-root');
	var WPMT = window.OmninoxSiteToolkit;

	if (!root || !WPMT) {
		return;
	}

	var i18n = (window.wp && window.wp.i18n) ? window.wp.i18n : {
		__: function (s) { return s; },
		_n: function (single, plural, n) { return (Number(n) === 1) ? single : plural; },
		_x: function (s) { return s; }
	};
	var __ = i18n.__;
	var _n = i18n._n;
	var _x = i18n._x;

	var state = {
		route: 'dashboard',
		overview: null,
		changes: [],
		tips: [],
		updates: null,
		dash: null,
		fm: { path: '', entries: [], editing: null, contents: '' },
		selected: {},
		results: {},
		settings: null,
		busy: {},
		loading: {},
		inspect: {},
		progress: {},
		expanded: {},
		searchReplace: {
			search: '',
			replace: '',
			target: 'all',
			mode: 'dry_run',
			tables: 'all',
			case_sensitive: 'no',
			selected: {}
		},
		mediaTab: 'unused',
		unused: {
			scanning: false,
			paused: false,
			cancelling: false,
			scanProgress: 0,
			scanStep: '',
			scanPhase: 'discovery',
			scanPhaseLabel: '',
			imagesDiscovered: 0,
			sourcesProcessed: 0,
			sourcesTotal: 0,
			referencesIndexed: 0,
			currentOffset: 0,
			stats: null,
			items: [],
			page: 1,
			perPage: 100,
			totalPages: 1,
			totalItems: 0,
			filter: 'likely_unused',
			search: '',
			selected: {},
			selectAllEligible: false,
			activePreview: null,
			activeReferences: null,
			showSettings: false,
			showHistory: false,
			historyTab: 'quarantined',
			history: [],
			quarantinedList: [],
			settings: {
				protect_recent_days: 30,
				protect_site_identity: 1,
				protect_woo_catalog: 1
			}
		},
		urlReplacement: {
			active: false,
			jobId: null,
			mode: 'replace',
			format: 'webp',
			status: 'idle',
			step: 'init',
			stepLabel: '',
			progress: 0,
			rowsInspected: 0,
			rowsReplaced: 0,
			replacementsCount: 0,
			summary: null,
			report: null,
			showReport: false
		}
	};

	var activeRequests = 0;
	var activeTaskLabel = '';

	function isAnyBusy() {
		return activeRequests > 0 ||
			Object.keys(state.busy || {}).some(function (k) { return state.busy[k]; }) ||
			Object.keys(state.loading || {}).some(function (k) { return state.loading[k]; });
	}

	function updateGlobalLoader(label) {
		if (label) {
			activeTaskLabel = label;
		}

		var loader = document.getElementById('wpmt-global-loader');
		var textNode = document.getElementById('wpmt-loader-text');

		if (!loader) { return; }

		if (isAnyBusy()) {
			loader.classList.add('is-active');
			if (textNode) {
				textNode.textContent = activeTaskLabel || __('Task in progress, please wait…', 'omninox-site-toolkit');
			}
		} else {
			loader.classList.remove('is-active');
			activeTaskLabel = '';
		}
	}

	var isRefreshingNonce = false;
	var nonceRefreshQueue = [];

	function refreshNonceAndRetry(path, options) {
		options = Object.assign({}, options, { _isRetry: true });

		return new Promise(function (resolve, reject) {
			nonceRefreshQueue.push({ path: path, options: options, resolve: resolve, reject: reject });

			if (isRefreshingNonce) {
				return;
			}
			isRefreshingNonce = true;

			var ajaxUrl = WPMT.ajaxUrl || (WPMT.adminUrl ? WPMT.adminUrl + 'admin-ajax.php' : '/wp-admin/admin-ajax.php');
			fetch(ajaxUrl + '?action=omninox_refresh_nonce', {
				method: 'GET',
				credentials: 'same-origin'
			})
				.then(function (res) { return res.json(); })
				.then(function (resData) {
					if (resData && resData.success && resData.data && resData.data.nonce) {
						WPMT.nonce = resData.data.nonce;
						var queue = nonceRefreshQueue.slice();
						nonceRefreshQueue = [];
						isRefreshingNonce = false;
						queue.forEach(function (item) {
							api(item.path, item.options).then(item.resolve).catch(item.reject);
						});
					} else {
						throw new Error(__('Session expired. Please refresh the page.', 'omninox-site-toolkit'));
					}
				})
				.catch(function (err) {
					var queue = nonceRefreshQueue.slice();
					nonceRefreshQueue = [];
					isRefreshingNonce = false;
					queue.forEach(function (item) {
						item.reject(err);
					});
				});
		});
	}

	function api(path, options) {
		options = options || {};
		var trackLoader = !options.silent;

		if (trackLoader) {
			activeRequests++;
			updateGlobalLoader(options.label || '');
		}

		var root = (WPMT.root || '').replace(/\/+$/, '');
		var endpoint = (path || '').replace(/^\/+/, '');
		var url = root + '/' + endpoint;

		if (root.indexOf('?') !== -1) {
			var parts = root.split('?');
			url = parts[0] + '?' + parts[1] + '/' + endpoint;
		}

		// Ensure URL strictly matches window.location origin (protocol, hostname, port)
		// so browsers treat the fetch as same-origin and send authentication cookies.
		try {
			if (typeof window !== 'undefined' && window.location) {
				var parsedUrl = new URL(url, window.location.href);
				if (parsedUrl.hostname === window.location.hostname || !parsedUrl.hostname) {
					parsedUrl.protocol = window.location.protocol;
					parsedUrl.host = window.location.host;
					url = parsedUrl.toString();
				}
			}
		} catch (e) { }

		var headers = {
			'Content-Type': 'application/json'
		};

		var isRestoreRoute = false;
		if (path && (path.indexOf('restore') !== -1 || path.indexOf('fix/backup') !== -1)) {
			isRestoreRoute = true;
		}
		if (options.body && options.body.action && String(options.body.action).indexOf('restore') === 0) {
			isRestoreRoute = true;
		}

		// Include dedicated restore continuation token ONLY if on an active restore route
		var restoreToken = null;
		if (isRestoreRoute) {
			try {
				restoreToken = sessionStorage.getItem('omninox_restore_token');
				if (restoreToken) {
					headers['X-Omninox-Restore-Token'] = restoreToken;
				}
			} catch (e) { }
		}

		// Include X-WP-Nonce on all standard administrative requests.
		// Only omit if specifically on an active restore operation that has a continuation token.
		if (WPMT.nonce) {
			if (!isRestoreRoute || !restoreToken) {
				headers['X-WP-Nonce'] = WPMT.nonce;
			}
		}

		return fetch(url, {
			method: options.method || 'GET',
			credentials: 'same-origin',
			headers: headers,
			body: options.body ? JSON.stringify(options.body) : undefined
		}).then(function (response) {
			if (trackLoader) {
				activeRequests = Math.max(0, activeRequests - 1);
				updateGlobalLoader();
			}

			var refreshedNonce = response.headers.get('X-WP-Nonce');
			if (refreshedNonce) {
				WPMT.nonce = refreshedNonce;
			}

			return response.json().catch(function () { return {}; }).then(function (data) {
				if (!response.ok) {
					if (!options._isRetry && (response.status === 403 || response.status === 401) && data && (data.code === 'rest_cookie_invalid_nonce' || (data.message && String(data.message).indexOf('Cookie check failed') !== -1))) {
						return refreshNonceAndRetry(path, options);
					}

					var msg = (data && data.message);
					if (!msg) {
						if (response.status === 401) {
							msg = __('Authentication expired. Please refresh the page.', 'omninox-site-toolkit');
						} else if (response.status === 403) {
							msg = __('Authorization failed. Access forbidden.', 'omninox-site-toolkit');
						} else if (response.status === 404) {
							msg = __('Requested resource no longer exists.', 'omninox-site-toolkit');
						} else if (response.status === 409) {
							msg = __('Another operation is already running.', 'omninox-site-toolkit');
						} else if (response.status === 422) {
							msg = __('Invalid request state.', 'omninox-site-toolkit');
						} else if (response.status === 500) {
							msg = __('Internal server error.', 'omninox-site-toolkit');
						} else if (response.status === 503) {
							msg = __('Server temporarily unavailable. Retrying…', 'omninox-site-toolkit');
						} else {
							msg = __('The request failed.', 'omninox-site-toolkit');
						}
					}
					var err = new Error(msg);
					err.status = response.status;
					err.code = (data && data.code) || '';
					err.data = data;
					throw err;
				}
				return data;
			});
		}).catch(function (error) {
			if (trackLoader) {
				activeRequests = Math.max(0, activeRequests - 1);
				updateGlobalLoader();
			}
			throw error;
		});
	}

	/* --------------------------------------------------------------- Helpers */

	var ICON_SVGS = {
		dashboard: '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
		security: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
		performance: '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
		backup: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
		database: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
		media: '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
		seo: '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><path d="m11 8 2 3h-4l2 3"/>',
		health: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
		analytics: '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
		accessibility: '<circle cx="12" cy="4" r="2"/><path d="m5 9 14 0"/><path d="m12 9 0 5"/><path d="m9 20 3-6 3 6"/>',
		redirects: '<polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/>',
		search: '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
		errors: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
		developer: '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
		reports: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
		settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
		white_label: '<path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
		whitelabel: '<path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',

		// Actions and elements
		play: '<polygon points="5 3 19 12 5 21 5 3"/>',
		check: '<polyline points="20 6 9 17 4 12"/>',
		warning: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
		refresh: '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
		download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
		trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
		restore: '<polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>',
		rollback: '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
		sparkle: '<path d="m12 3 1.912 5.885L20 10.8l-5.088 3.915L16.824 21 12 17.2 7.176 21l1.912-6.285L4 10.8l6.088-1.915L12 3Z"/>',
		lock: '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
		info: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>'
	};

	function icon(name, size, className) {
		size = size || 16;
		var key = (name || '').toLowerCase().replace(/-/g, '_');
		var path = ICON_SVGS[key] || ICON_SVGS.settings;
		var span = document.createElement('span');
		span.className = 'wpmt-icon ' + (className || '');
		span.style.width = size + 'px';
		span.style.height = size + 'px';
		span.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + path + '</svg>';
		return span;
	}

	function getActionIcon(actionId) {
		actionId = (actionId || '').toLowerCase();
		if (actionId.indexOf('cache') !== -1 || actionId.indexOf('clear') !== -1 || actionId.indexOf('flush') !== -1) return 'refresh';
		if (actionId.indexOf('backup') !== -1 || actionId.indexOf('snapshot') !== -1) return 'backup';
		if (actionId.indexOf('db') !== -1 || actionId.indexOf('database') !== -1 || actionId.indexOf('optimize') !== -1) return 'database';
		if (actionId.indexOf('security') !== -1 || actionId.indexOf('firewall') !== -1 || actionId.indexOf('malware') !== -1) return 'security';
		if (actionId.indexOf('image') !== -1 || actionId.indexOf('media') !== -1 || actionId.indexOf('webp') !== -1) return 'media';
		if (actionId.indexOf('health') !== -1 || actionId.indexOf('diagnos') !== -1) return 'health';
		if (actionId.indexOf('report') !== -1 || actionId.indexOf('export') !== -1) return 'reports';
		if (actionId.indexOf('search') !== -1) return 'search';
		if (actionId.indexOf('seo') !== -1) return 'seo';
		return 'sparkle';
	}

	function strtoupper(str) {
		return String(str || '').toUpperCase();
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);

		Object.keys(attrs || {}).forEach(function (key) {
			if (key === 'class') {
				node.className = attrs[key];
			} else if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key === 'html') {
				node.innerHTML = attrs[key];
			} else if (key.indexOf('on') === 0) {
				node.addEventListener(key.slice(2).toLowerCase(), attrs[key]);
			} else if (attrs[key] !== null && attrs[key] !== undefined && attrs[key] !== false) {
				node.setAttribute(key, attrs[key]);
			}
		});

		(children || []).forEach(function (child) {
			if (child === null || child === undefined || child === false) {
				return;
			}
			node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
		});

		return node;
	}

	function filePicker(accept, compact) {
		var input = el('input', {
			type: 'file',
			accept: accept || '',
			class: 'wpmt-file-picker-input',
			tabindex: '-1'
		});
		var status = el('span', {
			class: 'wpmt-file-picker-name',
			'aria-live': 'polite',
			text: __('No file selected', 'omninox-site-toolkit')
		});

		input.addEventListener('change', function () {
			status.textContent = input.files && input.files[0]
				? input.files[0].name
				: __('No file selected', 'omninox-site-toolkit');
		});

		return {
			input: input,
			node: el('div', { class: 'wpmt-file-picker' + (compact ? ' wpmt-file-picker-compact' : '') }, [
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small wpmt-file-picker-trigger',
					text: __('Choose file', 'omninox-site-toolkit'),
					onClick: function () { input.click(); }
				}),
				status,
				input
			])
		};
	}

	function toast(message, isError) {
		var existing = document.querySelector('.wpmt-toast');

		if (existing) {
			existing.remove();
		}

		var node = el('div', {
			class: 'wpmt-toast' + (isError ? ' wpmt-toast-error' : ''),
			role: 'status',
			text: message
		});

		document.body.appendChild(node);
		setTimeout(function () { node.remove(); }, 6000);
	}

	function moduleById(id) {
		return (WPMT.modules || []).filter(function (m) { return m.id === id; })[0] || null;
	}

	function accentFor(score) {
		if (score >= 90) { return 'var(--wpmt-pass)'; }
		if (score >= 70) { return 'var(--wpmt-warning)'; }
		return 'var(--wpmt-critical)';
	}

	function gauge(score) {
		var radius = 22;
		var circumference = 2 * Math.PI * radius;
		var offset = circumference * (1 - Math.max(0, Math.min(100, score)) / 100);

		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('width', '54');
		svg.setAttribute('height', '54');
		svg.setAttribute('viewBox', '0 0 54 54');
		svg.setAttribute('aria-hidden', 'true');

		['track', 'value'].forEach(function (kind) {
			var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
			circle.setAttribute('cx', '27');
			circle.setAttribute('cy', '27');
			circle.setAttribute('r', String(radius));
			circle.setAttribute('fill', 'none');
			circle.setAttribute('stroke-width', '5');
			circle.setAttribute('stroke-linecap', 'round');

			if (kind === 'track') {
				circle.setAttribute('class', 'wpmt-gauge-track');
			} else {
				circle.setAttribute('stroke', accentFor(score));
				circle.setAttribute('stroke-dasharray', String(circumference));
				circle.setAttribute('stroke-dashoffset', String(offset));
			}

			svg.appendChild(circle);
		});

		return el('div', { class: 'wpmt-gauge' }, [
			svg,
			el('span', { class: 'wpmt-gauge-value', text: String(score) })
		]);
	}

	/* ---------------------------------------------------------------- Render */

	function render() {
		var theme = resolveTheme();

		root.innerHTML = '';
		root.setAttribute('data-theme', theme);

		// Repaint the surrounding admin chrome too, otherwise a dark panel
		// sits on a white page and the contrast reads as broken.
		document.body.classList.toggle('wpmt-theme-dark', theme === 'dark');

		var accent = WPMT.branding && WPMT.branding.accent ? WPMT.branding.accent.toLowerCase() : '';

		if (accent && accent !== '#0f8f8a') {
			root.style.setProperty('--wpmt-accent', accent);
			root.style.setProperty('--wpmt-accent-strong', accent);
		}

		root.appendChild(el('div', { class: 'wpmt-app' }, [sidebar(), main()]));
	}

	function resolveTheme() {
		return 'light';
	}

	function sidebar() {
		var items = [{ id: 'dashboard', label: __('Dashboard', 'omninox-site-toolkit'), icon: 'dashboard' }]
			.concat((WPMT.modules || []).map(function (m) {
				return { id: m.id, label: m.label, locked: !m.unlocked, icon: m.id };
			}))
			.concat([
				{ id: 'reports', label: __('Reports', 'omninox-site-toolkit'), icon: 'reports' },
				{ id: 'settings', label: __('Settings', 'omninox-site-toolkit'), icon: 'settings' }
			]);

		var brandingName = (WPMT.branding && WPMT.branding.plugin_name) || 'OmniNox Site Toolkit';
		var logoUrl = (WPMT.branding && WPMT.branding.logo_url) || (WPMT.pluginUrl ? WPMT.pluginUrl + 'assets/images/logo.svg' : '');
		var isPremium = !!(WPMT.license && WPMT.license.premium);
		var tier = isPremium ? __('Pro — all features enabled', 'omninox-site-toolkit') : __('Free plan', 'omninox-site-toolkit');

		var logoEl = logoUrl
			? el('img', { class: 'wpmt-brand-img', src: logoUrl, alt: brandingName })
			: el('span', { class: 'wpmt-brand-emblem', html: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="28" height="28"><defs><linearGradient id="sbBg" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0f8f8a"/><stop offset="100%" stop-color="#0a6f6b"/></linearGradient><linearGradient id="sbAcc" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#6ee7b7"/><stop offset="100%" stop-color="#34d399"/></linearGradient></defs><rect width="128" height="128" rx="28" fill="url(#sbBg)"/><path d="M 64 22 L 96 38 L 96 74 C 96 90, 82 102, 64 108 C 46 102, 32 90, 32 74 L 32 38 Z" fill="none" stroke="#ffffff" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round" stroke-opacity="0.35"/><path d="M 47 48 L 47 80" fill="none" stroke="#ffffff" stroke-width="7.5" stroke-linecap="round"/><path d="M 81 48 L 81 80" fill="none" stroke="#ffffff" stroke-width="7.5" stroke-linecap="round"/><path d="M 47 50 L 79 78" fill="none" stroke="url(#sbAcc)" stroke-width="8" stroke-linecap="round"/><circle cx="81" cy="48" r="4.5" fill="url(#sbAcc)"/></svg>' });

		return el('nav', { class: 'wpmt-sidebar', 'aria-label': brandingName }, [
			el('div', { class: 'wpmt-brand' }, [
				el('div', { class: 'wpmt-brand-mark-wrap' }, [logoEl]),
				el('div', { class: 'wpmt-brand-text' }, [
					el('span', { class: 'wpmt-brand-name', text: brandingName }),
					el('span', { class: 'wpmt-brand-tier', text: tier })
				])
			]),
			isPremium ? null : el('button', {
				type: 'button',
				class: 'wpmt-btn wpmt-btn-pro wpmt-sidebar-upgrade',
				onClick: function () { showUpgradeModal(''); }
			}, [
				icon('sparkle', 14, 'wpmt-btn-icon'),
				document.createTextNode(' ' + __('Upgrade to Pro', 'omninox-site-toolkit'))
			]),
			el('div', { class: 'wpmt-nav' }, items.map(function (item) {
				var scores = (state.overview && state.overview.scores) || {};
				var score = scores[item.id] ? scores[item.id].score : null;

				return el('button', {
					type: 'button',
					'aria-current': state.route === item.id ? 'true' : 'false',
					onClick: function () { go(item.id); }
				}, [
					el('span', { class: 'wpmt-nav-item-content' }, [
						icon(item.icon || item.id, 16, 'wpmt-nav-icon'),
						el('span', { class: 'wpmt-nav-label', text: item.label })
					]),
					item.locked
						? el('span', { class: 'wpmt-chip wpmt-chip-pro' }, [
							icon('lock', 10),
							document.createTextNode(' ' + __('Pro', 'omninox-site-toolkit'))
						])
						: (score !== null && score !== undefined
							? el('span', { class: 'wpmt-chip', text: String(score) })
							: null)
				]);
			}))
		]);
	}

	function main() {
		var wrapper = el('div', { class: 'wpmt-main' });

		var globalLoader = el('div', {
			id: 'wpmt-global-loader',
			class: 'wpmt-global-loader' + (isAnyBusy() ? ' is-active' : '')
		}, [
			el('div', { class: 'wpmt-loader-bar' }, [
				el('div', { class: 'wpmt-loader-progress' })
			]),
			el('div', { class: 'wpmt-loader-status' }, [
				el('span', { class: 'wpmt-loader-spinner' }),
				el('span', { id: 'wpmt-loader-text', text: activeTaskLabel || __('Task in progress, please wait…', 'omninox-site-toolkit') })
			])
		]);

		wrapper.appendChild(globalLoader);

		// Permanent top warning banner when Pro plugin is not installed/active
		var isProActive = !!(WPMT.isProActive || (WPMT.license && WPMT.license.premium && WPMT.isProInstalled));
		var hasValidKey = !!(WPMT.license && (WPMT.license.has_valid_key || (WPMT.license.status === 'active' && WPMT.license.masked_key)));
		if (!isProActive) {
			var bannerTitle = hasValidKey
				? __('License Key Activated — Omninox Pro Companion Plugin Required', 'omninox-site-toolkit')
				: __('Omninox Site Toolkit Pro Plugin Required', 'omninox-site-toolkit');

			var bannerMessage = hasValidKey
				? __('Your Pro license key is activated! To unlock and use all Pro features (such as auto-filling alt text, unlimited conversions, database search & replace, full backup & restore, and live search), please download and install the Omninox Site Toolkit Pro plugin.', 'omninox-site-toolkit')
				: __('To use advanced Pro features (such as auto-filling alt text, unlimited WebP/AVIF conversions, database search & replace, full-site backup & restore, live search, and login security), please install and activate the Omninox Site Toolkit Pro plugin.', 'omninox-site-toolkit');

			var bannerBtnText = hasValidKey
				? __('Download Pro Plugin', 'omninox-site-toolkit')
				: __('Get Pro Plugin', 'omninox-site-toolkit');

			var banner = el('div', {
				class: 'wpmt-pro-notice-banner',
				style: 'background: ' + (hasValidKey ? 'var(--wpmt-pass-bg)' : 'var(--wpmt-warning-bg)') + '; border: 1px solid ' + (hasValidKey ? 'var(--wpmt-pass-border)' : 'var(--wpmt-warning-border)') + '; border-left: 4px solid ' + (hasValidKey ? 'var(--wpmt-pass)' : 'var(--wpmt-warning)') + '; border-radius: var(--wpmt-radius); padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; box-shadow: var(--wpmt-shadow-xs);'
			}, [
				el('div', { style: 'display: flex; align-items: center; gap: 12px; flex: 1; min-width: 280px;' }, [
					el('span', {
						class: hasValidKey ? 'dashicons dashicons-yes-alt' : 'dashicons dashicons-warning',
						style: 'font-size: 22px; width: 22px; height: 22px; color: ' + (hasValidKey ? 'var(--wpmt-pass)' : 'var(--wpmt-warning)') + ';'
					}),
					el('div', {}, [
						el('strong', {
							style: 'font-size: 13.5px; font-weight: 600; color: ' + (hasValidKey ? 'var(--wpmt-pass)' : 'var(--wpmt-warning)') + '; display: block; margin-bottom: 2px;',
							text: bannerTitle
						}),
						el('p', {
							style: 'margin: 0; font-size: 12.5px; color: var(--wpmt-ink-soft); line-height: 1.4;',
							text: bannerMessage
						})
					])
				]),
				el('div', { style: 'display: flex; gap: 10px; align-items: center;' }, [
					el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-pro',
						text: bannerBtnText,
						onClick: function () {
							if (hasValidKey && WPMT.license && WPMT.license.download_url) {
								window.open(WPMT.license.download_url, '_blank');
							} else {
								showUpgradeModal(__('Omninox Pro Features', 'omninox-site-toolkit'));
							}
						}
					})
				])
			]);
			wrapper.appendChild(banner);
		}

		if (state.route === 'dashboard') {
			renderDashboard(wrapper);
		} else if (state.route === 'settings') {
			renderSettings(wrapper);
		} else if (state.route === 'reports') {
			renderReports(wrapper);
		} else {
			renderModule(wrapper, state.route);
		}

		return wrapper;
	}

	function header(title, subtitle, actions, iconName) {
		return el('div', { class: 'wpmt-header' }, [
			el('div', { class: 'wpmt-header-title-wrap' }, [
				iconName ? icon(iconName, 22, 'wpmt-header-icon') : null,
				el('div', {}, [
					el('h1', { text: title }),
					subtitle ? el('p', { text: subtitle }) : null
				])
			]),
			el('div', { class: 'wpmt-header-actions' }, actions || [])
		]);
	}

	/* ------------------------------------------------------------- Dashboard */

	function renderDashboard(wrapper) {
		var overview = state.overview;

		wrapper.appendChild(header(
			__('Site overview', 'omninox-site-toolkit'),
			overview ? overview.site.url : WPMT.siteName,
			[
				el('button', {
					class: 'wpmt-btn wpmt-btn-primary',
					type: 'button',
					disabled: !!state.busy.all,
					onClick: runFullAudit
				}, [
					icon('sparkle', 14, 'wpmt-btn-icon'),
					document.createTextNode(' ' + (state.busy.all ? __('Scanning…', 'omninox-site-toolkit') : __('Run full audit', 'omninox-site-toolkit')))
				])
			],
			'dashboard'
		));

		if (!overview) {
			wrapper.appendChild(el('div', { class: 'wpmt-card' }, [
				el('div', { class: 'wpmt-empty', text: __('Loading…', 'omninox-site-toolkit') })
			]));
			return;
		}

		// 1. Overall score
		wrapper.appendChild(el('div', { class: 'wpmt-card' }, [
			el('h2', { text: __('Overall score', 'omninox-site-toolkit') }),
			el('div', { style: 'display:flex;gap:16px;align-items:center' }, [
				gauge(overview.overall || 0),
				el('div', {}, [
					el('p', {
						style: 'margin:0 0 4px',
						text: overview.overall
							? __('Weighted across security, performance, health and SEO.', 'omninox-site-toolkit')
							: __('No scans yet. Run a full audit to populate this dashboard.', 'omninox-site-toolkit')
					}),
					el('p', {
						style: 'margin:0;font-size:12px;color:var(--wpmt-ink-soft)',
						text: 'WordPress ' + overview.site.wp + ' · PHP ' + overview.site.php
					})
				])
			])
		]));

		// 2. Module scores grid (placed second right after overall score)
		var cards = (WPMT.modules || []).map(function (module) {
			var entry = (overview.scores || {})[module.id];
			var score = entry ? entry.score : 0;

			return el('button', {
				type: 'button',
				class: 'wpmt-score-card' + (module.unlocked ? '' : ' wpmt-locked'),
				onClick: function () { go(module.id); }
			}, [
				gauge(score),
				el('div', { class: 'wpmt-score-meta' }, [
					el('div', { style: 'display:flex;align-items:center;gap:7px;margin-bottom:2px;' }, [
						icon(module.id, 16, 'wpmt-card-module-icon'),
						el('strong', { text: module.label })
					]),
					el('span', {
						text: entry
							? __('Last scan', 'omninox-site-toolkit') + ': ' + entry.at
							: __('Not scanned yet', 'omninox-site-toolkit')
					})
				])
			]);
		});

		wrapper.appendChild(el('div', { class: 'wpmt-grid' }, cards));

		// 3. Quick actions
		wrapper.appendChild(quickActions());

		if (state.dash && state.dash.notices && state.dash.notices.length) {
			wrapper.appendChild(el('div', { class: 'wpmt-card wpmt-card-alert' }, [
				el('h2', { text: __('Notifications', 'omninox-site-toolkit') }),
				el('ul', { class: 'wpmt-notices' }, state.dash.notices.map(function (notice) {
					return el('li', {}, [
						el('span', { class: 'wpmt-chip wpmt-chip-' + notice.level, text: notice.level }),
						el('span', { text: ' ' + notice.message }),
						notice.action ? el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-small',
							text: __('Fix', 'omninox-site-toolkit'),
							onClick: function () { runQuick(notice.action); }
						}) : null
					]);
				}))
			]));
		}

		// Updates first: an out-of-date core is more urgent than any score.
		if (state.updates && state.updates.total) {
			wrapper.appendChild(el('div', { class: 'wpmt-card wpmt-card-alert' }, [
				el('h2', { text: __('Updates waiting', 'omninox-site-toolkit') }),
				el('p', { style: 'margin:0 0 10px', text: state.updates.message }),
				el('table', { class: 'wpmt-table' }, [
					el('thead', {}, [
						el('tr', {}, [
							el('th', { text: __('Item', 'omninox-site-toolkit') }),
							el('th', { text: __('Type', 'omninox-site-toolkit') }),
							el('th', { text: __('Installed', 'omninox-site-toolkit') }),
							el('th', { text: __('Available', 'omninox-site-toolkit') })
						])
					]),
					el('tbody', {}, state.updates.items.slice(0, 12).map(function (item) {
						return el('tr', {}, [
							el('td', {}, [
								el('span', { text: item.name }),
								item.urgent ? el('span', { class: 'wpmt-chip wpmt-chip-critical', text: __('security', 'omninox-site-toolkit') }) : null
							]),
							el('td', { text: item.type }),
							el('td', { class: 'wpmt-mono', text: item.current }),
							el('td', { class: 'wpmt-mono', text: item.latest })
						]);
					}))
				]),
				el('p', { style: 'margin:10px 0 0' }, [
					el('a', { class: 'wpmt-btn', href: WPMT.adminUrl + 'update-core.php', text: __('Open the Updates screen', 'omninox-site-toolkit') })
				])
			]));
		}

		// Tips, ranked by what would help this site most.
		if (state.tips && state.tips.length) {
			wrapper.appendChild(el('div', { class: 'wpmt-card' }, [
				el('h2', { text: __('What to improve next', 'omninox-site-toolkit') }),
				el('ol', { class: 'wpmt-reco' }, state.tips.map(function (tip) {
					return el('li', {}, [
						el('strong', { text: tip.title }),
						el('div', { class: 'wpmt-reco-meta' }, [
							el('span', { class: 'wpmt-chip wpmt-chip-' + tip.severity, text: tip.severity }),
							el('span', { class: 'wpmt-chip', text: tip.module_label }),
							tip.value ? el('span', { class: 'wpmt-chip', text: String(tip.value) }) : null
						]),
						el('p', { class: 'wpmt-reco-how', text: tip.advice }),
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-small',
							text: __('Open', 'omninox-site-toolkit') + ' ' + tip.module_label,
							onClick: function () { go(tip.module); }
						})
					]);
				}))
			]));
		}

		var widgets = dashboardWidgets();

		if (widgets) {
			wrapper.appendChild(widgets);
		}
	}

	/**
	 * Quick actions strip.
	 */
	function quickActions() {
		var actions = (state.dash && state.dash.quick) || [];

		return el('div', { class: 'wpmt-card' }, [
			el('h2', { text: __('Quick actions', 'omninox-site-toolkit') }),
			el('div', { class: 'wpmt-quick' }, actions.map(function (action) {
				var qIcon = getActionIcon(action.id);
				return el('button', {
					type: 'button',
					class: 'wpmt-quick-btn',
					disabled: !!state.busy['quick:' + action.id],
					title: action.description,
					onClick: function () { runQuick(action.id); }
				}, [
					el('div', { class: 'wpmt-quick-btn-head' }, [
						icon(qIcon, 16, 'wpmt-quick-icon'),
						el('strong', { text: state.busy['quick:' + action.id] ? __('Working…', 'omninox-site-toolkit') : action.label })
					]),
					el('span', { text: action.description })
				]);
			}))
		]);
	}

	function runQuick(id) {
		var key = 'quick:' + id;

		state.busy[key] = true;
		render();

		api('/quick/' + id, { method: 'POST', body: {} })
			.then(function (result) {
				state.busy[key] = false;

				if (result.url) {
					window.open(result.url, '_blank', 'noopener');
				}

				toast(result.message || __('Done.', 'omninox-site-toolkit'));

				return loadDashboard().then(function () {
					return Promise.all([loadOverview(), loadTips()]);
				}).then(function () {
					if (result.goto) {
						go(result.goto);
					} else {
						render();
					}
				});
			})
			.catch(function (error) {
				state.busy[key] = false;
				render();
				toast(error.message, true);
			});
	}

	/**
	 * The widget grid below the scores.
	 */
	function dashboardWidgets() {
		var d = state.dash;

		if (!d) {
			return null;
		}

		var widgets = [];

		if (d.resources) {
			widgets.push(widget(__('Resources', 'omninox-site-toolkit'), [
				metricRow(__('Uploads', 'omninox-site-toolkit'), d.resources.storage_used + ' (' + d.resources.storage_files + ' ' + __('files', 'omninox-site-toolkit') + ')'),
				metricRow(__('Disk free', 'omninox-site-toolkit'), d.resources.disk_free),
				metricRow(__('Peak memory', 'omninox-site-toolkit'), d.resources.memory_peak + ' / ' + d.resources.memory_limit),
				el('div', { class: 'wpmt-progress' }, [el('i', { style: 'width:' + Math.min(100, d.resources.memory_pct) + '%' })]),
				metricRow(__('Server load', 'omninox-site-toolkit'), d.resources.load_1 === null ? '—' : String(d.resources.load_1)),
				el('p', { class: 'wpmt-meta-history', text: d.resources.load_note })
			]));
		}

		if (d.analytics) {
			widgets.push(widget(__('Website analytics', 'omninox-site-toolkit'), [
				metricRow(__('Posts', 'omninox-site-toolkit'), d.analytics.posts),
				metricRow(__('Pages', 'omninox-site-toolkit'), d.analytics.pages),
				metricRow(__('Media items', 'omninox-site-toolkit'), d.analytics.media),
				metricRow(__('Users', 'omninox-site-toolkit'), d.analytics.users),
				metricRow(__('Comments', 'omninox-site-toolkit'), d.analytics.comments + ' (' + d.analytics.pending + ' ' + __('pending', 'omninox-site-toolkit') + ')'),
				metricRow(__('Database size', 'omninox-site-toolkit'), d.analytics.db_size),
				metricRow(__('Errors, 7 days', 'omninox-site-toolkit'), d.analytics.errors_7d)
			]));
		}

		if (d.activity && d.activity.length) {
			widgets.push(widget(__('Recent activity', 'omninox-site-toolkit'), [
				el('ul', { class: 'wpmt-feed' }, d.activity.map(function (row) {
					return el('li', {}, [
						el('span', { class: 'wpmt-feed-when', text: row.ago + ' ' + __('ago', 'omninox-site-toolkit') }),
						el('span', { text: row.what })
					]);
				}))
			]));
		}

		if (d.errors && d.errors.length) {
			widgets.push(widget(__('Latest errors', 'omninox-site-toolkit'), [
				el('ul', { class: 'wpmt-feed' }, d.errors.slice(0, 5).map(function (row) {
					var levelClass = (row.level === 'fatal' || row.level === 'critical' || row.level === 'error') ? 'critical' : 'warning';
					return el('li', {}, [
						el('span', { class: 'wpmt-chip wpmt-chip-' + levelClass, text: row.level || 'notice' }),
						el('span', { style: 'flex:1;min-width:0;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;', text: row.message }),
						row.where ? el('span', { class: 'wpmt-feed-when', text: row.where }) : null
					]);
				})),
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small',
					style: 'margin-top: 6px;',
					text: __('Open the error log', 'omninox-site-toolkit'),
					onClick: function () { go('errors'); }
				})
			]));
		} else {
			widgets.push(widget(__('Latest errors', 'omninox-site-toolkit'), [
				el('p', { class: 'wpmt-meta-history', text: __('Nothing logged. Either the site is quiet or error collection is switched off in Settings.', 'omninox-site-toolkit') })
			]));
		}

		if (d.scans && d.scans.length) {
			widgets.push(widget(__('Scan history', 'omninox-site-toolkit'), [
				el('table', { class: 'wpmt-table' }, [
					el('tbody', {}, d.scans.map(function (row) {
						var score = row.score;
						var scoreColor = score >= 80 ? '#16a34a' : (score >= 50 ? '#d97706' : '#dc2626');
						var scoreBg = score >= 80 ? '#dcfce7' : (score >= 50 ? '#fef3c7' : '#fee2e2');

						return el('tr', {}, [
							el('td', { style: 'width: 55%;' }, [
								el('button', {
									type: 'button',
									class: 'wpmt-link',
									text: row.module,
									onClick: function () { go(row.id); }
								})
							]),
							el('td', { style: 'text-align: center; width: 15%;' }, [
								score === null
									? el('span', { class: 'wpmt-mono', text: '—' })
									: el('span', {
										style: 'font-weight: 700; font-size: 11.5px; padding: 2px 7px; border-radius: 999px; color: ' + scoreColor + '; background: ' + scoreBg + ';',
										text: String(score)
									})
							]),
							el('td', { class: 'wpmt-feed-when', style: 'text-align: right; width: 30%;', text: row.ago ? row.ago + ' ' + __('ago', 'omninox-site-toolkit') : __('never', 'omninox-site-toolkit') })
						]);
					}))
				])
			]));
		}

		if (d.tasks && d.tasks.length) {
			widgets.push(widget(__('Scheduled tasks', 'omninox-site-toolkit'), [
				el('div', { class: 'wpmt-task-list' }, d.tasks.map(function (row) {
					var schedLabel = (row.schedule || 'interval').replace(/_/g, ' ');
					return el('div', {
						class: 'wpmt-task-row',
						style: 'display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--wpmt-surface-3);'
					}, [
						el('div', { style: 'flex: 1; min-width: 0;' }, [
							el('span', {
								class: 'wpmt-mono',
								style: 'font-size: 12.5px; font-weight: 600; color: var(--wpmt-ink); display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;',
								title: row.hook,
								text: row.hook
							}),
							el('div', { style: 'display: flex; align-items: center; gap: 8px; margin-top: 3px;' }, [
								el('span', {
									class: 'wpmt-chip wpmt-chip-notice',
									style: 'font-size: 10px; text-transform: lowercase; padding: 1px 6px;',
									text: schedLabel
								})
							])
						]),
						el('span', {
							class: row.overdue ? 'wpmt-was' : 'wpmt-feed-when',
							style: 'font-size: 12px; font-weight: 500; text-align: right; white-space: nowrap;',
							text: row.in
						})
					]);
				}))
			]));
		}

		return el('div', { class: 'wpmt-widgets' }, widgets);
	}

	function widget(title, children) {
		return el('div', { class: 'wpmt-card wpmt-widget' }, [el('h2', { text: title })].concat(children));
	}

	function metricRow(label, value) {
		return el('div', { class: 'wpmt-metric' }, [
			el('span', { text: label }),
			el('b', { class: 'wpmt-mono', text: String(value) })
		]);
	}

	function loadDashboard() {
		return api('/dashboard')
			.then(function (data) { state.dash = data; })
			.catch(function () { });
	}

	function runFullAudit() {
		var queue = (WPMT.modules || []).filter(function (m) { return m.unlocked; });

		state.busy.all = true;
		render();

		var index = 0;

		function next() {
			if (index >= queue.length) {
				state.busy.all = false;
				updateGlobalLoader();
				render();

				return Promise.all([loadOverview(true), loadTips(true)]).then(render).then(function () {
					toast(__('Audit finished.', 'omninox-site-toolkit'));
				});
			}

			var module = queue[index++];

			return api('/scan/' + module.id, { method: 'POST', body: { args: {} } })
				.then(function (result) {
					state.results[module.id] = result;
					return next();
				})
				.catch(function () {
					return next();
				});
		}

		next();
	}

	function renderEffectivenessCard(items) {
		if (!items || !items.length) {
			return null;
		}

		var rows = items.map(function (item) {
			var settingChipClass = item.setting === 'on' ? 'wpmt-chip-pass' : 'wpmt-chip';
			var settingText = item.setting === 'on' ? __('ON', 'omninox-site-toolkit') : __('OFF', 'omninox-site-toolkit');

			var effectChipClass = 'wpmt-chip';
			if (item.status === 'verified') {
				effectChipClass = 'wpmt-chip-pass';
			} else if (item.status === 'conflict' || item.status === 'headers_not_applied' || item.status === 'wrong_resource') {
				effectChipClass = 'wpmt-chip-error';
			} else if (item.status === 'warning') {
				effectChipClass = 'wpmt-chip-warning';
			} else if (item.status === 'no_eligible') {
				effectChipClass = 'wpmt-chip-info';
			}

			var detailsCol = [
				el('div', { style: 'font-weight:500;color:var(--wpmt-ink);margin-bottom:2px;' }, [document.createTextNode(item.evidence || '')])
			];
			if (item.recommendation) {
				detailsCol.push(el('div', { style: 'font-size:12px;color:var(--wpmt-ink-soft);margin-top:4px;' }, [
					el('strong', { text: __('Recommendation: ', 'omninox-site-toolkit') }),
					document.createTextNode(item.recommendation)
				]));
			}

			return el('tr', {}, [
				el('td', { style: 'font-weight:600;white-space:nowrap;' }, [document.createTextNode(item.label || item.id)]),
				el('td', { style: 'text-align:center;width:80px;' }, [
					el('span', { class: 'wpmt-chip ' + settingChipClass }, [document.createTextNode(settingText)])
				]),
				el('td', { style: 'white-space:nowrap;width:170px;' }, [
					el('span', { class: 'wpmt-chip ' + effectChipClass }, [document.createTextNode(item.effect || item.status)])
				]),
				el('td', {}, detailsCol)
			]);
		});

		return el('div', { class: 'wpmt-card' }, [
			el('div', { class: 'wpmt-card-header', style: 'margin-bottom:12px;' }, [
				el('h3', { style: 'margin:0;font-size:16px;display:flex;align-items:center;gap:8px;' }, [
					icon('performance', 18),
					document.createTextNode(__('Optimization Effectiveness Diagnostics (Live Anonymous Scan)', 'omninox-site-toolkit'))
				]),
				el('p', { style: 'margin:4px 0 0;font-size:13px;color:var(--wpmt-ink-soft);' }, [
					document.createTextNode(__('Independently tests anonymous frontend output and response headers to verify if optimizations are actually reaching visitors.', 'omninox-site-toolkit'))
				])
			]),
			el('div', { style: 'overflow-x:auto;' }, [
				el('table', { class: 'wpmt-table', style: 'width:100%;margin:0;' }, [
					el('thead', {}, [
						el('tr', {}, [
							el('th', { style: 'text-align:left;' }, [document.createTextNode(__('Feature', 'omninox-site-toolkit'))]),
							el('th', { style: 'text-align:center;width:80px;' }, [document.createTextNode(__('Setting', 'omninox-site-toolkit'))]),
							el('th', { style: 'text-align:left;width:170px;' }, [document.createTextNode(__('Actual Frontend Effect', 'omninox-site-toolkit'))]),
							el('th', { style: 'text-align:left;' }, [document.createTextNode(__('Supporting Evidence & Details', 'omninox-site-toolkit'))])
						])
					]),
					el('tbody', {}, rows)
				])
			])
		]);
	}

	/* ---------------------------------------------------------------- Module */

	function renderModule(wrapper, id) {
		var module = moduleById(id);

		if (!module) {
			wrapper.appendChild(el('div', { class: 'wpmt-card' }, [
				el('div', { class: 'wpmt-empty' }, [
					el('strong', { text: __('Module not available', 'omninox-site-toolkit') }),
					__('It may depend on a plugin that is not active on this site.', 'omninox-site-toolkit')
				])
			]));
			return;
		}

		var result = state.results[id];

		if (!module.unlocked) {
			wrapper.appendChild(header(module.label, null, [], id));
			if (id === 'backup') {
				var backupActionBtns = [];
				if (WPMT.isProInstalled && !WPMT.isProActive) {
					backupActionBtns.push(el('a', {
						class: 'wpmt-btn wpmt-btn-pro',
						href: WPMT.activateProUrl || (WPMT.adminUrl + 'plugins.php'),
						text: __('Activate Pro', 'omninox-site-toolkit')
					}));
				} else {
					backupActionBtns.push(el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-pro',
						onClick: function () { showUpgradeModal(module.label); }
					}, [
						icon('sparkle', 14, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Upgrade to Pro', 'omninox-site-toolkit'))
					]));
				}

				wrapper.appendChild(el('div', { class: 'wpmt-card wpmt-lock-card' }, [
					el('div', { class: 'wpmt-lock-hero' }, [
						el('span', { class: 'wpmt-lock-icon', 'aria-hidden': 'true' }, [icon('lock', 36)]),
						proBadge()
					]),
					el('h3', { class: 'wpmt-lock-title', text: __('Backup & Restore is available in OmniNox Pro', 'omninox-site-toolkit') }),
					el('p', { class: 'wpmt-lock-text', text: __('Full website backup, migration, restore, rollback, database migration and advanced recovery are available in OmniNox Pro.', 'omninox-site-toolkit') }),
					el('div', { class: 'wpmt-lock-actions' }, backupActionBtns)
				]));
				return;
			}

			wrapper.appendChild(el('div', { class: 'wpmt-card wpmt-lock-card' }, [
				el('div', { class: 'wpmt-lock-hero' }, [
					el('span', { class: 'wpmt-lock-icon', 'aria-hidden': 'true' }, [icon('lock', 36)]),
					proBadge()
				]),
				el('h3', { class: 'wpmt-lock-title', text: sprintf(__('%s is a Pro module', 'omninox-site-toolkit'), module.label) }),
				el('p', { class: 'wpmt-lock-text', text: __('Activate an OmniNox Pro license to unlock this module and every other Pro feature.', 'omninox-site-toolkit') }),
				el('div', { class: 'wpmt-lock-actions' }, [
					el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-pro',
						onClick: function () { showUpgradeModal(module.label); }
					}, [
						icon('sparkle', 14, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Unlock with Pro', 'omninox-site-toolkit'))
					])
				])
			]));
			return;
		}

		wrapper.appendChild(header(module.label, null, [
			el('button', {
				type: 'button',
				class: 'wpmt-btn wpmt-btn-primary',
				disabled: !!state.busy[id],
				onClick: function () { runScan(id); }
			}, [
				icon('refresh', 14, 'wpmt-btn-icon'),
				document.createTextNode(' ' + (state.busy[id] ? __('Scanning…', 'omninox-site-toolkit') : __('Run scan', 'omninox-site-toolkit')))
			])
		], id));

		if (id === 'media') {
			var subnav = el('div', { class: 'wpmt-subnav' }, [
				el('button', {
					type: 'button',
					class: 'wpmt-subnav-btn' + (state.mediaTab === 'unused' ? ' is-active' : ''),
					onClick: function () {
						state.mediaTab = 'unused';
						render();
					}
				}, [
					icon('media', 15),
					document.createTextNode(' ' + __('Unused Images & Safe Cleanup', 'omninox-site-toolkit'))
				]),
				el('button', {
					type: 'button',
					class: 'wpmt-subnav-btn' + (state.mediaTab === 'optimize' ? ' is-active' : ''),
					onClick: function () {
						state.mediaTab = 'optimize';
						render();
					}
				}, [
					icon('performance', 15),
					document.createTextNode(' ' + __('Optimization & Formats', 'omninox-site-toolkit'))
				])
			]);
			wrapper.appendChild(subnav);

			if (state.mediaTab === 'unused') {
				renderUnusedMediaManager(wrapper, module);
				return;
			}

			var usageCard = mediaUsageCard();
			if (usageCard) {
				wrapper.appendChild(usageCard);
			}

			// High-Performance Image URL Replacement Engine
			renderUrlReplacementCard(wrapper);
		}

		if (module.actions && module.actions.length) {
			wrapper.appendChild(actionPanel(module));
		}

		if (id === 'search') {
			return;
		}

		if (!result) {
			wrapper.appendChild(el('div', { class: 'wpmt-card' }, [
				el('div', { class: 'wpmt-empty' }, state.loading[id]
					? [el('strong', { text: __('Loading the last scan…', 'omninox-site-toolkit') })]
					: [
						el('strong', { text: __('No results yet', 'omninox-site-toolkit') }),
						__('Run a scan to see what this module finds.', 'omninox-site-toolkit')
					]
				)
			]));
			return;
		}

		if (result.scanned_at) {
			wrapper.appendChild(el('p', {
				class: 'wpmt-meta-history',
				style: 'margin:-8px 0 12px',
				text: __('Last scanned', 'omninox-site-toolkit') + ': ' + result.scanned_at
			}));
		}

		wrapper.appendChild(el('div', { class: 'wpmt-card' }, [
			el('div', { style: 'display:flex;gap:16px;align-items:center;margin-bottom:8px' }, [
				gauge(result.score || 0),
				el('div', {}, [
					el('strong', { text: __('Grade', 'omninox-site-toolkit') + ' ' + (result.grade || '—') }),
					el('p', { style: 'margin:2px 0 0;font-size:12px;color:var(--wpmt-ink-soft)', text: summaryLine(result.summary) })
				])
			])
		]));

		if (id === 'performance' && result.effectiveness && result.effectiveness.length) {
			var effCard = renderEffectivenessCard(result.effectiveness);
			if (effCard) {
				wrapper.appendChild(effCard);
			}
		}

		var issues = result.issues || [];
		var groups = {};
		var order = [];

		issues.forEach(function (issue) {
			var name = issue.group || __('Checks', 'omninox-site-toolkit');

			if (!groups[name]) {
				groups[name] = [];
				order.push(name);
			}

			groups[name].push(issue);
		});

		order.forEach(function (name) {
			wrapper.appendChild(el('div', { class: 'wpmt-card' }, [
				el('h2', { text: name }),
				el('div', { class: 'wpmt-issues' }, groups[name].map(function (issue) {
					return issueRow(id, issue);
				}))
			]));
		});

		var charts = chartsPanel(result.charts);

		if (charts) {
			wrapper.appendChild(charts);
		}

		var reco = recommendationsPanel(result.recommendations);

		if (reco) {
			wrapper.appendChild(reco);
		}

		// Fall back to the live change feed so the history survives a reload
		// even when the stored scan payload predates it.
		var log = changeLog((result.changes && result.changes.length) ? result.changes : state.changes);

		if (log) {
			wrapper.appendChild(log);
		}
	}

	function summaryLine(summary) {
		summary = summary || {};

		return [
			summary.critical + ' ' + __('critical', 'omninox-site-toolkit'),
			summary.warning + ' ' + __('warnings', 'omninox-site-toolkit'),
			summary.notice + ' ' + __('notices', 'omninox-site-toolkit'),
			summary.pass + ' ' + __('passed', 'omninox-site-toolkit')
		].join(' · ');
	}

	function issueRow(moduleId, issue) {
		var key = moduleId + ':' + issue.id;
		var fixKey = issue.fix ? (moduleId + ':' + issue.fix) : '';
		var isFixBusy = !!(fixKey && (state.busy[fixKey] || (state.progress && state.progress[fixKey])));
		var open = !!state.expanded[key];

		var body = el('div', {}, [
			el('div', { class: 'wpmt-issue-label', text: issue.label }),
			issue.value ? el('div', { class: 'wpmt-issue-value' }, [
				el('b', { text: String(issue.value) }),
				issue.expected ? '  →  ' + issue.expected : '',
				issue.previous
					? el('span', { class: 'wpmt-was', text: '  ' + __('was', 'omninox-site-toolkit') + ' ' + formatValue(issue.previous) })
					: null
			]) : null,
			issue.previous_summary
				? el('div', { class: 'wpmt-meta-history', text: issue.previous_summary })
				: null,
			issue.description ? el('div', { class: 'wpmt-issue-desc', text: issue.description }) : null,
			open ? detailPanel(issue) : null
		]);

		var actions = el('div', { class: 'wpmt-issue-actions' }, [
			true
				? el('button', {
					type: 'button',
					class: 'wpmt-btn',
					text: open ? __('Hide', 'omninox-site-toolkit') : __('Details', 'omninox-site-toolkit'),
					onClick: function () {
						state.expanded[key] = !open;

						if (!open && !issue.explanation) {
							api('/fix/' + moduleId, { method: 'POST', body: { action: 'explain_issue', params: { id: issue.id } } })
								.then(function (explanation) {
									issue.explanation = explanation;
									render();
								})
								.catch(function (error) {
									issue.explanation = {
										cause: error.message || __('The explanation could not be loaded.', 'omninox-site-toolkit')
									};
									render();
								});
						}

						render();
					}
				})
				: null,
			issue.fix ? el('button', {
				type: 'button',
				class: 'wpmt-btn wpmt-btn-primary',
				disabled: isFixBusy || !!state.busy[key],
				onClick: function () {
					if (issue.fix === 'unused_open_manager') {
						state.mediaTab = 'unused';
						render();
						return;
					}
					var mod = moduleById(moduleId);
					var act = mod && mod.actions && mod.actions.find(function (a) { return a.id === issue.fix; });
					if (act && act.type === 'batch') {
						runBatch(moduleId, issue.fix, [], issue.fix_params || {});
					} else {
						applyFix(moduleId, issue.fix, issue.fix_params || {});
					}
				}
			}, [
				icon(issue.fix === 'unused_open_manager' ? 'media' : 'sparkle', 12, 'wpmt-btn-icon'),
				document.createTextNode(' ' + (issue.fix === 'unused_open_manager' ? __('Review Unused Images', 'omninox-site-toolkit') : (isFixBusy || state.busy[key] ? __('Fixing…', 'omninox-site-toolkit') : __('Fix', 'omninox-site-toolkit'))))
			]) : null
		]);

		var sIcons = {
			critical: 'errors',
			warning: 'warning',
			notice: 'info',
			pass: 'check'
		};
		var sIcon = sIcons[issue.severity] || 'info';

		return el('div', { class: 'wpmt-issue' }, [
			el('div', {}, [
				el('span', { class: 'wpmt-chip wpmt-chip-' + issue.severity }, [
					icon(sIcon, 11, 'wpmt-chip-icon'),
					document.createTextNode(' ' + issue.severity)
				])
			]),
			body,
			actions,
			isFixBusy ? progressBar(fixKey) : null
		]);
	}

	function detailPanel(issue) {
		var children = [];
		var explanation = issue.explanation;

		if (explanation) {
			children.push(el('dl', { class: 'wpmt-explain' }, [
				el('dt', { text: __('Cause', 'omninox-site-toolkit') }),
				el('dd', { text: explanation.cause || __('Not recorded for this check.', 'omninox-site-toolkit') }),
				el('dt', { text: __('Impact', 'omninox-site-toolkit') }),
				el('dd', { text: explanation.impact || __('Not recorded for this check.', 'omninox-site-toolkit') }),
				el('dt', { text: __('Risk', 'omninox-site-toolkit') }),
				el('dd', { text: explanation.risk || __('Not recorded for this check.', 'omninox-site-toolkit') }),
				el('dt', { text: __('How to fix it', 'omninox-site-toolkit') }),
				el('dd', { text: explanation.fix || __('Not recorded for this check.', 'omninox-site-toolkit') })
			]));
		} else {
			children.push(el('p', {
				class: 'wpmt-meta-history',
				text: __('Loading the explanation…', 'omninox-site-toolkit')
			}));
		}

		var labels = {
			elements: __('Affected elements', 'omninox-site-toolkit'),
			items: __('Affected items', 'omninox-site-toolkit'),
			files: __('Files', 'omninox-site-toolkit'),
			plugins: __('Plugins', 'omninox-site-toolkit'),
			pairs: __('Colour pairs', 'omninox-site-toolkit'),
			links: __('Links', 'omninox-site-toolkit'),
			users: __('Accounts', 'omninox-site-toolkit'),
			checked: __('Paths checked', 'omninox-site-toolkit'),
			problems: __('Paths needing attention', 'omninox-site-toolkit'),
			matches: __('Matches', 'omninox-site-toolkit'),
			urls: __('URLs', 'omninox-site-toolkit')
		};

		Object.keys(issue.context || {}).forEach(function (name) {
			var value = issue.context[name];
			var heading = labels[name] || name.replace(/_/g, ' ');

			if (Array.isArray(value) && value.length && typeof value[0] === 'object') {
				children.push(el('div', { class: 'wpmt-detail-block' }, [
					el('strong', { text: heading + ' (' + value.length + ')' }),
					el('div', { style: 'overflow:auto' }, [table(value)])
				]));
			} else if (Array.isArray(value) && value.length) {
				children.push(el('div', { class: 'wpmt-detail-block' }, [
					el('strong', { text: heading + ' (' + value.length + ')' }),
					el('ul', { class: 'wpmt-detail-list' }, value.slice(0, 40).map(function (row) {
						return el('li', { text: String(row) });
					}))
				]));
			} else if (value && typeof value !== 'object') {
				children.push(el('div', { class: 'wpmt-detail-block' }, [
					el('strong', { text: heading + ': ' }),
					el('span', { text: String(value) })
				]));
			}
		});

		if (!children.length) {
			children.push(el('span', { text: __('No extra detail for this check.', 'omninox-site-toolkit') }));
		}

		return el('div', { class: 'wpmt-detail' }, children);
	}

	function table(rows, moduleId) {
		moduleId = moduleId || null;
		var columns = Object.keys(rows[0]);

		// For the redirects module add an actions column so each row can have
		// edit / enable / disable / delete controls.
		if (moduleId === 'redirects' && columns.indexOf('actions') === -1) {
			columns.push('actions');
			rows = rows.map(function (r) { r.actions = r; return r; });
		}

		return el('table', {}, [
			el('thead', {}, [
				el('tr', {}, columns.map(function (column) {
					return el('th', { text: column });
				}))
			]),
			el('tbody', {}, rows.slice(0, 60).map(function (row) {
				return el('tr', {}, columns.map(function (column) {
					var value = row[column];

					// Special-case action rendering for redirect rows.
					if (column === 'actions' && moduleId === 'redirects') {
						var enabled = !!row.enabled;
						var actions = el('span', { class: 'wpmt-row-actions' }, [
							el('button', {
								type: 'button',
								class: 'wpmt-btn wpmt-btn-small',
								text: __('Edit', 'omninox-site-toolkit'),
								onClick: function () {
									var newTo = window.prompt(__('Target (leave blank to keep current)', 'omninox-site-toolkit'), row.to);
									if (newTo === null) { return; }
									var newStatus = window.prompt(__('Status (301,302,410)', 'omninox-site-toolkit'), String(row.status || 301));
									if (newStatus === null) { return; }
									var enableConfirm = window.confirm(__('Enable this rule?', 'omninox-site-toolkit'));
									applyFix(moduleId, 'edit_redirect', {
										original: row.from,
										from: row.from,
										to: newTo || row.to,
										status: parseInt(newStatus, 10) || 301,
										enabled: enableConfirm ? 1 : 0
									});
								}
							}),
							el('button', {
								type: 'button',
								class: 'wpmt-btn wpmt-btn-small',
								text: enabled ? __('Disable', 'omninox-site-toolkit') : __('Enable', 'omninox-site-toolkit'),
								onClick: function () {
									applyFix(moduleId, 'toggle_redirect', { from: row.from, enabled: enabled ? 0 : 1 });
								}
							}),
							el('button', {
								type: 'button',
								class: 'wpmt-btn wpmt-btn-small wpmt-btn-danger',
								text: __('Delete', 'omninox-site-toolkit'),
								onClick: function () {
									if (window.confirm(__('Delete', 'omninox-site-toolkit') + ' ' + row.from + '?')) {
										applyFix(moduleId, 'delete_redirect', { from: row.from });
									}
								}
							})
						]);

						return el('td', {}, [actions]);
					}

					if (typeof value === 'string' && /^https?:\/\//.test(value)) {
						return el('td', {}, [
							el('a', {
								href: value,
								target: '_blank',
								rel: 'noopener',
								text: __('Download', 'omninox-site-toolkit')
							})
						]);
					}

					return el('td', {
						class: typeof value === 'number' ? 'wpmt-mono' : '',
						text: typeof value === 'object' ? JSON.stringify(value) : String(value)
					});
				}));
			}))
		]);
	}

	function actionPanel(module) {
		var visible = module.actions.filter(function (action) { return !action.hidden; });

		if (!visible.length) {
			return el('div', {});
		}

		return el('div', { class: 'wpmt-card' }, [
			el('h2', { text: __('Actions', 'omninox-site-toolkit') }),
			el('div', {}, visible.map(function (action) {
				return actionRow(module, action);
			}))
		]);
	}

	/**
	 * The caption under an action label: what it is now, and what it was before.
	 */
	function actionMeta(action) {
		var parts = [];

		if (action.current !== undefined && action.current !== null && action.current !== '') {
			parts.push(el('span', { class: 'wpmt-meta-now' }, [
				__('Now', 'omninox-site-toolkit') + ': ',
				el('b', { text: formatValue(action.current) })
			]));
		}

		if (action.previous) {
			parts.push(el('span', { class: 'wpmt-meta-was' }, [
				__('Previously', 'omninox-site-toolkit') + ': ',
				el('b', { text: formatValue(action.previous) })
			]));
		}

		var rows = [];

		if (parts.length) {
			rows.push(el('span', { class: 'wpmt-meta' }, parts));
		}

		if (action.history) {
			rows.push(el('span', { class: 'wpmt-meta-history', text: action.history }));
		}

		if (action.notice) {
			rows.push(el('span', { class: 'wpmt-meta-notice', text: action.notice }));
		}

		return rows;
	}

	function formatValue(value) {
		if (value === 1 || value === '1' || value === true) {
			return __('on', 'omninox-site-toolkit');
		}

		if (value === 0 || value === '0' || value === false) {
			return __('off', 'omninox-site-toolkit');
		}

		return String(value);
	}

	/* ---------------------------------------------------- Pro gating UI */

	function proBadge() {
		return el('span', { class: 'wpmt-pro-badge', title: __('Pro feature', 'omninox-site-toolkit') }, [
			icon('lock', 11, 'wpmt-pro-badge-lock'),
			el('span', { text: __('PRO', 'omninox-site-toolkit') })
		]);
	}

	function lockedRow(module, action) {
		var label = el('span', { class: 'wpmt-locked-label' }, [
			el('span', { text: action.label }),
			proBadge()
		].concat(action.notice ? [el('span', { class: 'wpmt-meta-notice', text: action.notice })] : []));

		var cta = el('button', {
			type: 'button',
			class: 'wpmt-btn wpmt-btn-pro',
			onClick: function () { showUpgradeModal(action.label); }
		}, [
			icon('sparkle', 12, 'wpmt-btn-icon'),
			document.createTextNode(' ' + __('Unlock with Pro', 'omninox-site-toolkit'))
		]);

		return el('div', {
			class: 'wpmt-action-row wpmt-locked',
			onClick: function (e) {
				// Clicking anywhere on a locked row opens the upgrade modal.
				if (e.target && e.target.tagName === 'BUTTON') { return; }
				showUpgradeModal(action.label);
			}
		}, [label, cta]);
	}

	function proFeatureList() {
		var map = WPMT.gateMap || {};
		var items = [];

		Object.keys(map).forEach(function (moduleId) {
			var mod = map[moduleId];
			if (!mod || typeof mod !== 'object') { return; }
			Object.keys(mod).forEach(function (actionId) {
				if (mod[actionId] === 'pro') {
					items.push(moduleId);
				}
			});
		});

		// De-duplicate module ids, keep a friendly list.
		var seen = {};
		var labels = [];
		items.forEach(function (id) {
			if (seen[id]) { return; }
			seen[id] = true;
			var mod = moduleById(id);
			if (mod && mod.label) {
				labels.push(mod.label);
			} else {
				// Fallback: turn "white_label" into "White label".
				labels.push(id.replace(/_/g, ' ').replace(/^\w/, function (c) { return c.toUpperCase(); }));
			}
		});

		return labels;
	}

	function showUpgradeModal(featureLabel) {
		closeModal();

		var lic = WPMT.license || {};
		var currentTheme = resolveTheme();
		var overlay = el('div', {
			class: 'wpmt-modal-overlay wpmt-wrap', 'data-theme': currentTheme, onClick: function (e) {
				if (e.target === overlay) { closeModal(); }
			}
		});

		var keyInput = el('input', {
			type: 'text',
			class: 'wpmt-input',
			placeholder: __('Enter your license key', 'omninox-site-toolkit'),
			'aria-label': __('License key', 'omninox-site-toolkit')
		});

		var status = el('p', { class: 'wpmt-modal-status', 'aria-live': 'polite' });

		var activateBtn = el('button', {
			type: 'button',
			class: 'wpmt-btn wpmt-btn-primary',
			text: __('Activate license', 'omninox-site-toolkit'),
			onClick: function () {
				var key = (keyInput.value || '').trim();
				if (!key) {
					status.textContent = __('Enter a license key to activate.', 'omninox-site-toolkit');
					status.className = 'wpmt-modal-status wpmt-modal-status-error';
					return;
				}
				activateBtn.disabled = true;
				activateBtn.textContent = __('Activating…', 'omninox-site-toolkit');
				activateLicense(key).then(function (ok) {
					if (ok) {
						var isProInstalled = !!(WPMT.isProInstalled || (WPMT.license && WPMT.license.pro_installed));
						if (isProInstalled) {
							status.textContent = __('License activated! Unlocking Pro features…', 'omninox-site-toolkit');
							status.className = 'wpmt-modal-status wpmt-modal-status-ok';
							setTimeout(function () { closeModal(); window.location.reload(); }, 1200);
						} else {
							status.textContent = __('License activated successfully! Please install and activate the Omninox Pro companion plugin to use Pro features.', 'omninox-site-toolkit');
							status.className = 'wpmt-modal-status wpmt-modal-status-ok';
							setTimeout(function () { closeModal(); window.location.reload(); }, 2000);
						}
					} else {
						activateBtn.disabled = false;
						activateBtn.textContent = __('Activate license', 'omninox-site-toolkit');
						status.textContent = state.lastLicenseError || __('Activation failed.', 'omninox-site-toolkit');
						status.className = 'wpmt-modal-status wpmt-modal-status-error';
					}
				});
			}
		});

		var featureLine = featureLabel
			? el('p', { class: 'wpmt-modal-lead', text: sprintf(__('%s is a Pro feature.', 'omninox-site-toolkit'), featureLabel) })
			: el('p', { class: 'wpmt-modal-lead', text: __('Unlock every Pro feature in Master Toolkit.', 'omninox-site-toolkit') });

		var proList = proFeatureList();
		var listNode = proList.length
			? el('ul', { class: 'wpmt-modal-list' }, proList.slice(0, 8).map(function (l) {
				return el('li', { text: l });
			}))
			: null;

		var plans = (WPMT.plans && WPMT.plans.single_lifetime) ? WPMT.plans : ((lic && lic.plans) ? lic.plans : null);
		var singlePlan = plans && plans.single_lifetime ? plans.single_lifetime : {
			name: __('Single Site — Lifetime License', 'omninox-site-toolkit'),
			price: '$15',
			billing: __('One-time payment (Pay once, use forever)', 'omninox-site-toolkit'),
			buy_url: WPMT.upgradeUrl || 'https://netinnovix.com/plugins/omninox-site-toolkit/buy'
		};

		var planBox = el('div', { class: 'wpmt-plan-card', style: 'background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.35); border-radius: 8px; padding: 14px 16px; margin: 12px 0 16px;' }, [
			el('div', { style: 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;' }, [
				el('strong', { style: 'font-size: 15px; color: #0f172a;', text: singlePlan.name || __('Single Site — Lifetime Plan', 'omninox-site-toolkit') }),
				el('span', { style: 'font-size: 18px; font-weight: 700; color: #16a34a; background: #dcfce7; padding: 2px 10px; border-radius: 20px;', text: singlePlan.price || '$15' })
			]),
			el('p', { style: 'margin: 0 0 6px; font-size: 12px; color: #475569;', text: singlePlan.billing || __('One-time purchase for 1 Site • No monthly or annual renewal fees', 'omninox-site-toolkit') })
		]);

		var upgradeLink = el('a', {
			class: 'wpmt-btn wpmt-btn-pro',
			href: singlePlan.buy_url || WPMT.upgradeUrl || 'https://netinnovix.com/plugins/omninox-site-toolkit/buy',
			target: '_blank',
			rel: 'noopener',
			text: sprintf(__('Get Omninox Pro — %s One-Time', 'omninox-site-toolkit'), singlePlan.price || '$15')
		});

		var modal = el('div', { class: 'wpmt-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': __('Upgrade to Pro', 'omninox-site-toolkit') }, [
			el('button', { type: 'button', class: 'wpmt-modal-close', 'aria-label': __('Close', 'omninox-site-toolkit'), text: '\u00D7', onClick: closeModal }),
			el('h2', { class: 'wpmt-modal-title', text: __('Upgrade to Pro', 'omninox-site-toolkit') }),
			featureLine,
			planBox,
			listNode,
			el('div', { class: 'wpmt-modal-cta' }, [upgradeLink]),
			el('hr', { class: 'wpmt-modal-sep' }),
			el('p', { class: 'wpmt-modal-lead', text: __('Already have a key?', 'omninox-site-toolkit') }),
			el('div', { class: 'wpmt-modal-form' }, [keyInput, activateBtn]),
			lic.test_mode ? el('p', { class: 'wpmt-meta-notice', text: __('Test mode is on. Use the sample key to validate against the test server.', 'omninox-site-toolkit') }) : null,
			status
		]);

		overlay.appendChild(modal);
		document.body.appendChild(overlay);
		state.modalOpen = true;

		// Focus trap starting point + Escape to close.
		keyInput.focus();
		document.addEventListener('keydown', escClose);
	}

	function escClose(e) {
		if (e.key === 'Escape') { closeModal(); }
	}

	function closeModal() {
		var existing = document.querySelector('.wpmt-modal-overlay');
		if (existing) { existing.remove(); }
		state.modalOpen = false;
		document.removeEventListener('keydown', escClose);
	}

	function activateLicense(key) {
		state.lastLicenseError = '';
		return api('/license', {
			method: 'POST',
			body: { key: key },
			label: __('Validating license…', 'omninox-site-toolkit')
		}).then(function (res) {
			if (!res) {
				state.lastLicenseError = __('No response from license server.', 'omninox-site-toolkit');
				return false;
			}
			if (res.error || (res.message && !res.success && !res.license)) {
				state.lastLicenseError = res.error || res.message;
				return false;
			}
			var lic = res.license || res;
			WPMT.license = lic;
			var is_ok = !!(res.success || (res.license && (res.license.premium || res.license.status === 'active' || res.license.status === 'valid')) || res.premium || res.status === 'active' || res.status === 'valid');
			if (!is_ok) {
				state.lastLicenseError = res.error || res.message || __('Activation failed.', 'omninox-site-toolkit');
			}
			return is_ok;
		}).catch(function (error) {
			state.lastLicenseError = error.message || __('Activation failed.', 'omninox-site-toolkit');
			return false;
		});
	}

	function deactivateLicense() {
		return api('/license', {
			method: 'DELETE',
			label: __('Deactivating license…', 'omninox-site-toolkit')
		}).then(function (res) {
			var lic = (res && res.license) ? res.license : res;
			WPMT.license = lic;
			toast(__('License deactivated.', 'omninox-site-toolkit'));
			setTimeout(function () { window.location.reload(); }, 800);
		}).catch(function (error) {
			toast(error.message || __('Could not deactivate.', 'omninox-site-toolkit'), true);
		});
	}

	// Minimal %s substitution for a single argument.
	function actionRow(module, action) {
		if (action.type === 'original_compression') { return originalCompressionPanel(); }
		var key = module.id + ':' + action.id;

		// Pro gating: a locked action shows a PRO badge and an upgrade prompt
		// instead of a live control. The server enforces this too — the UI
		// lock is convenience, never the security boundary.
		if (action.locked) {
			return lockedRow(module, action);
		}

		var label = el('span', {}, [el('span', { text: action.label })].concat(actionMeta(action)));

		if ('search_replace' === action.id) {
			return searchReplaceForm(module, action, label);
		}

		if (action.type === 'toggle') {
			var isChecked = action.current === 1 || action.current === '1' || action.current === true || action.current === 'on';

			return el('label', { class: 'wpmt-toggle' }, [
				label,
				el('input', {
					type: 'checkbox',
					role: 'switch',
					'aria-checked': isChecked ? 'true' : 'false',
					'aria-label': action.label || __('Toggle setting', 'omninox-site-toolkit'),
					checked: isChecked,
					disabled: !!state.busy[key],
					onChange: function (event) {
						event.target.setAttribute('aria-checked', event.target.checked ? 'true' : 'false');
						applyFix(module.id, action.id, { value: event.target.checked ? 1 : 0 });
					}
				})
			]);
		}

		if (action.type === 'select') {
			var options = (action.options || []).map(function (option) {
				return [String(option.value), option.label];
			});

			// Pre-select whatever is closest to the value in force right now.
			var chosen = options.length ? options[0][0] : '';

			options.forEach(function (option) {
				if (String(action.current).indexOf(option[1]) === 0) {
					chosen = option[0];
				}
			});

			var picker = select(options, chosen, function (value) { chosen = value; });

			return el('div', { class: 'wpmt-toggle' }, [
				label,
				el('span', { class: 'wpmt-control-group' }, [
					picker,
					el('button', {
						type: 'button',
						class: 'wpmt-btn',
						disabled: !!state.busy[key],
						text: state.busy[key] ? __('Applying…', 'omninox-site-toolkit') : __('Apply', 'omninox-site-toolkit'),
						onClick: function () {
							applyFix(module.id, action.id, { value: chosen });
						}
					})
				])
			]);
		}

		if (action.type === 'inspector') {
			return el('div', {}, [
				el('div', { class: 'wpmt-toggle' }, [
					label,
					el('button', {
						type: 'button',
						class: 'wpmt-btn',
						disabled: !!state.busy[key],
						text: state.busy[key]
							? __('Loading…', 'omninox-site-toolkit')
							: (state.inspect[key] ? __('Refresh', 'omninox-site-toolkit') : __('Open', 'omninox-site-toolkit')),
						onClick: function () { runInspector(module.id, action.id); }
					})
				]),
				state.inspect[key] ? inspectorTable(state.inspect[key]) : null
			]);
		}

		if (action.id === 'search_replace' || action.type === 'search_replace') {
			return searchReplaceForm(module, action, label);
		}

		if (action.type === 'form') {
			var values = {};

			var inputs = (action.fields || []).map(function (field) {
				values[field.id] = field.type === 'select' && field.options && field.options.length
					? String(field.options[0].value)
					: '';

				var control = field.type === 'select'
					? select(
						(field.options || []).map(function (option) { return [String(option.value), option.label]; }),
						values[field.id],
						function (value) { values[field.id] = value; }
					)
					: el('input', {
						type: 'text',
						placeholder: field.placeholder || '',
						onInput: function (event) { values[field.id] = event.target.value; }
					});

				return el('label', { class: 'wpmt-field' }, [
					el('span', { text: field.label }),
					control
				]);
			});

			return el('div', { class: 'wpmt-form-action' }, [
				label,
				el('div', { class: 'wpmt-form-fields' }, inputs),
				el('button', {
					type: 'button',
					class: 'wpmt-btn',
					disabled: !!state.busy[key],
					text: state.busy[key] ? __('Working…', 'omninox-site-toolkit') : (action.submit || __('Run', 'omninox-site-toolkit')),
					onClick: function () {
						if (action.confirm && !window.confirm(action.confirm)) {
							return;
						}

						runInspector(module.id, action.id, values);
					}
				}),
				state.inspect[key] ? inspectorTable(state.inspect[key]) : null,
				state.inspect[key] && state.inspect[key].can_revert
					? el('p', { class: 'wpmt-search-revert' }, [
						el('span', { text: __('A reversible snapshot was saved for this live replacement.', 'omninox-site-toolkit') }),
						el('button', {
							type: 'button',
							class: 'wpmt-btn',
							text: __('Revert this replacement', 'omninox-site-toolkit'),
							onClick: function () {
								if (window.confirm(__('Restore the values changed by this replacement?', 'omninox-site-toolkit'))) {
									applyFix(module.id, 'revert_search_replace', { history_id: state.inspect[key].history_id });
								}
							}
						})
					])
					: null
			]);
		}

		if (action.type === 'upload') {
			var picker = filePicker(action.accept || '');

			return el('div', { class: 'wpmt-form-action' }, [
				label,
				el('div', { class: 'wpmt-form-fields' }, [picker.node]),
				el('button', {
					type: 'button',
					class: 'wpmt-btn',
					disabled: !!state.busy[key],
					text: state.busy[key] ? __('Uploading…', 'omninox-site-toolkit') : (action.submit || __('Upload', 'omninox-site-toolkit')),
					onClick: function () {
						var file = picker.input.files && picker.input.files[0];

						if (!file) {
							toast(__('Choose a file first.', 'omninox-site-toolkit'), true);
							return;
						}

						uploadFileChunked(module.id, action.id, file);
					}
				}),
				(state.busy[key] || (state.progress && state.progress[key])) ? progressBar(key) : null
			]);
		}

		if (action.type === 'backuplist') {
			return el('div', {}, [
				el('div', { class: 'wpmt-toggle' }, [
					label,
					el('button', {
						type: 'button',
						class: 'wpmt-btn',
						disabled: !!state.busy[key],
						text: state.busy[key] ? __('Loading…', 'omninox-site-toolkit') : (action.submit || __('Show', 'omninox-site-toolkit')),
						onClick: function () { runInspector(module.id, action.id); }
					})
				]),
				state.inspect[key] ? backupList(module.id, state.inspect[key].rows || []) : null
			]);
		}

		if (action.type === 'selector') {
			return mediaSelector(module, action, key);
		}

		if (action.type === 'select' || action.type === 'toggle') {
			var current = action.current !== undefined ? action.current : '';
			var control = action.type === 'select'
				? select(
					(action.options || []).map(function (option) { return [String(option.value), option.label]; }),
					String(current),
					function (value) { current = value; }
				)
				: el('input', {
					type: 'checkbox',
					role: 'switch',
					'aria-checked': !!Number(current) ? 'true' : 'false',
					'aria-label': action.label || __('Toggle setting', 'omninox-site-toolkit'),
					checked: !!Number(current),
					onChange: function (event) {
						current = event.target.checked ? 1 : 0;
						event.target.setAttribute('aria-checked', event.target.checked ? 'true' : 'false');
					}
				});

			return el('div', { class: 'wpmt-toggle' }, [
				label,
				el('span', { class: 'wpmt-control-group' }, [
					control,
					el('button', {
						type: 'button',
						class: 'wpmt-btn',
						disabled: !!state.busy[key],
						text: state.busy[key] ? __('Saving…', 'omninox-site-toolkit') : __('Save', 'omninox-site-toolkit'),
						onClick: function () {
							applyFix(module.id, action.id, { value: current });
						}
					})
				])
			]);
		}

		if (action.type === 'filemanager') {
			return fileManager(module, action);
		}

		if (action.type === 'number') {
			var input = el('input', { type: 'number', min: '1', style: 'width:100px' });

			return el('div', { class: 'wpmt-toggle' }, [
				label,
				el('span', { class: 'wpmt-control-group' }, [
					input,
					el('button', {
						type: 'button',
						class: 'wpmt-btn',
						text: __('Apply', 'omninox-site-toolkit'),
						onClick: function () { applyFix(module.id, action.id, { value: input.value }); }
					})
				])
			]);
		}

		// Generic button/batch/form action — collects form fields whose
		// values are forwarded as parameters.
		var formInputs = (action.fields || []).map(function (field) {
			state.formValues = state.formValues || {};
			var formKey = key + ':' + field.id;
			if (state.formValues[formKey] === undefined) {
				state.formValues[formKey] = field.value || (field.options && field.options.length ? field.options[0].value : '');
			}

			var control;
			if (field.type === 'select') {
				control = el('select', {
					class: 'wpmt-select',
					name: field.id,
					'data-field-id': field.id,
					onChange: function (event) { state.formValues[formKey] = event.target.value; }
				}, (field.options || []).map(function (opt) {
					return el('option', {
						value: opt.value,
						text: opt.label,
						selected: opt.value === state.formValues[formKey]
					});
				}));
			} else if (field.type === 'textarea') {
				control = el('textarea', {
					class: 'wpmt-textarea',
					rows: field.rows || 4,
					style: 'width:100%;font-family:Consolas,Monaco,monospace;font-size:13px;padding:8px 10px;border-radius:6px;border:1px solid var(--wpmt-border,#dcdcde);background:var(--wpmt-surface,#fff);color:var(--wpmt-ink,#2c3338);',
					placeholder: field.placeholder || '',
					name: field.id,
					'data-field-id': field.id,
					onInput: function (event) { state.formValues[formKey] = event.target.value; },
					onChange: function (event) { state.formValues[formKey] = event.target.value; }
				});
				control.value = state.formValues[formKey] || '';
			} else {
				control = el('input', {
					type: field.type || 'text',
					placeholder: field.placeholder || '',
					value: state.formValues[formKey] || '',
					name: field.id,
					'data-field-id': field.id,
					onInput: function (event) { state.formValues[formKey] = event.target.value; },
					onChange: function (event) { state.formValues[formKey] = event.target.value; }
				});
			}

			return el('label', { class: 'wpmt-field' }, [
				el('span', { text: field.label }),
				control
			]);
		});

		var formContainer = el('div', { class: 'wpmt-action-form-wrap' }, [
			el('div', { class: 'wpmt-toggle' }, [
				label,
				el('button', {
					type: 'button',
					class: 'wpmt-btn',
					disabled: !!state.busy[key],
					text: state.busy[key] ? __('Working…', 'omninox-site-toolkit') : (action.submit || __('Run', 'omninox-site-toolkit')),
					onClick: function (event) {
						if (action.confirm && !window.confirm(action.confirm)) {
							return;
						}

						var currentValues = {};
						(action.fields || []).forEach(function (field) {
							var formKey = key + ':' + field.id;
							currentValues[field.id] = state.formValues[formKey] || '';
						});

						// Also query live DOM values in this container if present
						var domFields = formContainer.querySelectorAll('input, select, textarea');
						for (var i = 0; i < domFields.length; i++) {
							var df = domFields[i];
							var fid = df.getAttribute('data-field-id') || df.name;
							if (fid) {
								currentValues[fid] = df.value;
								state.formValues[key + ':' + fid] = df.value;
							}
						}

						if (action.type === 'batch') {
							runBatch(module.id, action.id, [], currentValues);
						} else {
							applyFix(module.id, action.id, currentValues);
						}
					}
				})
			]),
			formInputs.length ? el('div', { class: 'wpmt-form-fields' }, formInputs) : null,
			(state.busy[key] || (state.progress && state.progress[key])) ? progressBar(key) : null,
			state.inspect[key] ? inspectorTable(state.inspect[key]) : null
		]);

		return formContainer;
	}

	function searchReplaceForm(module, action, label) {
		var key = module.id + ':' + action.id;
		state.searchReplace = state.searchReplace || {
			search: '',
			replace: '',
			mode: 'dry_run',
			tables: [],
			case_sensitive: 'no'
		};
		var draft = state.searchReplace;
		if (!Array.isArray(draft.tables)) {
			draft.tables = draft.tables && 'all' !== draft.tables ? String(draft.tables).split(',') : [];
		}

		// Extract tables list from action definition
		var tablesField = (action.fields || []).find(function (f) { return f.id === 'tables'; });
		var rawTables = (tablesField && tablesField.options && tablesField.options.length) ? tablesField.options : [];
		var tableList = [];
		rawTables.forEach(function (tbl) {
			if (['all', 'posts', 'postmeta', 'options', 'users'].indexOf(tbl.value) === -1) {
				tableList.push(tbl);
			}
		});
		if (!tableList.length) {
			tableList = rawTables;
		}

		var modeOptions = [
			['dry_run', __('Dry Run (Preview changes only - safe)', 'omninox-site-toolkit')],
			['live_run', __('Live Run (Update database)', 'omninox-site-toolkit')]
		];
		var caseOptions = [
			['no', __('Case-insensitive (default)', 'omninox-site-toolkit')],
			['yes', __('Case-sensitive', 'omninox-site-toolkit')]
		];

		var runBtn = null;

		var searchInput = el('input', {
			type: 'text',
			placeholder: __('String, domain, URL, or text to find…', 'omninox-site-toolkit'),
			value: draft.search || '',
			onInput: function (event) {
				draft.search = event.target.value;
				if (runBtn) { runBtn.disabled = !(draft.search || '').trim() || !(draft.replace || '').trim(); }
			},
			onChange: function (event) { draft.search = event.target.value; }
		});

		var replaceInput = el('input', {
			type: 'text',
			placeholder: __('Replacement text or new URL…', 'omninox-site-toolkit'),
			value: draft.replace || '',
			onInput: function (event) {
				draft.replace = event.target.value;
				if (runBtn) { runBtn.disabled = !(draft.search || '').trim() || !(draft.replace || '').trim(); }
			},
			onChange: function (event) { draft.replace = event.target.value; }
		});

		// 1. Top row: Search for | Replace with side-by-side (50% each)
		var topRow = el('div', { class: 'wpmt-sr-grid-2' }, [
			el('label', { class: 'wpmt-field' }, [
				el('span', { text: __('Search for', 'omninox-site-toolkit') }),
				searchInput
			]),
			el('label', { class: 'wpmt-field' }, [
				el('span', { text: __('Replace with', 'omninox-site-toolkit') }),
				replaceInput
			])
		]);

		// 2. Middle: Multi-select tables box (100% full width with sizes & quick selection buttons)
		var selectEl = el('select', {
			multiple: true,
			size: 8,
			class: 'wpmt-select-multiple',
			onChange: function (event) {
				var selected = [];
				for (var i = 0; i < event.target.options.length; i++) {
					if (event.target.options[i].selected) {
						selected.push(event.target.options[i].value);
					}
				}
				draft.tables = selected;
			}
		}, tableList.map(function (tbl) {
			var isSelected = Array.isArray(draft.tables) && draft.tables.indexOf(tbl.value) !== -1;
			return el('option', {
				value: tbl.value,
				text: tbl.label || tbl.value,
				selected: isSelected
			});
		}));

		var tableHelperBar = el('div', { class: 'wpmt-table-helper-bar' }, [
			el('small', {
				text: __('Select multiple tables with Ctrl-Click for Windows or Cmd-Click for Mac.', 'omninox-site-toolkit')
			}),
			el('div', { class: 'wpmt-table-helper-buttons' }, [
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small',
					text: __('Select all', 'omninox-site-toolkit'),
					onClick: function () {
						draft.tables = tableList.map(function (t) { return t.value; });
						render();
					}
				}),
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small',
					text: __('Core WP tables', 'omninox-site-toolkit'),
					onClick: function () {
						draft.tables = tableList
							.filter(function (t) {
								return /posts|postmeta|options|users|usermeta|comments|commentmeta|terms|term_taxonomy/.test(t.value);
							})
							.map(function (t) { return t.value; });
						render();
					}
				}),
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small',
					text: __('Clear selection', 'omninox-site-toolkit'),
					onClick: function () {
						draft.tables = [];
						render();
					}
				})
			])
		]);

		var tablesSection = el('div', { class: 'wpmt-sr-tables-block' }, [
			el('label', {
				text: __('Select tables', 'omninox-site-toolkit')
			}),
			selectEl,
			tableHelperBar
		]);

		// 3. Execution Controls: Execution mode & Case sensitivity (2 columns)
		var modeSelect = el('select', {
			class: 'wpmt-select',
			onChange: function (event) { draft.mode = event.target.value; }
		}, modeOptions.map(function (opt) {
			return el('option', { value: opt[0], text: opt[1], selected: opt[0] === (draft.mode || 'dry_run') });
		}));

		var caseSelect = el('select', {
			class: 'wpmt-select',
			onChange: function (event) { draft.case_sensitive = event.target.value; }
		}, caseOptions.map(function (opt) {
			return el('option', { value: opt[0], text: opt[1], selected: opt[0] === (draft.case_sensitive || 'no') });
		}));

		var bottomControls = el('div', { class: 'wpmt-sr-grid-2' }, [
			el('label', { class: 'wpmt-field' }, [
				el('span', { text: __('Execution mode', 'omninox-site-toolkit') }),
				modeSelect
			]),
			el('label', { class: 'wpmt-field' }, [
				el('span', { text: __('Case sensitivity', 'omninox-site-toolkit') }),
				caseSelect
			])
		]);

		// 4. Run Search & Replace Button
		runBtn = el('button', {
			type: 'button',
			class: 'wpmt-btn wpmt-btn-primary',
			disabled: !!state.busy[key] || !(draft.search || '').trim() || !(draft.replace || '').trim(),
			text: state.busy[key] ? __('Working…', 'omninox-site-toolkit') : (action.submit || __('Run Search & Replace', 'omninox-site-toolkit')),
			onClick: function () {
				var tablesPayload = draft.tables && draft.tables.length ? draft.tables.join(',') : 'all';

				runInspector(module.id, action.id, {
					search: draft.search,
					replace: draft.replace,
					tables: tablesPayload,
					mode: draft.mode || 'dry_run',
					case_sensitive: draft.case_sensitive || 'no'
				});
			}
		});

		return el('div', { class: 'wpmt-form-action' }, [
			label,
			topRow,
			tablesSection,
			bottomControls,
			el('div', { style: 'margin-top:14px;' }, [runBtn]),
			state.inspect[key] ? inspectorTable(state.inspect[key]) : null,
			state.inspect[key] && state.inspect[key].can_revert ? el('p', { class: 'wpmt-search-revert' }, [
				el('span', { text: __('A reversible snapshot was saved for this live replacement.', 'omninox-site-toolkit') }),
				el('button', {
					type: 'button',
					class: 'wpmt-btn',
					text: __('Revert this replacement', 'omninox-site-toolkit'),
					onClick: function () {
						if (window.confirm(__('Restore the values changed by this replacement?', 'omninox-site-toolkit'))) {
							applyFix(module.id, 'revert_search_replace', { history_id: state.inspect[key].history_id });
						}
					}
				})
			]) : null
		]);
	}

	/**
	 * Recent environment changes, so the old value is never lost.
	 */
	function changeLog(changes) {
		if (!changes || !changes.length) {
			return null;
		}

		return el('div', { class: 'wpmt-card' }, [
			el('h2', { text: __('Recent changes', 'omninox-site-toolkit') }),
			el('table', { class: 'wpmt-table' }, [
				el('thead', {}, [
					el('tr', {}, [
						el('th', { text: __('Setting', 'omninox-site-toolkit') }),
						el('th', { text: __('Was', 'omninox-site-toolkit') }),
						el('th', { text: __('Now', 'omninox-site-toolkit') }),
						el('th', { text: __('When', 'omninox-site-toolkit') }),
						el('th', { text: __('Who', 'omninox-site-toolkit') })
					])
				]),
				el('tbody', {}, changes.map(function (change) {
					return el('tr', {}, [
						el('td', { text: change.label || change.key }),
						el('td', { class: 'wpmt-mono wpmt-was', text: formatValue(change.from) }),
						el('td', { class: 'wpmt-mono', text: formatValue(change.to) }),
						el('td', { text: change.when || __('just now', 'omninox-site-toolkit') }),
						el('td', { text: change.user || '—' })
					]);
				}))
			])
		]);
	}

	function loadOverview() {
		return api('/overview')
			.then(function (data) {
				state.overview = data;
			})
			.catch(function () { });
	}

	/* ---------------------------------------------------------------- Action */

	function runScan(id) {
		state.busy[id] = true;
		render();

		return api('/scan/' + id, { method: 'POST', body: { args: {} } })
			.then(function (result) {
				state.results[id] = result;
				state.busy[id] = false;
				render();
				return loadOverview();
			})
			.catch(function (error) {
				state.busy[id] = false;
				render();
				toast(error.message, true);
			});
	}

	function loadModules() {
		return api('/modules')
			.then(function (modules) {
				if (Array.isArray(modules) && modules.length) {
					WPMT.modules = modules;
				}
			})
			.catch(function () { });
	}

	function applyFix(moduleId, action, params) {
		var key = moduleId + ':' + action;

		// Track previous value in case request fails or reverts
		var mod = moduleById(moduleId);
		var previousVal = undefined;
		if (mod && mod.actions) {
			mod.actions.forEach(function (act) {
				if (act.id === action) {
					previousVal = act.current;
					if (params && params.value !== undefined) {
						act.current = params.value;
					}
				}
			});
		}

		state.busy[key] = true;
		render();

		api('/fix/' + moduleId, {
			method: 'POST',
			body: { action: action, params: params },
			label: __('Applying fix safely…', 'omninox-site-toolkit')
		})
			.then(function (result) {
				var note = fixSummary(result);

				if (result.previous !== undefined && result.current !== undefined && !result.message) {
					note = formatValue(result.previous) + ' → ' + formatValue(result.current);
				}

				// Authoritatively sync action state from server response immediately
				if (mod && mod.actions) {
					mod.actions.forEach(function (act) {
						if (act.id === action) {
							if (result.enabled !== undefined) {
								act.current = result.enabled ? 1 : 0;
							} else if (result.authoritative !== undefined) {
								act.current = result.authoritative ? 1 : 0;
							} else if (result.current !== undefined) {
								act.current = (result.current === 'on' || result.current === 1 || result.current === true) ? 1 : 0;
							}
							if (result.previous !== undefined) {
								act.previous = result.previous;
							}
						}
					});
				}

				if (result && (result.rows || result.output !== undefined || result.body !== undefined || result.result !== undefined || result.status !== undefined || result.affected_rows !== undefined)) {
					state.inspect[key] = result;
					state.inspector = state.inspector || {};
					state.inspector[moduleId] = result;
				}

				// Unblock UI and notify user immediately so button doesn't freeze during slow background scan
				state.busy[key] = false;
				render();
				toast(note);

				return Promise.all([loadModules(), loadSettings(), loadChanges()])
					.then(function () {
						return Promise.all([runScan(moduleId), loadOverview()]);
					})
					.then(function () {
						render();
					})
					.catch(function () {
						render();
					});
			})
			.catch(function (error) {
				// Revert optimistic toggle state on failure
				if (previousVal !== undefined && mod && mod.actions) {
					mod.actions.forEach(function (act) {
						if (act.id === action) {
							act.current = previousVal;
						}
					});
				}
				state.busy[key] = false;
				render();
				toast(error.message || __('Could not apply fix.', 'omninox-site-toolkit'), true);
			});
	}

	function fixSummary(result) {
		var note = result.message || __('Fix applied successfully!', 'omninox-site-toolkit');
		var changes = (result.changes || []).map(function (change) {
			return (change.label || __('Change', 'omninox-site-toolkit')) + ': ' + formatValue(change.from) + ' → ' + formatValue(change.to);
		});
		var files = result.files_modified || result.files;

		if (changes.length) {
			note += ' ' + changes.join('; ') + '.';
		}

		if (Array.isArray(files) && files.length > 0) {
			note += ' ' + __('Files modified', 'omninox-site-toolkit') + ': ' + files.join(', ') + '.';
		} else if (Array.isArray(files) && files.length === 0 && (result.files_modified !== undefined || result.files !== undefined)) {
			note += ' ' + __('Files modified: none.', 'omninox-site-toolkit');
		}

		return note;
	}

	/**
	 * Drive a batched action (backup, restore, optimise). Each call sends an
	 * incremented offset until the server responds with done:true.
	 *
	 * @param {string} moduleId    Module slug.
	 * @param {string} action      Action id.
	 * @param {Array}  ids         Optional item IDs for bulk media actions.
	 * @param {Object} fixedParams Extra params merged into every request (e.g.
	 *                             { file: 'site-backup.zip' } for restore_full).
	 */
	function originalCompressionPanel() {
		var host = el('section', { class: 'wpmt-action-form-wrap', 'aria-label': 'Original Image Compression' });
		var settings = state.originalCompressionSettings || (state.originalCompressionSettings = {
			target: '102400', custom_kb: '100', quality_floor: '82', backup: 'yes', retention_days: '30',
			strip_metadata: 'no', lossy_png: 'no', keep_smaller: 'yes', force: 'no', mime: '',
			min_kb: '0', max_kb: '0', date_from: '', date_to: '', exclude: ''
		});
		var busy = false, current = null, page = null;
		var results = el('div', { 'aria-live': 'polite' });
		var history = el('div', {});
		var controls = [];
		function field(key, label, choices, type) {
			var control = choices ? el('select', { class: 'wpmt-select', style: 'width:100%;min-width:0;max-width:100%;box-sizing:border-box', onChange: function (e) { settings[key] = e.target.value; } },
				choices.map(function (c) { return el('option', { value: c[0], text: c[1], selected: settings[key] === c[0] }); })) :
				el('input', { type: type || 'number', value: settings[key], min: '0', onChange: function (e) { settings[key] = e.target.value; } });
			controls.push(control);
			return el('label', { class: 'wpmt-field' }, [el('span', { text: label }), control]);
		}
		function call(action, params) { return api('/fix/media', { method: 'POST', silent: action === 'compression_step' || action === 'compression_status' || action === 'compression_history', body: { action: action, params: params || {} } }); }
		function button(label, handler, disabled) { return el('button', { type: 'button', class: 'wpmt-btn', text: label, disabled: !!disabled, onClick: handler }); }
		function bytes(n) { return Number(n || 0).toLocaleString() + ' bytes'; }
		function invoke(action, params) {
			if (busy) { return; } busy = true;
			return call(action, params).then(function (job) { current = job; draw(); }).catch(function (e) { toast(e.message, true); }).finally(function () { busy = false; });
		}
		function draw() {
			results.textContent = '';
			if (!current) { return; }
			var j = current, selected = {};
			results.appendChild(el('h4', { text: 'Job: ' + j.status }));
			results.appendChild(el('p', { text: j.message }));
			if (j.last_error) { results.appendChild(el('p', { text: j.last_error, role: 'alert' })); }
			var metrics = [
				['Eligible images', j.eligible], ['Processed', j.processed], ['Actually optimized', j.optimized],
				['Unchanged', j.unchanged], ['Skipped', j.skipped], ['Failed', j.failed],
				['Measured original total (processed files)', bytes(j.before)], ['Measured final total at processing', bytes(j.after)],
				['Verified savings at processing', bytes(j.saved)], ['Weighted reduction', j.ratio + '%'],
				['Restored', j.restored], ['Restore failures', j.restore_failed]
			];
			results.appendChild(el('table', { class: 'widefat striped' }, [el('tbody', {}, metrics.map(function (m) {
				return el('tr', {}, [el('th', { scope: 'row', text: m[0] }), el('td', { text: String(m[1]) })]);
			}))]));
			if (j.restored) { results.appendChild(el('p', { text: 'Compression totals are historical. Restored files no longer retain those savings.' })); }
			var actions = el('div', { class: 'wpmt-form-fields' });
			if (j.status === 'running' || j.status === 'discovering') { actions.appendChild(button('Pause', function () { invoke('compression_pause', { job_id: j.id }); })); }
			if (j.status === 'paused') { actions.appendChild(button('Resume', function () { invoke('compression_resume', { job_id: j.id }); })); }
			if (['running', 'discovering', 'paused', 'restoring'].indexOf(j.status) >= 0) {
				actions.appendChild(button('Cancel remaining work', function () { invoke('compression_cancel', { job_id: j.id }); }));
			}
			if (['completed', 'cancelled', 'paused'].indexOf(j.status) >= 0) {
				actions.appendChild(button('Restore all from this job', function () {
					if (window.confirm('Restore backed-up originals from this job? Changed files will be protected.')) { invoke('compression_restore', { job_id: j.id }); }
				}));
				actions.appendChild(button('Restore selected results', function () {
					var ids = Object.keys(selected).filter(function (id) { return selected[id]; }).map(Number);
					if (!ids.length) { toast('Select result rows first.', true); return; }
					if (window.confirm('Restore the selected original images?')) { invoke('compression_restore', { job_id: j.id, ids: ids }); }
				}));
			}
			results.appendChild(actions);
			var rows = (j.results || []).map(function (row) {
				return el('tr', {}, [
					el('td', {}, [el('input', { type: 'checkbox', 'aria-label': 'Select ' + row.file, onChange: function (e) { selected[row.id] = e.target.checked; } })]),
					el('td', { text: row.file || ('Attachment ' + row.id) }), el('td', { text: bytes(row.before) }),
					el('td', { text: bytes(row.after) }), el('td', { text: bytes(row.saved) + (row.before ? ' (' + (100 * row.saved / row.before).toFixed(1) + '%)' : '') }),
					el('td', { text: row.restored ? 'Restored' : row.status.replace(/_/g, ' ') + (row.status === 'optimized' && !row.target_reached ? ' — target not reached' : '') }),
					el('td', { text: row.message || '' })
				]);
			});
			results.appendChild(el('div', { style: 'overflow-x:auto' }, [el('table', { class: 'widefat striped' }, [
				el('thead', {}, [el('tr', {}, ['Select', 'Image', 'Before', 'After', 'Saved', 'Status', 'Details'].map(function (s) { return el('th', { text: s }); }))]), el('tbody', {}, rows)
			])]));
			results.appendChild(button('Previous results', function () { page = Math.max(0, j.result_offset - 50); invoke('compression_status', { job_id: j.id, result_offset: page }); }, j.result_offset === 0));
			results.appendChild(button('Next results', function () { page = j.result_offset + 50; invoke('compression_status', { job_id: j.id, result_offset: page }); }, j.result_offset + 50 >= j.processed));
			if ((j.recovery_history || []).length) {
				results.appendChild(el('details', {}, [el('summary', { text: 'Recovery history' }), el('ul', {}, j.recovery_history.map(function (r) {
					return el('li', { text: '#' + r.id + ': ' + r.status + ' — ' + new Date(r.at * 1000).toLocaleString() + (r.message ? ': ' + r.message : '') });
				}))]));
			}
		}
		host.appendChild(el('h3', { text: 'Original Image Compression' }));
		host.appendChild(el('p', { text: 'JPEG/JPG → JPEG/JPG; PNG → PNG. No resizing or WebP/AVIF conversion. WordPress scaled copies and thumbnails remain unchanged. A quality floor reduces risk but cannot guarantee visually lossless JPEGs. Preview important images and retain backups.' }));
		host.appendChild(el('div', { class: 'wpmt-form-fields' }, [
			field('target', 'Target', [['102400', '100 KB'], ['153600', '150 KB'], ['204800', '200 KB'], ['307200', '300 KB'], ['custom', 'Custom']]),
			field('custom_kb', 'Custom target (KB)'), field('quality_floor', 'JPEG quality floor (75–95; default 82)'),
			field('keep_smaller', 'When target cannot be reached', [['yes', 'Keep a verified smaller result'], ['no', 'Leave original unchanged']]),
			field('backup', 'Retain recoverable originals', [['yes', 'Yes'], ['no', 'No — only transaction recovery']]), field('retention_days', 'Backup retention days (1–3650)'),
			field('strip_metadata', 'Remove optional JPEG metadata', [['no', 'No'], ['yes', 'I consent (EXIF and ICC retained)']]),
			field('lossy_png', 'Optional lossy PNG', [['no', 'No — lossless only'], ['yes', 'I consent to conservative quantization (Imagick only)']]),
			field('force', 'Force recompress', [['no', 'No — protect previous generations'], ['yes', 'I accept additional JPEG generation loss']]),
			field('mime', 'File type', [['', 'JPEG and PNG'], ['image/jpeg', 'JPEG/JPG'], ['image/png', 'PNG']]),
			field('min_kb', 'Minimum source KB'), field('max_kb', 'Maximum source KB (0 = no limit)'),
			field('date_from', 'Uploaded from', null, 'date'), field('date_to', 'Uploaded through', null, 'date'),
			field('exclude', 'Exclude attachment IDs (comma separated)', null, 'text')
		]));
		var selection = state.compressionSelection || [];
		host.appendChild(el('p', { text: selection.length ? selection.length + ' selected attachment(s).' : 'All eligible original JPEG/PNG images matching these filters.' }));
		if (selection.length) { host.appendChild(button('Use all eligible images', function () { state.compressionSelection = []; render(); })); }
		host.appendChild(button('Start compression', function () {
			if (!window.confirm('Rewrite the selected original JPEG/PNG files using these settings? The target size is not guaranteed.')) { return; }
			var params = Object.assign({}, settings, { ids: selection, request_id: Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) });
			params.target = settings.target === 'custom' ? Math.round(Number(settings.custom_kb) * 1024) : Number(settings.target);
			params.min_bytes = Math.round(Number(settings.min_kb) * 1024); params.max_bytes = Math.round(Number(settings.max_kb) * 1024);
			page = null; invoke('compression_start', params);
		}));
		host.appendChild(el('p', { text: 'Jobs checkpoint each image and continue through WordPress cron when the browser closes. Cron must be running. Private backups use server storage outside the website; temporary-directory cleanup can remove them unless OMNINOX_COMPRESSION_STORAGE points to persistent private storage.' }));
		host.appendChild(history); host.appendChild(results);
		call('compression_history').then(function (data) {
			if (!document.contains(host)) { return; }
			history.appendChild(el('p', { text: 'Available: ' + Object.keys(data.capabilities).filter(function (k) { return data.capabilities[k]; }).join(', ') }));
			(data.jobs || []).forEach(function (j) { history.appendChild(button(new Date(j.created * 1000).toLocaleString() + ' — ' + j.status, function () { current = j; page = null; draw(); })); });
			if (!current && data.jobs.length) { current = data.jobs[0]; draw(); }
		}).catch(function (e) { toast(e.message, true); });
		function poll() {
			if (!document.contains(host)) { return; }
			if (current && !busy && ['running', 'discovering', 'restoring'].indexOf(current.status) >= 0) {
				var params = { job_id: current.id }; if (page !== null) { params.result_offset = page; }
				invoke('compression_step', params).finally(function () { window.setTimeout(poll, 2000); });
			} else { window.setTimeout(poll, 2000); }
		}
		window.setTimeout(poll, 2000);
		return host;
	}

	function runBatch(moduleId, action, ids, fixedParams) {
		if (moduleId === 'media' && action === 'bulk_compress') {
			state.compressionSelection = ids || [];
			state.mediaTab = 'optimize';
			render();
			toast(__('Review the Original Image Compression settings, then start the job.', 'omninox-site-toolkit'));
			return;
		}
		var key = moduleId + ':' + action;
		var batch = 10;
		var offset = 0;
		var passes = 0;
		var retries = 0;
		var totals = { done: 0, failed: 0, savedBytes: 0 };
		var errors = [];
		var extra = fixedParams || {};
		var isBackupOrRestore = (
			action === 'backup_full' ||
			action === 'backup_database' ||
			action === 'restore_full' ||
			action === 'restore_database' ||
			action === 'restore_rollback'
		);
		var isReplaceAction = (
			action === 'replace_originals_with_webp' ||
			action === 'replace_originals_with_avif'
		);
		if (isReplaceAction) {
			batch = 25;
		}
		var maxRetries = isBackupOrRestore ? 12 : (isReplaceAction ? 5 : 0);

		state.busy[key] = true;
		state.progress[key] = { done: 0, total: 0, label: __('Starting…', 'omninox-site-toolkit') };
		render();

		function step() {
			// Merge fixed params (e.g. archive filename, job_id) with the batch
			// cursor so the server always has what it needs.
			var params = Object.assign({}, extra, { offset: offset, batch: batch, ids: ids || [] });

			return api('/fix/' + moduleId, {
				method: 'POST',
				body: { action: action, params: params }
			}).then(function (result) {
				if (isBackupOrRestore) {
					offset = passes + 1; // Always positive continuation cursor for server
				} else {
					var advanced = (typeof result.offset === 'number' && result.offset > offset);
					offset = advanced ? result.offset : (offset + batch);
				}
				passes += 1;
				retries = 0; // Reset retry counter on successful server response

				if (result.job_id) {
					extra.job_id = result.job_id;
				}

				if (result.continuation_token) {
					try {
						sessionStorage.setItem('omninox_restore_token', result.continuation_token);
					} catch (e) { }
				}

				totals.done += (result.converted || result.compressed || result.regenerated || result.pairs_done || 0);
				totals.failed += (result.failed || 0);

				(result.errors || []).forEach(function (entry) {
					if (errors.length < 5) {
						errors.push(entry.error || entry.message || (typeof entry === 'string' ? entry : ''));
					}
				});

				state.progress[key] = {
					done: typeof result.offset === 'number' ? result.offset : (result.done ? (result.total || 0) : offset),
					total: result.total || 0,
					label: result.message || ''
				};

				renderProgress(key);

				// Stop if the server says it is finished. Non-backup/non-restore runs cap at 500 passes,
				// but backups and restores run until completely done without arbitrary limits.
				var shouldContinue = !result.done && (isBackupOrRestore ? true : passes < 500);

				if (shouldContinue) {
					return step();
				}

				finish(result);
			});
		}

		function finish(result) {
			state.busy[key] = false;
			delete state.progress[key];
			if (action === 'restore_full' || action === 'restore_rollback' || action === 'restore_cancel') {
				delete state.restoreStatus;
				try {
					sessionStorage.removeItem('omninox_restore_token');
				} catch (e) { }
			}
			render();

			var summary = result.message || __('Batch finished.', 'omninox-site-toolkit');

			if (totals.failed) {
				summary += ' ' + sprintf(
					__('%d files could not be processed.', 'omninox-site-toolkit'),
					totals.failed
				);

				if (errors.length) {
					summary += ' ' + errors[0];
				}
			}

			// For download-ready results (e.g. a completed backup) open the
			// download URL immediately so the user does not have to find it.
			if (result.url) {
				window.location.href = result.url;
			}

			toast(summary, totals.failed > 0 && totals.done === 0);

			// Free WebP quota exhausted mid-run: surface the upgrade path.
			if (result.upgrade_required || result.limit_reached) {
				showUpgradeModal(__('Unlimited image conversion', 'omninox-site-toolkit'));
			}

			// Refresh the module view so the counts reflect the new state.
			runScan(moduleId);
		}

		function executeStep() {
			return step().catch(function (error) {
				var status = error.status || 0;
				var isNetworkError = !status;
				var isTransient = isNetworkError || status === 502 || status === 503 || status === 504 || (status >= 500 && status < 600);
				var isAuth401 = (status === 401 && action === 'restore_full' && retries === 0);
				var isConflict409 = (status === 409 && action === 'restore_full' && retries < 2);

				var canRetry = (isTransient || isAuth401 || isConflict409) && (retries < maxRetries);

				if (canRetry) {
					retries++;
					var delay = Math.min(16000, Math.pow(2, retries) * 1000);
					var retryLabel = '';

					if (isNetworkError) {
						retryLabel = sprintf(
							__('Network connection interrupted. Retrying in %d seconds (attempt %d of %d)…', 'omninox-site-toolkit'),
							Math.round(delay / 1000),
							retries,
							maxRetries
						);
					} else if (status === 401) {
						retryLabel = sprintf(
							__('Restore authentication expired. Recovering session in %d seconds…', 'omninox-site-toolkit'),
							Math.round(delay / 1000)
						);
					} else if (status === 409) {
						retryLabel = sprintf(
							__('Another restore is active. Synchronizing in %d seconds…', 'omninox-site-toolkit'),
							Math.round(delay / 1000)
						);
					} else if (status === 503) {
						retryLabel = sprintf(
							__('Server temporarily unavailable (HTTP 503). Retrying in %d seconds (attempt %d of %d)…', 'omninox-site-toolkit'),
							Math.round(delay / 1000),
							retries,
							maxRetries
						);
					} else if (status === 502 || status === 504) {
						retryLabel = sprintf(
							__('Gateway timeout (HTTP %d). Retrying in %d seconds (attempt %d of %d)…', 'omninox-site-toolkit'),
							status,
							Math.round(delay / 1000),
							retries,
							maxRetries
						);
					} else {
						retryLabel = sprintf(
							__('Server temporary error (HTTP %d). Retrying in %d seconds (attempt %d of %d)…', 'omninox-site-toolkit'),
							status,
							Math.round(delay / 1000),
							retries,
							maxRetries
						);
					}

					state.progress[key] = {
						done: offset,
						total: 0,
						label: retryLabel
					};
					renderProgress(key);

					return new Promise(function (resolve) {
						setTimeout(resolve, delay);
					}).then(function () {
						if (action === 'restore_full') {
							// Retrieve authoritative server state before next step
							return api('/fix/' + moduleId, {
								method: 'POST',
								body: { action: 'restore_status' }
							}).then(function (serverStatus) {
								if (serverStatus && serverStatus.has_job) {
									if (serverStatus.status === 'database_recovery_required') {
										throw new Error(serverStatus.error_message || __('Database recovery required.', 'omninox-site-toolkit'));
									}
									if (serverStatus.job_id) {
										extra.job_id = serverStatus.job_id;
									}
									if (serverStatus.continuation_token) {
										try {
											sessionStorage.setItem('omninox_restore_token', serverStatus.continuation_token);
										} catch (e) { }
									}
									if (typeof serverStatus.offset === 'number') {
										offset = serverStatus.offset;
									}
								}
								return executeStep();
							}).catch(function (statusErr) {
								return executeStep();
							});
						}
						return executeStep();
					});
				}

				state.busy[key] = false;
				if (action === 'restore_full') {
					state.progress[key] = {
						done: offset,
						total: 0,
						error: true,
						label: sprintf(
							__('Restore paused: %s. Click Resume to continue safely from this checkpoint.', 'omninox-site-toolkit'),
							error.message || __('Request interrupted', 'omninox-site-toolkit')
						)
					};
					// Mark status so interrupted banner can be rendered
					state.restoreStatus = {
						has_job: true,
						is_stale: true,
						file: extra.file || '',
						job_id: extra.job_id || '',
						can_rollback: true,
						phase: 'interrupted',
						error_message: error.message || ''
					};
					render();
				} else {
					delete state.progress[key];
					render();
				}
				toast(error.message, true);
			});
		}

		executeStep();
	}

	/**
	 * Inspectors and forms return data to display rather than a status
	 * message, so they get their own runner and their own render path.
	 */
	function runInspector(moduleId, action, params) {
		var key = moduleId + ':' + action;

		state.busy[key] = true;
		render();

		return api('/fix/' + moduleId, { method: 'POST', body: { action: action, params: params || {} } })
			.then(function (result) {
				state.busy[key] = false;
				state.inspect[key] = result;
				render();

				if (result.download_url) {
					window.location.href = String(result.download_url).replace(/&amp;/g, '&');
				}

				if (result.message) {
					toast(result.message);
				}

				return result;
			})
			.catch(function (error) {
				state.busy[key] = false;
				render();
				toast(error.message, true);
			});
	}

	function inspectorTable(result) {
		var rows = result.rows;
		var moduleId = result.module || null;

		if (Array.isArray(rows) && rows.length) {
			return el('div', { class: 'wpmt-inspector' }, [
				result.message ? el('div', { style: 'margin-bottom:12px;padding:10px 14px;background:var(--wpmt-surface,#1e293b);border:1px solid var(--wpmt-line,rgba(255,255,255,0.1));border-radius:6px;font-weight:600;font-size:13px;color:var(--wpmt-ink,#f8fafc);', text: result.message }) : null,
				el('p', {
					class: 'wpmt-meta-history',
					text: rows.length + ' ' + __('rows', 'omninox-site-toolkit')
				}),
				table(rows, moduleId)
			].filter(Boolean));
		}

		if (Array.isArray(rows)) {
			return el('div', { class: 'wpmt-inspector' }, [
				result.message ? el('div', { style: 'margin-bottom:12px;padding:10px 14px;background:var(--wpmt-surface,#1e293b);border:1px solid var(--wpmt-line,rgba(255,255,255,0.1));border-radius:6px;font-weight:600;font-size:13px;', text: result.message }) : null,
				el('p', { class: 'wpmt-meta-history', text: __('Nothing to show.', 'omninox-site-toolkit') })
			].filter(Boolean));
		}

		// Non-tabular results, formatted as clean key-value summary
		if (typeof result === 'object' && result !== null) {
			var summaryRows = [];
			Object.keys(result).forEach(function (k) {
				if (k !== 'module' && k !== 'message' && typeof result[k] !== 'object') {
					var label = k.replace(/_/g, ' ').replace(/\b\w/g, function (l) { return l.toUpperCase(); });
					summaryRows.push(el('div', { style: 'display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--wpmt-line,rgba(255,255,255,0.1));font-size:13px;' }, [
						el('span', { style: 'color:var(--wpmt-ink-soft,#94a3b8);', text: label }),
						el('strong', { text: String(result[k]) })
					]));
				}
			});

			if (summaryRows.length) {
				return el('div', { class: 'wpmt-inspector', style: 'margin-top:12px;padding:14px;background:var(--wpmt-surface,#1e293b);border:1px solid var(--wpmt-line,rgba(255,255,255,0.1));border-radius:6px;' }, [
					result.message ? el('div', { style: 'font-weight:600;margin-bottom:10px;font-size:14px;color:var(--wpmt-ink,#f8fafc);', text: result.message }) : null
				].concat(summaryRows).filter(Boolean));
			}
		}

		return el('div', { class: 'wpmt-inspector' }, [
			el('pre', { class: 'wpmt-pre', text: JSON.stringify(result, null, 2) })
		]);
	}

	/**
	 * Charts are drawn as inline SVG. A charting library would be another
	 * 60 KB for four bar charts and a sparkline.
	 */
	function barChart(spec) {
		var data = (spec.data || []).filter(function (d) { return d && d.value > 0; });

		if (!data.length) {
			return null;
		}

		var max = Math.max.apply(null, data.map(function (d) { return d.value; }));
		var rows = data.slice(0, 12);

		return el('div', { class: 'wpmt-chart' }, [
			el('h3', { class: 'wpmt-chart-title', text: spec.title }),
			el('div', { class: 'wpmt-bars' }, rows.map(function (d) {
				var pct = max ? Math.max(2, Math.round((d.value / max) * 100)) : 0;

				return el('div', { class: 'wpmt-bar-row' }, [
					el('span', { class: 'wpmt-bar-label', title: d.label, text: d.label }),
					el('span', { class: 'wpmt-bar-track' }, [
						el('span', { class: 'wpmt-bar-fill', style: 'width:' + pct + '%' })
					]),
					el('span', { class: 'wpmt-bar-value', text: d.value + (d.unit ? ' ' + d.unit : '') })
				]);
			}))
		]);
	}

	function lineChart(spec) {
		var data = (spec.data || []).filter(function (d) { return d && typeof d.y === 'number'; });

		if (data.length < 2) {
			return null;
		}

		var w = 640;
		var h = 140;
		var pad = 8;
		var max = Math.max(100, Math.max.apply(null, data.map(function (d) { return d.y; })));

		var points = data.map(function (d, i) {
			var x = pad + (i / (data.length - 1)) * (w - pad * 2);
			var y = h - pad - (d.y / max) * (h - pad * 2);
			return x.toFixed(1) + ',' + y.toFixed(1);
		}).join(' ');

		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
		svg.setAttribute('class', 'wpmt-line');
		svg.setAttribute('preserveAspectRatio', 'none');
		svg.setAttribute('role', 'img');
		svg.setAttribute('aria-label', spec.title);

		var area = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
		area.setAttribute('points', pad + ',' + (h - pad) + ' ' + points + ' ' + (w - pad) + ',' + (h - pad));
		area.setAttribute('class', 'wpmt-line-area');
		svg.appendChild(area);

		var line = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
		line.setAttribute('points', points);
		line.setAttribute('class', 'wpmt-line-stroke');
		svg.appendChild(line);

		return el('div', { class: 'wpmt-chart' }, [
			el('h3', { class: 'wpmt-chart-title', text: spec.title }),
			svg,
			el('p', {
				class: 'wpmt-meta-history',
				text: data[0].x + '  →  ' + data[data.length - 1].x +
					'   (' + Math.round(data[0].y) + ' → ' + Math.round(data[data.length - 1].y) + ')'
			})
		]);
	}

	function chartsPanel(charts) {
		var drawn = (charts || []).map(function (spec) {
			return spec.type === 'line' ? lineChart(spec) : barChart(spec);
		}).filter(Boolean);

		if (!drawn.length) {
			return null;
		}

		return el('div', { class: 'wpmt-card' }, [
			el('h2', { text: __('Charts', 'omninox-site-toolkit') }),
			el('div', { class: 'wpmt-charts' }, drawn)
		]);
	}

	function recommendationsPanel(items) {
		if (!items || !items.length) {
			return null;
		}

		return el('div', { class: 'wpmt-card' }, [
			el('h2', { text: __('How to make this faster', 'omninox-site-toolkit') }),
			el('ol', { class: 'wpmt-reco' }, items.map(function (item) {
				return el('li', {}, [
					el('strong', { text: item.title }),
					el('div', { class: 'wpmt-reco-meta' }, [
						el('span', { class: 'wpmt-chip wpmt-chip-pass', text: item.gain }),
						el('span', { class: 'wpmt-chip', text: item.effort })
					]),
					el('p', { class: 'wpmt-reco-how', text: item.how })
				]);
			}))
		]);
	}

	function readFile(file) {
		return new Promise(function (resolve, reject) {
			var reader = new FileReader();
			reader.onload = function () { resolve(reader.result); };
			reader.onerror = function () { reject(new Error('read failed')); };
			reader.readAsDataURL(file);
		});
	}

	/**
	 * Upload a file in chunks to prevent browser memory exhaustion ("Out of Memory")
	 * and bypass server post_max_size / upload_max_filesize limits.
	 *
	 * @param {string} moduleId Module ID (e.g. 'backup')
	 * @param {string} actionId Action ID (e.g. 'import_backup')
	 * @param {File}   file     File object from <input type="file">
	 * @return {Promise}
	 */
	function uploadFileChunked(moduleId, actionId, file) {
		var key = moduleId + ':' + actionId;
		var chunkSize = 8 * 1024 * 1024; // 8 MB chunks
		var totalSize = file.size;
		var totalChunks = Math.max(1, Math.ceil(totalSize / chunkSize));
		var chunkIndex = 0;
		var targetFile = '';

		state.busy[key] = true;
		state.progress[key] = { done: 0, total: totalChunks, label: __('Preparing upload…', 'omninox-site-toolkit') };
		render();

		function readChunk(blob) {
			return new Promise(function (resolve, reject) {
				var reader = new FileReader();
				reader.onload = function () { resolve(reader.result); };
				reader.onerror = function () { reject(new Error(__('Failed to read file chunk.', 'omninox-site-toolkit'))); };
				reader.readAsDataURL(blob);
			});
		}

		function sendNextChunk() {
			var start = chunkIndex * chunkSize;
			var end = Math.min(totalSize, start + chunkSize);
			var blob = file.slice(start, end);

			return readChunk(blob).then(function (base64Data) {
				var payload = {
					name: file.name,
					data: base64Data,
					chunk: chunkIndex,
					chunks: totalChunks,
					target_file: targetFile
				};

				return api('/fix/' + moduleId, {
					method: 'POST',
					body: { action: actionId, params: payload }
				});
			}).then(function (result) {
				if (result.target_file) {
					targetFile = result.target_file;
				}

				chunkIndex += 1;

				state.progress[key] = {
					done: chunkIndex,
					total: totalChunks,
					label: result.message || (__('Uploading archive: chunk ', 'omninox-site-toolkit') + chunkIndex + ' / ' + totalChunks + '…')
				};
				renderProgress(key);

				if (!result.done && chunkIndex < totalChunks) {
					return sendNextChunk();
				}

				finish(result);
			});
		}

		function finish(result) {
			state.busy[key] = false;
			delete state.progress[key];
			state.inspect[key] = result;
			render();

			if (result.message) {
				toast(result.message);
			}
		}

		return sendNextChunk().catch(function (error) {
			state.busy[key] = false;
			delete state.progress[key];
			render();
			toast(error.message, true);
		});
	}

	/**
	 * Backup list with per-row download, restore and delete.
	 */
	function backupList(moduleId, rows) {
		// Check for interrupted restore status once per module view
		if (!state.restoreStatusChecked) {
			state.restoreStatusChecked = true;
			api('/fix/' + moduleId, { method: 'POST', body: { action: 'restore_status' } })
				.then(function (status) {
					if (status && status.continuation_token) {
						sessionStorage.setItem('omninox_restore_token', status.continuation_token);
					}
					if (status && status.has_job && status.is_stale) {
						state.restoreStatus = status;
						render();
					}
				}).catch(function () { });
		}

		var alertBox = null;
		if (state.restoreStatus && state.restoreStatus.has_job && state.restoreStatus.is_stale) {
			var rJob = state.restoreStatus;
			alertBox = el('div', {
				style: 'margin-bottom:16px;padding:14px 18px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);border-radius:8px;color:#fca5a5;'
			}, [
				el('div', { style: 'font-weight:700;font-size:14px;margin-bottom:6px;', text: __('Interrupted Restore Job Detected', 'omninox-site-toolkit') }),
				el('div', { style: 'font-size:13px;margin-bottom:10px;', text: sprintf(__('An unfinished restore exists for archive "%s" (Phase: %s). You can resume it safely from the last verified checkpoint or rollback to the safety snapshot.', 'omninox-site-toolkit'), rJob.file || '', rJob.phase || '') }),
				el('div', { style: 'display:flex;gap:8px;align-items:center;flex-wrap:wrap;' }, [
					el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-small wpmt-btn-primary',
						onClick: function () {
							runBatch(moduleId, 'restore_full', [], { resume: true, file: rJob.file, job_id: rJob.job_id });
						}
					}, [
						icon('play', 12, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Resume Restore', 'omninox-site-toolkit'))
					]),
					rJob.can_rollback ? el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-small',
						onClick: function () {
							if (window.confirm(__('Rollback database to the pre-restore safety snapshot?', 'omninox-site-toolkit'))) {
								runInspector(moduleId, 'restore_rollback').then(function () {
									delete state.restoreStatus;
									render();
								});
							}
						}
					}, [
						icon('rollback', 12, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Rollback from Snapshot', 'omninox-site-toolkit'))
					]) : null,
					el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-small wpmt-btn-ghost',
						onClick: function () {
							api('/fix/' + moduleId, { method: 'POST', body: { action: 'restore_cancel' } }).then(function () {
								delete state.restoreStatus;
								render();
							});
						}
					}, [
						icon('refresh', 12, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Cancel & Dismiss', 'omninox-site-toolkit'))
					])
				])
			]);
		}

		if (!rows.length) {
			return el('div', { class: 'wpmt-inspector' }, [
				alertBox,
				el('p', { class: 'wpmt-meta-history', text: __('No archives yet. Run a backup above.', 'omninox-site-toolkit') })
			]);
		}

		return el('div', { class: 'wpmt-inspector' }, [
			alertBox,
			el('table', { class: 'wpmt-table' }, [
				el('thead', {}, [
					el('tr', {}, [
						el('th', { text: __('Archive', 'omninox-site-toolkit') }),
						el('th', { text: __('Created', 'omninox-site-toolkit') }),
						el('th', { text: __('Age', 'omninox-site-toolkit') }),
						el('th', { text: __('Size', 'omninox-site-toolkit') }),
						el('th', { text: __('Actions', 'omninox-site-toolkit') })
					])
				]),
				el('tbody', {}, rows.map(function (row, index) {
					return el('tr', {}, [
						el('td', { class: 'wpmt-mono', text: row.file }),
						el('td', { text: row.created }),
						el('td', { text: row.age + ' ' + __('ago', 'omninox-site-toolkit') }),
						el('td', { class: 'wpmt-mono', text: row.size }),
						el('td', {}, [
							el('span', { class: 'wpmt-row-actions' }, [
								el('button', {
									type: 'button',
									class: 'wpmt-btn wpmt-btn-small',
									onClick: function () { downloadVia(moduleId, 'download_backup', { file: row.file }); }
								}, [
									icon('download', 12, 'wpmt-btn-icon'),
									document.createTextNode(' ' + __('Download', 'omninox-site-toolkit'))
								]),
								el('button', {
									type: 'button',
									class: 'wpmt-btn wpmt-btn-small',
									onClick: function () {
										var confirmMsg = __('Restore complete site (files & database) from this archive? A snapshot of the current site is taken first.', 'omninox-site-toolkit');
										if (row.site && window.location.origin && row.site.replace(/\/+$/, '') !== window.location.origin.replace(/\/+$/, '')) {
											confirmMsg = sprintf(
												__("Cross-site restore detected!\n\nSource site:\n%s\n\nCurrent destination site:\n%s\n\nThe restore will automatically adapt and migrate URLs from '%s' to '%s' while keeping this site's domain intact.\n\nProceed with restore?", 'omninox-site-toolkit'),
												row.site,
												window.location.origin,
												row.site,
												window.location.origin
											);
										}
										if (window.confirm(confirmMsg)) {
											runBatch(moduleId, 'restore_full', [], { file: row.file });
										}
									}
								}, [
									icon('restore', 12, 'wpmt-btn-icon'),
									document.createTextNode(' ' + __('Restore', 'omninox-site-toolkit'))
								]),
								el('button', {
									type: 'button',
									class: 'wpmt-btn wpmt-btn-small wpmt-btn-danger',
									disabled: index < 3,
									title: index < 3 ? __('The three most recent archives are kept.', 'omninox-site-toolkit') : '',
									onClick: function () {
										if (window.confirm(__('Delete this archive permanently?', 'omninox-site-toolkit'))) {
											runInspector(moduleId, 'delete_backup', { file: row.file })
												.then(function () { runInspector(moduleId, 'list_backups'); });
										}
									}
								}, [
									icon('trash', 12, 'wpmt-btn-icon'),
									document.createTextNode(' ' + __('Delete', 'omninox-site-toolkit'))
								])
							])
						])
					]);
				}))
			])
		]);
	}

	function downloadVia(moduleId, action, params) {
		api('/fix/' + moduleId, { method: 'POST', body: { action: action, params: params || {} } })
			.then(function (result) {
				if (result.download_url) {
					window.location.href = String(result.download_url).replace(/&amp;/g, '&');
				} else if (result.message) {
					toast(result.message);
				}
			})
			.catch(function (error) { toast(error.message, true); });
	}

	/**
	 * Media selector: tick individual images or the whole page, then run a
	 * bulk action against just that selection.
	 */
	function mediaSelector(module, action, key) {
		var result = state.inspect[key];
		var rows = result ? (result.rows || []) : [];
		var values = { filter: 'all', page: '1' };

		var fields = (action.fields || []).map(function (field) {
			var control = field.type === 'select'
				? select(
					(field.options || []).map(function (o) { return [String(o.value), o.label]; }),
					values[field.id],
					function (v) { values[field.id] = v; }
				)
				: el('input', {
					type: 'text',
					placeholder: field.placeholder || '',
					onInput: function (e) { values[field.id] = e.target.value; }
				});

			return el('label', { class: 'wpmt-field' }, [el('span', { text: field.label }), control]);
		});

		var selectedIds = Object.keys(state.selected).filter(function (id) { return state.selected[id]; });

		return el('div', { class: 'wpmt-form-action' }, [
			label_(action),
			el('div', { class: 'wpmt-form-fields' }, fields),
			el('button', {
				type: 'button',
				class: 'wpmt-btn',
				disabled: !!state.busy[key],
				text: state.busy[key] ? __('Loading…', 'omninox-site-toolkit') : (action.submit || __('Show', 'omninox-site-toolkit')),
				onClick: function () {
					state.selected = {};
					runInspector(module.id, action.id, values);
				}
			}),
			rows.length ? el('div', { class: 'wpmt-inspector' }, [
				el('div', { class: 'wpmt-bulk-bar' }, [
					el('span', {
						class: 'wpmt-meta-history',
						text: selectedIds.length
							? selectedIds.length + ' ' + __('selected', 'omninox-site-toolkit')
							: __('Nothing selected. Actions below will run on the ticked images only.', 'omninox-site-toolkit')
					})
				].concat((action.bulk || []).map(function (bulk) {
					return el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-small',
						disabled: !selectedIds.length,
						text: bulk.label,
						onClick: function () {
							runBatch(module.id, bulk.id, selectedIds.map(Number));
						}
					});
				}))),
				selectorTable(rows)
			]) : null
		]);
	}

	function label_(action) {
		return el('span', {}, [el('span', { text: action.label })].concat(actionMeta(action)));
	}

	function selectorTable(rows) {
		var columns = ['file', 'size', 'status', 'webp', 'compressed', 'saved', 'alt'];

		var headerBox = el('input', {
			type: 'checkbox',
			onChange: function (e) {
				rows.forEach(function (row) { state.selected[row.id] = e.target.checked; });
				render();
			}
		});

		return el('table', { class: 'wpmt-table' }, [
			el('thead', {}, [
				el('tr', {}, [el('th', {}, [headerBox])].concat(columns.map(function (c) {
					return el('th', { text: c });
				})))
			]),
			el('tbody', {}, rows.map(function (row) {
				return el('tr', {}, [
					el('td', {}, [
						el('input', {
							type: 'checkbox',
							checked: !!state.selected[row.id],
							onChange: function (e) {
								state.selected[row.id] = e.target.checked;
							}
						})
					])
				].concat(columns.map(function (c) {
					return el('td', { class: c === 'file' ? 'wpmt-mono' : '', text: String(row[c] === undefined ? '' : row[c]) });
				})));
			}))
		]);
	}

	/**
	 * File manager. Every path is resolved server side inside a sandbox, so
	 * this only has to be a reasonable interface, not a security boundary.
	 */
	function fileManager(module, action) {
		var key = module.id + ':browse';
		var fm = state.fm;

		function load(path) {
			state.busy[key] = true;
			render();

			return api('/fix/' + module.id, { method: 'POST', body: { action: 'list', params: { path: path } } })
				.then(function (result) {
					state.busy[key] = false;
					state.fm.path = result.path || '';
					state.fm.parent = result.parent;
					state.fm.entries = result.entries || [];
					state.fm.editing = null;
					render();
				})
				.catch(function (error) {
					state.busy[key] = false;
					render();
					toast(error.message, true);
				});
		}

		function op(name, params, reload) {
			return api('/fix/' + module.id, { method: 'POST', body: { action: name, params: params } })
				.then(function (result) {
					if (result.download_url) {
						window.location.href = String(result.download_url).replace(/&amp;/g, '&');
					}

					if (result.message) {
						toast(result.message);
					}

					if (reload !== false) {
						load(state.fm.path);
					}

					return result;
				})
				.catch(function (error) { toast(error.message, true); });
		}

		if (!fm.loaded) {
			state.fm.loaded = true;
			load('');
		}

		var uploader = filePicker('', true);

		var toolbar = el('div', { class: 'wpmt-fm-bar' }, [
			el('button', {
				type: 'button',
				class: 'wpmt-btn wpmt-btn-small',
				disabled: !fm.path,
				text: '↑ ' + __('Up', 'omninox-site-toolkit'),
				onClick: function () { load(fm.parent || ''); }
			}),
			el('span', { class: 'wpmt-fm-path wpmt-mono', text: '/' + (fm.path || '') }),
			el('span', { class: 'wpmt-row-actions' }, [
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small',
					text: __('New folder', 'omninox-site-toolkit'),
					onClick: function () {
						var name = window.prompt(__('Folder name', 'omninox-site-toolkit'));
						if (name) { op('create_folder', { path: fm.path, name: name }); }
					}
				}),
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small',
					text: __('New file', 'omninox-site-toolkit'),
					onClick: function () {
						var name = window.prompt(__('File name', 'omninox-site-toolkit'));
						if (name) { op('create_file', { path: fm.path, name: name }); }
					}
				}),
				uploader.node,
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small',
					text: __('Upload', 'omninox-site-toolkit'),
					onClick: function () {
						var file = uploader.input.files && uploader.input.files[0];

						if (!file) {
							toast(__('Choose a file first.', 'omninox-site-toolkit'), true);
							return;
						}

						readFile(file).then(function (data) {
							op('upload', { path: fm.path, name: file.name, data: data });
						});
					}
				}),
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small',
					text: __('Refresh', 'omninox-site-toolkit'),
					onClick: function () { load(fm.path); }
				}),
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small wpmt-btn-primary',
					text: '📥 ' + __('Download folder (ZIP)', 'omninox-site-toolkit'),
					onClick: function () { op('download', { path: fm.path }, false); }
				})
			])
		]);

		var table = el('table', { class: 'wpmt-table' }, [
			el('thead', {}, [
				el('tr', {}, [
					el('th', { text: __('Name', 'omninox-site-toolkit') }),
					el('th', { text: __('Size', 'omninox-site-toolkit') }),
					el('th', { text: __('Modified', 'omninox-site-toolkit') }),
					el('th', { text: __('Perms', 'omninox-site-toolkit') }),
					el('th', { text: __('Actions', 'omninox-site-toolkit') })
				])
			]),
			el('tbody', {}, (fm.entries || []).map(function (entry) {
				return el('tr', {}, [
					el('td', {}, [
						entry.type === 'folder'
							? el('button', {
								type: 'button',
								class: 'wpmt-link',
								text: '📁 ' + entry.name,
								onClick: function () { load(entry.path); }
							})
							: el('span', { class: 'wpmt-mono', text: entry.name })
					]),
					el('td', { class: 'wpmt-mono', text: entry.size }),
					el('td', { text: entry.modified }),
					el('td', { class: 'wpmt-mono', text: entry.permissions }),
					el('td', {}, [
						el('span', { class: 'wpmt-row-actions' }, [
							entry.editable ? el('button', {
								type: 'button',
								class: 'wpmt-btn wpmt-btn-small',
								text: __('Edit', 'omninox-site-toolkit'),
								onClick: function () {
									op('read', { path: entry.path }, false).then(function (result) {
										if (result && result.contents !== undefined) {
											state.fm.editing = entry.path;
											state.fm.contents = result.contents;
											render();
										}
									});
								}
							}) : null,
							el('button', {
								type: 'button',
								class: 'wpmt-btn wpmt-btn-small',
								text: __('Download', 'omninox-site-toolkit'),
								onClick: function () { op('download', { path: entry.path }, false); }
							}),
							el('button', {
								type: 'button',
								class: 'wpmt-btn wpmt-btn-small',
								text: __('Rename', 'omninox-site-toolkit'),
								onClick: function () {
									var name = window.prompt(__('New name', 'omninox-site-toolkit'), entry.name);
									if (name && name !== entry.name) { op('rename', { path: entry.path, name: name }); }
								}
							}),
							el('button', {
								type: 'button',
								class: 'wpmt-btn wpmt-btn-small wpmt-btn-danger',
								text: __('Delete', 'omninox-site-toolkit'),
								onClick: function () {
									if (window.confirm(__('Delete', 'omninox-site-toolkit') + ' ' + entry.name + '?')) {
										op('delete', { path: entry.path });
									}
								}
							})
						])
					])
				]);
			}))
		]);

		var editor = null;

		if (fm.editing) {
			var textarea = el('textarea', { class: 'wpmt-editor', rows: '18' });
			textarea.value = fm.contents;

			editor = el('div', { class: 'wpmt-fm-editor' }, [
				el('h3', { class: 'wpmt-chart-title', text: fm.editing }),
				textarea,
				el('p', { class: 'wpmt-row-actions', style: 'margin-top:8px' }, [
					el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-primary',
						text: __('Save', 'omninox-site-toolkit'),
						onClick: function () {
							op('save', { path: fm.editing, contents: textarea.value }, false)
								.then(function () { state.fm.editing = null; render(); });
						}
					}),
					el('button', {
						type: 'button',
						class: 'wpmt-btn',
						text: __('Cancel', 'omninox-site-toolkit'),
						onClick: function () { state.fm.editing = null; render(); }
					})
				])
			]);
		}

		return el('div', {}, [
			el('p', { class: 'wpmt-meta-notice', text: action.notice }),
			toolbar,
			el('div', { class: 'wpmt-inspector' }, [table]),
			editor
		]);
	}

	function sprintf(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var str = String(template || '');
		// Handle numbered placeholders like %1$s, %2$d, %1$d, %2$s
		str = str.replace(/%([0-9]+)\$([sd])/g, function (match, index) {
			var val = args[parseInt(index, 10) - 1];
			return (val !== undefined && val !== null) ? String(val) : '';
		});
		// Handle standard %s and %d sequential placeholders
		var argIndex = 0;
		str = str.replace(/%[sd]/g, function (match) {
			if (argIndex < args.length) {
				var val = args[argIndex++];
				return (val !== undefined && val !== null) ? String(val) : '';
			}
			return match;
		});
		return str;
	}

	function progressBar(key) {
		var progress = (state.progress && state.progress[key]) || { done: 0, total: 0, label: __('Starting…', 'omninox-site-toolkit') };
		var percent = progress.total
			? Math.min(100, Math.round((progress.done / progress.total) * 100))
			: 0;
		var isIndeterminate = !progress.total && !progress.error;

		return el('div', {
			class: 'wpmt-progress-wrap' + (isIndeterminate ? ' is-indeterminate' : '') + (progress.error ? ' is-error' : ''),
			'data-progress': key
		}, [
			el('div', { class: 'wpmt-progress' + (isIndeterminate ? ' is-indeterminate' : '') }, [
				el('i', {
					style: isIndeterminate ? '' : 'width:' + percent + '%;'
				})
			]),
			el('p', { class: 'wpmt-progress-meta' }, [
				el('span', { class: 'wpmt-progress-label', text: progress.label || __('Processing…', 'omninox-site-toolkit') }),
				progress.total
					? el('span', { class: 'wpmt-progress-pct', text: percent + '% (' + progress.done + ' / ' + progress.total + ')' })
					: null
			])
		]);
	}

	function renderProgress(key) {
		var node = document.querySelector('[data-progress="' + key + '"]');
		var progress = state.progress && state.progress[key];

		if (!node) {
			return;
		}

		if (!progress) {
			node.remove();
			return;
		}

		var newNode = progressBar(key);
		if (node.parentNode) {
			node.parentNode.replaceChild(newNode, node);
		}
	}


	/* ------------------------------------------------------------ Utilities */

	function copyToClipboard(text) {
		return new Promise(function (resolve, reject) {
			if (!text) { reject(); return; }

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(resolve).catch(reject);
				return;
			}

			try {
				var field = document.createElement('textarea');
				field.value = text;
				field.style.position = 'fixed';
				field.style.left = '-9999px';
				document.body.appendChild(field);
				field.select();
				document.execCommand('copy');
				document.body.removeChild(field);
				resolve();
			} catch (e) {
				reject(e);
			}
		});
	}


	/* --------------------------------------------------------------- Support desk */

	var desk = {
		filter: '',
		search: '',
		selected: null,
		list: null,
		detail: null,
		timer: null,
		sending: false
	};

	function renderSupport(wrapper) {
		wrapper.appendChild(header(
			__('Support desk', 'omninox-site-toolkit'),
			__('Conversations from the chat widget. Reply here and it appears in the visitor\u2019s chat within seconds — no mailbox or forwarding needed.', 'omninox-site-toolkit')
		));

		var layout = el('div', { class: 'wpmt-desk' }, [
			el('div', { class: 'wpmt-desk-list' }, [
				el('div', { class: 'wpmt-desk-filters', id: 'wpmt-desk-filters' }),
				el('input', {
					type: 'search',
					class: 'wpmt-desk-search',
					placeholder: __('Search ticket, email or subject…', 'omninox-site-toolkit'),
					value: desk.search,
					onInput: function (event) {
						desk.search = event.target.value;
						clearTimeout(desk.searchTimer);
						desk.searchTimer = setTimeout(loadDeskList, 350);
					}
				}),
				el('div', { id: 'wpmt-desk-items', class: 'wpmt-desk-items' }, [
					el('div', { class: 'wpmt-empty', text: __('Loading…', 'omninox-site-toolkit') })
				])
			]),
			el('div', { id: 'wpmt-desk-detail', class: 'wpmt-desk-detail' }, [
				el('div', { class: 'wpmt-empty', text: __('Pick a conversation on the left.', 'omninox-site-toolkit') })
			])
		]);

		wrapper.appendChild(layout);

		loadDeskList();
		startDeskPolling();
	}

	/**
	 * The desk refreshes on a timer so a new question appears without the
	 * admin reloading. It stops when they navigate away.
	 */
	function startDeskPolling() {
		stopDeskPolling();

		desk.timer = window.setInterval(function () {
			if (state.route !== 'support') {
				stopDeskPolling();
				return;
			}

			if (document.hidden || desk.sending) {
				return;
			}

			loadDeskList(true);

			if (desk.selected) {
				loadDeskConversation(desk.selected, true);
			}
		}, 10000);
	}

	function stopDeskPolling() {
		if (desk.timer) {
			window.clearInterval(desk.timer);
			desk.timer = null;
		}
	}

	function loadDeskList(quiet) {
		var query = '?per_page=50';

		if (desk.filter) { query += '&status=' + encodeURIComponent(desk.filter); }
		if (desk.search) { query += '&search=' + encodeURIComponent(desk.search); }

		return api('/support/conversations' + query)
			.then(function (data) {
				desk.list = data;
				paintDeskFilters(data.counts || {});
				paintDeskList(data.items || []);
			})
			.catch(function (error) {
				if (!quiet) { toast(error.message, true); }
			});
	}

	function paintDeskFilters(counts) {
		var box = document.getElementById('wpmt-desk-filters');

		if (!box) { return; }

		var filters = [
			['', __('All', 'omninox-site-toolkit'), counts.all || 0],
			['waiting_for_admin', __('Waiting', 'omninox-site-toolkit'), counts.waiting_for_admin || 0],
			['admin_replied', __('Replied', 'omninox-site-toolkit'), counts.admin_replied || 0],
			['ai_active', __('With assistant', 'omninox-site-toolkit'), counts.ai_active || 0],
			['resolved', __('Resolved', 'omninox-site-toolkit'), counts.resolved || 0]
		];

		box.innerHTML = '';

		filters.forEach(function (item) {
			box.appendChild(el('button', {
				type: 'button',
				class: 'wpmt-desk-filter' + (desk.filter === item[0] ? ' is-active' : ''),
				onClick: function () {
					desk.filter = item[0];
					loadDeskList();
				}
			}, [
				document.createTextNode(item[1]),
				el('span', { class: 'wpmt-chip', text: String(item[2]) })
			]));
		});
	}

	function paintDeskList(items) {
		var box = document.getElementById('wpmt-desk-items');

		if (!box) { return; }

		box.innerHTML = '';

		if (!items.length) {
			box.appendChild(el('div', { class: 'wpmt-empty', text: __('Nothing here yet.', 'omninox-site-toolkit') }));
			return;
		}

		items.forEach(function (item) {
			var classes = 'wpmt-desk-item';

			if (desk.selected === item.id) { classes += ' is-selected'; }
			if (item.unread) { classes += ' is-unread'; }

			box.appendChild(el('button', {
				type: 'button',
				class: classes,
				onClick: function () {
					desk.selected = item.id;
					paintDeskList(items);
					loadDeskConversation(item.id);
				}
			}, [
				el('div', { class: 'wpmt-desk-item-top' }, [
					el('span', { class: 'wpmt-desk-item-who', text: item.user_email || item.user_name || __('Anonymous visitor', 'omninox-site-toolkit') }),
					el('span', { class: 'wpmt-desk-status wpmt-desk-status-' + item.status, text: item.status_label })
				]),
				el('p', { class: 'wpmt-desk-item-preview', text: item.last_message || '' }),
				el('div', { class: 'wpmt-desk-item-meta' }, [
					item.ticket_id ? el('code', { text: item.ticket_id }) : null,
					el('span', { text: relativeTime(item.last_activity) })
				].filter(Boolean))
			]));
		});
	}

	function loadDeskConversation(id, quiet) {
		return api('/support/conversation?id=' + encodeURIComponent(id))
			.then(function (data) {
				// Do not repaint under the admin's cursor while they type a
				// reply, or the textarea is wiped mid-sentence.
				var box = document.getElementById('wpmt-desk-reply');

				if (quiet && box && box.value.trim() && desk.detail && desk.detail.messages.length === data.messages.length) {
					return;
				}

				desk.detail = data;
				paintDeskDetail(data, box ? box.value : '');
			})
			.catch(function (error) {
				if (!quiet) { toast(error.message, true); }
			});
	}

	function paintDeskDetail(data, draftReply) {
		var panel = document.getElementById('wpmt-desk-detail');

		if (!panel) { return; }

		panel.innerHTML = '';

		panel.appendChild(el('div', { class: 'wpmt-desk-head' }, [
			el('div', {}, [
				el('strong', { text: data.user_email || data.user_name || __('Anonymous visitor', 'omninox-site-toolkit') }),
				el('div', { class: 'wpmt-desk-head-meta' }, [
					data.ticket_id ? el('code', { text: data.ticket_id }) : null,
					el('span', { class: 'wpmt-desk-status wpmt-desk-status-' + data.status, text: data.status_label }),
					el('span', { class: 'wpmt-chip wpmt-priority-' + (data.priority || 'medium'), text: (data.priority || 'medium').toUpperCase() }),
					data.assigned_agent ? el('span', { class: 'wpmt-chip', text: __('Agent: ', 'omninox-site-toolkit') + data.assigned_agent }) : null,
					el('span', { text: relativeTime(data.last_activity) })
				].filter(Boolean))
			]),
			el('div', { class: 'wpmt-desk-head-actions' }, [
				select(
					[['low', 'Low Priority'], ['medium', 'Medium Priority'], ['high', 'High Priority'], ['urgent', 'Urgent']],
					data.priority || 'medium',
					function (value) { setDeskPriority(data.id, value); }
				),
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small',
					text: __('Mark resolved', 'omninox-site-toolkit'),
					onClick: function () { setDeskStatus(data.id, 'resolved'); }
				}),
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-small',
					text: __('Close', 'omninox-site-toolkit'),
					onClick: function () { setDeskStatus(data.id, 'closed'); }
				})
			])
		]));

		if (data.summary) {
			panel.appendChild(el('details', { class: 'wpmt-desk-summary' }, [
				el('summary', { text: __('Issue summary and steps already tried', 'omninox-site-toolkit') }),
				el('pre', { class: 'wpmt-pre', style: 'white-space:pre-wrap', text: data.summary })
			]));
		}

		var thread = el('div', { class: 'wpmt-desk-thread', id: 'wpmt-desk-thread' });

		(data.messages || []).forEach(function (message) {
			var who = message.sender === 'user'
				? (data.user_name || __('Visitor', 'omninox-site-toolkit'))
				: (message.sender === 'admin' ? (message.author || __('Support', 'omninox-site-toolkit')) : __('AI Assistant', 'omninox-site-toolkit'));

			var body = [el('p', { text: message.message })];

			((message.payload && message.payload.steps) || []).forEach(function (step, index) {
				body.push(el('p', { class: 'wpmt-desk-step', text: (index + 1) + '. ' + step }));
			});

			thread.appendChild(el('div', { class: 'wpmt-desk-message wpmt-desk-message-' + message.sender }, [
				el('div', { class: 'wpmt-desk-message-head' }, [
					el('strong', { text: who }),
					el('span', { text: relativeTime(message.at) })
				]),
				el('div', { class: 'wpmt-desk-message-body' }, body)
			]));
		});

		panel.appendChild(thread);

		var reply = el('textarea', {
			id: 'wpmt-desk-reply',
			rows: '3',
			placeholder: __('Write a reply — the visitor sees it in their chat within seconds…', 'omninox-site-toolkit'),
			onKeydown: function (event) {
				if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
					event.preventDefault();
					sendDeskReply(data.id);
				}
			}
		});

		if (draftReply) { reply.value = draftReply; }

		panel.appendChild(el('div', { class: 'wpmt-desk-composer' }, [
			reply,
			el('div', { class: 'wpmt-desk-composer-actions' }, [
				el('span', { class: 'wpmt-field-hint', text: __('Ctrl/Cmd + Enter to send', 'omninox-site-toolkit') }),
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-primary',
					text: __('Send reply', 'omninox-site-toolkit'),
					onClick: function () { sendDeskReply(data.id); }
				})
			])
		]));

		thread.scrollTop = thread.scrollHeight;
	}

	function sendDeskReply(id) {
		var field = document.getElementById('wpmt-desk-reply');

		if (!field) { return; }

		var message = field.value.trim();

		if (!message) {
			toast(__('Write a reply first.', 'omninox-site-toolkit'), true);
			return;
		}

		desk.sending = true;
		field.disabled = true;

		api('/support/reply', { method: 'POST', body: { id: id, message: message } })
			.then(function (result) {
				desk.sending = false;
				desk.detail = result.conversation;
				paintDeskDetail(result.conversation, '');
				loadDeskList(true);
				toast(__('Reply sent.', 'omninox-site-toolkit'));
			})
			.catch(function (error) {
				desk.sending = false;
				field.disabled = false;
				toast(error.message, true);
			});
	}

	function setDeskStatus(id, status) {
		api('/support/status', { method: 'POST', body: { id: id, status: status } })
			.then(function (data) {
				desk.detail = data;
				paintDeskDetail(data, '');
				loadDeskList(true);
			})
			.catch(function (error) { toast(error.message, true); });
	}

	function setDeskPriority(id, priority) {
		api('/support/priority', { method: 'POST', body: { id: id, priority: priority } })
			.then(function (data) {
				desk.detail = data;
				paintDeskDetail(data, '');
				loadDeskList(true);
				toast(__('Priority updated.', 'omninox-site-toolkit'));
			})
			.catch(function (error) { toast(error.message, true); });
	}

	function setDeskAgent(id, agentId) {
		api('/support/assign', { method: 'POST', body: { id: id, agent_id: agentId } })
			.then(function (data) {
				desk.detail = data;
				paintDeskDetail(data, '');
				loadDeskList(true);
				toast(__('Agent assigned.', 'omninox-site-toolkit'));
			})
			.catch(function (error) { toast(error.message, true); });
	}

	function relativeTime(at) {
		if (!at) { return ''; }

		var parsed = new Date(String(at).replace(' ', 'T'));

		if (isNaN(parsed.getTime())) { return ''; }

		var seconds = Math.round((Date.now() - parsed.getTime()) / 1000);

		if (seconds < 60) { return __('just now', 'omninox-site-toolkit'); }
		if (seconds < 3600) { return Math.round(seconds / 60) + __('m ago', 'omninox-site-toolkit'); }
		if (seconds < 86400) { return Math.round(seconds / 3600) + __('h ago', 'omninox-site-toolkit'); }

		return parsed.toLocaleDateString();
	}

	/* --------------------------------------------------------------- Reports */

	function renderReports(wrapper) {
		wrapper.appendChild(header(
			__('Reports', 'omninox-site-toolkit'),
			__('Build a client-ready audit from the most recent scan of each module.', 'omninox-site-toolkit'),
			[],
			'reports'
		));

		var premium = WPMT.license && WPMT.license.premium;

		wrapper.appendChild(el('div', { class: 'wpmt-card' }, [
			el('h2', { text: __('Generate', 'omninox-site-toolkit') }),
			el('div', { style: 'display:flex;gap:8px;flex-wrap:wrap' }, [
				reportButton('html', __('HTML report', 'omninox-site-toolkit'), true),
				reportButton('pdf', __('PDF report', 'omninox-site-toolkit'), premium),
				reportButton('csv', __('CSV export', 'omninox-site-toolkit'), premium),
				reportButton('json', __('JSON export', 'omninox-site-toolkit'), premium)
			]),
			el('p', {
				style: 'margin:12px 0 0;font-size:12px;color:var(--wpmt-ink-soft)',
				text: __('PDF export uses Dompdf when it is installed; otherwise a print-ready HTML file is produced instead.', 'omninox-site-toolkit')
			})
		]));
	}

	function reportButton(format, label, enabled) {
		return el('button', {
			type: 'button',
			class: 'wpmt-btn' + (format === 'html' ? ' wpmt-btn-primary' : ''),
			disabled: !enabled || !!state.busy['report:' + format],
			text: state.busy['report:' + format] ? __('Building…', 'omninox-site-toolkit') : label,
			onClick: function () {
				state.busy['report:' + format] = true;
				render();

				api('/report', { method: 'POST', body: { format: format, modules: [] } })
					.then(function (result) {
						state.busy['report:' + format] = false;
						render();
						toast(result.notice || __('Report ready.', 'omninox-site-toolkit'));
						window.open(result.url, '_blank', 'noopener');
					})
					.catch(function (error) {
						state.busy['report:' + format] = false;
						render();
						toast(error.message, true);
					});
			}
		});
	}

	/* -------------------------------------------------- Smart Unused Media Detector */

	function formatBytes(bytes) {
		bytes = Number(bytes) || 0;
		if (bytes <= 0) return '0 B';
		var units = ['B', 'KB', 'MB', 'GB', 'TB'];
		var i = Math.floor(Math.log(bytes) / Math.log(1024));
		if (i >= units.length) i = units.length - 1;
		return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
	}

	function getStatusLabel(status) {
		switch (status) {
			case 'likely_unused': return __('Likely Unused', 'omninox-site-toolkit');
			case 'used': return __('Verified Used', 'omninox-site-toolkit');
			case 'protected': return __('Protected', 'omninox-site-toolkit');
			case 'quarantined': return __('In Quarantine', 'omninox-site-toolkit');
			default: return __('Unknown / Review', 'omninox-site-toolkit');
		}
	}

	function renderUnusedMediaManager(wrapper, module) {
		if (!state.unused.loaded && !state.unused.scanning && !state.busy['unused_load']) {
			loadUnusedResults(1);
		}

		var stats = state.unused.stats || {
			total: 0,
			used: 0,
			likely_unused: 0,
			unknown: 0,
			protected: 0,
			recoverable_bytes: 0
		};

		// 1. Stats Bar
		var statsCards = el('div', { class: 'wpmt-unused-stats-grid' }, [
			el('div', {
				class: 'wpmt-unused-stat-card' + (state.unused.filter === 'all' ? ' is-active' : ''),
				style: 'cursor:pointer;',
				onClick: function () {
					state.unused.filter = 'all';
					state.unused.page = 1;
					loadUnusedResults(1);
				}
			}, [
				el('span', { class: 'wpmt-unused-stat-title', text: __('Scanned Images', 'omninox-site-toolkit') }),
				el('span', { class: 'wpmt-unused-stat-value', text: String(stats.total || 0) }),
				el('span', { class: 'wpmt-unused-stat-sub', text: __('Show all attachments', 'omninox-site-toolkit') })
			]),
			el('div', {
				class: 'wpmt-unused-stat-card stat-unused' + (state.unused.filter === 'likely_unused' ? ' is-active' : ''),
				style: 'cursor:pointer;',
				onClick: function () {
					state.unused.filter = 'likely_unused';
					state.unused.page = 1;
					loadUnusedResults(1);
				}
			}, [
				el('span', { class: 'wpmt-unused-stat-title', text: __('Likely Unused', 'omninox-site-toolkit') }),
				el('span', { class: 'wpmt-unused-stat-value', text: String(stats.likely_unused || 0) }),
				el('span', { class: 'wpmt-unused-stat-sub', text: __('No references detected (Active)', 'omninox-site-toolkit') })
			]),
			el('div', {
				class: 'wpmt-unused-stat-card stat-used' + (state.unused.filter === 'used' ? ' is-active' : ''),
				style: 'cursor:pointer;',
				onClick: function () {
					state.unused.filter = 'used';
					state.unused.page = 1;
					loadUnusedResults(1);
				}
			}, [
				el('span', { class: 'wpmt-unused-stat-title', text: __('Verified In Use', 'omninox-site-toolkit') }),
				el('span', { class: 'wpmt-unused-stat-value', text: String(stats.used || 0) }),
				el('span', { class: 'wpmt-unused-stat-sub', text: __('Content, theme or builder', 'omninox-site-toolkit') })
			]),
			el('div', {
				class: 'wpmt-unused-stat-card stat-protected' + (state.unused.filter === 'protected' ? ' is-active' : ''),
				style: 'cursor:pointer;',
				onClick: function () {
					state.unused.filter = 'protected';
					state.unused.page = 1;
					loadUnusedResults(1);
				}
			}, [
				el('span', { class: 'wpmt-unused-stat-title', text: __('Protected', 'omninox-site-toolkit') }),
				el('span', { class: 'wpmt-unused-stat-value', text: String(stats.protected || 0) }),
				el('span', { class: 'wpmt-unused-stat-sub', text: __('Site identity or recent uploads', 'omninox-site-toolkit') })
			]),
			el('div', { class: 'wpmt-unused-stat-card stat-storage' }, [
				el('span', { class: 'wpmt-unused-stat-title', text: __('Recoverable Space', 'omninox-site-toolkit') }),
				el('span', { class: 'wpmt-unused-stat-value', text: formatBytes(stats.recoverable_bytes || 0) }),
				el('span', { class: 'wpmt-unused-stat-sub', text: __('Estimated storage savings', 'omninox-site-toolkit') })
			])
		]);
		wrapper.appendChild(statsCards);

		// 2. Toolbar
		var isScanning = !!state.unused.scanning;
		var isPaused = !!state.unused.paused;
		var toolbar = el('div', { class: 'wpmt-unused-toolbar' }, [
			el('div', { class: 'wpmt-unused-toolbar-group' }, [
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-primary',
					disabled: isScanning,
					onClick: function () { startUnusedScan('full'); }
				}, [
					icon('refresh', 14, 'wpmt-btn-icon'),
					document.createTextNode(' ' + (isScanning ? (isPaused ? __('Scan Paused', 'omninox-site-toolkit') : __('Scanning…', 'omninox-site-toolkit')) : __('Scan Website Images', 'omninox-site-toolkit')))
				]),
				el('button', {
					type: 'button',
					class: 'wpmt-btn',
					disabled: isScanning,
					title: __('Quick Scan only changed content and media since previous scan', 'omninox-site-toolkit'),
					onClick: function () { startUnusedScan('quick'); }
				}, [
					icon('sparkle', 14, 'wpmt-btn-icon'),
					document.createTextNode(' ' + __('Quick Scan', 'omninox-site-toolkit'))
				]),
				select([
					['likely_unused', __('Filter: Likely Unused', 'omninox-site-toolkit')],
					['all', __('Filter: All Statuses', 'omninox-site-toolkit')],
					['used', __('Filter: Verified In Use', 'omninox-site-toolkit')],
					['protected', __('Filter: Protected', 'omninox-site-toolkit')],
					['unknown', __('Filter: Unknown / Review', 'omninox-site-toolkit')],
					['quarantined', __('Filter: In Quarantine', 'omninox-site-toolkit')]
				], state.unused.filter || 'likely_unused', function (val) {
					state.unused.filter = val;
					state.unused.page = 1;
					loadUnusedResults(1);
				}),
				el('input', {
					type: 'text',
					class: 'wpmt-unused-search',
					placeholder: __('Search filenames…', 'omninox-site-toolkit'),
					value: state.unused.search || '',
					onInput: function (e) {
						state.unused.search = e.target.value;
						if (window._wpmtSearchTimer) clearTimeout(window._wpmtSearchTimer);
						window._wpmtSearchTimer = setTimeout(function () {
							state.unused.page = 1;
							loadUnusedResults(1);
						}, 350);
					}
				})
			]),
			el('div', { class: 'wpmt-unused-toolbar-group' }, [
				el('button', {
					type: 'button',
					class: 'wpmt-btn',
					title: __('Scan Settings', 'omninox-site-toolkit'),
					onClick: function () {
						state.unused.showSettings = true;
						render();
					}
				}, [
					icon('settings', 14, 'wpmt-btn-icon'),
					document.createTextNode(' ' + __('Settings', 'omninox-site-toolkit'))
				]),
				el('button', {
					type: 'button',
					class: 'wpmt-btn',
					title: __('Quarantine & Audit History', 'omninox-site-toolkit'),
					onClick: function () {
						loadQuarantineAndHistory();
					}
				}, [
					icon('security', 14, 'wpmt-btn-icon'),
					document.createTextNode(' ' + __('Quarantine & History', 'omninox-site-toolkit'))
				]),
				el('button', {
					type: 'button',
					class: 'wpmt-btn',
					title: __('Export scan results', 'omninox-site-toolkit'),
					onClick: function () {
						exportUnusedResults('csv');
					}
				}, [
					icon('download', 14, 'wpmt-btn-icon'),
					document.createTextNode(' ' + __('Export CSV', 'omninox-site-toolkit'))
				])
			])
		]);
		wrapper.appendChild(toolbar);

		// 3. Phase 11 Progress Box with live metrics & controls
		if (isScanning) {
			var progressBox = el('div', { class: 'wpmt-unused-progress-box', style: 'background:var(--wpmt-surface-2);border:1px solid var(--wpmt-border);border-radius:var(--wpmt-radius);padding:18px 20px;margin-bottom:16px;' }, [
				el('div', { style: 'display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px;flex-wrap:wrap;gap:12px;' }, [
					el('div', {}, [
						el('div', { style: 'font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--wpmt-primary);display:flex;align-items:center;gap:6px;' }, [
							icon('media', 13),
							document.createTextNode(__('Smart Media Scanner', 'omninox-site-toolkit') + (isPaused ? (' — ' + __('PAUSED', 'omninox-site-toolkit')) : ''))
						]),
						el('div', { style: 'font-size:15px;font-weight:700;color:var(--wpmt-ink);margin-top:3px;', text: state.unused.scanPhaseLabel || __('Scanning website references…', 'omninox-site-toolkit') })
					]),
					el('div', { style: 'display:flex;align-items:center;gap:8px;' }, [
						isPaused ? el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm wpmt-btn-primary',
							onClick: function () { resumeUnusedScan(); }
						}, [
							icon('check', 12),
							document.createTextNode(' ' + __('Resume', 'omninox-site-toolkit'))
						]) : el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm',
							onClick: function () { pauseUnusedScan(); }
						}, [
							document.createTextNode(__('Pause', 'omninox-site-toolkit'))
						]),
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm wpmt-btn-danger',
							onClick: function () { cancelUnusedScan(); }
						}, [
							document.createTextNode(__('Cancel', 'omninox-site-toolkit'))
						])
					])
				]),
				// Metric Row
				el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:14px;background:var(--wpmt-surface);padding:12px 14px;border-radius:var(--wpmt-radius-xs);border:1px solid var(--wpmt-border);font-size:12px;' }, [
					el('div', {}, [
						el('span', { style: 'color:var(--wpmt-ink-muted);display:block;font-size:11px;', text: __('Images Discovered', 'omninox-site-toolkit') }),
						el('strong', { style: 'font-size:14px;color:var(--wpmt-ink);', text: (state.unused.imagesDiscovered || 0).toLocaleString() })
					]),
					el('div', {}, [
						el('span', { style: 'color:var(--wpmt-ink-muted);display:block;font-size:11px;', text: __('Sources Processed', 'omninox-site-toolkit') }),
						el('strong', { style: 'font-size:14px;color:var(--wpmt-ink);', text: (state.unused.sourcesProcessed || 0).toLocaleString() + ' / ' + (state.unused.sourcesTotal || 1).toLocaleString() })
					]),
					el('div', {}, [
						el('span', { style: 'color:var(--wpmt-ink-muted);display:block;font-size:11px;', text: __('References Indexed', 'omninox-site-toolkit') }),
						el('strong', { style: 'font-size:14px;color:var(--wpmt-primary);', text: (state.unused.referencesIndexed || 0).toLocaleString() })
					]),
					el('div', {}, [
						el('span', { style: 'color:var(--wpmt-ink-muted);display:block;font-size:11px;', text: __('Progress', 'omninox-site-toolkit') }),
						el('strong', { style: 'font-size:14px;color:var(--wpmt-ink);', text: (state.unused.scanProgress || 0) + '%' })
					])
				]),
				// Progress bar fill
				el('div', { class: 'wpmt-unused-progress-bar-bg', style: 'height:8px;border-radius:4px;background:var(--wpmt-border);overflow:hidden;position:relative;' }, [
					el('div', { class: 'wpmt-unused-progress-bar-fill', style: 'height:100%;background:var(--wpmt-primary);transition:width 0.3s ease;width:' + (state.unused.scanProgress || 0) + '%' })
				]),
				el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-top:8px;font-size:11.5px;color:var(--wpmt-ink-muted);' }, [
					el('span', { text: state.unused.scanStep || __('Processing high-speed database index…', 'omninox-site-toolkit') }),
					el('span', { text: __('Safe & Non-blocking', 'omninox-site-toolkit') })
				])
			]);
			wrapper.appendChild(progressBox);
		}

		// 4. Results Table
		var items = state.unused.items || [];
		var selectedCount = Object.keys(state.unused.selected).filter(function (id) { return state.unused.selected[id]; }).length;
		var allVisibleSelected = items.length > 0 && items.every(function (item) { return !!state.unused.selected[item.id]; });

		var tableCard = el('div', { class: 'wpmt-unused-table-card' }, [
			el('table', { class: 'wpmt-table', style: 'width:100%;border-collapse:collapse;' }, [
				el('thead', {}, [
					el('tr', {}, [
						el('th', { style: 'width:36px;text-align:center;' }, [
							el('input', {
								type: 'checkbox',
								checked: allVisibleSelected,
								onChange: function (e) {
									var checked = e.target.checked;
									items.forEach(function (item) {
										state.unused.selected[item.id] = checked;
									});
									render();
								}
							})
						]),
						el('th', { style: 'width:64px;text-align:center;', text: __('Preview', 'omninox-site-toolkit') }),
						el('th', { text: __('Filename', 'omninox-site-toolkit') }),
						el('th', { style: 'width:90px;', text: __('File Size', 'omninox-site-toolkit') }),
						el('th', { style: 'width:110px;', text: __('Uploaded', 'omninox-site-toolkit') }),
						el('th', { style: 'width:130px;', text: __('Status', 'omninox-site-toolkit') }),
						el('th', { style: 'width:140px;', text: __('References', 'omninox-site-toolkit') }),
						el('th', { style: 'width:190px;text-align:right;', text: __('Actions', 'omninox-site-toolkit') })
					])
				]),
				(allVisibleSelected && (state.unused.totalItems || 0) > items.length) ? el('tbody', {}, [
					el('tr', { style: 'background:rgba(45,212,191,0.08);text-align:center;' }, [
						el('td', { colspan: 8, style: 'padding:10px 16px;font-size:12.5px;color:var(--wpmt-ink);border-bottom:1px solid var(--wpmt-border);' }, [
							state.unused.selectAllEligible
								? el('span', {}, [
									document.createTextNode(sprintf(
										/* translators: %d: count */
										__('All %d images in current filter are selected. ', 'omninox-site-toolkit'),
										state.unused.totalItems
									)),
									el('a', {
										href: '#',
										style: 'font-weight:600;margin-left:8px;text-decoration:underline;color:var(--wpmt-primary);cursor:pointer;',
										onClick: function (e) {
											e.preventDefault();
											state.unused.selectAllEligible = false;
											state.unused.selected = {};
											render();
										}
									}, [document.createTextNode(__('Clear selection', 'omninox-site-toolkit'))])
								])
								: el('span', {}, [
									document.createTextNode(sprintf(
										/* translators: %d: count */
										__('All %d images on this page are selected. ', 'omninox-site-toolkit'),
										items.length
									)),
									el('a', {
										href: '#',
										style: 'font-weight:600;margin-left:8px;text-decoration:underline;color:var(--wpmt-primary);cursor:pointer;',
										onClick: function (e) {
											e.preventDefault();
											state.unused.selectAllEligible = true;
											selectAllMatchingFilter();
										}
									}, [document.createTextNode(sprintf(
										/* translators: %d: count */
										__('Select all %d images across all pages', 'omninox-site-toolkit'),
										state.unused.totalItems
									))])
								])
						])
					])
				]) : null,
				el('tbody', {}, items.length ? items.map(function (item) {
					var isChecked = !!state.unused.selected[item.id];
					var isQuarantined = item.status === 'quarantined' || !!item.is_quarantined;
					var isProtected = item.status === 'protected' || !!item.is_protected;

					return el('tr', { style: isChecked ? 'background:rgba(45,212,191,0.06);' : '' }, [
						el('td', { style: 'text-align:center;' }, [
							el('input', {
								type: 'checkbox',
								checked: isChecked,
								onChange: function (e) {
									state.unused.selected[item.id] = e.target.checked;
									render();
								}
							})
						]),
						el('td', { style: 'text-align:center;' }, [
							item.thumb_url
								? el('img', {
									src: item.thumb_url,
									alt: item.filename,
									class: 'wpmt-unused-thumb',
									onClick: function () {
										state.unused.activePreview = item;
										render();
									}
								})
								: el('div', { class: 'wpmt-unused-thumb', style: 'display:flex;align-items:center;justify-content:center;' }, [
									icon('media', 20)
								])
						]),
						el('td', {}, [
							el('div', { style: 'font-weight:600;color:var(--wpmt-ink);' }, [
								el('a', {
									href: item.edit_url || ('post.php?post=' + item.id + '&action=edit'),
									target: '_blank',
									rel: 'noopener',
									text: item.filename || ('#' + item.id),
									style: 'color:var(--wpmt-ink);text-decoration:none;'
								})
							]),
							el('small', { style: 'color:var(--wpmt-ink-muted);', text: 'ID: ' + item.id + (item.dimensions ? (' · ' + item.dimensions) : '') })
						]),
						el('td', { text: item.formatted_size || item.file_size_fmt || formatBytes(item.bytes || item.file_size || 0) }),
						el('td', { text: item.date || item.uploaded || '—' }),
						el('td', {}, [
							el('span', {
								class: 'wpmt-badge-status is-' + (item.status || 'unknown'),
								text: getStatusLabel(item.status)
							})
						]),
						el('td', {}, [
							((typeof item.references_count !== 'undefined' ? item.references_count : item.reference_count) > 0)
								? el('button', {
									type: 'button',
									class: 'wpmt-btn wpmt-btn-sm',
									onClick: function () {
										state.unused.activeReferences = item;
										render();
									}
								}, [
									document.createTextNode(
										(typeof item.references_count !== 'undefined' ? item.references_count : item.reference_count) + ' ' +
										((typeof item.references_count !== 'undefined' ? item.references_count : item.reference_count) === 1 ? __('reference', 'omninox-site-toolkit') : __('references', 'omninox-site-toolkit'))
									)
								])
								: el('span', { style: 'color:var(--wpmt-ink-muted);font-size:12px;', text: __('None found', 'omninox-site-toolkit') })
						]),
						el('td', { style: 'text-align:right;' }, [
							el('div', { style: 'display:inline-flex;gap:6px;' }, [
								isQuarantined ? el('button', {
									type: 'button',
									class: 'wpmt-btn wpmt-btn-sm',
									title: __('Restore to uploads folder', 'omninox-site-toolkit'),
									onClick: function () {
										restoreUnusedItems([item.id]);
									}
								}, [document.createTextNode(__('Restore', 'omninox-site-toolkit'))]) : null,

								(!isQuarantined && item.status === 'likely_unused') ? el('button', {
									type: 'button',
									class: 'wpmt-btn wpmt-btn-sm',
									title: __('Isolate into safe quarantine folder', 'omninox-site-toolkit'),
									onClick: function () {
										quarantineUnusedItems([item.id]);
									}
								}, [document.createTextNode(__('Quarantine', 'omninox-site-toolkit'))]) : null,

								!isQuarantined ? el('button', {
									type: 'button',
									class: 'wpmt-btn wpmt-btn-sm',
									title: isProtected ? __('Remove protection', 'omninox-site-toolkit') : __('Mark protected from deletion', 'omninox-site-toolkit'),
									onClick: function () {
										toggleUnusedProtection(item.id, !isProtected);
									}
								}, [
									icon(isProtected ? 'check' : 'security', 12),
									document.createTextNode(isProtected ? (' ' + __('Protected', 'omninox-site-toolkit')) : (' ' + __('Protect', 'omninox-site-toolkit')))
								]) : null,

								el('button', {
									type: 'button',
									class: 'wpmt-btn wpmt-btn-sm wpmt-btn-danger',
									title: __('Permanently delete this attachment', 'omninox-site-toolkit'),
									onClick: function () {
										confirmPermanentDelete([item.id], item.filename);
									}
								}, [document.createTextNode(__('Delete', 'omninox-site-toolkit'))])
							])
						])
					]);
				}) : [
					el('tr', {}, [
						el('td', { colspan: 8, style: 'text-align:center;padding:32px;color:var(--wpmt-ink-soft);' }, [
							el('strong', { text: __('No media matches current filter.', 'omninox-site-toolkit') }),
							el('p', { style: 'margin-top:6px;', text: __('Click "Scan Website Images" to analyze all content, blocks, and builders for image references.', 'omninox-site-toolkit') })
						])
					])
				])
			]),
			// Pagination
			el('div', { class: 'wpmt-table-helper-bar', style: 'padding:12px 16px;border-top:1px solid var(--wpmt-border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;' }, [
				el('div', { style: 'display:flex;align-items:center;gap:16px;' }, [
					el('small', {
						text: sprintf(
							/* translators: 1: current page, 2: total pages, 3: total items */
							__('Showing page %1$d of %2$d (%3$d total items)', 'omninox-site-toolkit'),
							state.unused.page || 1,
							state.unused.totalPages || 1,
							state.unused.totalItems || 0
						)
					}),
					el('div', { style: 'display:inline-flex;align-items:center;gap:6px;' }, [
						el('span', { style: 'font-size:12px;color:var(--wpmt-ink-muted);', text: __('Per page:', 'omninox-site-toolkit') }),
						el('select', {
							class: 'wpmt-select wpmt-select-sm',
							style: 'padding:3px 8px;font-size:12px;border-radius:var(--wpmt-radius-xs);border:1px solid var(--wpmt-border);background:var(--wpmt-surface-2);color:var(--wpmt-ink);cursor:pointer;',
							onChange: function (e) {
								state.unused.perPage = parseInt(e.target.value, 10) || 100;
								state.unused.selected = {};
								state.unused.selectAllEligible = false;
								loadUnusedResults(1);
							}
						}, [50, 100, 200, 500].map(function (n) {
							return el('option', {
								value: String(n),
								selected: (state.unused.perPage || 100) === n,
								text: String(n)
							});
						}))
					])
				]),
				el('div', { class: 'wpmt-table-helper-buttons' }, [
					el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-sm',
						disabled: (state.unused.page || 1) <= 1,
						onClick: function () {
							if ((state.unused.page || 1) > 1) {
								state.unused.page--;
								loadUnusedResults(state.unused.page);
							}
						}
					}, [document.createTextNode(__('Previous', 'omninox-site-toolkit'))]),
					el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-sm',
						disabled: (state.unused.page || 1) >= (state.unused.totalPages || 1),
						onClick: function () {
							if ((state.unused.page || 1) < (state.unused.totalPages || 1)) {
								state.unused.page++;
								loadUnusedResults(state.unused.page);
							}
						}
					}, [document.createTextNode(__('Next', 'omninox-site-toolkit'))])
				])
			])
		]);
		wrapper.appendChild(tableCard);

		// 5. Floating Bulk Actions Bar
		if (selectedCount > 0) {
			var bulkBar = el('div', { class: 'wpmt-bulk-bar' }, [
				el('div', { style: 'display:flex;align-items:center;gap:12px;' }, [
					el('strong', {
						text: sprintf(
							/* translators: %d: count */
							__('%d images selected', 'omninox-site-toolkit'),
							selectedCount
						)
					}),
					el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-sm',
						onClick: function () {
							state.unused.selected = {};
							render();
						}
					}, [document.createTextNode(__('Clear selection', 'omninox-site-toolkit'))])
				]),
				el('div', { style: 'display:flex;align-items:center;gap:8px;' }, [
					state.unused.filter === 'quarantined' ? el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-primary',
						onClick: function () {
							var ids = Object.keys(state.unused.selected).filter(function (id) { return state.unused.selected[id]; });
							restoreUnusedItems(ids);
						}
					}, [
						icon('check', 14, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Restore Selected', 'omninox-site-toolkit'))
					]) : el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-primary',
						onClick: function () {
							var ids = Object.keys(state.unused.selected).filter(function (id) { return state.unused.selected[id]; });
							quarantineUnusedItems(ids);
						}
					}, [
						icon('security', 14, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Quarantine Selected (Safe)', 'omninox-site-toolkit'))
					]),
					el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-danger',
						onClick: function () {
							var ids = Object.keys(state.unused.selected).filter(function (id) { return state.unused.selected[id]; });
							confirmPermanentDelete(ids);
						}
					}, [
						icon('trash', 14, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Delete Permanently', 'omninox-site-toolkit'))
					])
				])
			]);
			wrapper.appendChild(bulkBar);
		}

		// 6. Modals & Overlays
		renderUnusedModals(wrapper);
	}

	function renderUnusedModals(wrapper) {
		// A. Lightbox Modal
		if (state.unused.activePreview) {
			var preview = state.unused.activePreview;
			var lightbox = el('div', {
				class: 'wpmt-lightbox-modal',
				onClick: function (e) {
					if (e.target === lightbox) {
						state.unused.activePreview = null;
						render();
					}
				}
			}, [
				el('div', { class: 'wpmt-lightbox-card' }, [
					el('div', { style: 'padding:16px 20px;border-bottom:1px solid var(--wpmt-border);display:flex;justify-content:space-between;align-items:center;' }, [
						el('strong', { text: preview.filename || ('#' + preview.id) }),
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm',
							onClick: function () {
								state.unused.activePreview = null;
								render();
							}
						}, [document.createTextNode('✕')])
					]),
					el('div', { class: 'wpmt-lightbox-preview' }, [
						preview.url
							? el('img', { src: preview.url, alt: preview.filename })
							: el('span', { text: __('No preview available', 'omninox-site-toolkit') })
					]),
					el('div', { style: 'padding:16px 20px;font-size:13px;display:grid;grid-template-columns:1fr 1fr;gap:12px;' }, [
						el('div', {}, [
							el('p', { style: 'margin:0 0 4px;color:var(--wpmt-ink-muted);', text: __('Attachment ID', 'omninox-site-toolkit') + ': ' + preview.id }),
							el('p', { style: 'margin:0 0 4px;color:var(--wpmt-ink-muted);', text: __('File Size', 'omninox-site-toolkit') + ': ' + (preview.formatted_size || formatBytes(preview.bytes || 0)) }),
							el('p', { style: 'margin:0;color:var(--wpmt-ink-muted);', text: __('Dimensions', 'omninox-site-toolkit') + ': ' + (preview.dimensions || '—') })
						]),
						el('div', {}, [
							el('p', { style: 'margin:0 0 4px;color:var(--wpmt-ink-muted);', text: __('Upload Date', 'omninox-site-toolkit') + ': ' + (preview.date || '—') }),
							el('p', { style: 'margin:0 0 4px;color:var(--wpmt-ink-muted);', text: __('Status', 'omninox-site-toolkit') + ': ' + getStatusLabel(preview.status) }),
							el('p', { style: 'margin:0;' }, [
								el('a', {
									href: preview.edit_url || ('post.php?post=' + preview.id + '&action=edit'),
									target: '_blank',
									rel: 'noopener',
									text: __('Edit in Media Library ↗', 'omninox-site-toolkit'),
									style: 'color:var(--wpmt-accent);'
								})
							])
						])
					])
				])
			]);
			wrapper.appendChild(lightbox);
		}

		// B. References Details Modal
		if (state.unused.activeReferences) {
			var refItem = state.unused.activeReferences;
			var refModal = el('div', {
				class: 'wpmt-lightbox-modal',
				onClick: function (e) {
					if (e.target === refModal) {
						state.unused.activeReferences = null;
						render();
					}
				}
			}, [
				el('div', { class: 'wpmt-lightbox-card', style: 'max-width:700px;' }, [
					el('div', { style: 'padding:16px 20px;border-bottom:1px solid var(--wpmt-border);display:flex;justify-content:space-between;align-items:center;' }, [
						el('div', {}, [
							el('strong', { text: __('Verified Usage References', 'omninox-site-toolkit') }),
							el('div', { style: 'font-size:12px;color:var(--wpmt-ink-muted);margin-top:2px;', text: refItem.filename + ' (ID: ' + refItem.id + ')' })
						]),
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm',
							onClick: function () {
								state.unused.activeReferences = null;
								render();
							}
						}, [document.createTextNode('✕')])
					]),
					el('div', { style: 'padding:16px 20px;max-height:450px;overflow-y:auto;' }, [
						refItem.references && refItem.references.length ? el('table', { class: 'wpmt-table', style: 'width:100%;font-size:12px;' }, [
							el('thead', {}, [
								el('tr', {}, [
									el('th', { text: __('Object Type', 'omninox-site-toolkit') }),
									el('th', { text: __('Object ID / Title', 'omninox-site-toolkit') }),
									el('th', { text: __('Source', 'omninox-site-toolkit') }),
									el('th', { text: __('Field / Location', 'omninox-site-toolkit') }),
									el('th', { text: __('Method', 'omninox-site-toolkit') })
								])
							]),
							el('tbody', {}, refItem.references.map(function (ref) {
								return el('tr', {}, [
									el('td', { text: ref.type || 'post' }),
									el('td', {}, [
										ref.id ? el('a', {
											href: 'post.php?post=' + ref.id + '&action=edit',
											target: '_blank',
											rel: 'noopener',
											text: '#' + ref.id,
											style: 'color:var(--wpmt-accent);'
										}) : el('span', { text: '—' })
									]),
									el('td', { text: ref.source || 'content' }),
									el('td', { text: ref.field || '—' }),
									el('td', { text: ref.method || 'id_match' })
								]);
							}))
						]) : el('p', { style: 'color:var(--wpmt-ink-soft);text-align:center;padding:20px;', text: __('No direct references found for this image.', 'omninox-site-toolkit') })
					])
				])
			]);
			wrapper.appendChild(refModal);
		}

		// C. Settings Modal
		if (state.unused.showSettings) {
			var curSettings = state.unused.settings || {
				protect_recent_days: 30,
				protect_site_identity: 1,
				protect_woo_catalog: 1
			};

			var settingsModal = el('div', {
				class: 'wpmt-lightbox-modal',
				onClick: function (e) {
					if (e.target === settingsModal) {
						state.unused.showSettings = false;
						render();
					}
				}
			}, [
				el('div', { class: 'wpmt-lightbox-card', style: 'max-width:560px;' }, [
					el('div', { style: 'padding:16px 20px;border-bottom:1px solid var(--wpmt-border);display:flex;justify-content:space-between;align-items:center;' }, [
						el('strong', { text: __('Unused Media Scanner Settings', 'omninox-site-toolkit') }),
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm',
							onClick: function () {
								state.unused.showSettings = false;
								render();
							}
						}, [document.createTextNode('✕')])
					]),
					el('div', { style: 'padding:20px;display:flex;flex-direction:column;gap:16px;' }, [
						el('label', { class: 'wpmt-field' }, [
							el('span', { style: 'font-weight:600;', text: __('Protect Recently Uploaded Images', 'omninox-site-toolkit') }),
							el('p', { style: 'font-size:12px;color:var(--wpmt-ink-muted);margin:2px 0 6px;', text: __('Images uploaded within this number of days will never be marked as unused (useful for upcoming drafts and scheduled posts).', 'omninox-site-toolkit') }),
							el('input', {
								type: 'number',
								min: 0,
								max: 365,
								value: String(curSettings.protect_recent_days || 30),
								style: 'padding:8px;background:var(--wpmt-surface-2);border:1px solid var(--wpmt-border);color:var(--wpmt-ink);border-radius:var(--wpmt-radius-xs);width:120px;',
								onChange: function (e) {
									curSettings.protect_recent_days = parseInt(e.target.value, 10) || 0;
								}
							})
						]),
						el('label', { style: 'display:flex;align-items:flex-start;gap:10px;cursor:pointer;' }, [
							el('input', {
								type: 'checkbox',
								checked: !!curSettings.protect_site_identity,
								onChange: function (e) {
									curSettings.protect_site_identity = e.target.checked ? 1 : 0;
								}
							}),
							el('div', {}, [
								el('span', { style: 'font-weight:600;', text: __('Protect Site Identity & Theme Settings', 'omninox-site-toolkit') }),
								el('p', { style: 'font-size:12px;color:var(--wpmt-ink-muted);margin:2px 0 0;', text: __('Always protect Custom Logo, Site Icon (Favicon), and Customizer Header images.', 'omninox-site-toolkit') })
							])
						]),
						el('label', { style: 'display:flex;align-items:flex-start;gap:10px;cursor:pointer;' }, [
							el('input', {
								type: 'checkbox',
								checked: !!curSettings.protect_woo_catalog,
								onChange: function (e) {
									curSettings.protect_woo_catalog = e.target.checked ? 1 : 0;
								}
							}),
							el('div', {}, [
								el('span', { style: 'font-weight:600;', text: __('Protect WooCommerce Product Catalog', 'omninox-site-toolkit') }),
								el('p', { style: 'font-size:12px;color:var(--wpmt-ink-muted);margin:2px 0 0;', text: __('Protect product gallery images, variable thumbnails, and category icons.', 'omninox-site-toolkit') })
							])
						])
					]),
					el('div', { style: 'padding:14px 20px;border-top:1px solid var(--wpmt-border);display:flex;justify-content:flex-end;gap:10px;' }, [
						el('button', {
							type: 'button',
							class: 'wpmt-btn',
							onClick: function () {
								state.unused.showSettings = false;
								render();
							}
						}, [document.createTextNode(__('Cancel', 'omninox-site-toolkit'))]),
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-primary',
							onClick: function () {
								state.unused.settings = curSettings;
								api('/fix/media', {
									method: 'POST',
									body: { action: 'unused_save_settings', params: { settings: curSettings } }
								}).then(function () {
									state.unused.showSettings = false;
									toast(__('Scanner settings saved.', 'omninox-site-toolkit'));
									render();
								});
							}
						}, [document.createTextNode(__('Save Settings', 'omninox-site-toolkit'))])
					])
				])
			]);
			wrapper.appendChild(settingsModal);
		}

		// D. Quarantine & Audit History Drawer
		if (state.unused.showHistory) {
			var histModal = el('div', {
				class: 'wpmt-lightbox-modal',
				onClick: function (e) {
					if (e.target === histModal) {
						state.unused.showHistory = false;
						render();
					}
				}
			}, [
				el('div', { class: 'wpmt-lightbox-card', style: 'max-width:850px;' }, [
					el('div', { style: 'padding:16px 20px;border-bottom:1px solid var(--wpmt-border);display:flex;justify-content:space-between;align-items:center;' }, [
						el('div', { style: 'display:flex;gap:12px;align-items:center;' }, [
							el('button', {
								type: 'button',
								class: 'wpmt-btn wpmt-btn-sm' + (state.unused.historyTab === 'quarantined' ? ' wpmt-btn-primary' : ''),
								onClick: function () {
									state.unused.historyTab = 'quarantined';
									render();
								}
							}, [document.createTextNode(__('Quarantined Files (' + (state.unused.quarantinedList || []).length + ')', 'omninox-site-toolkit'))]),
							el('button', {
								type: 'button',
								class: 'wpmt-btn wpmt-btn-sm' + (state.unused.historyTab === 'audit' ? ' wpmt-btn-primary' : ''),
								onClick: function () {
									state.unused.historyTab = 'audit';
									render();
								}
							}, [document.createTextNode(__('Audit History', 'omninox-site-toolkit'))])
						]),
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm',
							onClick: function () {
								state.unused.showHistory = false;
								render();
							}
						}, [document.createTextNode('✕')])
					]),
					el('div', { style: 'padding:16px 20px;max-height:480px;overflow-y:auto;' }, [
						state.unused.historyTab === 'quarantined' ? (
							(state.unused.quarantinedList && state.unused.quarantinedList.length) ? el('div', {}, [
								el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;' }, [
									el('p', { style: 'margin:0;font-size:12px;color:var(--wpmt-ink-muted);', text: __('Files currently isolated in uploads/omninox-media-quarantine/. Public access is forbidden.', 'omninox-site-toolkit') }),
									el('button', {
										type: 'button',
										class: 'wpmt-btn wpmt-btn-sm wpmt-btn-primary',
										onClick: function () {
											var allQIds = state.unused.quarantinedList.map(function (q) { return q.id; });
											restoreUnusedItems(allQIds);
										}
									}, [document.createTextNode(__('Restore All', 'omninox-site-toolkit'))])
								]),
								el('table', { class: 'wpmt-table', style: 'width:100%;font-size:12px;' }, [
									el('thead', {}, [
										el('tr', {}, [
											el('th', { text: __('Attachment ID', 'omninox-site-toolkit') }),
											el('th', { text: __('Filename', 'omninox-site-toolkit') }),
											el('th', { text: __('Total Size', 'omninox-site-toolkit') }),
											el('th', { text: __('Quarantined By', 'omninox-site-toolkit') }),
											el('th', { style: 'text-align:right;', text: __('Actions', 'omninox-site-toolkit') })
										])
									]),
									el('tbody', {}, state.unused.quarantinedList.map(function (q) {
										return el('tr', {}, [
											el('td', { text: '#' + q.id }),
											el('td', { style: 'font-weight:600;', text: q.filename }),
											el('td', { text: q.formatted_size }),
											el('td', { text: q.quarantined_by || 'admin' }),
											el('td', { style: 'text-align:right;' }, [
												el('div', { style: 'display:inline-flex;gap:6px;' }, [
													el('button', {
														type: 'button',
														class: 'wpmt-btn wpmt-btn-sm',
														onClick: function () {
															restoreUnusedItems([q.id]);
														}
													}, [document.createTextNode(__('Restore', 'omninox-site-toolkit'))]),
													el('button', {
														type: 'button',
														class: 'wpmt-btn wpmt-btn-sm wpmt-btn-danger',
														onClick: function () {
															confirmPermanentDelete([q.id], q.filename);
														}
													}, [document.createTextNode(__('Delete', 'omninox-site-toolkit'))])
												])
											])
										]);
									}))
								])
							]) : el('p', { style: 'text-align:center;padding:32px;color:var(--wpmt-ink-soft);', text: __('Quarantine folder is currently empty.', 'omninox-site-toolkit') })
						) : (
							(state.unused.history && state.unused.history.length) ? el('table', { class: 'wpmt-table', style: 'width:100%;font-size:12px;' }, [
								el('thead', {}, [
									el('tr', {}, [
										el('th', { text: __('Date & Time', 'omninox-site-toolkit') }),
										el('th', { text: __('Admin', 'omninox-site-toolkit') }),
										el('th', { text: __('Action', 'omninox-site-toolkit') }),
										el('th', { text: __('Filename / ID', 'omninox-site-toolkit') }),
										el('th', { text: __('Size', 'omninox-site-toolkit') }),
										el('th', { text: __('Status', 'omninox-site-toolkit') })
									])
								]),
								el('tbody', {}, state.unused.history.map(function (h) {
									return el('tr', {}, [
										el('td', { text: h.timestamp || '—' }),
										el('td', { text: h.user || 'admin' }),
										el('td', { style: 'font-weight:600;', text: h.action || '—' }),
										el('td', { text: (h.filename || '') + (h.attachment_id ? (' (#' + h.attachment_id + ')') : '') }),
										el('td', { text: formatBytes(h.size || 0) }),
										el('td', {}, [
											el('span', {
												class: 'wpmt-badge-status is-' + (h.status === 'success' ? 'used' : 'quarantined'),
												text: h.status || 'success'
											})
										])
									]);
								}))
							]) : el('p', { style: 'text-align:center;padding:32px;color:var(--wpmt-ink-soft);', text: __('No cleanup actions have been logged yet.', 'omninox-site-toolkit') })
						)
					])
				])
			]);
			wrapper.appendChild(histModal);
		}

		// E. Permanent Deletion Confirmation Modal
		if (state.unused.confirmDelete) {
			var delData = state.unused.confirmDelete;
			var delModal = el('div', {
				class: 'wpmt-lightbox-modal',
				onClick: function (e) {
					if (e.target === delModal) {
						state.unused.confirmDelete = null;
						render();
					}
				}
			}, [
				el('div', { class: 'wpmt-lightbox-card', style: 'max-width:520px;border-color:var(--wpmt-critical);' }, [
					el('div', { style: 'padding:16px 20px;border-bottom:1px solid var(--wpmt-border);background:var(--wpmt-critical-bg);color:var(--wpmt-critical);display:flex;align-items:center;gap:10px;' }, [
						icon('errors', 18),
						el('strong', { text: __('Permanent Image Deletion Warning', 'omninox-site-toolkit') })
					]),
					el('div', { style: 'padding:20px;font-size:13px;' }, [
						el('p', {
							style: 'margin:0 0 10px;line-height:1.5;', text: sprintf(
								/* translators: %d: count */
								__('Are you sure you want to permanently delete %d selected image(s)?', 'omninox-site-toolkit'),
								(delData.ids || []).length
							)
						}),
						el('div', { style: 'background:var(--wpmt-surface-2);border-left:3px solid var(--wpmt-critical);padding:10px 12px;margin-bottom:12px;border-radius:var(--wpmt-radius-xs);font-size:12px;color:var(--wpmt-ink-soft);' }, [
							el('strong', { style: 'color:var(--wpmt-critical);', text: __('Caution: This action is irreversible!', 'omninox-site-toolkit') }),
							el('p', { style: 'margin:4px 0 0;', text: __('WordPress will permanently erase the database attachment record, the original file, and all generated intermediate thumbnail derivatives from your server storage.', 'omninox-site-toolkit') })
						]),
						el('p', { style: 'margin:0;font-size:12px;color:var(--wpmt-ink-muted);', text: __('Tip: We recommend clicking "Quarantine" instead so files can be safely verified and restored if needed.', 'omninox-site-toolkit') })
					]),
					el('div', { style: 'padding:14px 20px;border-top:1px solid var(--wpmt-border);display:flex;justify-content:flex-end;gap:10px;' }, [
						el('button', {
							type: 'button',
							class: 'wpmt-btn',
							onClick: function () {
								state.unused.confirmDelete = null;
								render();
							}
						}, [document.createTextNode(__('Cancel', 'omninox-site-toolkit'))]),
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-danger',
							onClick: function () {
								deleteUnusedItems(delData.ids);
							}
						}, [
							icon('trash', 14, 'wpmt-btn-icon'),
							document.createTextNode(' ' + __('Yes, Permanently Delete', 'omninox-site-toolkit'))
						])
					])
				])
			]);
			wrapper.appendChild(delModal);
		}
	}

	function startUnusedScan(mode) {
		mode = mode || 'full';
		state.unused.scanning = true;
		state.unused.paused = false;
		state.unused.cancelling = false;
		state.unused.scanProgress = 2;
		state.unused.scanPhase = 'discovery';
		state.unused.scanPhaseLabel = __('Stage 1: Discovering Attachments & Content Sources…', 'omninox-site-toolkit');
		state.unused.scanStep = __('Initializing indexed database tables and source counters…', 'omninox-site-toolkit');
		state.unused.imagesDiscovered = 0;
		state.unused.sourcesProcessed = 0;
		state.unused.sourcesTotal = 0;
		state.unused.referencesIndexed = 0;
		state.unused.currentOffset = 0;
		render();

		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_scan_start', params: { mode: mode } }
		}).then(function (res) {
			if (state.unused.cancelling) {
				cancelUnusedScan();
				return;
			}
			state.unused.scanPhase = res.phase || 'indexing_meta';
			state.unused.scanPhaseLabel = res.phase_label || __('Stage 2: Indexing Media References…', 'omninox-site-toolkit');
			state.unused.scanProgress = res.progress || 5;
			state.unused.imagesDiscovered = res.images_discovered || 0;
			state.unused.sourcesProcessed = res.sources_processed || 0;
			state.unused.sourcesTotal = res.sources_total || 0;
			state.unused.referencesIndexed = res.references_indexed || 0;
			state.unused.scanStep = res.message || __('Indexing postmeta and featured image references…', 'omninox-site-toolkit');
			render();
			stepUnusedScan(0, res.images_discovered || 0);
		}).catch(function (err) {
			state.unused.scanning = false;
			render();
			toast(err.message || __('Scan failed to initialize.', 'omninox-site-toolkit'), true);
		});
	}

	function stepUnusedScan(offset, total) {
		if (state.unused.cancelling) {
			cancelUnusedScan();
			return;
		}

		if (state.unused.paused) {
			return;
		}

		state.unused.currentOffset = offset;
		var batch = 100;

		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_scan_step', params: { offset: offset, batch: batch } }
		}).then(function (res) {
			if (state.unused.cancelling) {
				cancelUnusedScan();
				return;
			}

			if (state.unused.paused) {
				return;
			}

			state.unused.scanPhase = res.phase || state.unused.scanPhase;
			state.unused.scanPhaseLabel = res.phase_label || state.unused.scanPhaseLabel;
			state.unused.scanProgress = res.progress || state.unused.scanProgress;
			state.unused.imagesDiscovered = res.images_discovered || state.unused.imagesDiscovered;
			state.unused.sourcesProcessed = res.sources_processed || state.unused.sourcesProcessed;
			state.unused.sourcesTotal = res.sources_total || state.unused.sourcesTotal;
			state.unused.referencesIndexed = res.references_indexed || state.unused.referencesIndexed;
			state.unused.scanStep = res.message || state.unused.scanStep;

			if (res.done === true || res.status === 'complete') {
				state.unused.scanning = false;
				state.unused.paused = false;
				state.unused.scanProgress = 100;
				state.unused.filter = 'likely_unused';
				render();
				toast(__('Unused media scan completed.', 'omninox-site-toolkit'));
				loadUnusedResults(1);
			} else {
				render();
				stepUnusedScan(res.offset || 0, res.total || total);
			}
		}).catch(function (err) {
			state.unused.scanning = false;
			state.unused.paused = false;
			render();
			toast(err.message || __('Scan interrupted.', 'omninox-site-toolkit'), true);
		});
	}

	function pauseUnusedScan() {
		state.unused.paused = true;
		render();
		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_scan_pause' }
		}).then(function () {
			toast(__('Scan paused.', 'omninox-site-toolkit'));
		});
	}

	function resumeUnusedScan() {
		state.unused.paused = false;
		render();
		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_scan_resume' }
		}).then(function () {
			toast(__('Resuming scan…', 'omninox-site-toolkit'));
			stepUnusedScan(state.unused.currentOffset || 0, state.unused.imagesDiscovered || 0);
		});
	}

	function cancelUnusedScan() {
		state.unused.scanning = false;
		state.unused.paused = false;
		state.unused.cancelling = false;
		render();
		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_scan_cancel' }
		}).then(function () {
			toast(__('Scan cancelled.', 'omninox-site-toolkit'));
		});
	}

	function loadUnusedResults(page) {
		state.busy['unused_load'] = true;
		state.unused.page = page || 1;

		api('/fix/media', {
			method: 'POST',
			body: {
				action: 'unused_get_results',
				params: {
					page: state.unused.page,
					per_page: state.unused.perPage,
					filter: state.unused.filter || 'likely_unused',
					search: state.unused.search
				}
			}
		}).then(function (res) {
			state.busy['unused_load'] = false;
			state.unused.loaded = true;
			if (res) {
				state.unused.stats = res.stats || state.unused.stats;
				state.unused.items = res.items || [];
				state.unused.page = res.page || 1;
				state.unused.totalPages = res.total_pages || 1;
				state.unused.totalItems = res.total_items || 0;
			}
			render();
		}).catch(function (err) {
			state.busy['unused_load'] = false;
			render();
			toast(err.message || __('Failed to load scan results.', 'omninox-site-toolkit'), true);
		});
	}

	function selectAllMatchingFilter() {
		state.busy['unused_load'] = true;
		render();

		api('/fix/media', {
			method: 'POST',
			body: {
				action: 'unused_get_results',
				params: {
					page: 1,
					per_page: 500,
					filter: state.unused.filter,
					search: state.unused.search
				}
			}
		}).then(function (res) {
			state.busy['unused_load'] = false;
			if (res && res.items) {
				res.items.forEach(function (it) {
					state.unused.selected[it.id] = true;
				});
			}
			render();
		}).catch(function () {
			state.busy['unused_load'] = false;
			render();
		});
	}

	function quarantineUnusedItems(ids) {
		if (!ids || !ids.length) return;
		state.busy['unused_action'] = true;
		render();

		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_quarantine', params: { ids: ids } }
		}).then(function (res) {
			state.busy['unused_action'] = false;
			state.unused.selected = {};
			toast(res.message || sprintf(__('Quarantined %d images safely.', 'omninox-site-toolkit'), (res.quarantined || []).length));
			loadUnusedResults(state.unused.page);
		}).catch(function (err) {
			state.busy['unused_action'] = false;
			render();
			toast(err.message || __('Quarantine failed.', 'omninox-site-toolkit'), true);
		});
	}

	function restoreUnusedItems(ids) {
		if (!ids || !ids.length) return;
		state.busy['unused_action'] = true;
		render();

		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_restore', params: { ids: ids } }
		}).then(function (res) {
			state.busy['unused_action'] = false;
			state.unused.selected = {};
			toast(res.message || sprintf(__('Restored %d images to uploads folder.', 'omninox-site-toolkit'), (res.restored || []).length));
			loadUnusedResults(state.unused.page);
			if (state.unused.showHistory) {
				loadQuarantineAndHistory();
			}
		}).catch(function (err) {
			state.busy['unused_action'] = false;
			render();
			toast(err.message || __('Restoration failed.', 'omninox-site-toolkit'), true);
		});
	}

	function confirmPermanentDelete(ids, filename) {
		if (!ids || !ids.length) return;
		state.unused.confirmDelete = {
			ids: ids,
			filename: filename || ''
		};
		render();
	}

	function deleteUnusedItems(ids) {
		if (!ids || !ids.length) return;
		state.busy['unused_action'] = true;
		state.unused.confirmDelete = null;
		render();

		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_delete', params: { ids: ids, confirmed: true } }
		}).then(function (res) {
			state.busy['unused_action'] = false;
			state.unused.selected = {};
			toast(res.message || sprintf(__('Permanently deleted %d images.', 'omninox-site-toolkit'), (res.deleted || []).length));
			loadUnusedResults(state.unused.page);
			if (state.unused.showHistory) {
				loadQuarantineAndHistory();
			}
		}).catch(function (err) {
			state.busy['unused_action'] = false;
			render();
			toast(err.message || __('Deletion failed.', 'omninox-site-toolkit'), true);
		});
	}

	function toggleUnusedProtection(id, protect) {
		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_set_protection', params: { attachment_id: id, protected: protect } }
		}).then(function () {
			toast(protect ? __('Image marked protected.', 'omninox-site-toolkit') : __('Protection removed.', 'omninox-site-toolkit'));
			loadUnusedResults(state.unused.page);
		}).catch(function (err) {
			toast(err.message || __('Failed to update protection status.', 'omninox-site-toolkit'), true);
		});
	}

	function exportUnusedResults(format) {
		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_export_' + format, params: { filter: state.unused.filter, search: state.unused.search } }
		}).then(function (res) {
			if (res && res.content) {
				var mime = format === 'csv' ? 'text/csv;charset=utf-8;' : 'application/json;charset=utf-8;';
				var blob = new Blob([res.content], { type: mime });
				var link = document.createElement('a');
				link.href = URL.createObjectURL(blob);
				link.download = res.filename || ('omninox-media-audit.' + format);
				document.body.appendChild(link);
				link.click();
				document.body.removeChild(link);
				toast(__('Export downloaded successfully.', 'omninox-site-toolkit'));
			} else {
				toast(__('Export returned empty content.', 'omninox-site-toolkit'), true);
			}
		}).catch(function (err) {
			toast(err.message || __('Export requires Omninox Pro.', 'omninox-site-toolkit'), true);
		});
	}

	function loadQuarantineAndHistory() {
		state.unused.showHistory = true;
		render();

		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_get_quarantined' }
		}).then(function (res) {
			state.unused.quarantinedList = (res && res.quarantined) || [];
			render();
		});

		api('/fix/media', {
			method: 'POST',
			body: { action: 'unused_get_history', params: { limit: 100 } }
		}).then(function (res) {
			state.unused.history = Array.isArray(res) ? res : [];
			render();
		});
	}

	/* ---------------------------------- High-Performance URL Replacement Engine */

	function loadUrlReplacementSummary() {
		var fmt = state.urlReplacement.format || 'webp';
		api('/fix/media', {
			method: 'POST',
			body: { action: 'url_replacement_summary', params: { format: fmt } }
		}).then(function (res) {
			state.busy['url_summary_load'] = false;
			if (res) {
				state.urlReplacement.summary = res;
				render();
			}
		}).catch(function () {
			state.busy['url_summary_load'] = false;
		});
	}

	function startUrlReplacement(mode) {
		mode = mode || 'replace';
		var fmt = state.urlReplacement.format || 'webp';

		if (mode === 'replace' && !window.confirm(__('This will rewrite image URLs across posts, builders, options, and taxonomies to point to WebP/AVIF files and move originals into a 30-day holding area. A reversible rollback journal will be saved. Continue?', 'omninox-site-toolkit'))) {
			return;
		}

		state.urlReplacement.active = true;
		state.urlReplacement.mode = mode;
		state.urlReplacement.status = 'running';
		state.urlReplacement.progress = 5;
		state.urlReplacement.step = 'init';
		state.urlReplacement.stepLabel = (mode === 'preview') ? __('Starting Dry-Run Preview…', 'omninox-site-toolkit') : __('Initializing URL Replacement Engine…', 'omninox-site-toolkit');
		state.urlReplacement.rowsInspected = 0;
		state.urlReplacement.rowsReplaced = 0;
		state.urlReplacement.replacementsCount = 0;
		render();

		api('/fix/media', {
			method: 'POST',
			body: { action: 'url_replacement_start', params: { mode: mode, format: fmt } }
		}).then(function (res) {
			if (res && res.job_id) {
				state.urlReplacement.jobId = res.job_id;
				state.urlReplacement.step = res.current_step;
				state.urlReplacement.stepLabel = res.step_label;
				state.urlReplacement.progress = res.progress || 10;
				render();
				stepUrlReplacement();
			} else {
				state.urlReplacement.active = false;
				render();
				toast(__('Failed to start URL replacement.', 'omninox-site-toolkit'), true);
			}
		}).catch(function (err) {
			state.urlReplacement.active = false;
			render();
			toast(err.message || __('Error starting URL replacement.', 'omninox-site-toolkit'), true);
		});
	}

	function stepUrlReplacement() {
		if (!state.urlReplacement.active || !state.urlReplacement.jobId) {
			return;
		}

		if (state.urlReplacement.status === 'paused') {
			return;
		}

		api('/fix/media', {
			method: 'POST',
			body: {
				action: 'url_replacement_step',
				params: { job_id: state.urlReplacement.jobId, batch: 100 }
			}
		}).then(function (res) {
			if (!state.urlReplacement.active) {
				return;
			}

			if (res.status === 'paused') {
				state.urlReplacement.status = 'paused';
				render();
				return;
			}

			state.urlReplacement.step = res.current_step;
			state.urlReplacement.stepLabel = res.step_label;
			state.urlReplacement.progress = res.progress || 10;
			state.urlReplacement.rowsInspected = res.rows_inspected || 0;
			state.urlReplacement.rowsReplaced = res.rows_replaced || 0;
			state.urlReplacement.replacementsCount = res.replacements_count || 0;

			if (res.done || res.status === 'complete') {
				state.urlReplacement.active = false;
				state.urlReplacement.status = 'complete';
				state.urlReplacement.progress = 100;
				state.urlReplacement.report = res;
				state.urlReplacement.showReport = true;
				render();
				toast(res.message || __('URL replacement completed successfully.', 'omninox-site-toolkit'));
				loadUrlReplacementSummary();
			} else {
				render();
				setTimeout(stepUrlReplacement, 120);
			}
		}).catch(function (err) {
			if (err && (err.code === 'omninox_rate_limited' || err.status === 429)) {
				state.urlReplacement.stepLabel = __('Rate limit reached, pausing briefly before auto-resuming…', 'omninox-site-toolkit');
				render();
				setTimeout(stepUrlReplacement, 2500);
				return;
			}
			state.urlReplacement.active = false;
			render();
			toast(err.message || __('URL replacement step interrupted.', 'omninox-site-toolkit'), true);
		});
	}

	function pauseUrlReplacement() {
		if (!state.urlReplacement.jobId) return;
		state.urlReplacement.status = 'paused';
		render();
		api('/fix/media', {
			method: 'POST',
			body: { action: 'url_replacement_pause', params: { job_id: state.urlReplacement.jobId } }
		}).then(function () {
			toast(__('Replacement paused.', 'omninox-site-toolkit'));
		});
	}

	function resumeUrlReplacement() {
		if (!state.urlReplacement.jobId) return;
		state.urlReplacement.status = 'running';
		render();
		api('/fix/media', {
			method: 'POST',
			body: { action: 'url_replacement_resume', params: { job_id: state.urlReplacement.jobId } }
		}).then(function () {
			toast(__('Resuming replacement…', 'omninox-site-toolkit'));
			stepUrlReplacement();
		});
	}

	function cancelUrlReplacement() {
		if (!state.urlReplacement.jobId) return;
		var jid = state.urlReplacement.jobId;
		state.urlReplacement.active = false;
		state.urlReplacement.status = 'cancelled';
		render();
		api('/fix/media', {
			method: 'POST',
			body: { action: 'url_replacement_cancel', params: { job_id: jid } }
		}).then(function () {
			toast(__('Replacement cancelled.', 'omninox-site-toolkit'));
		});
	}

	function rollbackUrlReplacement() {
		if (!state.urlReplacement.jobId) {
			toast(__('No previous replacement job ID recorded in session.', 'omninox-site-toolkit'), true);
			return;
		}

		if (!window.confirm(__('Revert all database records and restore original files for the last replacement job? This action reads from the secure rollback journal.', 'omninox-site-toolkit'))) {
			return;
		}

		state.busy['url_rollback'] = true;
		render();

		api('/fix/media', {
			method: 'POST',
			body: { action: 'url_replacement_rollback', params: { job_id: state.urlReplacement.jobId } }
		}).then(function (res) {
			state.busy['url_rollback'] = false;
			state.urlReplacement.status = 'rolled_back';
			state.urlReplacement.jobId = null;
			render();
			toast(res.message || __('Rollback completed successfully.', 'omninox-site-toolkit'));
			loadUrlReplacementSummary();
		}).catch(function (err) {
			state.busy['url_rollback'] = false;
			render();
			toast(err.message || __('Rollback failed.', 'omninox-site-toolkit'), true);
		});
	}

	function exportUrlReplacementCsv() {
		var fmt = state.urlReplacement.format || 'webp';
		api('/fix/media', {
			method: 'POST',
			body: { action: 'url_replacement_export_csv', params: { format: fmt } }
		}).then(function (res) {
			if (res && res.csv_data) {
				var blob = new Blob([res.csv_data], { type: 'text/csv;charset=utf-8;' });
				var link = document.createElement('a');
				link.href = URL.createObjectURL(blob);
				link.download = res.filename || 'image-url-replacement-map.csv';
				document.body.appendChild(link);
				link.click();
				document.body.removeChild(link);
				toast(__('CSV replacement map downloaded successfully.', 'omninox-site-toolkit'));
			} else {
				toast(__('No verified conversion mappings found to export.', 'omninox-site-toolkit'), true);
			}
		});
	}

	function renderUrlReplacementCard(wrapper) {
		if (!state.urlReplacement.summary && !state.busy['url_summary_load']) {
			state.busy['url_summary_load'] = true;
			loadUrlReplacementSummary();
		}

		var summary = state.urlReplacement.summary || {
			total_mappings: 0,
			distinct_attachments: 0,
			missing_files: 0,
			format: 'webp'
		};

		var isRunning = state.urlReplacement.active;
		var isPaused = (state.urlReplacement.status === 'paused');
		var isPreview = (state.urlReplacement.mode === 'preview');

		var card = el('div', { class: 'wpmt-card wpmt-url-engine-card', style: 'margin-bottom:20px;' }, [
			// Header
			el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px;' }, [
				el('div', {}, [
					el('div', { style: 'font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--wpmt-primary);display:flex;align-items:center;gap:6px;' }, [
						icon('performance', 14),
						document.createTextNode(__('URL Replacement Engine', 'omninox-site-toolkit'))
					]),
					el('h3', { style: 'margin:4px 0 0;font-size:16px;font-weight:700;color:var(--wpmt-ink);', text: __('High-Performance Database Image URL Rewriter', 'omninox-site-toolkit') })
				]),
				el('div', { style: 'display:flex;align-items:center;gap:10px;' }, [
					el('span', {
						class: 'wpmt-badge-status is-' + (isRunning ? 'used' : (state.urlReplacement.status === 'complete' ? 'pass' : 'notice')),
						text: isRunning ? (isPaused ? __('PAUSED', 'omninox-site-toolkit') : (isPreview ? __('PREVIEWING', 'omninox-site-toolkit') : __('REPLACING', 'omninox-site-toolkit'))) : String(state.urlReplacement.status || 'idle').toUpperCase()
					})
				])
			]),

			// Stats grid
			el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px;' }, [
				el('div', { class: 'wpmt-unused-stat-card' }, [
					el('span', { class: 'wpmt-unused-stat-title', text: __('Verified URL Mappings', 'omninox-site-toolkit') }),
					el('span', { class: 'wpmt-unused-stat-value', text: String(summary.total_mappings || 0) }),
					el('span', { class: 'wpmt-unused-stat-sub', text: __('All registered thumbnail sizes', 'omninox-site-toolkit') })
				]),
				el('div', { class: 'wpmt-unused-stat-card' }, [
					el('span', { class: 'wpmt-unused-stat-title', text: __('Converted Attachments', 'omninox-site-toolkit') }),
					el('span', { class: 'wpmt-unused-stat-value', text: String(summary.distinct_attachments || 0) }),
					el('span', { class: 'wpmt-unused-stat-sub', text: __('Ready for replacement', 'omninox-site-toolkit') })
				]),
				el('div', { class: 'wpmt-unused-stat-card' }, [
					el('span', { class: 'wpmt-unused-stat-title', text: __('Records Replaced', 'omninox-site-toolkit') }),
					el('span', { class: 'wpmt-unused-stat-value', text: String(state.urlReplacement.rowsReplaced || 0) }),
					el('span', { class: 'wpmt-unused-stat-sub', text: __('Database rows updated', 'omninox-site-toolkit') })
				]),
				el('div', { class: 'wpmt-unused-stat-card' }, [
					el('span', { class: 'wpmt-unused-stat-title', text: __('Rollback Journal', 'omninox-site-toolkit') }),
					el('span', { class: 'wpmt-unused-stat-value', text: state.urlReplacement.jobId ? __('Protected', 'omninox-site-toolkit') : __('Idle', 'omninox-site-toolkit') }),
					el('span', { class: 'wpmt-unused-stat-sub', text: __('Reversible backup active', 'omninox-site-toolkit') })
				])
			]),

			// Progress Box if active
			isRunning ? el('div', { class: 'wpmt-unused-progress-box', style: 'background:var(--wpmt-surface-2);border:1px solid var(--wpmt-border);border-radius:var(--wpmt-radius);padding:16px 18px;margin-bottom:16px;' }, [
				el('div', { style: 'display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;' }, [
					el('strong', { text: state.urlReplacement.stepLabel || __('Processing database records…', 'omninox-site-toolkit') }),
					el('div', { style: 'display:flex;align-items:center;gap:8px;' }, [
						isPaused ? el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm wpmt-btn-primary',
							onClick: function () { resumeUrlReplacement(); }
						}, [document.createTextNode(__('Resume', 'omninox-site-toolkit'))]) : el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm',
							onClick: function () { pauseUrlReplacement(); }
						}, [document.createTextNode(__('Pause', 'omninox-site-toolkit'))]),
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm wpmt-btn-danger',
							onClick: function () { cancelUrlReplacement(); }
						}, [document.createTextNode(__('Cancel', 'omninox-site-toolkit'))])
					])
				]),
				el('div', { class: 'wpmt-unused-progress-bar-bg', style: 'height:8px;border-radius:4px;background:var(--wpmt-border);overflow:hidden;' }, [
					el('div', { class: 'wpmt-unused-progress-bar-fill', style: 'height:100%;background:var(--wpmt-primary);transition:width 0.3s ease;width:' + (state.urlReplacement.progress || 0) + '%' })
				]),
				el('div', { style: 'display:flex;justify-content:space-between;margin-top:8px;font-size:12px;color:var(--wpmt-ink-muted);' }, [
					el('span', { text: sprintf(__('Scanned: %d rows | Modified: %d rows (%d URL instances)', 'omninox-site-toolkit'), state.urlReplacement.rowsInspected || 0, state.urlReplacement.rowsReplaced || 0, state.urlReplacement.replacementsCount || 0) }),
					el('span', { text: (state.urlReplacement.progress || 0) + '%' })
				])
			]) : null,

			// Controls Toolbar
			el('div', { style: 'display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;padding-top:12px;border-top:1px solid var(--wpmt-border);' }, [
				el('div', { style: 'display:flex;align-items:center;gap:8px;' }, [
					el('button', {
						type: 'button',
						class: 'wpmt-btn',
						disabled: isRunning,
						title: __('Dry-run preview without modifying database', 'omninox-site-toolkit'),
						onClick: function () { startUrlReplacement('preview'); }
					}, [
						icon('search', 14, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Preview Changes', 'omninox-site-toolkit'))
					]),
					el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-primary',
						disabled: isRunning,
						title: __('Replace original URLs with converted files across the entire site', 'omninox-site-toolkit'),
						onClick: function () { startUrlReplacement('replace'); }
					}, [
						icon('sparkle', 14, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Start Replacement', 'omninox-site-toolkit'))
					]),
					state.urlReplacement.jobId ? el('button', {
						type: 'button',
						class: 'wpmt-btn wpmt-btn-danger',
						disabled: isRunning || state.busy['url_rollback'],
						title: __('Roll back all database modifications from the last job', 'omninox-site-toolkit'),
						onClick: function () { rollbackUrlReplacement(); }
					}, [
						icon('backup', 14, 'wpmt-btn-icon'),
						document.createTextNode(' ' + (state.busy['url_rollback'] ? __('Rolling back…', 'omninox-site-toolkit') : __('Roll Back', 'omninox-site-toolkit')))
					]) : null
				]),
				el('div', { style: 'display:flex;align-items:center;gap:8px;' }, [
					el('button', {
						type: 'button',
						class: 'wpmt-btn',
						title: __('Download exact URL replacement CSV mapping', 'omninox-site-toolkit'),
						onClick: function () { exportUrlReplacementCsv(); }
					}, [
						icon('download', 14, 'wpmt-btn-icon'),
						document.createTextNode(' ' + __('Export CSV Map', 'omninox-site-toolkit'))
					]),
					state.urlReplacement.report ? el('button', {
						type: 'button',
						class: 'wpmt-btn',
						onClick: function () {
							state.urlReplacement.showReport = true;
							render();
						}
					}, [document.createTextNode(__('View Report', 'omninox-site-toolkit'))]) : null
				])
			])
		]);

		wrapper.appendChild(card);

		// Report Modal if requested
		if (state.urlReplacement.showReport && state.urlReplacement.report) {
			var rep = state.urlReplacement.report;
			var repModal = el('div', {
				class: 'wpmt-lightbox-modal',
				onClick: function (e) {
					if (e.target === repModal) {
						state.urlReplacement.showReport = false;
						render();
					}
				}
			}, [
				el('div', { class: 'wpmt-lightbox-card', style: 'max-width:540px;' }, [
					el('div', { style: 'padding:16px 20px;border-bottom:1px solid var(--wpmt-border);display:flex;justify-content:space-between;align-items:center;' }, [
						el('strong', { text: __('URL Replacement Execution Report', 'omninox-site-toolkit') }),
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-sm',
							onClick: function () {
								state.urlReplacement.showReport = false;
								render();
							}
						}, [document.createTextNode('✕')])
					]),
					el('div', { style: 'padding:20px;font-size:13px;line-height:1.6;' }, [
						el('p', { style: 'margin:0 0 12px;font-weight:600;color:var(--wpmt-ink);', text: rep.message || __('Execution complete.', 'omninox-site-toolkit') }),
						el('table', { class: 'wpmt-table', style: 'width:100%;margin-bottom:14px;' }, [
							el('tbody', {}, [
								el('tr', {}, [el('td', { text: __('Execution Mode', 'omninox-site-toolkit') }), el('td', { style: 'font-weight:600;', text: String(rep.mode || 'replace').toUpperCase() })]),
								el('tr', {}, [el('td', { text: __('Total Rows Scanned', 'omninox-site-toolkit') }), el('td', { style: 'font-weight:600;', text: String(rep.rows_inspected || 0) })]),
								el('tr', {}, [el('td', { text: __('Database Rows Updated', 'omninox-site-toolkit') }), el('td', { style: 'font-weight:600;color:var(--wpmt-primary);', text: String(rep.rows_replaced || 0) })]),
								el('tr', {}, [el('td', { text: __('URL Occurrences Replaced', 'omninox-site-toolkit') }), el('td', { style: 'font-weight:600;', text: String(rep.replacements_count || 0) })]),
								el('tr', {}, [el('td', { text: __('Job Tracking ID', 'omninox-site-toolkit') }), el('td', { style: 'font-family:monospace;font-size:11px;', text: rep.job_id || '—' })])
							])
						]),
						el('small', { style: 'color:var(--wpmt-ink-muted);', text: __('Original files are preserved in uploads/omninox-media-backup/ for 30 days. You can roll back modifications at any time.', 'omninox-site-toolkit') })
					]),
					el('div', { style: 'padding:14px 20px;border-top:1px solid var(--wpmt-border);display:flex;justify-content:flex-end;' }, [
						el('button', {
							type: 'button',
							class: 'wpmt-btn wpmt-btn-primary',
							onClick: function () {
								state.urlReplacement.showReport = false;
								render();
							}
						}, [document.createTextNode(__('Close', 'omninox-site-toolkit'))])
					])
				])
			]);
			wrapper.appendChild(repModal);
		}
	}

	/* -------------------------------------------------------------- Settings */

	function mediaUsageCard() {
		var lic = WPMT.license || {};

		if (typeof lic.media_used !== 'number' || typeof lic.media_limit !== 'number') {
			return null;
		}

		var used = lic.media_used;
		var limit = lic.media_limit;
		var isUnlimited = (limit === -1);
		var pct = isUnlimited ? 0 : (limit ? Math.min(100, Math.round((used / limit) * 100)) : 0);
		var children = [];

		var hasValidKey = !!(lic.has_valid_key || (lic.status === 'active' && lic.masked_key));
		if (!lic.premium && !hasValidKey && !isUnlimited) {
			children.push(el('p', { class: 'wpmt-lock-text', text: __('You are on the free plan. Activate a license key to unlock every Pro feature.', 'omninox-site-toolkit') }));
		}

		children.push(el('div', { class: 'wpmt-usage' }, [
			el('div', { class: 'wpmt-usage-label' }, [
				el('span', { text: isUnlimited ? __('WebP / AVIF conversions', 'omninox-site-toolkit') : __('Free WebP conversions used', 'omninox-site-toolkit') }),
				el('strong', { text: isUnlimited ? (used + ' / ' + __('Unlimited (Pro)', 'omninox-site-toolkit')) : (used + ' / ' + limit) })
			]),
			isUnlimited ? null : el('div', { class: 'wpmt-usage-bar' }, [
				el('div', { class: 'wpmt-usage-fill' + (pct >= 100 ? ' is-full' : ''), style: 'width:' + pct + '%' })
			])
		].filter(Boolean)));

		return el('div', { class: 'wpmt-card' }, children);
	}

	function licenseCard() {
		var lic = WPMT.license || {};
		var isPremium = !!lic.premium;
		var hasValidKey = !!(lic.has_valid_key || (lic.status === 'active' && lic.masked_key));
		var isProInstalled = !!(WPMT.isProInstalled || lic.pro_installed);

		var children = [
			el('h2', { text: __('License & Pro', 'omninox-site-toolkit') })
		];

		if (isPremium || hasValidKey) {
			var statusText = isPremium
				? __('Pro is active', 'omninox-site-toolkit')
				: __('License key is active (Pro plugin install required)', 'omninox-site-toolkit');

			children.push(el('div', {
				class: 'wpmt-license-status ' + (isPremium ? 'wpmt-license-active' : 'wpmt-license-warning'),
				style: isPremium ? '' : 'background: #ecfdf5; border: 1px solid #a7f3d0; padding: 10px 14px; border-radius: 6px; display: flex; align-items: center; gap: 8px; margin-bottom: 12px;'
			}, [
				el('span', { class: 'dashicons ' + (isPremium ? 'dashicons-yes' : 'dashicons-yes-alt'), style: 'color: #059669; font-size: 20px; width: 20px; height: 20px;', 'aria-hidden': 'true' }),
				el('strong', { style: 'color: #065f46; font-size: 14px;', text: statusText })
			]));

			if (!isProInstalled) {
				children.push(el('div', {
					style: 'background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 6px; padding: 12px 14px; margin: 12px 0 16px;'
				}, [
					el('p', {
						style: 'margin: 0 0 10px; font-size: 13px; color: #78350f; line-height: 1.4;',
						text: __('Your license key is activated! To unlock and run all Pro features, download and activate the Omninox Site Toolkit Pro companion plugin.', 'omninox-site-toolkit')
					}),
					el('a', {
						class: 'wpmt-btn wpmt-btn-pro',
						style: 'background: #059669; border-color: #047857; color: #fff; text-decoration: none; display: inline-block;',
						href: lic.download_url || WPMT.upgradeUrl || 'https://netinnovix.com/plugins/omninox-site-toolkit',
						target: '_blank',
						rel: 'noopener',
						text: __('Download Pro Plugin (ZIP)', 'omninox-site-toolkit')
					})
				]));
			}

			if (lic.masked_key) {
				children.push(field(__('License key', 'omninox-site-toolkit'), el('code', { class: 'wpmt-license-key', text: lic.masked_key })));
			}
			if (lic.expires) {
				children.push(field(__('Renews / expires', 'omninox-site-toolkit'), el('span', { text: 'lifetime' === lic.expires ? __('Never (lifetime)', 'omninox-site-toolkit') : lic.expires })));
			}
			if (lic.sites) {
				children.push(field(__('Sites', 'omninox-site-toolkit'), el('span', { text: (lic.sites_used || 1) + ' / ' + lic.sites })));
			}

			children.push(el('div', { class: 'wpmt-license-actions', style: 'margin-top: 16px;' }, [
				el('button', {
					type: 'button',
					class: 'wpmt-btn',
					text: __('Deactivate on this site', 'omninox-site-toolkit'),
					onClick: function () { deactivateLicense(); }
				})
			]));
		} else {
			children.push(el('p', { class: 'wpmt-lock-text', text: __('You are on the free plan. Activate a license key to unlock every Pro feature.', 'omninox-site-toolkit') }));

			var keyInput = el('input', {
				type: 'text',
				class: 'wpmt-input',
				placeholder: __('Enter your license key', 'omninox-site-toolkit'),
				'aria-label': __('License key', 'omninox-site-toolkit')
			});
			var msg = el('p', { class: 'wpmt-modal-status', 'aria-live': 'polite' });
			var btn = el('button', {
				type: 'button',
				class: 'wpmt-btn wpmt-btn-primary',
				text: __('Activate license', 'omninox-site-toolkit'),
				onClick: function () {
					var key = (keyInput.value || '').trim();
					if (!key) {
						msg.textContent = __('Enter a license key to activate.', 'omninox-site-toolkit');
						msg.className = 'wpmt-modal-status wpmt-modal-status-error';
						return;
					}
					btn.disabled = true;
					btn.textContent = __('Activating…', 'omninox-site-toolkit');
					activateLicense(key).then(function (ok) {
						if (ok) {
							msg.textContent = __('Activated. Reloading…', 'omninox-site-toolkit');
							msg.className = 'wpmt-modal-status wpmt-modal-status-ok';
							setTimeout(function () { window.location.reload(); }, 1000);
						} else {
							btn.disabled = false;
							btn.textContent = __('Activate license', 'omninox-site-toolkit');
							msg.textContent = state.lastLicenseError || __('Activation failed.', 'omninox-site-toolkit');
							msg.className = 'wpmt-modal-status wpmt-modal-status-error';
						}
					});
				}
			});

			var plans = (WPMT.plans && WPMT.plans.single_lifetime) ? WPMT.plans : ((lic && lic.plans) ? lic.plans : null);
			var singlePlan = plans && plans.single_lifetime ? plans.single_lifetime : {
				name: __('Single Site — Lifetime License', 'omninox-site-toolkit'),
				price: '$15',
				billing: __('One-time payment (Pay once, use forever)', 'omninox-site-toolkit'),
				buy_url: WPMT.upgradeUrl || 'https://netinnovix.com/plugins/omninox-site-toolkit/buy'
			};

			children.push(el('div', { class: 'wpmt-plan-card', style: 'background: rgba(34,197,94,0.06); border: 1px solid rgba(34,197,94,0.25); border-radius: 8px; padding: 14px 16px; margin: 12px 0 16px;' }, [
				el('div', { style: 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;' }, [
					el('strong', { style: 'font-size: 15px; color: #0f172a;', text: singlePlan.name || __('Single Site — Lifetime Plan', 'omninox-site-toolkit') }),
					el('span', { style: 'font-size: 18px; font-weight: 700; color: #16a34a; background: #dcfce7; padding: 2px 10px; border-radius: 20px;', text: singlePlan.price || '$15' })
				]),
				el('p', { style: 'margin: 0 0 10px; font-size: 13px; color: #475569;', text: singlePlan.billing || __('One-time purchase for 1 Site • No recurring fees • Lifetime updates & Pro features', 'omninox-site-toolkit') }),
				el('div', { class: 'wpmt-license-actions' }, [
					el('a', { class: 'wpmt-btn wpmt-btn-pro', href: singlePlan.buy_url || WPMT.upgradeUrl || 'https://netinnovix.com/plugins/omninox-site-toolkit/buy', target: '_blank', rel: 'noopener', text: sprintf(__('Buy 1-Site License — %s (One-Time)', 'omninox-site-toolkit'), singlePlan.price || '$15') })
				])
			]));

			children.push(el('p', { class: 'wpmt-meta-lead', style: 'margin-top: 14px; font-weight: 600;', text: __('Already purchased a license? Enter your key below:', 'omninox-site-toolkit') }));
			children.push(el('div', { class: 'wpmt-modal-form' }, [keyInput, btn]));
			if (lic.test_mode) {
				children.push(el('p', { class: 'wpmt-meta-notice', text: __('Test mode is on: the sample key validates against the test license server.', 'omninox-site-toolkit') }));
			}
			children.push(msg);
		}

		return el('div', { class: 'wpmt-card' }, children);
	}

	function renderSettings(wrapper) {
		var settings = state.settings;

		wrapper.appendChild(header(__('Settings', 'omninox-site-toolkit'), null, [], 'settings'));

		wrapper.appendChild(licenseCard());

		if (!settings) {
			wrapper.appendChild(el('div', { class: 'wpmt-card' }, [
				el('div', { class: 'wpmt-empty', text: __('Loading…', 'omninox-site-toolkit') })
			]));
			return;
		}

		var draft = JSON.parse(JSON.stringify(settings));

		wrapper.appendChild(el('div', { class: 'wpmt-card' }, [
			el('h2', { text: __('General & scheduling', 'omninox-site-toolkit') }),

			field(__('Automatic scans', 'omninox-site-toolkit'), select(
				[['off', __('Off', 'omninox-site-toolkit')], ['daily', __('Daily', 'omninox-site-toolkit')], ['weekly', __('Weekly', 'omninox-site-toolkit')], ['monthly', __('Monthly', 'omninox-site-toolkit')]],
				draft.scan_schedule,
				function (value) { draft.scan_schedule = value; }
			)),

			field(__('Send alerts to', 'omninox-site-toolkit'), input('email', draft.notify_email, function (value) { draft.notify_email = value; })),
			field(__('Keep history for (days)', 'omninox-site-toolkit'), input('number', draft.retention_days, function (value) { draft.retention_days = value; })),

			el('label', { class: 'wpmt-toggle' }, [
				el('span', { text: __('Collect PHP, REST and 404 errors', 'omninox-site-toolkit') }),
				el('input', {
					type: 'checkbox',
					checked: !!draft.log_errors,
					onChange: function (event) { draft.log_errors = event.target.checked; }
				})
			]),

			el('label', { class: 'wpmt-toggle' }, [
				el('span', { text: __('Delete all plugin data when uninstalling', 'omninox-site-toolkit') }),
				el('input', {
					type: 'checkbox',
					checked: !!draft.delete_data_on_uninstall,
					onChange: function (event) { draft.delete_data_on_uninstall = event.target.checked; }
				})
			]),

			el('p', { style: 'margin-top:14px' }, [
				el('button', {
					type: 'button',
					class: 'wpmt-btn wpmt-btn-primary',
					text: __('Save settings', 'omninox-site-toolkit'),
					onClick: function () {
						api('/settings', { method: 'POST', body: draft })
							.then(function (saved) {
								state.settings = saved;
								render();
								toast(__('Settings saved.', 'omninox-site-toolkit'));
							})
							.catch(function (error) { toast(error.message, true); });
					}
				})
			])
		]));

		var log = changeLog(state.changes);

		if (log) {
			log.appendChild(el('p', { style: 'margin:12px 0 0' }, [
				el('button', {
					type: 'button',
					class: 'wpmt-btn',
					text: __('Clear this history', 'omninox-site-toolkit'),
					onClick: function () {
						api('/changes', { method: 'DELETE' }).then(function () {
							state.changes = [];
							render();
							toast(__('Change history cleared.', 'omninox-site-toolkit'));
						}).catch(function (error) { toast(error.message, true); });
					}
				})
			]));

			wrapper.appendChild(log);
		}
	}

	function field(label, control, hint) {
		return el('label', { class: 'wpmt-field' }, [
			el('span', { text: label }),
			control,
			hint ? el('small', { class: 'wpmt-field-hint', text: hint }) : null
		]);
	}

	function input(type, value, onChange, id) {
		return el('input', {
			type: type,
			id: id || null,
			value: value === null || value === undefined ? '' : String(value),
			onInput: function (event) { onChange(event.target.value); }
		});
	}

	function select(options, value, onChange, id) {
		return el('select', {
			id: id || null,
			onChange: function (event) { onChange(event.target.value); }
		}, options.map(function (option) {
			return el('option', {
				value: option[0],
				selected: option[0] === value,
				text: option[1]
			});
		}));
	}

	/* ---------------------------------------------------------------- Routing */

	function go(route) {
		state.route = route;
		window.location.hash = '#/' + route;
		render();
		ensureResults(route);
	}

	/**
	 * Load the stored result for a module view if we do not already have it.
	 * Called on navigation, on hash change and on first paint, so a refresh
	 * keeps showing the last scan instead of an empty state.
	 */
	function ensureResults(route, force) {
		var reserved = ['dashboard', 'settings', 'reports'];

		if (reserved.indexOf(route) !== -1 || (!force && state.results[route]) || state.loading[route]) {
			return Promise.resolve();
		}

		state.loading[route] = true;

		return api('/results/' + route)
			.then(function (result) {
				state.loading[route] = false;

				if (result && result.issues) {
					state.results[route] = result;
					render();
				}
			})
			.catch(function () {
				state.loading[route] = false;
			});
	}

	window.setInterval(function () {
		if (state.route === 'errors' && !state.busy.errors) {
			ensureResults('errors', true);
		}
	}, 30000);

	function routeFromHash() {
		var hash = (window.location.hash || '').replace(/^#\/?/, '');

		return hash || 'dashboard';
	}

	function loadOverview(silent) {
		return api('/overview', { silent: !!silent }).then(function (overview) {
			state.overview = overview;
			WPMT.license = overview.license;
		});
	}

	function loadSettings() {
		return api('/settings').then(function (settings) {
			state.settings = settings;
		});
	}

	function loadTips(silent) {
		return Promise.all([
			api('/tips', { silent: !!silent }).then(function (tips) { state.tips = tips || []; }).catch(function () { }),
			api('/updates', { silent: !!silent }).then(function (updates) { state.updates = updates; }).catch(function () { })
		]);
	}

	function loadChanges() {
		return api('/changes').then(function (changes) {
			state.changes = changes || [];
		}).catch(function () {
			state.changes = [];
		});
	}

	window.addEventListener('hashchange', function () {
		state.route = routeFromHash();
		if (state.route !== 'backups') {
			try { sessionStorage.removeItem('omninox_restore_token'); } catch (e) { }
		}
		render();
		ensureResults(state.route);
	});

	state.route = routeFromHash();
	if (state.route !== 'backups') {
		try { sessionStorage.removeItem('omninox_restore_token'); } catch (e) { }
	}
	render();

	Promise.all([
		loadOverview().catch(function (e) { return null; }),
		loadSettings().catch(function (e) { return null; }),
		loadChanges().catch(function (e) { return null; }),
		loadTips().catch(function (e) { return null; }),
		loadDashboard().catch(function (e) { return null; })
	])
		.then(function () {
			render();
			// Restore whatever view the person reloaded on.
			return ensureResults(state.route);
		})
		.catch(function (error) {
			render();
			toast(error.message, true);
		});
})();
