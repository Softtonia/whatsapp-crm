# 💬 WhatsApp CRM

An enterprise-ready WhatsApp CRM built on **Laravel 12** featuring multi-tenant shared inboxes, WhatsApp Cloud API webhooks, AI-assisted smart replies, sales lead pipelines, dynamic email templates, and trigger-based campaigns.

---

## ⚡ Quick Links & Postman

Direct links to API documentation and testing resources:

| Resource | Direct Link | Description |
|---|---|---|
| 📮 **Postman Collection** | [WhatsApp-CRM.postman_collection.json](docs/postman/WhatsApp-CRM.postman_collection.json) | Complete collection of all admin endpoints |
| 🌐 **Postman Environment** | [WhatsApp-CRM.postman_environment.json](docs/postman/WhatsApp-CRM.postman_environment.json) | Local environment variables (`base_url`, `admin_token`) |
| 📁 **Postman Directory** | [docs/postman/](docs/postman/) | Folder containing collection & environment files |
| 🛣️ **API Routes** | [routes/api.php](routes/api.php) | Central route definitions |

> **Postman Tip**: Import both files in Postman, choose the environment, run **Admin Login**, and the bearer token will auto-populate for all requests.

---

## 🚀 Key Modules

- **Shared Team Inbox**: Real-time WhatsApp Cloud API chat sync, assignments, tags, and internal notes.
- **WhatsApp Cloud API**: Official webhook verification, inbound message handler, and outbound media dispatch.
- **AI Smart Assistant**: Business knowledge base integration with AI reply drafting and human approval workflows.
- **Lead Pipeline**: Kanban-style customizable stages, deal values, and scheduled follow-up reminders.
- **Contacts & Bulk Ops**: Contact interaction history, Excel/CSV bulk import, and export.
- **Email Templates**: Dynamic placeholder replacement (`{{UserName}}`, `{{Otp}}`, etc.), live preview, and HTML/Plain formats.
- **Campaigns**: Trigger-driven, scheduled, or instant email delivery with duplicate-send prevention.
- **Security & RBAC**: Spatie Permissions, Sanctum auth, and encrypted email OTP password resets.

---

## 🛠️ Quick Start

```bash
# 1. Install dependencies
composer install && npm install && npm run build

# 2. Setup environment & app key
cp .env.example .env
php artisan key:generate

# 3. Run database migrations & seeders
php artisan migrate --seed

# 4. Storage link & start queue worker
php artisan storage:link
php artisan queue:work --queue=emails,default

# 5. Serve locally
php artisan serve
```

---

## 📡 API Overview

Base URL: `{{base_url}}/api/auth/admin` *(Protected by `Bearer <token>`)*

- **Auth & Password**: `/login`, `/me`, `/logout`, `/forgot-password`, `/verify-password-otp`, `/reset-password`
- **Shared Inbox**: `/inbox/conversations`, `/inbox/conversations/{id}/messages`, `/inbox/conversations/{id}/notes`
- **Leads & Pipeline**: `/lead-pipeline/stages`, `/leads`, `/leads/{id}/notes`
- **Contacts**: `/contacts`, `/contacts/import`, `/contacts/export`, `/contacts/import/template`
- **AI Assistant**: `/ai/knowledge`, `/ai/drafts`, `/ai/drafts/{id}/approve`, `/ai/drafts/{id}/reject`
- **Email Templates**: `/email-templates`, `/email-templates/placeholders`, `/email-templates/preview`, `/email-templates/by-key/{key}`
- **Campaigns**: `/campaigns/events`, `/campaigns`, `/campaigns/{id}/status`
- **Catalog & RBAC**: `/products`, `/businesses`, `/roles`, `/permissions`
- **Webhooks**: `GET|POST /api/webhooks/whatsapp`

---

## 🧪 Testing

```bash
php artisan test
```

---

## 📄 License

Proprietary software maintained by [Softtonia](https://github.com/Softtonia).
