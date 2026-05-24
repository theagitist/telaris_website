# CLAUDE.md (www.telaris.ca)

This file orients Claude Code sessions opened in `/var/www/www.telaris.ca/`. For project-wide context across the three Telaris codebases (`starmaps`, `telaris`, `www`), read `~/apps/telaris/CLAUDE.md` first.

## What this is

The Pluriverse website at <https://www.telaris.ca>. PHP 8.3 + MySQL, AGPL-3.0-or-later. Mirrors the Telaris instance stack: same `inc/db.php` + idempotent `db_ensure_*` shape, same `project_info`-row-per-locale chrome pattern. The federation plan v10 frames this surface as the Pluriverse application proper; the v1 schema (just `project_info` + `content_cache`) is designed to extend into the federation tables when stage 2 lands.

Migrated from Python-built static HTML on 2026-05-24 (5 commits on `main`, `02cc048` → `8a96a3e`). The migration record lives in `~/apps/obsidian/Academia/Projects/Telaris/Architecture/Pluriverse website PHP migration.md`.

## Layout

```
/var/www/www.telaris.ca/
├── README.md
├── .gitignore             # /config.php, /vendor/, /composer.lock, /docs/*.pdf
├── composer.json          # league/commonmark ^2.7
├── config.php             # DB creds + PLURIVERSE_DOCS_SRC. Gitignored. 0640 root:www-data.
├── index.php              # Front controller. bootstrap → handler dispatch.
├── inc/
│   ├── bootstrap.php
│   ├── content.php        # league/commonmark + Obsidian callout transformer + docs/instances lists.
│   ├── db.php             # PDO + db_ensure_project_info + db_ensure_content_cache + getters.
│   ├── db_defaults.php    # Chrome strings for EN/ES/PT/FR.
│   ├── locale.php         # pluriverse_resolve_request, pluriverse_locale_url.
│   ├── partials/
│   │   ├── head.php       # <head> + opening <body> + navbar.
│   │   └── footer.php     # footer + closing tags.
│   └── pages/
│       ├── home.php
│       ├── documentation.php
│       ├── instances.php
│       ├── manifest.php       # → _content_page.php with slug=manifest
│       ├── privacy.php        # → _content_page.php with slug=privacy
│       ├── terms.php          # → _content_page.php with slug=terms (renders docs slug "tos")
│       └── _content_page.php  # Shared template for the three markdown-backed pages.
├── assets/                # styles.css + bg.js. Shared static.
├── docs/                  # PDF downloads. Gitignored. Populated by docs-repo builds.
└── vendor/                # Composer deps. Gitignored.
```

## URLs

Six pages × four locales = 24 routes. URL slugs stay English across locales; only navbar labels and content are localized.

| Page | EN | ES | PT | FR |
|---|---|---|---|---|
| Home | `/` | `/es/` | `/pt/` | `/fr/` |
| Documentation | `/documentation/` | `/es/documentation/` | `/pt/documentation/` | `/fr/documentation/` |
| Instances | `/instances/` | `/es/instances/` | `/pt/instances/` | `/fr/instances/` |
| Manifest | `/manifest/` | `/es/manifest/` | `/pt/manifest/` | `/fr/manifest/` |
| Privacy | `/privacy/` | `/es/privacy/` | `/pt/privacy/` | `/fr/privacy/` |
| Terms | `/terms/` | `/es/terms/` | `/pt/terms/` | `/fr/terms/` |

The language toggle in the navbar preserves the current page across locales.

## Database

DO managed MySQL, `pluriverse` database, user `pluriverse`. Two tables at v1:

- **`project_info`** — locale PK; ~26 chrome columns as `TEXT` (not VARCHAR — 26 × VARCHAR(1024) at utf8mb4 exceeds MySQL's 65535-byte in-row limit); `updated_at`. Seeded via `INSERT IGNORE` so operator edits survive.
- **`content_cache`** — `(slug, locale)` PK + `source_mtime` for cache validation + `rendered_html` MEDIUMTEXT.

Schema migrations land via `db_ensure_*` helpers called lazily on first use. No SQL files, no setup wizard.

## Conventions

- **`declare(strict_types=1)`** on every PHP file.
- **All DB access through `inc/db.php`.** Never bypass it.
- **`h($s)`** is the global HTML-escape helper (defined in bootstrap.php). **`info($key)`** looks up a chrome string from the current locale's project_info row.
- **No em-dashes** in any text I produce (project rule).
- **Canadian English** for any new English text (project rule).
- **Vocabulary mapping is UI-only.** Code, DB, API keep `constellation` / `node` / `portal`; UI uses Galaxy / Wormhole / Portal (and the locale equivalents).

## nginx

Vhost at `/etc/nginx/sites-available/www.telaris.ca.conf` (not in this repo).

- `index index.php;`
- `try_files $uri /index.php?route=$uri&$args;` — no `$uri/` stage (dirs fall through to PHP).
- `location = /index.php` → PHP-FPM. `location ~ \.php$` → 404 (only the front controller executes PHP).
- `location ^~ /inc/`, `^~ /vendor/`, `= /config.php`, `= /composer.json`, `= /composer.lock` → 404.

Reload: `sudo nginx -t && sudo systemctl reload nginx`.

## PHP-FPM ACLs (one-time, host-level)

The Manifest / Privacy / Terms pages read markdown from `~/apps/telaris/documentation/src/`. PHP-FPM runs as `www-data`, which needs read access into a user-owned tree. Filesystem ACLs grant this:

```sh
sudo setfacl -m u:www-data:x /home/<user> /home/<user>/apps /home/<user>/apps/telaris /home/<user>/apps/telaris/documentation
sudo setfacl -R -m u:www-data:rX /home/<user>/apps/telaris/documentation/src
```

Already applied on this host.

## Deploy

Two idempotent scripts under `bin/`, mirroring the Telaris instance pattern:

- **`bin/setup-app.php`** (no root) — verifies PHP / extensions / composer; runs `composer install --no-dev`; tests DB connection; materializes the schema via `db_ensure_*`; verifies the four locale rows.
- **`bin/setup-host.php`** (sudo) — verifies nginx + PHP-FPM; installs the vhost from `etc/nginx/www.telaris.ca.conf.sample` on first run (then leaves it operator-owned, since Certbot edits in place); sets `config.php` to `0640 root:www-data`; applies filesystem ACLs on `PLURIVERSE_DOCS_SRC` so www-data can read the markdown sources.

Both support `--check` (read-only; exit 1 on any gap) and `--verbose`.

```sh
# Fresh install:
cp config.php.sample config.php   # fill in DB creds + PLURIVERSE_DOCS_SRC
sudo php bin/setup-host.php       # vhost, perms, ACLs, reload nginx
php bin/setup-app.php             # composer install, schema, seed

# Re-deploy after code pull:
php bin/setup-app.php
sudo php bin/setup-host.php --check
```

The PDFs in `/docs/` arrive automatically when the docs repo builds with `TELARIS_WWW_DOCS_DIR=/var/www/www.telaris.ca/docs/` set in the shell rcfile.

## Federation stage 2 — in flight

Mechanical mirrors (2a-2e) and Mailgun integration (2f-i) shipped 2026-05-24. Commits `2228152` → `027bcc9`. Provisioned on this host:

- 12 federation tables materialized: `instances`, `instance_status_log` + archive, `registry_admins`, `magic_link_tokens`, `sessions`, `blacklists`, `anomaly_log`, `key_events_signed`, `key_event_push_attempts`, `pluriverse_log` + archive.
- Four secret keys in `secrets/` (0600 www-data:www-data): `pluriverse-coord.key` (Ed25519, fingerprint `IzyKJPRmhmVxWNKQEmTY4g`), `log.key`, `pii_master.key`, `pii_lookup.key`.
- `GET /api/pluriverse/identity` live (returns `kind: "pluriverse-coord"`).
- `GET /api/pluriverse/openapi.json` live (OpenAPI 3.1; info.version cross-pinned to identity.protocol_version).
- `inc/federation/http_sig.php` (RFC 9421 sign/verify); byte-identical to instance side.
- `inc/mail.php` (PHPMailer wrapper via Mailgun SMTP). Live MAIL_* credentials in `config.php` may need a top-up — verify via `pluriverse_mail_connection_check()` before sending.

Design for the remaining sub-chunks (2f-ii through 2l) at `~/apps/obsidian/Academia/Projects/Telaris/Architecture/P2P federation/Stage 2 application surface design.md`. Active execution state at [[project-telaris-federation-stage-2-active]] in memory.

Next-up: confirm MAIL_* in config.php → smoke email to `aemjcr@gmail.com` (Adri's main address per [[user-primary-email]]) → 2f-ii (`bin/init-admin`).

## License

LICENSE: AGPL-3.0-or-later text in `LICENSE` at the repo root (added 2026-05-24, commit `042ba07`). composer.json declares the same license. Both layers in place.
