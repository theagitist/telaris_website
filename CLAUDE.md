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

Eight pages × four locales = 32 routes. URL slugs stay English across locales; only navbar labels and content are localized.

| Page | EN | ES | PT | FR |
|---|---|---|---|---|
| Home | `/` | `/es/` | `/pt/` | `/fr/` |
| Documentation | `/documentation/` | `/es/documentation/` | `/pt/documentation/` | `/fr/documentation/` |
| Instances | `/instances/` | `/es/instances/` | `/pt/instances/` | `/fr/instances/` |
| Manifest | `/manifest/` | `/es/manifest/` | `/pt/manifest/` | `/fr/manifest/` |
| Contact | `/contact/` | `/es/contact/` | `/pt/contact/` | `/fr/contact/` |
| Governance | `/governance/` | `/es/governance/` | `/pt/governance/` | `/fr/governance/` |
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

## Federation stage 2, closed (2026-05-25)

Stage 1 (2026-05-24) shipped the helper-only surface; stage 2 closed 2026-05-25 with the full application surface live on this codebase.

Provisioned on this host:

- 12 federation tables: `instances`, `instance_status_log` + archive, `registry_admins`, `magic_link_tokens`, `sessions`, `blacklists`, `anomaly_log`, `key_events_signed`, `key_event_push_attempts`, `pluriverse_log` + archive.
- Six secret keys in `secrets/` (0600 www-data:www-data): `pluriverse-coord.key` (Ed25519, fingerprint `IzyKJPRmhmVxWNKQEmTY4g`), `log.key`, `pii_master.key`, `pii_lookup.key` on this side; `pluriverse.key` + `log.key` on each instance.
- One row in `registry_admins`: `aemjcr@gmail.com` / "Adri M.", seeded via CLI.
- One row in `instances`: Starmaps (`starmaps.polivoxia.ca`), `admission_status='published'`, fingerprint `MtwnZ422XdQYkpT5KQp2sg`. First live federation member.

API endpoints (all under `/api/pluriverse/`):

| Method · Path | Auth | Purpose |
|---|---|---|
| GET `/identity` | none | `kind: pluriverse-coord` identity envelope |
| GET `/openapi.json` | none | OpenAPI 3.1 spec, info.version cross-pinned to identity.protocol_version |
| POST `/operators/apply` | RFC 9421 sig, tag=`pluriverse-apply` | Operator join request (signed-only; no public form) |
| GET `/operators/status` | RFC 9421 sig, tag=`pluriverse-status` | Instance asks for its own current admission_status |
| GET `/peers.json` | none (data-hash ETag + 304) | Published-instance directory |
| GET `/blacklist.json` | none | Curated hostname/ip/domain blocklist |
| GET `/key-events.json?since=…` | none | Pull fallback for the push-based compromise channel |

Page routes (front controller; locale-prefixed `/es/`, `/pt/`, `/fr/` variants exist for the public pages):

| Path | Surface |
|---|---|
| `/operators/verify-magic-link?t=…` | Magic-link consume; branches on token `purpose` ∈ `{operator, admin}` |
| `/dashboard` | Operator self-service: read-only own-instance view + CSRF-protected logout. Sign-in via magic-link request from a non-authenticated GET. |
| `/admin` | Pluriverse admin: read-only instance list + per-row transition actions (publish, reject, blacklist, unpublish, reinstate). CSRF-protected. Admin sign-in via magic-link request. |
| `/`, `/documentation/`, `/instances/`, `/manifest/`, `/privacy/`, `/terms/`, `/contact/`, `/governance/` | Public site pages (locale-prefixed `/es/`, `/pt/`, `/fr/` variants for each) |

Composer runtime deps (all installed): `league/commonmark ^2.7`, `zircote/swagger-php ^6.1`, `phpmailer/phpmailer ^7.1`.

Design at `~/apps/obsidian/Academia/Projects/Telaris/Architecture/P2P federation/Stage 2 application surface design.md`. Cycle record at [[project-telaris-federation-stage-2-active]] in memory.

**2k shipped 2026-05-25** (`0059fa2`): the apply-ack email and the admin sign-in email moved from EN-only to inline 4-locale dicts (operator locale comes from the application payload; admin locale from the page locale, since registry_admins has no locale field yet). Adversarial second-pass scan confirmed no further user-facing EN-only chrome on the Pluriverse pages or partials. RFC 9457 problem-detail strings on the API surface stay EN as technical prose (code is locale-invariant; instance-side admin UI translates its own surface).

**2l shipped 2026-05-25** (`534f9a7`): `/contact` (source code, admin email, security disclosures) and `/governance` (admission, removal/blacklist/appeals, forking the Pluriverse) as static front-controller pages, 4 locales each. 16 new chrome keys × 4 locales = 64 strings. Three sections per page; section bodies stored as markdown in db_defaults and rendered through league/commonmark at request time. Footer grows two links; navbar unchanged.

**2m shipped 2026-05-25** (`8641f0a` + `8c51126`): operator-side mutations on `/dashboard` (the deferred half of 2h). 2m-i adds a CSRF-protected withdraw flow (transitions {pending, verified, published, outdated} → withdrawn, destroys the session, redirects with a localized confirmation banner; the apply re-apply logic now also drops 'withdrawn' rows for same-operator semantics, mirroring 'expired'). 2m-ii adds edit for the three non-PII fields (label, editorial_framing, locale) with full validation (uniqueness, length caps, locale enum); atomic UPDATE + status_log INSERT in one transaction; redirect target carries the new locale prefix so the URL matches the saved language. 22 new chrome keys × 4 locales = 88 strings.

**What's still ahead**: JWS-signed envelopes for the three public reads (peers.json / blacklist.json / key-events.json, gated on stages 3+ peer verifier), HEAD method on the API router, PII edit on the dashboard (operator_email + other_contacts, gated on a re-verify flow). Stages 3+ (instance pulls peers.json, verifies, mirrors published galaxies, three-round handshake, content-addressable media, JWS publish events, key-events push channel) are the big remaining federation arc.

## License

LICENSE: AGPL-3.0-or-later text in `LICENSE` at the repo root (added 2026-05-24, commit `042ba07`). composer.json declares the same license. Both layers in place.
