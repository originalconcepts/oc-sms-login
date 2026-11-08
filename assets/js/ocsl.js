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

	/* ---------------- helpers ---------------- */

	function q(sel, root) { return (root || document).querySelector(sel); }
	function qa(sel, root) { return [].slice.call((root || document).querySelectorAll(sel)); }
	function fmt(s, v) { return String(s || '').replace('%s', v); }

	function post(action, data) {
		var body = new FormData();
		body.append('action', 'ocsl_' + action);
		body.append('nonce', D.nonce);
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
		return fetch(D.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.catch(function () { return { success: false, data: { msg: T.error } }; });
	}

	function samePage(a, b) {
		try {
			var x = new URL(a, location.href), y = new URL(b, location.href);
			return x.origin === y.origin && x.pathname.replace(/\/+$/, '') === y.pathname.replace(/\/+$/, '');
		} catch (e) { return false; }
	}

	function guestChosen() {
		try { return sessionStorage.getItem(GUEST_KEY) === '1'; } catch (e) { return false; }
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
			this.step('register');
			return;
		}
		if (data.step === 'code') {
			q('.ocsl-pretty', this.root).textContent = data.pretty || '';
			var test = q('.ocsl-test', this.root);
			if (test) {
				test.hidden = !data.test_code;
				test.textContent = data.test_code ? fmt(T.testCode, data.test_code) : '';
			}
			qa('.ocsl-box', this.root).forEach(function (b) { b.value = ''; });
			this.step('code');
			this.countdown(data.wait || 60);
			this.webOtp();
			return;
		}
		if (data.step === 'done') {
			this.step('done');
			var to = data.to || this.to;
			setTimeout(function () {
				if (to && !samePage(to, location.href)) { location.href = to; } else { location.reload(); }
			}, 400);
		}
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

		q('[data-ocsl-step="phone"]', root).addEventListener('submit', function (e) {
			e.preventDefault();
			if (self.busy) { return; }
			var form = this;
			self.phone = q('input[name=phone]', form).value;
			if (self.phone.replace(/\D/g, '').length < 9) { self.error('phone', 'הזינו מספר טלפון נייד.'); return; }
			self.lock(form, true);
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
		modal.hidden = false;
		document.documentElement.classList.add('ocsl-open');
		popupBox.step('phone');
		return true;
	}

	function closePopup() {
		if (!modal) { return; }
		if (modal.hasAttribute('data-ocsl-locked') && D.onCheckout) {
			location.href = D.cart; // The checkout stays closed to guests: back to the cart.
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

	var gated = D.mode === 'required' || (D.mode === 'optional' && !guestChosen());

	function isCheckoutTrigger(el) {
		var a = el.closest('a[href]');
		if (a && D.checkout && samePage(a.href, D.checkout)) { return a.href; }
		var b = el.closest('.checkout-button, a.checkout, [name="proceed_to_checkout"]');
		if (b) { return D.checkout; }
		return '';
	}

	if (modal && gated && D.mode !== 'off') {
		// Capture phase: before the theme's own handlers send the guest to the checkout.
		document.addEventListener('click', function (e) {
			if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) { return; }
			var to = isCheckoutTrigger(e.target);
			if (!to) { return; }
			e.preventDefault();
			e.stopPropagation();
			openPopup(to);
		}, true);

		if (D.onCheckout) {
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

	qa('[data-ocsl-inline]').forEach(function (el) {
		var b = new Box(el);
		b.step('phone');
	});

	window.ocslOpen = openPopup;
});
