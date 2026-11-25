/**
 * OC SMS Login: the phone popup, the checkout gate and the inline sign-in.
 * No dependencies.
 */
(function (run) {
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})(function () {
	'use strict';

	var D = window.ocslData;
	if (!D) { return; }
	var T = D.i18n || {};
	var GUEST_KEY = 'ocsl_guest';
	var SIGNED_KEY = 'ocsl_signed_in';

	/*
	 * This script only runs on pages served to a guest. Right after a sign-in, such a page is a stale
	 * copy (a prefetch made while still a guest, or the browser's cache): fetch it again, once.
	 */
	try {
		var signedAt = parseInt(sessionStorage.getItem(SIGNED_KEY) || '0', 10);
		if (signedAt) {
			sessionStorage.removeItem(SIGNED_KEY);
			if (Date.now() - signedAt < 120000) { location.reload(); return; }
		}
	} catch (e) {}

	/* ---------------- helpers ---------------- */

	function q(sel, root) { return (root || document).querySelector(sel); }
	function qa(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }
	function fmt(s, v) { return String(s || '').replace('%s', v); }

	function send(action, data) {
		var body = new FormData();
		body.append('action', 'ocsl_' + action);
		body.append('nonce', D.nonce);
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
		return fetch(D.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.catch(function () { return { success: false, data: { msg: T.error } }; });
	}

	/*
	 * A page cache can serve this page long after its nonce died (a guest's nonce lives half a day to
	 * a day, a cached page often longer). The server says so (stale): take a fresh nonce and ask again,
	 * once, so the visitor never meets "refresh the page" on a page that a refresh cannot renew.
	 */
	function post(action, data) {
		return send(action, data).then(function (res) {
			if (!res || res.success || !res.data || !res.data.stale) { return res; }
			return send('nonce', {}).then(function (n) {
				if (!n || !n.success || !n.data || !n.data.nonce) { return res; }
				D.nonce = n.data.nonce;
				return send(action, data);
			});
		});
	}

	function samePage(a, b) {
		try {
			var x = new URL(a, location.href), y = new URL(b, location.href);
			if (x.origin !== y.origin || x.pathname.replace(/\/+$/, '') !== y.pathname.replace(/\/+$/, '')) { return false; }
			// Plain permalinks: every page shares one path and differs by ?page_id=.
			return (x.searchParams.get('page_id') || '') === (y.searchParams.get('page_id') || '');
		} catch (e) { return false; }
	}

	function guestChosen() {
		try { return sessionStorage.getItem(GUEST_KEY) === '1'; } catch (e) { return false; }
	}

	/*
	 * A code already on its way, kept for this tab: when the visitor closes the popup, or moves on
	 * to the checkout before typing the code, the next sign-in box opens at the code, not the phone.
	 */
	var FLOW_KEY = 'ocsl_flow';
	function flowGet() {
		try { var f = JSON.parse(sessionStorage.getItem(FLOW_KEY) || 'null'); return f && f.phone ? f : null; } catch (e) { return null; }
	}
	function flowSet(f) {
		try { sessionStorage.setItem(FLOW_KEY, JSON.stringify(f)); } catch (e) {}
	}
	function flowClear() {
		try { sessionStorage.removeItem(FLOW_KEY); } catch (e) {}
	}

	/* ---------------- one sign-in box (the popup's, or an inline block) ---------------- */

	function Box(root) {
		this.root = root;
		this.phone = '';
		this.to = root.getAttribute('data-ocsl-to') || '';
		this.timer = null;
		this.busy = false;
		this.bind();
	}

	Box.prototype.step = function (name) {
		qa('[data-ocsl-step]', this.root).forEach(function (el) {
			el.hidden = el.getAttribute('data-ocsl-step') !== name;
		});
		var shown = q('[data-ocsl-step="' + name + '"]', this.root);
		qa('.ocsl-err', this.root).forEach(function (e) { e.hidden = true; });
		if (!shown) { return; }
		var focus = name === 'code' ? q('.ocsl-box', shown) : q('input:not([type=hidden]):not([type=checkbox]):not([name=website])', shown);
		if (focus) { setTimeout(function () { focus.focus(); }, 30); }
		if (name === 'register') {
			var ts = q('input[name=ts]', shown);
			if (ts) { ts.value = Math.floor(Date.now() / 1000); }
		}
	};

	Box.prototype.error = function (stepName, msg) {
		var el = q('[data-ocsl-step="' + stepName + '"] .ocsl-err', this.root);
		if (el) { el.textContent = msg || T.error; el.hidden = false; }
	};

	Box.prototype.lock = function (form, on, label) {
		this.busy = on;
		var btn = q('button[type=submit]', form);
		if (!btn) { return; }
		if (on) { btn.setAttribute('data-label', btn.textContent); btn.textContent = label || T.sending; }
		else if (btn.getAttribute('data-label')) { btn.textContent = btn.getAttribute('data-label'); }
		btn.disabled = on;
	};

	Box.prototype.answer = function (res, fromStep) {
		var data = (res && res.data) || {};
		if (!res || !res.success) {
			if (data.step) { this.step(data.step); }
			if (data.unavailable && D.onCheckout) { location.reload(); return; } // The gate fell; the shop's own checkout takes over.
			this.error(data.step || fromStep, data.msg);
			if (data.expired) { this.resendReady(); }
			return;
		}
		if (data.step === 'register') {
			// A new customer on the way to the checkout, where the shop asks for no code: the details
			// open the account, and the button says where it leads.
			var regBtn = q('[data-ocsl-step="register"] button[type=submit]', this.root);
			if (regBtn) {
				if (!regBtn.getAttribute('data-code-label')) { regBtn.setAttribute('data-code-label', regBtn.textContent); }
				regBtn.textContent = data.nocode && T.registerGo ? T.registerGo : regBtn.getAttribute('data-code-label');
			}
			this.step('register');
			return;
		}
		if (data.step === 'code') {
			var now = Date.now(), wait = data.wait || 60;
			flowSet({
				phone: this.phone,
				pretty: data.pretty || '',
				test: data.test_code || '',
				until: now + (D.expiry || 300) * 1000,
				resendAt: now + wait * 1000
			});
			this.showCode(data.pretty, data.test_code, wait);
			return;
		}
		if (data.step === 'done') {
			flowClear();
			try { sessionStorage.setItem(SIGNED_KEY, String(Date.now())); } catch (e) {}
			this.step('done');
			var to = data.to || this.to;
			var dest = (to && !samePage(to, location.href)) ? to : '';
			var handoff = data.handoff || '';
			setTimeout(function () {
				if (handoff) {
					// The next page load signs in again from a one-time token, in case the browser dropped
					// the cookie of the background request; the server strips the token right away.
					var u = new URL(dest || location.href, location.href);
					u.searchParams.set('ocsl_login', handoff);
					location.href = u.toString();
				} else if (dest) {
					location.href = dest;
				} else {
					location.reload();
				}
			}, 400);
		}
	};

	Box.prototype.showCode = function (pretty, testCode, wait) {
		q('.ocsl-pretty', this.root).textContent = pretty || '';
		var test = q('.ocsl-test', this.root);
		if (test) {
			test.hidden = !testCode;
			test.textContent = testCode ? fmt(T.testCode, testCode) : '';
		}
		qa('.ocsl-box', this.root).forEach(function (b) { b.value = ''; });
		this.step('code');
		if (wait > 0) { this.countdown(wait); } else { this.resendReady(); }
		this.webOtp();
	};

	/** Open at the code when one is still on its way to this visitor; the phone is filled in either way. */
	Box.prototype.resume = function () {
		var f = flowGet();
		if (!f) { return false; }
		var input = q('[data-ocsl-step="phone"] input[name=phone]', this.root);
		if (input && !input.value) { input.value = f.phone; }
		if (Date.now() >= f.until) { return false; }
		this.phone = f.phone;
		this.showCode(f.pretty, f.test, Math.ceil((f.resendAt - Date.now()) / 1000));
		return true;
	};

	Box.prototype.countdown = function (secs) {
		var wait = q('.ocsl-wait', this.root), btn = q('[data-ocsl-resend]', this.root), left = secs, self = this;
		clearInterval(this.timer);
		btn.hidden = true;
		wait.hidden = false;
		wait.textContent = fmt(T.resendIn, left);
		this.timer = setInterval(function () {
			left -= 1;
			if (left <= 0) { self.resendReady(); return; }
			wait.textContent = fmt(T.resendIn, left);
		}, 1000);
	};

	Box.prototype.resendReady = function () {
		clearInterval(this.timer);
		var wait = q('.ocsl-wait', this.root), btn = q('[data-ocsl-resend]', this.root);
		if (wait) { wait.hidden = true; }
		if (btn) { btn.hidden = false; }
	};

	/** Android Chrome can read the code from the SMS by itself. */
	Box.prototype.webOtp = function () {
		if (!('OTPCredential' in window) || !navigator.credentials) { return; }
		if (this.abort) { this.abort.abort(); }
		var self = this, ac = new AbortController();
		this.abort = ac;
		navigator.credentials.get({ otp: { transport: ['sms'] }, signal: ac.signal }).then(function (otp) {
			if (otp && otp.code) { self.fillCode(otp.code); self.submitCode(); }
		}).catch(function () {});
	};

	Box.prototype.fillCode = function (code) {
		var digits = String(code).replace(/\D/g, '').slice(0, 6).split('');
		qa('.ocsl-box', this.root).forEach(function (b, i) { b.value = digits[i] || ''; });
	};

	Box.prototype.code = function () {
		return qa('.ocsl-box', this.root).map(function (b) { return b.value; }).join('');
	};

	Box.prototype.submitCode = function () {
		var form = q('[data-ocsl-step="code"]', this.root), self = this;
		if (this.busy) { return; }
		var code = this.code();
		if (code.length !== 6) { this.error('code', 'הקוד הוא 6 ספרות.'); return; }
		this.lock(form, true, T.checking);
		post('verify', { phone: this.phone, code: code, to: this.to }).then(function (res) {
			self.lock(form, false);
			self.answer(res, 'code');
		});
	};

	Box.prototype.bind = function () {
		var self = this, root = this.root;

		/*
		 * Enter in a field sends its step. The browser would do that by itself, but themes and shop
		 * plugins listen for Enter on the whole page and swallow it (so a stray Enter never posts a
		 * cart or a checkout form), and our box lives inside their page, drawers and checkout.
		 */
		root.addEventListener('keydown', function (e) {
			if (e.key !== 'Enter' || e.isComposing || e.shiftKey || e.ctrlKey || e.altKey || e.metaKey) { return; }
			var field = e.target;
			if (!field || field.tagName !== 'INPUT' || field.type === 'checkbox') { return; }
			var form = field.closest('form[data-ocsl-step]');
			if (!form || !root.contains(form)) { return; }
			e.preventDefault();
			e.stopPropagation();
			if (form.getAttribute('data-ocsl-step') === 'code') { self.submitCode(); return; }
			form.dispatchEvent(new Event('submit', { cancelable: true }));
		});

		q('[data-ocsl-step="phone"]', root).addEventListener('submit', function (e) {
			e.preventDefault();
			if (self.busy) { return; }
			var form = this;
			self.phone = q('input[name=phone]', form).value;
			if (self.phone.replace(/\D/g, '').length < 9) { self.error('phone', 'הזינו מספר טלפון נייד.'); return; }
			self.lock(form, true, T.checking);
			post('start', { phone: self.phone, to: self.to }).then(function (res) {
				self.lock(form, false);
				self.answer(res, 'phone');
			});
		});

		q('[data-ocsl-step="register"]', root).addEventListener('submit', function (e) {
			e.preventDefault();
			if (self.busy) { return; }
			var form = this, data = { phone: self.phone, to: self.to };
			['first', 'last', 'email', 'website', 'ts'].forEach(function (n) {
				var f = q('[name=' + n + ']', form);
				data[n] = f ? f.value : '';
			});
			data.consent = q('[name=consent]', form).checked ? '1' : '';
			if (!data.first || !data.last || !data.email) { self.error('register', 'צריך שם פרטי, שם משפחה ואימייל.'); return; }
			if (!data.consent) { self.error('register', 'צריך לאשר את מדיניות הפרטיות כדי להמשיך.'); return; }
			self.lock(form, true);
			post('register', data).then(function (res) {
				self.lock(form, false);
				self.answer(res, 'register');
			});
		});

		q('[data-ocsl-step="code"]', root).addEventListener('submit', function (e) {
			e.preventDefault();
			self.submitCode();
		});

		qa('[data-ocsl-change]', root).forEach(function (b) {
			b.addEventListener('click', function () {
				clearInterval(self.timer);
				if (self.abort) { self.abort.abort(); }
				flowClear();
				self.step('phone');
			});
		});

		q('[data-ocsl-resend]', root).addEventListener('click', function () {
			var btn = this;
			btn.hidden = true;
			post('resend', { phone: self.phone, to: self.to }).then(function (res) {
				if (!res || !res.success) { btn.hidden = false; }
				self.answer(res, 'code');
			});
		});

		var boxes = qa('.ocsl-box', root);
		boxes.forEach(function (box, i) {
			box.addEventListener('input', function () {
				var v = box.value.replace(/\D/g, '');
				if (v.length > 1) { self.fillCode(v); }
				else { box.value = v; }
				var next = boxes[Math.min(i + 1, 5)];
				if (v && i < 5) { next.focus(); }
				if (self.code().length === 6) { self.submitCode(); }
			});
			box.addEventListener('keydown', function (e) {
				if (e.key === 'Backspace' && !box.value && i > 0) { boxes[i - 1].focus(); boxes[i - 1].value = ''; }
			});
			box.addEventListener('paste', function (e) {
				var txt = (e.clipboardData || window.clipboardData).getData('text');
				if (txt && txt.replace(/\D/g, '').length >= 6) {
					e.preventDefault();
					self.fillCode(txt);
					self.submitCode();
				}
			});
		});
	};

	/* ---------------- the popup ---------------- */

	var modal = q('[data-ocsl-modal]');
	var popupBox = modal ? new Box(q('.ocsl-dialog', modal)) : null;
	var lastFocus = null;

	function openPopup(to) {
		if (!modal) { return false; }
		popupBox.to = to || '';
		lastFocus = document.activeElement;
		// On the way to the checkout the title says so; opened from the header's icon it is a plain sign-in.
		var h = q('#ocsl-title', modal), words = to ? T.gateTitle : T.title;
		if (h && words) { h.textContent = words; }
		// Last in <body>, so it stays above a side cart that shares the top z-index.
		if (document.body.lastElementChild !== modal) { document.body.appendChild(modal); }
		modal.hidden = false;
		document.documentElement.classList.add('ocsl-open');
		if (!popupBox.resume()) { popupBox.step('phone'); }
		return true;
	}

	function closePopup() {
		if (!modal) { return; }
		if (modal.hasAttribute('data-ocsl-locked') && D.onCheckout) {
			// The checkout stays closed to guests: back to the cart (or home, in a shop whose cart is the checkout page).
			location.href = (D.cart && !samePage(D.cart, location.href)) ? D.cart : (D.home || '/');
			return;
		}
		modal.hidden = true;
		document.documentElement.classList.remove('ocsl-open');
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	if (modal) {
		qa('[data-ocsl-close]', modal).forEach(function (el) { el.addEventListener('click', closePopup); });
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !modal.hidden) { closePopup(); }
			if (e.key === 'Tab' && !modal.hidden) {
				var f = qa('button:not([hidden]), input:not([type=hidden]):not([tabindex="-1"]), a[href]', modal).filter(function (el) {
					return el.offsetParent !== null;
				});
				if (!f.length) { return; }
				var first = f[0], last = f[f.length - 1];
				if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
				else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
			}
		});
		var guest = q('[data-ocsl-guest]', modal);
		if (guest) {
			guest.addEventListener('click', function () {
				try { sessionStorage.setItem(GUEST_KEY, '1'); } catch (e) {}
				var to = popupBox.to;
				modal.hidden = true;
				document.documentElement.classList.remove('ocsl-open');
				if (to && !samePage(to, location.href)) { location.href = to; }
			});
		}
	}

	/* ---------------- the gate ---------------- */

	function gated() {
		return D.mode === 'required' || (D.mode === 'optional' && !guestChosen());
	}

	function isCheckoutTrigger(el) {
		var a = el.closest('a[href]');
		// Paying for an existing order, or its thank-you page, are not the way into the checkout.
		if (a && /[?&\/](order-pay|order-received)[=\/]/.test(a.href)) { return ''; }
		if (a && D.checkout && samePage(a.href, D.checkout)) { return a.href; }
		var b = el.closest('.checkout-button, a.checkout, [name="proceed_to_checkout"]');
		if (b) { return D.checkout; }
		return '';
	}

	if (modal && D.mode !== 'off') {
		// Capture phase: before the theme's own handlers send the guest to the checkout.
		document.addEventListener('click', function (e) {
			if (!gated() || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
			var to = isCheckoutTrigger(e.target);
			if (!to) { return; }
			e.preventDefault();
			e.stopPropagation();
			openPopup(to);
		}, true);

		if (D.onCheckout && gated()) {
			openPopup(D.checkout);
		}
	}

	// Extra triggers set by the shop (e.g. the header's account icon): open the popup in place.
	if (modal && D.selectors) {
		document.addEventListener('click', function (e) {
			var hit;
			try { hit = e.target.closest(D.selectors); } catch (err) { return; }
			if (!hit) { return; }
			e.preventDefault();
			e.stopPropagation();
			openPopup('');
		}, true);
	}

	/* ---------------- inline blocks (My Account, the shortcode) ---------------- */

	function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
	var MAIL_ICON = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>';

	/*
	 * Our block inside a theme's header login drawer (OC main theme: #auth-panel, WooCommerce's
	 * login form): dress it like OC Theme's drawer. A header line, a centred title, the phone form at
	 * full width, "או" and an email/password door that opens the theme's own form, and the sign-up at
	 * the bottom, which opens the theme's own register view. Nothing of the theme is removed.
	 */
	function drawerize(el) {
		var panel = el.closest('#auth-panel, .auth-panel');
		if (!panel || panel.classList.contains('ocsl-drawer')) { return; }
		panel.classList.add('ocsl-drawer');

		var head = document.createElement('div');
		head.className = 'ocsl-dhead';
		head.textContent = T.drawerHead || '';
		el.insertBefore(head, el.firstChild);
		var title = q('[data-ocsl-step="phone"] .ocsl-h', el);
		if (title && T.drawerTitle) { title.textContent = T.drawerTitle; }
		var tel = q('[data-ocsl-step="phone"] input[name=phone]', el);
		if (tel) { tel.setAttribute('placeholder', 'מספר טלפון נייד'); }

		// The drawer has its own way of keeping the theme's forms closed (the stylesheet's .ocsl-only rules are for a page).
		el.classList.remove('ocsl-only');
		// The shop shows the phone alone: no door to the theme's email and password form.
		var loginCol = D.emailLogin === false ? null : panel.querySelector('#customer_login .u-column1');
		var oldOr = q('.ocsl-or', el);
		if (loginCol) {
			var alt = document.createElement('div');
			alt.className = 'ocsl-alt';
			alt.innerHTML = '<p class="ocsl-or"><span>או</span></p><button type="button" class="ocsl-door" data-ocsl-email>' + MAIL_ICON + '<span>' + esc(T.drawerEmail) + '</span></button>';
			if (oldOr) { oldOr.parentNode.replaceChild(alt, oldOr); } else { el.appendChild(alt); }
			var back = document.createElement('button');
			back.type = 'button';
			back.className = 'ocsl-link ocsl-dback';
			back.textContent = T.drawerBack || '';
			loginCol.insertBefore(back, loginCol.firstChild);
			q('[data-ocsl-email]', alt).addEventListener('click', function () {
				panel.classList.add('ocsl-email-open');
				var u = panel.querySelector('#username');
				if (u) { setTimeout(function () { u.focus(); }, 30); }
			});
			back.addEventListener('click', function () {
				panel.classList.remove('ocsl-email-open');
				if (tel) { tel.focus(); }
			});
		} else if (oldOr) {
			oldOr.parentNode.removeChild(oldOr);
		}

		var regBtn = panel.querySelector('#customer_login button.register-form, #customer_login .register-form-link button.auth-btn');
		var cl = panel.querySelector('#customer_login');
		if (regBtn && cl) {
			var foot = document.createElement('div');
			foot.className = 'ocsl-dfoot';
			foot.innerHTML = '<p class="ocsl-dfoot-t">' + esc(T.drawerJoinTitle) + '</p>' +
				(T.drawerJoinText ? '<p class="ocsl-dfoot-s">' + esc(T.drawerJoinText) + '</p>' : '') +
				'<button type="button" class="ocsl-door" data-ocsl-join>' + esc(T.drawerJoinBtn) + '</button>';
			(panel.querySelector('.form-login') || el.parentNode).appendChild(foot);
			panel.classList.add('ocsl-has-join');
			q('[data-ocsl-join]', foot).addEventListener('click', function () { regBtn.click(); });
			var sync = function () { panel.classList.toggle('ocsl-reg-open', cl.classList.contains('register-show')); };
			if (window.MutationObserver) { new MutationObserver(sync).observe(cl, { attributes: true, attributeFilter: ['class'] }); }
			sync();
		}
	}

	/*
	 * The theme's own sign-up form (WooCommerce's register form, in the header drawer or on My
	 * Account) is sent in place: its errors appear inside the form instead of after a page load that
	 * prints them somewhere in the middle of the page. WooCommerce still does the work: the form is
	 * posted to this same page exactly as the browser would post it, and the answer is read for
	 * WooCommerce's errors, or for a signed-in page.
	 */
	function inPlaceRegister(form) {
		if (form.getAttribute('data-ocsl-ajax')) { return; }
		form.setAttribute('data-ocsl-ajax', '1');
		var btn = q('button[type=submit], input[type=submit]', form);
		var msg = document.createElement('p');
		msg.className = 'ocsl-err ocsl-reg-err';
		msg.setAttribute('role', 'alert');
		msg.hidden = true;
		var row = btn ? btn.closest('p, .form-row, div') : null;
		if (row && row.parentNode === form) { form.insertBefore(msg, row); } else if (btn && btn.parentNode) { btn.parentNode.insertBefore(msg, btn); } else { form.appendChild(msg); }
		var busy = false;

		function say(text) { msg.textContent = text || ''; msg.hidden = !text; }
		function lock(on) {
			busy = on;
			if (!btn) { return; }
			if (on) { btn.setAttribute('data-label', btn.textContent || btn.value); }
			var label = on ? 'נרשמים...' : btn.getAttribute('data-label');
			if (btn.tagName === 'INPUT') { btn.value = label; } else { btn.textContent = label; }
			btn.disabled = on;
		}
		// Every field the visitor can see is needed (themes add names and a second password).
		function firstEmpty() {
			return qa('input[type=text], input[type=email], input[type=password], input[type=tel]', form).filter(function (f) {
				return f.offsetParent && f.name !== 'website' && f.value.trim() === '';
			})[0] || null;
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			e.stopImmediatePropagation();
			if (busy) { return; }
			say('');
			var empty = firstEmpty();
			if (empty) { say('צריך למלא את כל השדות.'); empty.focus(); return; }
			var email = q('[name="email"]', form);
			if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) { say('הזינו כתובת אימייל תקינה.'); email.focus(); return; }
			var pw = qa('input[type=password]', form).filter(function (f) { return f.offsetParent; });
			if (pw.length === 2 && pw[0].value !== pw[1].value) { say('הסיסמאות לא זהות.'); pw[1].focus(); return; }

			var data = new FormData(form);
			if (btn && btn.name && !data.has(btn.name)) { data.append(btn.name, btn.value || '1'); }
			if (!data.has('register')) { data.append('register', '1'); }
			lock(true);
			fetch(location.href, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (r) { return r.text(); })
				.then(function (html) {
					var doc = new DOMParser().parseFromString(html, 'text/html');
					var seen = {};
					var errs = qa('.woocommerce-error:not(.hidden-notice) li, .woocommerce-error:not(.hidden-notice):not(ul)', doc)
						.map(function (el) { return el.textContent.replace(/\s+/g, ' ').trim(); })
						.filter(function (t) { if (!t || seen[t]) { return false; } seen[t] = 1; return true; });
					if (errs.length) { lock(false); say(errs.join(' ')); return; }
					if (doc.body && /(^|\s)logged-in(\s|$)/.test(doc.body.className)) {
						try { sessionStorage.setItem(SIGNED_KEY, String(Date.now())); } catch (err) {}
						location.reload();
						return;
					}
					lock(false);
					say(T.error);
				})
				.catch(function () { lock(false); say(T.error); });
		}, true);
	}
	qa('form.woocommerce-form-register').forEach(inPlaceRegister);

	qa('[data-ocsl-inline]').forEach(function (el) {
		drawerize(el);
		var b = new Box(el);
		if (!b.resume()) { b.step('phone'); }
	});

	window.ocslOpen = openPopup;
});
