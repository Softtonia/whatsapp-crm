# WhatsApp Shared Inbox — Admin APIs

All admin endpoints use `/api/auth/admin`. Login is `POST /api/auth/admin/login`; all other admin endpoints require a Sanctum Bearer token and the `super_admin` role. Webhooks are provider-facing and use Meta verification/signature validation instead of admin auth.

## Configure a business WhatsApp account

`POST /api/auth/admin/inbox/accounts`
```json
{
  "business_id": 1,
  "phone_number_id": "YOUR_META_PHONE_NUMBER_ID",
  "waba_id": "YOUR_WHATSAPP_BUSINESS_ACCOUNT_ID",
  "display_phone_number": "+91xxxxxxxxxx",
  "access_token": "YOUR_LONG_LIVED_CLOUD_API_ACCESS_TOKEN",
  "status": "active"
}
```
The access token is encrypted at rest and is never returned by account APIs. Do not put real credentials in Postman exports or source control.

- `GET /api/auth/admin/inbox/accounts`
- `PATCH /api/auth/admin/inbox/accounts/{account}` (can rotate access token or change status)

## Conversations

- `GET /api/auth/admin/inbox/conversations?status=open&search=customer&business_id=1&assigned_to=3&unread_only=1&per_page=25`
- `GET /api/auth/admin/inbox/conversations/{conversation}`
- `GET /api/auth/admin/inbox/conversations/{conversation}/messages?per_page=50`
- `PATCH /api/auth/admin/inbox/conversations/{conversation}`
- `POST /api/auth/admin/inbox/conversations/{conversation}/read`
- `POST /api/auth/admin/inbox/conversations/{conversation}/notes`
- `POST /api/auth/admin/inbox/conversations/{conversation}/messages`
- `GET /api/auth/admin/inbox/messages/{message}/media`

Status values: `open`, `pending`, `resolved`. Assignment must be to an active member of that business. `is_human_takeover: true` records that a human agent has taken over; no AI automation is implemented in this phase.

PATCH example:
```json
{"status":"pending","assigned_to":3,"is_human_takeover":true}
```

Internal note example:
```json
{"body":"Customer asked for a callback after 4 PM."}
```

Send text example:
```json
{"type":"text","body":"Hello! How can we help?"}
```

Send image/document/video/audio by supplying either a previously uploaded `media_id` or an HTTPS `link`; use `caption` for supported types and `filename` for documents. This API sends through the official WhatsApp Cloud API; a configured account and valid permissions are required. The 24-hour customer service window and approved-template requirements are enforced by Meta; template sending is outside this first-phase scope.

## Webhook setup

Set `WHATSAPP_VERIFY_TOKEN` and `WHATSAPP_APP_SECRET` in `.env`. Configure Meta's callback URL as:
`https://YOUR_PUBLIC_DOMAIN/api/webhooks/whatsapp`

Subscribe to the `messages` webhook field. The GET verification endpoint validates the verify token; POST validates `X-Hub-Signature-256` against the raw request body using the Meta App Secret. Do not disable signature validation. For local development use a secure tunnel with HTTPS.

Webhook payloads are de-duplicated by provider message ID. Inbound text/media metadata and delivery status updates are stored. Media download is proxied by the authenticated admin media endpoint; media bytes are not stored locally.

## Migrations and run

Add `.env` values, then run `php artisan optimize:clear`, `php artisan migrate --seed`, and `php artisan route:list --path=auth/admin`. The archive intentionally excludes `.env`, vendor/, SQLite data, and log files. Run `composer install` in your project before executing artisan commands.

## Out of scope

No lead management, AI replies, n8n/Make workflows, campaigns, template campaign scheduling, or analytics are implemented in this package.
