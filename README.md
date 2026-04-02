# WHMCS · Cipi server module

WHMCS provisioning module that talks to the [Cipi](https://cipi.sh/docs) control plane REST API via [`cipi-sh/api`](https://github.com/cipi-sh/api).

## Requirements

- WHMCS 8.x (provisioning module type “Server”)
- Cipi server with API enabled: `cipi api <domain>` and `cipi api ssl` on the VPS
- Sanctum token with the required abilities (e.g. `apps-create`, `apps-view`, `apps-delete`, `deploy-manage`, `ssl-manage` depending on what you expose to billing)

## Installation

1. Copy `modules/servers/cipi/` into your WHMCS root (`modules/servers/cipi/`).
2. In **WHMCS Admin → System Settings → Servers → Add New Server**:
   - **Type**: Cipi (Laravel hosting)
   - **Hostname**: API base URL, e.g. `https://api.example.com` (no trailing slash)
   - **Password**: paste the Bearer token (`cipi api token create` on the server, with required abilities)
   - **Secure**: yes (TLS)
3. Create a **hosting product** linked to this server and set **Module Settings** (PHP, app type, Git SSH URL, branch).

## App types this module can provision

Cipi supports two creation modes; this module maps both via the product **Module Setting “App Type”** (`configoption2`).

| App type in WHMCS | Cipi CLI equivalent | What gets created | Git / deploy |
| --- | --- | --- | --- |
| **laravel** (default) | `cipi app create` | Full Laravel stack: isolated Linux user, PHP-FPM pool, Nginx vhost, MariaDB, Supervisor workers, Deployer `releases/` + `shared/.env`, cron, deploy key | **SSH repository URL required**; branch from settings |
| **custom** | `cipi app create --custom` | Custom site: `htdocs`, Nginx + PHP for static/SPA/WordPress/other PHP; **no** Laravel Deployer layout, **no** DB/workers/cron unless you add them separately | Optional: leave **Git Repository** empty for **SFTP-only** (Cipi 4.4.4+), or set repo for Git deploy into `htdocs` |

**Not managed by this module:** editing apps (`cipi app edit`), SSL (`cipi ssl install`), aliases, databases beyond the Laravel default, workers, or deploy triggers — those stay in Cipi/CI or require extending the module or using the Cipi API elsewhere.

## Customer-facing output (what the end user sees)

This module does **not** add a **Client Area** tab, custom buttons, or live status from Cipi. Customers only get what **WHMCS** shows by default for a hosting/service product:

| What the customer sees | Notes |
| --- | --- |
| **Service details** | Domain, service status (Active / Suspended), renewal dates — normal WHMCS client area |
| **Username** | The WHMCS **service username** (mapped to the Cipi Linux user name after sanitization) |
| **Password field** | WHMCS may show the auto-generated **service password** from provisioning — this is **not** automatically replaced with Cipi’s real SSH password |
| **Welcome / product emails** | Whatever you configure in WHMCS email templates — they will **not** include Cipi-only data (DB password, deploy key, webhook URL) unless you add **hooks** or custom automation |

**Important:** On the server, `cipi app create` prints **one-time** credentials (SSH password for the app user, DB password, deploy key, webhook, etc.). The REST call used here does not, by itself, push those secrets into WHMCS or customer emails. For production use you should either:

- Poll the Cipi job/API for details and update the service via a **WHMCS hook** / **Provisioning Module Addon**, or  
- Deliver credentials through your **support** / **manual** process, or  
- Extend this module to call `GET /api/apps/{name}` after create and map fields into **custom service fields** (future enhancement).

So: **billing and service record = WHMCS; technical secrets = Cipi unless you integrate further.**

## What the module does

- **Test Connection**: `GET /api/apps` (validates token and reachability).
- **Create**: `POST /api/apps` — creates a Cipi app aligned with the WHMCS service (service username → Linux app username, sanitized).
- **Terminate**: `DELETE /api/apps/{name}` — removes the app (async: waits for job when the API returns `202`).

The module does **not** stop the site on Cipi: there is nothing to call. Instead, `Suspend` / `Unsuspend` return **success** so WHMCS updates client and portal state correctly (otherwise an error string would block suspend in admin). With **Module Log** enabled you get a note that no API call was made.

## License

MIT
