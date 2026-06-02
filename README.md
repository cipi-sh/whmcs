<h1 align="center">Cipi Server Module for WHMCS</h1>

<p align="center">
  Provision and manage <a href="https://cipi.sh">Cipi</a>-hosted apps directly from WHMCS billing.<br>
  Bridges the WHMCS provisioning lifecycle to the <a href="https://github.com/cipi-sh/api">Cipi REST API</a>.
</p>

---

## Overview

This module integrates [Cipi](https://cipi.sh/docs) — a modern Laravel hosting control panel — into WHMCS as a **Server provisioning module**. It automates app creation, deletion, SSL certificates, deployments, and configuration changes for your hosting customers through the Cipi REST API.

---

## Features

### Provisioning lifecycle

| WHMCS action            | API call                  | Behaviour                                                                                |
| ----------------------- | ------------------------- | ---------------------------------------------------------------------------------------- |
| **Test Connection**     | `GET /api/apps`           | Validates token and API reachability                                                     |
| **Create Account**      | `POST /api/apps`          | Provisions a Cipi app (Laravel or custom); waits for async jobs; optionally installs SSL |
| **Suspend**             | `POST /api/apps/{name}/suspend`   | Takes the app offline (HTTP 503 maintenance page) without deleting it; waits for async jobs |
| **Unsuspend**           | `POST /api/apps/{name}/unsuspend` | Restores the app's normal Nginx vhost; waits for async jobs                       |
| **Terminate Account**   | `DELETE /api/apps/{name}` | Removes the app; waits for async jobs                                                    |
| **Change Package**      | `PUT /api/apps/{name}`    | Updates PHP version, Git repository, or branch                                           |

> **Suspend / Unsuspend** require **Cipi 4.5.8+** (suspend/unsuspend endpoints) and a token with the `apps-suspend` ability. Suspending swaps the app's vhost for a generic HTTP 503 maintenance page; unsuspending restores it.

### Admin buttons

From the WHMCS admin service view, operators can trigger one-click actions:

| Button              | API call                                | Description                                   |
| ------------------- | --------------------------------------- | --------------------------------------------- |
| **Install SSL**     | `POST /api/apps/{name}/ssl`             | Install a Let's Encrypt certificate           |
| **Deploy**          | `POST /api/apps/{name}/deploy`          | Trigger a zero-downtime deployment            |
| **Rollback Deploy** | `POST /api/apps/{name}/deploy/rollback` | Revert to the previous release                |
| **Unlock Deploy**   | `POST /api/apps/{name}/deploy/unlock`   | Unlock a stuck deployment                     |
| **List Aliases**    | `GET /api/apps/{name}/aliases`          | Lists aliases; output in Module Log             |
| **Add Alias**       | `POST /api/apps/{name}/aliases/{alias}` | Uses **Alias domain (admin)**; async job        |
| **Remove Alias**    | `DELETE …/aliases/{alias}`              | Uses **Alias domain (admin)**; async job        |
| **App Info**        | `GET /api/apps/{name}`                  | Fetch current app details into the Module Log |

For **Add Alias** and **Remove Alias**, set the product field **Alias domain (admin)** to the full hostname, save the service, then run the button.

### Auto-SSL on creation

Enable **Auto SSL** in the product module settings to automatically install a Let's Encrypt certificate right after provisioning. If SSL installation fails, the app is still created successfully and a warning is logged.

### Full API client

The bundled `CipiApiClient` covers the entire Cipi REST API surface. Even if a feature is not wired to a WHMCS hook, you can use the client in custom hooks or addons:

| Area          | Methods                                                                                                           |
| ------------- | ----------------------------------------------------------------------------------------------------------------- |
| **Apps**      | `listApps`, `getApp`, `createApp`, `editApp`, `deleteApp`, `suspendApp`, `unsuspendApp`                          |
| **Deploy**    | `deployApp`, `rollbackDeploy`, `unlockDeploy`                                                                     |
| **SSL**       | `installSsl`                                                                                                      |
| **Aliases**   | `listAliases`, `addAlias`, `removeAlias`                                                                          |
| **Databases** | `listDatabases`, `createDatabase`, … — HTTP paths under `/api/dbs` (aligned with Cipi API 1.6+) |
| **Jobs**      | `getJob`, `waitForJob`                                                                                            |

---

## Requirements

- **WHMCS 8.x** (provisioning module type "Server")
- **Cipi server** with the API enabled:
  ```bash
  cipi api <domain>
  cipi api ssl
  ```
- **Sanctum Bearer token** with the abilities you need:

  | Ability              | Required for                                      |
  | -------------------- | ------------------------------------------------- |
  | `apps-view`          | Test Connection, App Info                         |
  | `apps-create`        | Create Account                                  |
  | `apps-edit`          | Change Package                                  |
  | `apps-suspend`       | Suspend / Unsuspend                             |
  | `apps-delete`        | Terminate Account                               |
  | `deploy-manage`      | Deploy, Rollback, Unlock                        |
  | `ssl-manage`         | Install SSL, Auto-SSL                           |
  | `aliases-view`       | List Aliases, `listAliases` in code               |
  | `aliases-create`     | Add Alias                                         |
  | `aliases-delete`     | Remove Alias                                      |
  | `dbs-view` / `dbs-*` | Database list and DB jobs (`GET /api/dbs`, …)   |

  On the Cipi host, **`GET /api/dbs`** runs `sudo cipi db list`. Ensure Cipi is **4.4.17+** (or a matching `cipi-api` sudoers entry) so database commands do not fail with `sudo: a terminal is required`. See the [Cipi API README](https://github.com/cipi-sh/api/blob/main/README.md).

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

   | Field        | Value                                                              |
   | ------------ | ------------------------------------------------------------------ |
   | **Type**     | Cipi (Laravel hosting)                                             |
   | **Hostname** | API base URL, e.g. `https://api.example.com` _(no trailing slash)_ |
   | **Password** | Bearer token from `cipi api token create`                          |
   | **Secure**   | Yes _(recommended — enables TLS verification)_                     |

3. Create a **Hosting Product** linked to this server and configure the **Module Settings**:

   | Setting              | Description                               | Default   |
   | -------------------- | ----------------------------------------- | --------- |
   | PHP Version          | `8.2` / `8.3` / `8.4` / `8.5`             | `8.5`     |
   | App Type             | `laravel` or `custom`                     | `laravel` |
   | Git Repository (SSH) | Required for Laravel; optional for custom | —         |
   | Git Branch           | Branch to deploy                          | `main`    |
   | Auto SSL             | Install Let's Encrypt after creation      | No        |
   | Alias domain (admin) | Hostname for Add/Remove Alias buttons     | —         |

---

## App types

Cipi supports two creation modes. This module maps both via the **App Type** product setting.

| App Type                | Cipi equivalent            | Stack                                                                                                                               | Git / Deploy                                                                                                                        |
| ----------------------- | -------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| **laravel** _(default)_ | `cipi app create`          | Isolated Linux user, PHP-FPM pool, Nginx vhost, MariaDB, Supervisor workers, Deployer `releases/` + `shared/.env`, cron, deploy key | SSH repository URL **required**; branch from settings                                                                               |
| **custom**              | `cipi app create --custom` | `htdocs/` directory, Nginx + PHP — ideal for static sites, SPAs, WordPress, or generic PHP apps                                     | **Optional**: leave Git Repository empty for **SFTP-only** hosting (Cipi 4.4.4+), or set a repo for Git-based deploy into `htdocs/` |

---

## Customer-facing behaviour

This module does **not** add a Client Area tab, custom buttons, or live status from Cipi. Customers see only the standard WHMCS service view:

| Visible to customer | Notes                                                                                     |
| ------------------- | ----------------------------------------------------------------------------------------- |
| Service details     | Domain, status (Active / Suspended), renewal dates                                        |
| Username            | Mapped to the Cipi Linux user after sanitisation                                          |
| Password            | WHMCS-generated service password _(not the Cipi SSH password)_                            |
| Emails              | Controlled by WHMCS email templates — Cipi credentials are **not** injected automatically |

### About credentials

When Cipi provisions an app it generates **one-time secrets** (SSH password, DB password, deploy key, webhook URL). The REST API used by this module does **not** automatically push those secrets into WHMCS.

For production use, you should either:

- **Extend this module** to call `GET /api/apps/{name}` after creation and map credentials to WHMCS custom service fields
- **Write a WHMCS hook / addon** that polls the Cipi API and updates service records
- **Deliver credentials manually** through your support workflow

> **TL;DR** — WHMCS handles billing and the service record; Cipi holds the technical secrets unless you integrate further.

---

## Module logging

All API calls are logged via `logModuleCall()` — Create, Suspend, Unsuspend, Terminate, Change Package, SSL, Deploy, Rollback, Unlock, List/Add/Remove Alias, and App Info. Enable **Utilities > Logs > Module Log** in WHMCS Admin for full visibility.

---

## Extending the module

The `CipiApiClient` class covers endpoints that are not yet wired to WHMCS hooks. You can use them in your own **hooks**, **addons**, or **custom module functions**:

```php
// Example: add an alias from a WHMCS hook
require_once ROOTDIR . '/modules/servers/cipi/lib/CipiApiClient.php';

$client = new CipiApiClient('https://api.example.com', $token);
$client->addAlias('myapp', 'alias.example.com');

// Example: create an extra database
$client->createDatabase('myapp_extra');

// Example: backup a database
$client->backupDatabase('myapp');
```

---

## Project structure

```
modules/servers/cipi/
├── cipi.php                  # WHMCS module entry point (hooks, buttons, helpers)
└── lib/
    └── CipiApiClient.php     # cURL-based HTTP client for the full Cipi REST API
```

No Composer dependencies — the module is a self-contained drop-in.

---

## Support

This module is **under active development**. For bugs, questions, or custom integrations:

**Andrea Pollastri** — [web.ap.it](https://web.ap.it)

---

## License

This project is open-sourced under the [MIT License](LICENSE).
