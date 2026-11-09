# OC SMS Login ↔ OC Pluginz hub: the contract

Status: **built** (2026-11-08). Plugin side: this repo. Hub side: `originalconcepts/ocpluginz-hub`, branch
`feature/sms-relay` (hub-store 0.4.22, `OCPZS_Sms`), deployed only with the owner's approval.

## The business model (the owner, 2026-11-08)

- **The plugin is free.** Product `oc-sms-login`, plugin folder and main file `oc-sms-login`.
- **The messages are paid**, in packs. OC Pluginz holds one SMS bank at ActiveTrail; a shop buys a pack,
  OC Pluginz credits the shop's balance, and the shop's codes go out **from OC Pluginz' ActiveTrail
  account under the shop's own sender name** (English, up to 11 characters).
- **The ActiveTrail key lives only on the hub.** A shop never holds it.

## Packs

Prices are **before VAT** (18%); the checkout adds VAT. Credits never expire.

| key | messages | price | per message | incl. VAT |
|---|---:|---:|---:|---:|
| `sms2500` | 2,500 | ₪199 | 8 ag. | ₪234.82 |
| `sms5000` | 5,000 | ₪349 | 7 ag. | ₪411.82 |
| `sms10000` | 10,000 | ₪590 | 5.9 ag. | ₪696.20 |
| `sms25000` | 25,000 | ₪1,290 | 5.2 ag. | ₪1,522.20 |
| `sms50000` | 50,000 | ₪2,290 | 4.6 ag. | ₪2,702.20 |
| `sms100000` | 100,000 | ₪3,990 | 4 ag. | ₪4,708.20 |

Six Woo products on the hub (one per pack), paid through the hub's Cardcom checkout; the order credits
the licence's bank (`pack:<order>:<item>`), like the OC Snap packs.

## The entitlement

`POST /sdk/activate` and `GET /sdk/status` are the same as for every product. The licence is free (a
zero-price plan, or one granted by the agency). For `oc-sms-login` the entitlement also carries:

```json
"credits": {
  "unit": "messages",
  "balance": 9412,
  "packs": [
    {"key": "sms2500", "messages": 2500, "price": "₪234.82", "price_ex": 199, "url": "https://my.ocpluginz.com/checkout/?add-to-cart=NNN"},
    …
  ],
  "buy_url": "https://ocpluginz.com/oc-sms-login.html#credits"
}
```

`price` is the hub's display string **with** VAT; `price_ex` is the number before VAT, which the plugin
shows (and adds VAT to). The plugin shows the hub's `packs` when present and falls back to the table
above (without buy links). The licence is the free plan `free`: a ₪0 Woo product, or opened by the first
pack bought on an account that has none (the key is mailed then).

## The endpoints (base `https://my.ocpluginz.com/wp-json/ocpz/v1`)

Every request carries `X-OCPZ-Key`, `X-OCPZ-Site` (the domain) and `X-OCPZ-Version`, plus the identity
fields every OC plugin sends (`key`, `product`, `domain`, …). The usual refusals apply: 401
`ocpzs_bad_key`, 403 `ocpzs_inactive`, 403 `ocpzs_site_not_active`, 429 `ocpzs_rate`.

### `GET /sms/balance`

`{balance, unit: "messages", packs, buy_url}`.

### `POST /sms/send` with `{to, text, sender, purpose}`

- `to`: Israeli mobile, local form `05XXXXXXXX`. Anything else: 400 `ocpzs_bad_request`.
- `text`: the message, at most 300 characters. The hub counts the **segments** it will cost:
  GSM-7 text 160 a single message, 153 per part when longer; any non-GSM character (Hebrew) makes it
  UCS-2: 70 a single message, 67 per part. The plugin's default text is one segment.
- `sender`: `[A-Za-z0-9 ]{1,11}`, else 400 `ocpzs_sender`. The hub may pin a sender per licence
  (an agency override), in which case it uses its own and ignores this field.
- `purpose`: `login` (the only one for now). The text must carry the code (a run of 4 to 8 digits), so the
  bank cannot be used as a marketing channel.

The hub charges the segments from the bank **atomically before sending**, calls ActiveTrail
`POST https://webapi.mymarketing.co.il/api/smscampaign/OperationalMessage` with its own key
(`details.from_name` = the sender, `details.content` = the text, `mobiles[0].phone_number` = `to`,
`scheduling.send_now` = true), and:

- **200** `{ok: true, cost, balance}` when ActiveTrail answered 200.
- **402** `ocpz_no_credits` with `data: {balance, cost, packs, buy_url}` when the bank cannot cover it
  (nothing is sent, nothing is charged).
- **502** `ocpzs_upstream` when ActiveTrail did not answer 200: the charge is **refunded** (ref
  `sms:<id>:back`) and `data.balance` carries the balance after the refund.

Rate limits on the hub (on top of the plugin's own per-phone, per-IP and daily rails): 60 sends a minute
per key, and the same number at most 5 times an hour per key.

Ledger refs: `sms:<id>` for a send, `sms:<id>:back` for its refund, `pack:<order>:<item>` for packs.
Usage: `OCPZ_Ledger::track_usage` with `messages` += segments.

## Hub settings (OC Pluginz → ⚙️ הגדרות → "SMS")

- **ActiveTrail key:** encrypted, `ocpzs_sms_activetrail_key`; `OCPZS_ACTIVETRAIL_KEY` in wp-config wins.
- **Adding or removing messages** on a licence: the shared bank form, labelled "הודעות".
- **Sender override** per licence: the `ocpzs_sms_sender` filter (no screen yet).
- A **test message** form (on OC Pluginz' own account) and a button that creates the missing Woo products
  (the free plan and the six packs: published, hidden from the catalogue, prices before VAT).
- No key: `503 ocpzs_sms_unconfigured`, nothing charged, and the shop owner gets a mail once a day.

## The plugin's other ways out (no hub involved)

- **Agency mode** (`OC_PLUGINZ_AGENCY` + `OCSL_ACTIVETRAIL_KEY` in wp-config.php): our own sites call
  ActiveTrail directly, no balance.
- **Test mode** (`OCSL_TEST_MODE` in wp-config.php): nothing is sent, the code is shown in the popup.
  Development copies only.
