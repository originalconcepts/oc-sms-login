# OC SMS Login (כניסה עם SMS)

Phone-first sign-in for WooCommerce, with a phone gate in front of the checkout — the Deliz checkout
experience, as a plugin any OC store can run:

1. A guest heads to the checkout (the side cart's button, the cart page, any checkout link, or the
   checkout URL itself) and gets the phone popup first.
2. A number the shop knows (an account, or past guest orders) gets a 6-digit SMS code.
   A new number fills first name, last name and email once, then gets the code — the account opens
   only when the code comes back, so nobody can open an account on somebody else's number.
3. Signed in, the checkout opens with every detail already there: WooCommerce fills the fields from the
   account, and the account was filled from the customer's last order (address, city, floor,
   apartment, entry code, the shop's own fields).

**Free plugin, paid messages.** The codes go out from OC Pluginz' ActiveTrail account under the shop's
own sender name, and every message comes out of the site's balance on the OC Pluginz hub (packs bought
in the plugin's "חבילות SMS" screen, paid through Cardcom). The ActiveTrail key never reaches a shop.
See [docs/hub-contract.md](docs/hub-contract.md).

## Modes of the gate

| mode | what a guest gets |
|---|---|
| `required` (default) | cannot order without signing in. Enforced on the server too (`woocommerce_checkout_process`). |
| `optional` | the popup offers the sign-in; "continue as a guest" closes it for the session. |
| `off` | no gate. The block on My Account and the `[oc_sms_login]` shortcode still work. |

**The gate never blocks a sale:** while a code cannot go out (no licence, an empty balance, a hub
outage that answers "unavailable"), the gate stands down and the checkout opens to guests as before.

## Ways out

| transport | when | how |
|---|---|---|
| `hub` | normal | `POST /sms/send` on my.ocpluginz.com; the hub charges the balance and sends |
| `direct` | `OC_PLUGINZ_AGENCY` + `OCSL_ACTIVETRAIL_KEY` in wp-config.php | our own sites call ActiveTrail themselves |
| `test` | `OCSL_TEST_MODE` in wp-config.php | nothing is sent; the code is shown in the popup. **Development copies only**: anyone can sign in to any account. A constant, never a setting. |

## Security

- Codes: `random_int`, stored only as `wp_hash(code|phone)`, die after `code_expiry` seconds or
  `max_attempts` wrong tries.
- Every send is rate-limited per phone (hourly), per IP (hourly) and per site (daily cap, with an email
  to the admin when reached), counted only once a message really went out.
- Nonce on every step; honeypot + time trap on registration.
- Registering a new number with an email that already has an account is refused (otherwise anyone could
  take over an account by its email with their own phone).
- Shop staff (`manage_options` / `manage_woocommerce`) never sign in through a phone number.
- The order lookup re-checks the number on the order it found, so an order store that ignored the
  filter can never hand someone else's details to a new account.

## Structure

```
oc-sms-login.php                 bootstrap, constants, loads everything
includes/class-ocsl-settings.php one option, defaults merged on read
includes/class-ocsl-phone.php    canonical number, its stored spellings, its account / last order
includes/class-ocsl-sender.php   hub | direct | test, the message, the send log
includes/class-ocsl-auth.php     the AJAX steps: start, register, verify, resend
includes/class-ocsl-gate.php     the popup, the checkout gate, the My Account block, the shortcode
includes/class-ocsl-integrations.php  the verified phone for other plugins (OC ValueCard: no second code)
includes/class-ocsl-admin.php    settings, SMS packs, send log (under the OC Pluginz menu)
includes/hub/                    the OC Pluginz licence client (from OC Popup), notices, updater, account screen
oc-pluginz/                      the suite's shared admin menu (canonical copy in ocpluginz-hub/lib)
assets/                          ocsl.js (no dependencies), ocsl.css
docs/hub-contract.md             what the hub implements
```

Hooks: `ocsl_sending_available` (filter), `ocsl_fill_fields` (filter the fields copied from the last
order), `ocsl_hub_allows` (filter). JS: `window.ocslOpen(targetUrl)` opens the popup.

Requires WordPress 6.0+, WooCommerce 6.0+, PHP 7.4+. HPOS-compatible.

## Release

Bump `Version:` and `OCSL_VERSION` together (CI checks), `bash bin/build-zip.sh` → `dist/oc-sms-login.zip`
(top folder `oc-sms-login/`).
