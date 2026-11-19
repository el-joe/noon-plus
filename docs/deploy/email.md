# Email — provider setup, DNS and delivery tracking

The platform sends every important email through one engine (`app/Shared/Notifications/Email`, catalogue in `docs/project/email-catalogue.md`). This page covers what the owner must configure to send real mail. **Sending through a real provider needs the owner's provider account and keys; development stays on `MAIL_MAILER=log`.**

## 1. How mail leaves the app

- Every email is a queued `EventEmail` notification on the **`mail`** Redis queue (Horizon supervisor `supervisor-default`, wait alert at 120 s). 5 tries, back-off 30 s / 2 min / 10 min / 30 min. A failed email is marked `failed` in the email log with the reason.
- Laravel's mailer decides the transport: `MAIL_MAILER` = `log` (dev), `smtp`, `ses`, `postmark`, `mailgun` (Symfony transports already in the framework; no new packages).
- From address: `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` (e.g. `no-reply@nawy.com`, `Nawy`). Use a sub-domain dedicated to mail (e.g. `mail.nawy.com`) if marketing mail is added later.

## 2. Provider environment variables

| Provider | `.env` |
|---|---|
| SMTP (any relay) | `MAIL_MAILER=smtp` `MAIL_HOST` `MAIL_PORT=587` `MAIL_USERNAME` `MAIL_PASSWORD` `MAIL_SCHEME=smtp` (STARTTLS) or `smtps` (465) |
| Amazon SES | `MAIL_MAILER=ses` `AWS_ACCESS_KEY_ID` `AWS_SECRET_ACCESS_KEY` `AWS_DEFAULT_REGION` (e.g. `eu-central-1`); IAM policy `ses:SendRawEmail`. Move the account out of the SES sandbox. |
| Postmark | `MAIL_MAILER=postmark` `POSTMARK_API_KEY` (server token); optional `POSTMARK_MESSAGE_STREAM_ID=outbound` |
| Mailgun | `MAIL_MAILER=mailgun` `MAILGUN_DOMAIN` `MAILGUN_SECRET` `MAILGUN_ENDPOINT` (`api.eu.mailgun.net` for EU) |

`config/services.php` already reads the SES / Postmark / Mailgun keys (Laravel defaults). After changing `.env`: `php artisan config:cache` and `php artisan horizon:terminate`.

## 3. Bounce / complaint webhooks

Endpoint: `POST https://<api host>/api/v1/webhooks/email/{driver}` — `driver` = `generic`, `ses`, `postmark`, `mailgun`. Only drivers listed in `NAWY_MAIL_WEBHOOK_DRIVERS` (comma separated, default `generic`) answer; each verifies the request:

| Driver | Verification | Env |
|---|---|---|
| `ses` | SNS message signature (v1 SHA1 / v2 SHA256) with the certificate from `sns.<region>.amazonaws.com`; topic must match. Subscription confirmations are confirmed automatically. In SES: configuration set → event destination SNS (Bounce, Complaint, Delivery, Open). | `NAWY_MAIL_SES_TOPIC_ARN` |
| `postmark` | HTTP basic auth on the webhook URL (set the same user/password in Postmark → Webhooks; events Bounce, Spam complaint, Delivery, Open) | `NAWY_MAIL_WEBHOOK_USER`, `NAWY_MAIL_WEBHOOK_PASSWORD` |
| `mailgun` | HMAC-SHA256(timestamp + token) with the webhook signing key, timestamp ≤ 15 min old | `MAILGUN_WEBHOOK_SIGNING_KEY` |
| `generic` | HMAC-SHA256 of the raw body, hex, header `X-Nawy-Signature` (for any relay or adapter) | `NAWY_MAIL_WEBHOOK_SECRET` |

Effects: the email log row (matched by the `email_log_id` metadata/tag, else the provider message id) becomes `bounced` / `complained` / `sent` (delivery) or gets `opened_at`. A **hard bounce** puts the address on the suppression list for all mail; a **complaint** suppresses everything that can be turned off and alerts admins who opted in to "Email complaints". Soft bounces are only logged.

## 4. DNS (owner action, per sending domain)

| Record | Value |
|---|---|
| SPF (TXT on the sending domain) | `v=spf1 include:amazonses.com ~all` (SES) · `include:spf.mtasv.net` (Postmark) · `include:mailgun.org` (Mailgun) · your relay's include for SMTP. One SPF record per domain — merge includes. |
| DKIM | SES: 3 CNAMEs `<token>._domainkey` → `<token>.dkim.amazonses.com` (Easy DKIM). Postmark: TXT `<selector>pm._domainkey`. Mailgun: TXT `<selector>._domainkey`. Values come from the provider console. |
| DMARC (TXT `_dmarc`) | Start with `v=DMARC1; p=none; rua=mailto:dmarc@nawy.com; fo=1`, move to `p=quarantine` then `p=reject` once reports are clean. |
| Return-Path / MAIL FROM | SES custom MAIL FROM (`bounce.mail.nawy.com` MX + SPF), Postmark Return-Path CNAME `pm-bounces`, Mailgun tracking CNAME — aligns SPF for DMARC. |

Country storefront domains (Admin → Countries → Domains) only affect links in emails; mail is sent from the one sending domain.

## 5. One-click unsubscribe

Non-transactional mail (reminders, invitations, wishlist alerts, cart reminders, admin alerts) carries `List-Unsubscribe: <https://<api>/api/v1/email/unsubscribe/{token}>, <landing page>` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058, required by Gmail / Yahoo for bulk senders). Tokens are HMAC-signed with `APP_KEY` (rotating the key invalidates old links). Customers land on the website `/{market}/unsubscribe?token=…`; panel users and guests on `https://<api>/email/unsubscribe/{token}`. Transactional and security mail has no unsubscribe link and cannot be turned off.

## 6. Development

- `MAIL_MAILER=log`: messages go to `storage/logs/laravel.log`.
- Optional local mail catcher (not installed by the project): run **Mailpit** (`docker run -p 8025:8025 -p 1025:1025 axllent/mailpit`) and set `MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=1025 MAIL_SCHEME=smtp` to read the HTML in a browser at `http://localhost:8025`.
- Admin → Content → Notification templates → *Send test to me* sends the template with sample data to the signed-in admin; Admin → Platform → Email log shows status, failure reason, resend and the suppression list.

## 7. Checklist before go-live

1. Provider account out of sandbox, sending domain verified (SPF, DKIM, DMARC, MAIL FROM) — **owner**.
2. `.env` provider keys + `MAIL_FROM_*` — **owner**.
3. Webhook configured in the provider + `NAWY_MAIL_WEBHOOK_DRIVERS` + its secret — **owner**.
4. Horizon running with the `mail` queue (already in `config/horizon.php`).
5. Send a test from the template editor to Gmail and Outlook; check SPF/DKIM `pass` in the headers.
