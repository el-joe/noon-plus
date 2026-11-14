# Time & timezone rules (user decision, 2026-11-14)

## Rules
1. **Storage.** Every timestamp is stored in **UTC** (`timestamp` columns, app timezone `UTC`). Business dates are never stored as local strings.
2. **Each country has its own timezone** (`countries.timezone`, IANA):

   | Country | Timezone | UTC offset |
   |---|---|---|
   | UAE | Asia/Dubai | +04 |
   | Oman | Asia/Muscat | +04 |
   | KSA | Asia/Riyadh | +03 |
   | Egypt | Africa/Cairo | +02, +03 in summer (DST) |

   Storefront, apps, notifications, delivery estimates and reports for a country use that country's timezone. "Daily" jobs and report day boundaries run per country.
3. **The admin panel shows times in Oman time.** `settings.admin_timezone = Asia/Muscat`, editable per admin user later. Every date-time an admin sees (lists, details, audit log, dashboards) is in Oman time.
4. **Country-scoped date-time inputs** are flash sale start/end, banner, page and block schedules, coupon and voucher validity, campaign end dates, ad bookings, scheduled streams and radio slots, and travel departures.
   - They are entered in **that country's local time**, because a sale "starts at 10:00 PM in Egypt".
   - The field shows the country's timezone as its label.
   - **A small label under the field shows the Oman equivalent**, e.g. `= Sat 16 Nov 2026, 12:00 AM Oman time (GST, UTC+4)` (Egypt is UTC+2 in November).
5. **Displayed country date-times** in admin tables show local time plus a small Oman chip. Example: `15 Nov, 10:00 PM EG` `· 12:00 AM +1d Oman`. A tooltip shows the full date, both offsets and UTC.
6. **Partner, marketer, travel and carrier panels** show times in the account's own country timezone. When the panel's country is not Oman, the same small Oman label is optional (off by default).
7. **DST and invalid times.**
   - Egypt has daylight saving time. Inputs reject times that don't exist (the spring-forward gap) and ask the user to choose when a time is ambiguous (the fall-back repeat).
   - The Oman label is computed for the actual date, never with a fixed offset.
8. **Countdowns on the storefront** use the server's UTC `ends_at`, so every customer sees the same moment.

## Implementation
- **Backend:** `App\Shared\Time\CountryClock`, with `toUtc(local, country)`, `toCountry(utc, country)` and `toAdmin(utc)`.
  - Form requests take `{value, tz}`.
  - Resources return `{utc, local, local_tz, admin, admin_tz}`.
- **Blade:**
  - `<x-ui.datetime-input name country="egy" />` is a Flatpickr input that renders the live "= Oman time" helper with `Intl.DateTimeFormat`.
  - `<x-ui.datetime :value :country />` is the display version with the Oman chip.
- **Tests:**
  - Egypt 22:00 maps to 00:00 next day in Oman in winter (UTC+2) and 23:00 Oman in summer (UTC+3).
  - UAE and Oman are identical.
  - KSA 22:00 maps to 23:00 Oman.
  - DST gap and overlap cases.
  - A scheduler run at the UTC boundary.
