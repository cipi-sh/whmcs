<p align="center">
  <img src="https://cipi.sh/cipi-logo.png" alt="Cipi" width="120">
</p>

<h1 align="center">Cipi Server Module for WHMCS</h1>

<p align="center">
  Provision and manage <a href="https://cipi.sh">Cipi</a>-hosted apps directly from WHMCS billing.<br>
  Bridges the WHMCS provisioning lifecycle to the <a href="https://github.com/cipi-sh/api">Cipi REST API</a>.
</p>

<p align="center">
  <a href="https://github.com/cipi-sh/whmcs/blob/main/LICENSE"><img src="https://img.shields.io/github/license/cipi-sh/whmcs?style=flat-square" alt="License"></a>
  <a href="https://github.com/cipi-sh/whmcs/releases"><img src="https://img.shields.io/github/v/release/cipi-sh/whmcs?style=flat-square&label=release" alt="Release"></a>
  <img src="https://img.shields.io/badge/WHMCS-8.x-blue?style=flat-square" alt="WHMCS 8.x">
  <img src="https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.2+">
</p>

---

## Overview

This module integrates [Cipi](https://cipi.sh/docs) — a modern Laravel hosting control panel — into WHMCS as a **Server provisioning module**. It allows you to automate app creation and deletion for your hosting customers through the Cipi REST API.

### What it does

| Action | API call | Behaviour |
| --- | --- | --- |
| **Test Connection** | `GET /api/apps` | Validates token and API reachability |
| **Create Account** | `POST /api/apps` | Provisions a Cipi app (Laravel or custom); waits for async job if `202` |
| **Suspend / Unsuspend** | *none* | Returns success so WHMCS updates billing state (Cipi has no suspend endpoint) |
| **Terminate Account** | `DELETE /api/apps/{name}` | Removes the app; waits for async job if `202` |

### What it does *not* manage

Editing apps, SSL certificates, aliases, extra databases, Supervisor workers, deploy triggers, or anything beyond the create/delete lifecycle. Those remain in the Cipi dashboard, CI pipeline, or require extending this module.

---

## Requirements

- **WHMCS 8.x** (provisioning module type "Server")
- **Cipi server** with the API enabled:
  ```bash
  cipi api <domain>
  cipi api ssl
  ```
- **Sanctum Bearer token** with the required abilities:
  `apps-create` · `apps-view` · `apps-delete` · `deploy-manage` (minimum for full lifecycle)

---

## Installation

1. Copy the `modules/servers/cipi/` folder into your WHMCS root:

   ```
   your-whmcs/
   └── modules/
       └── servers/
           └── cipi/
               ├── cipi.php
               └── lib/
                   └── CipiApiClient.php
   ```

2. In **WHMCS Admin > System Settings > Servers > Add New Server**:

   | Field | Value |
   | --- | --- |
   | **Type** | Cipi (Laravel hosting) |
   | **Hostname** | API base URL, e.g. `https://api.example.com` *(no trailing slash)* |
   | **Password** | Bearer token from `cipi api token create` |
   | **Secure** | Yes *(recommended — enables TLS verification)* |

3. Create a **Hosting Product** linked to this server and configure the **Module Settings**:

   | Setting | Description | Default |
   | --- | --- | --- |
   | PHP Version | `8.2` / `8.3` / `8.4` / `8.5` | `8.5` |
   | App Type | `laravel` or `custom` | `laravel` |
   | Git Repository (SSH) | Required for Laravel; optional for custom | — |
   | Git Branch | Branch to deploy | `main` |

---

## App types

Cipi supports two creation modes. This module maps both via the **App Type** product setting (`configoption2`).

| App Type | Cipi equivalent | Stack | Git / Deploy |
| --- | --- | --- | --- |
| **laravel** *(default)* | `cipi app create` | Isolated Linux user, PHP-FPM pool, Nginx vhost, MariaDB, Supervisor workers, Deployer `releases/` + `shared/.env`, cron, deploy key | SSH repository URL **required**; branch from settings |
| **custom** | `cipi app create --custom` | `htdocs/` directory, Nginx + PHP — ideal for static sites, SPAs, WordPress, or generic PHP apps | **Optional**: leave Git Repository empty for **SFTP-only** hosting (Cipi 4.4.4+), or set a repo for Git-based deploy into `htdocs/` |

---

## Customer-facing behaviour

This module does **not** add a Client Area tab, custom buttons, or live status from Cipi. Customers see only the standard WHMCS service view:

| Visible to customer | Notes |
| --- | --- |
| Service details | Domain, status (Active / Suspended), renewal dates |
| Username | Mapped to the Cipi Linux user after sanitisation |
| Password | WHMCS-generated service password *(not the Cipi SSH password)* |
| Emails | Controlled by WHMCS email templates — Cipi credentials are **not** injected automatically |

### About credentials

When Cipi provisions an app it generates **one-time secrets** (SSH password, DB password, deploy key, webhook URL). The REST API used by this module does **not** automatically push those secrets into WHMCS.

For production use, you should either:

- **Extend this module** to call `GET /api/apps/{name}` after creation and map credentials to WHMCS custom service fields
- **Write a WHMCS hook / addon** that polls the Cipi API and updates service records
- **Deliver credentials manually** through your support workflow

> **TL;DR** — WHMCS handles billing and the service record; Cipi holds the technical secrets unless you integrate further.

---

## Module logging

All API calls made by Create and Terminate are logged via `logModuleCall()`. Suspend and Unsuspend log a note that no API call was made. Enable **Module Log** in WHMCS Admin for full visibility.

---

## Project structure

```
modules/servers/cipi/
├── cipi.php                  # WHMCS module entry point (hooks + helpers)
└── lib/
    └── CipiApiClient.php     # cURL-based HTTP client for the Cipi REST API
```

No Composer dependencies — the module is a self-contained drop-in.

---

## Support

This module is **under active development**. For bugs, questions, or custom integrations:

**Andrea Pollastri** — [web.ap.it](https://web.ap.it)

---

## License

This project is open-sourced under the [MIT License](LICENSE).
