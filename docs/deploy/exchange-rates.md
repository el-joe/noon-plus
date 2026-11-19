# Exchange rates and PayPal in USD

PayPal cannot charge or settle AED, OMR, SAR, EGP, KWD, QAR, BHD or JOD. Nawy charges PayPal in **USD**. The USD amount comes from an **exchange rate** kept in the central `exchange_rates` table. The order's money stays in the country currency at every step.

## How it works

- **Rates.** Admins add rates in **Admin → Countries & gateways → Exchange rates** (permission `exchange_rates.manage`, held by Finance and super admins).
  - A rate means `1 base = rate quote`, for example `1 AED = 0.2723 USD`.
  - It applies from its *effective* time, entered in Oman time.
  - Rows are only ever added, never edited. Every row is in the activity log (`exchange_rates`).
- **Enabling PayPal for a country.** It needs a current `{country currency} → USD` rate.
  - Without one, the gateway screen refuses to enable PayPal.
  - Checkout never offers PayPal while a country has no rate.
- **Starting a PayPal payment.**
  - The payment stores the country amount in `amount`.
  - It also stores `charge_currency` (USD), `charge_amount` (converted half-up to cents), `exchange_rate` and `exchange_rate_id`.
  - PayPal is only ever sent `charge_amount` in USD.
- **What stays in the country currency:** captured amounts, refunded amounts, the ledger, settlements and payouts.
- **Amounts PayPal reports.**
  - A reported USD amount equal to `charge_amount` counts as the exact order amount.
  - Any other amount is converted back with the stored rate and puts the payment in *under review* (`amount_mismatch`).
- **Refunds.**
  - A refund converts the country amount with the payment's **stored** rate, never the current one, and keeps the USD figure in `payment_refunds.charge_amount`.
  - Refunding everything that is left returns exactly the USD left, so rounding never strands cents.
- **Checkout display.**
  - `GET /api/v1/{market}/checkout/payment-methods` shows PayPal with `charge: {currency, amount, rate}`.
  - The order payment resource shows `payment.charge`.

PayPal's own currency list is in `config/nawy.php` under `payments.paypal.native_currencies`. Those currencies are charged as they are, with no conversion. None of the Nawy countries' currencies are in it.

## Automatic rates (optional, owner key)

The `nawy:exchange-rates:sync` job runs daily at 00:05 Oman time. It asks the configured provider for every active country currency → USD.

| `NAWY_EXCHANGE_RATES_DRIVER` | Behaviour |
|---|---|
| `manual` (default) | Fetches nothing. Admins keep the rates. |
| `log` | Logs what it would fetch, and fetches nothing. |
| `openexchangerates` | Calls Open Exchange Rates (`/api/latest.json`, USD base; other pairs are derived through USD). Needs **`NAWY_EXCHANGE_RATES_APP_ID`**, the owner's App ID from openexchangerates.org (the free plan is enough). |

Provider rates are stored with `source = openexchangerates`. A failed sync is reported, and the previous rates stay in force.

Note that AED, SAR, OMR, QAR, BHD and JOD are pegged to the USD. One manual rate per currency, reviewed when the bank rate changes, is enough to launch PayPal. EGP and KWD float: use the provider, or update those rates often.
