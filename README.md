# telaris_website

Source for the Pluriverse website at <https://www.telaris.ca>. PHP 8.3 + MySQL, mirroring the stack of every Telaris instance. The federation plan v10 frames `www.telaris.ca` as the eventual home of the Pluriverse application proper; this codebase is the public-facing surface that ships first.

## Pages

Six pages × four locales = 24 URLs, served by one front controller.

| Page | EN | ES | PT | FR |
|---|---|---|---|---|
| Home | `/` | `/es/` | `/pt/` | `/fr/` |
| Documentation | `/documentation/` | `/es/documentation/` | `/pt/documentation/` | `/fr/documentation/` |
| Instances | `/instances/` | `/es/instances/` | `/pt/instances/` | `/fr/instances/` |
| Manifest | `/manifest/` | `/es/manifest/` | `/pt/manifest/` | `/fr/manifest/` |
| Privacy | `/privacy/` | `/es/privacy/` | `/pt/privacy/` | `/fr/privacy/` |
| Terms | `/terms/` | `/es/terms/` | `/pt/terms/` | `/fr/terms/` |

URL slugs stay English across all four locales; only the navbar labels and page content are localized. The language toggle in the navbar preserves the current page across locales (e.g. `/es/manifest/` ↔ `/manifest/` ↔ `/pt/manifest/` ↔ `/fr/manifest/`).

## Architecture

PHP 8.3 + MySQL, mirroring the Telaris instance pattern:

- **`config.php`** — runtime credentials, gitignored. Per-instance, never in source control.
- **`inc/db.php`** — PDO + utf8mb4 + InnoDB. Idempotent `db_ensure_*()` helpers create / reconcile the schema on first call; default project_info rows seeded by `INSERT IGNORE` so operator edits are preserved.
- **`inc/db_defaults.php`** — default chrome strings for EN/ES/PT/FR, used by the seed.
- **`inc/locale.php`** — parses `REQUEST_URI` → `(locale, page, prefix)`.
- **`inc/bootstrap.php`** — common page bootstrap (config + db + schema ensure + locale resolve + project_info load). Every page request_onces this once.
- **`inc/content.php`** — markdown renderer (league/commonmark) with the same Obsidian-callout transformer the docs PDFs use. Renders to cached HTML keyed by `(slug, locale, source_mtime)`.
- **`inc/partials/`** — head/navbar and footer.
- **`inc/pages/`** — six page handlers: `home`, `documentation`, `instances`, `manifest`, `privacy`, `terms`.
- **`index.php`** — front controller. Bootstrap → handler dispatch.

Long-form pages (Manifest, Privacy, Terms) render their prose at request time from the documentation repo at `~/apps/telaris/documentation/src/<slug>[-locale]/01-<slug>.md`. The same files build the downloadable PDFs in `docs/`. Cache invalidates automatically when the source mtime changes.

## Schema

Two tables in the `pluriverse` MySQL database:

- **`project_info`** — one row per locale; chrome strings (navbar labels, page titles, page leads, doc captions, etc.). Operators may edit rows directly; seed rows install only if absent.
- **`content_cache`** — markdown render cache, keyed by `(slug, locale, source_mtime)`.

Federation tables (twelve) are materialized as of stage 2 (2026-05-25): `instances`, `instance_status_log` + archive, `registry_admins`, `magic_link_tokens`, `sessions`, `blacklists`, `anomaly_log`, `key_events_signed`, `key_event_push_attempts`, `pluriverse_log` + archive. All idempotent via `db_ensure_*()`; see `inc/db_federation.php`.

## Federation surface

The Pluriverse coordinates a network of Telaris instances. Stage 1 + stage 2 are live; stages 3+ ship the peer-side pull and verify on instances.

API endpoints (under `/api/pluriverse/`):

| Endpoint | Auth | Purpose |
|---|---|---|
| `GET /identity` | none | Identity envelope (kind=pluriverse-coord), cross-pinned to the OpenAPI spec version. |
| `GET /openapi.json` | none | OpenAPI 3.1 spec for the surface. |
| `POST /operators/apply` | RFC 9421 sig, tag=`pluriverse-apply` | Operator join request. Signed-only (instance signs with its `pluriverse.key`); no public form. |
| `GET /operators/status` | RFC 9421 sig, tag=`pluriverse-status` | Instance asks for its own current admission_status (drives the instance-side status-sync poll). |
| `GET /peers.json` | none | Published-instance directory (peers discover each other from here). |
| `GET /blacklist.json` | none | Curated hostname/ip/domain blocklist. |
| `GET /key-events.json?since=...` | none | Pull fallback for the push-based compromise channel. |

Page routes (front controller; locale-prefixed `/es/`, `/pt/`, `/fr/` variants on the public pages):

| Path | Surface |
|---|---|
| `/operators/verify-magic-link?t=…` | Magic-link consume; branches on token `purpose` ∈ `{operator, admin}`. |
| `/dashboard` | Operator self-service: read-only own-instance view (summary, contacts, galaxies, status history) + CSRF-protected logout. Sign-in via magic-link request. |
| `/admin` | Pluriverse admin: instance list + per-row transition actions (publish, reject, blacklist, unpublish, reinstate). CSRF-protected. Admin sign-in via magic-link request, gated to `registry_admins` rows. |

Public reads (`peers.json`, `blacklist.json`, `key-events.json`) ship as plain JSON for now; JWS-signed envelopes with the Pluriverse coord key are a planned follow-up before any stage-3 peer-side verifier ships.

## Dependencies

- PHP 8.3, PHP-FPM, ext-pdo_mysql, ext-mbstring, ext-json, ext-ctype, ext-sodium (federation signing), ext-curl (apply-side HTTP), ext-apcu (rate-limit buckets).
- MySQL 8.x.
- Composer packages:
  - [`league/commonmark`](https://commonmark.thephpleague.com/) for markdown rendering of Manifest / Privacy / Terms.
  - [`zircote/swagger-php`](https://github.com/zircote/swagger-php) for the OpenAPI 3.1 surface.
  - [`phpmailer/phpmailer`](https://github.com/PHPMailer/PHPMailer) for ack + magic-link emails over Mailgun SMTP.
- `acl` (the `setfacl` binary). On Debian / Ubuntu: `sudo apt install acl`.

## Setup scripts

Two idempotent scripts at `bin/`, mirroring the Telaris instance pattern. Both support `--check` (read-only; exit 1 on any gap) and `--verbose` (success-line details).

- **`bin/setup-app.php`** — app-level bootstrap. Runs as the deploying user (not root). Verifies PHP version + extensions + composer; runs `composer install --no-dev` if `vendor/` is missing; verifies `config.php` exists; tests the DB connection; materializes the schema via `db_ensure_*`; verifies the four locale rows are seeded.

- **`bin/setup-host.php`** — host-level provisioning. Runs as root via sudo. Checks nginx + PHP-FPM are active; installs the vhost from `etc/nginx/www.telaris.ca.conf.sample` if no vhost exists at `/etc/nginx/sites-available/<host>.conf` (Certbot edits the file in place after install, so we don't drift-check); enables it via the standard `sites-enabled` symlink; sets `config.php` to `0640 root:www-data` (or `<sudo-user>:www-data`); applies filesystem ACLs on the docs source tree (parsed from `PLURIVERSE_DOCS_SRC` in `config.php`) so PHP-FPM (www-data) can read the markdown the Manifest / Privacy / Terms pages render at request time. Reloads nginx once at the end if any nginx-touching fix succeeded.

```sh
# Initial install on a fresh host:
cp config.php.sample config.php          # then fill in DB credentials + docs path
sudo php bin/setup-host.php               # install vhost, perms, ACLs, reload nginx
php bin/setup-app.php                     # composer install, materialize schema

# Run after every code pull (idempotent):
php bin/setup-app.php
sudo php bin/setup-host.php --check       # cheap drift check
```

## File layout

```
.
├── README.md
├── LICENSE                   # GNU AGPL-3.0.
├── .gitignore                # /config.php, /vendor/, /composer.lock, /docs/*.pdf
├── composer.json
├── config.php                # Per-instance; gitignored.
├── config.php.sample         # Template for fresh installs.
├── index.php                 # Front controller.
├── bin/
│   ├── setup-app.php         # Deploying-user CLI: composer + schema bootstrap.
│   └── setup-host.php        # Root CLI: nginx vhost, config.php perms, docs ACLs.
├── etc/
│   └── nginx/
│       └── www.telaris.ca.conf.sample  # Canonical vhost; installed by setup-host.
├── inc/
│   ├── bootstrap.php
│   ├── content.php
│   ├── db.php
│   ├── db_defaults.php
│   ├── locale.php
│   ├── partials/
│   │   ├── head.php          # <head> + opening <body> + navbar
│   │   └── footer.php        # footer + closing tags
│   └── pages/
│       ├── home.php
│       ├── documentation.php
│       ├── instances.php
│       ├── manifest.php      # → _content_page.php with slug=manifest
│       ├── privacy.php       # → _content_page.php with slug=privacy
│       ├── terms.php         # → _content_page.php with slug=terms
│       └── _content_page.php # Shared template for the three markdown-backed pages.
├── assets/
│   ├── styles.css            # Shared stylesheet.
│   └── bg.js                 # Home-only canvas animation.
├── docs/                     # PDF downloads. Gitignored; populated by the docs
│                             # repo's build pipeline when TELARIS_WWW_DOCS_DIR
│                             # is set to this directory.
└── vendor/                   # Composer-installed deps. Gitignored.
```

## Deployment

The two setup scripts above cover both fresh-install and re-deploy. On any subsequent code pull, `php bin/setup-app.php` is the only required step. The vhost lives at `/etc/nginx/sites-available/<host>.conf`; the document root is `/var/www/www.telaris.ca/`. The vhost routes every request through `index.php` (front controller); direct access to `config.php`, `/inc/`, `/vendor/`, and `composer.json` returns 404.

The PDFs in `docs/` are gitignored because they are generated artefacts whose source lives in [telaris-documentation](https://github.com/theagitist/telaris-documentation). They are copied here automatically whenever the docs repo's build runs with `TELARIS_WWW_DOCS_DIR=/var/www/www.telaris.ca/docs/` set in the shell rcfile.

## License

This repo is **AGPL-3.0-or-later**. Full license text in `LICENSE` (the canonical GNU text). The choice is deliberate: under GPL v3, a hostile or commercial operator could fork the Pluriverse and run a modified version as a network service without sharing changes; AGPL v3 closes that SaaS loophole and preserves the project's commitment that *any operator can fork the Pluriverse and run a parallel one if the network needs governance recovery* with full source visibility. See the federation plan v10 and the project Manifest for the political framing.

The Telaris **instance** code at <https://github.com/theagitist/telaris> is GPL v3 (different scope — operators distribute the code; the source-sharing requirement bites at distribution).
