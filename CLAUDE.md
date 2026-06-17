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
│       ├── changelog.php      # Curated highlights from pluriverse_changelog().
│       ├── manifest.php       # → _content_page.php with slug=manifest
│       ├── privacy.php        # → _content_page.php with slug=privacy
│       ├── terms.php          # → _content_page.php with slug=terms (renders docs slug "tos")
│       └── _content_page.php  # Shared template for the three markdown-backed pages.
├── assets/                # styles.css + bg.js. Shared static.
├── docs/                  # PDF downloads. Gitignored. Populated by docs-repo builds.
└── vendor/                # Composer deps. Gitignored.
```

## URLs

Nine pages × four locales = 36 routes. URL slugs stay English across locales; only navbar labels and content are localized.

| Page | EN | ES | PT | FR |
|---|---|---|---|---|
| Home | `/` | `/es/` | `/pt/` | `/fr/` |
| Documentation | `/documentation/` | `/es/documentation/` | `/pt/documentation/` | `/fr/documentation/` |
| Instances | `/instances/` | `/es/instances/` | `/pt/instances/` | `/fr/instances/` |
| Changelog | `/changelog/` | `/es/changelog/` | `/pt/changelog/` | `/fr/changelog/` |
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

## Changelog page (`/changelog`)

Curated, visitor-facing highlights, newest first: not the full engineering CHANGELOG (that lives in the Academia vault under `Projects/Telaris/Documentation/CHANGELOG.md`). It surfaces only changes that alter what visitors, editors, or operators can do, in plain language, fully localized EN/ES/PT/FR with no English fallback.

- **Chrome** (`nav_changelog`, `changelog_title`, `changelog_lead`): `project_info` columns in `PROJECT_INFO_COLUMNS` (`inc/db.php`) + a row in every locale block of `db_defaults.php`. Auto-migrated by `db_ensure_project_info()` on next page load.
- **Entries**: `pluriverse_changelog($locale)` in `inc/content.php`, same shape as `pluriverse_docs()`. A shared `$order` list of entry keys (newest first) plus a per-locale `date` / `title` / `body` for each.
- **Renderer**: `inc/pages/changelog.php`; routed in `index.php` (`'changelog' => 'changelog'`); navbar item in `inc/partials/head.php` (`$navItems`, placed before the external Source code link); `.changelog-list` styles in `assets/styles.css` (a vertical timeline reusing the site palette, no PDF).

**To add an entry**: put a new key at the top of `$order`, then add a matching `'key' => ['date', 'title', 'body']` block in all four locale arrays. Use coarse month-year date labels anchored to real version-tag dates (`git for-each-ref --sort=creatordate refs/tags` in the instance repo at `/var/www/starmaps.polivoxia.ca`). No version numbers in the copy; use the project vocabulary (Galaxy / Wormhole and locale equivalents); no em-dashes.

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

**2n shipped 2026-05-25** (`2dc95bf`): HEAD method support on the five unauthenticated GET endpoints (identity, openapi.json, peers.json, blacklist.json, key-events.json). Body captured into an output buffer and discarded; Content-Length set from the buffered byte count. Signed GET endpoints (operators/status) stay GET-only because RFC 9421 covers @method in the signature base. Conditional HEAD with matching If-None-Match returns 304 as expected.

**JWS-signing follow-up DROPPED 2026-05-25**: the v10 plan doesn't actually require coord-signed wrappers for peers.json / blacklist.json, and key-events.json already carries inner JWS-signed payloads via `signed_payload`. Reconsider only if a peer-to-peer cache-forwarding scenario surfaces.

**2o-i shipped 2026-05-25** (`f774ab9`): operator edit for `other_contacts` (the encrypted contact-handles array, up to 8 `{service, user_id}` entries). Inline JS for add/remove rows; server-side validation mirrors apply_handler; re-encrypt with the same per-row HKDF-derived key; UPDATE + status_log INSERT in one transaction. 14 new chrome keys × 4 locales. The stale `dashboard_label_other_contacts` chrome key was dropped (its display site is replaced by `dashboard_other_contacts_heading` + `dashboard_other_contacts_help`).

**2o-ii shipped 2026-05-25** (`76ce5e3`): operator email-change flow. Three new `pending_email_*` columns on `instances` (one of them `UNIQUE` on the new lookup hash) hold the proposed new address through the magic-link round-trip. `magic_link_tokens.purpose` ENUM grows `email-change`. POST `action=request_email_change` validates the new address, encrypts it under the OLD row context with column-info `pending_email`, stores the pending row, mints a `purpose='email-change'` token tied to the NEW lookup hash, and emails the link to the NEW mailbox. The verify endpoint, on `purpose='email-change'`, calls a new `db_promote_email_change` helper that decrypts the pending email under the OLD context, re-encrypts it (and the existing `other_contacts`) under the NEW context (row context tied to `operator_email_lookup_hash`), swaps the canonical columns atomically, and clears pending. POST `action=cancel_email_change` clears pending columns. 16 new chrome keys × 4 locales.

**The operator dashboard is now functionally complete**: an operator can edit everything they submitted at apply time (label, framing, locale, other_contacts, email) and withdraw / re-apply.

**What's still ahead**: Stages 3+ (instance pulls peers.json, verifies, mirrors published galaxies, three-round handshake, content-addressable media, JWS publish events, key-events push channel) are the big remaining federation arc.

## License

LICENSE: AGPL-3.0-or-later text in `LICENSE` at the repo root (added 2026-05-24, commit `042ba07`). composer.json declares the same license. Both layers in place.

## Presentation deck (`presentation.html`)

A standalone conference deck for Telaris, modelled on the Journeyways deck at `www.journeyways.ca/presentation.html` (same controller and affordances, re-skinned into the Telaris brand). Created 2026-06-07.

- **Where it lives.** `/var/www/www.telaris.ca/presentation.html`, a plain static file served directly by nginx (the `location /` `try_files $uri ...` stage hits the file before the PHP front controller, so it is NOT routed through `index.php` and does not get the locale/navbar chrome). `noindex, nofollow`; linkable by direct URL but not in the nav or sitemap. Not localized (English only).
- **Self-contained.** One file: inline `<style>` and inline `<script>`, no framework, no build step, no external fonts (system monospace only, per the brand Type rule). External references are all same-origin static assets: the three `/assets/presentation/*.jpg` screenshots, `/assets/presentation/qr.svg` (the black-on-white closing QR), the favicons, plus in-site links (`/`, `/manifest`, the GitHub source repo, UBC GRSJ).
- **Brand fidelity.** Tokens copied from `assets/styles.css` (`--accent #00ffcc` Wormhole mint, `--bg #000` Void, `--fg #e8eef0` Aurora white, `--dim`, `--dimmer`, `--ghost`). The cover wordmark reuses the landing treatment (uppercase, `0.18em` tracking, CRT-scanline `background-image`, mint `text-shadow`). The ambient background is an adapted copy of `assets/bg.js` (the pastel node-network over a starfield) drawn onto a fixed `#bg` canvas, with a `.bg-scrim` radial vignette over it so slide type stays readable; both are hidden under `prefers-reduced-motion` and in print. Chapter spine markers are small inline-SVG constellation glyphs (two pastel nodes + a mint link line), tinted per chapter from the 17-colour pastel array. Mint is signal-only (accent, rules, chip borders, pull-quote bar), never body text; pull quotes are mint and non-italic (mono italic is out of brand). Public copy avoids em-dashes.
- **Content.** 15 slides, grounded in the real project, **why-led** (the political and architectural difference comes first, then the mechanics, an explicit operator choice). Order (operator-iterated 2026-06-07): cover; the problem (tree vs cosmos, floated tree/graph SVG diagrams with text wrapping); the proposition (decolonial thought / relational philosophy / fractal design, authors kept to speaker notes); how it's built (the decolonial commitments; the "monospace" and "no surveillance of meaning" items were removed, the latter to leave room for future local fractal analytics); sovereignty in the build (consent withdrawal); inside the cosmos (one slide, three real grsj306 screenshots in a row: galaxy + hub + cluster); galaxies; the instrument (three components: wormholes, portals, keywords); wormholes; moving between worlds (portals / clusters / multigalaxy); the graph of meaning (the rhizome mechanic); the Pluriverse (single folded slide: architecture + what it is / why / what it is not, "a meeting point, not a master", federated by consent); beyond v1 (self-hosting / requestable instances / open weave; placed near the end so the roadmap does not interrupt the mechanics); in short (synthesis recap, "the structure is the argument"); closing (QR + links). Cut along the way: "where it comes from / a method with a lineage", "what's running / live instances", and a second Pluriverse slide (folded into one); the two image slides ("inside the cosmos" + "the weave") were folded into one. The cover carries no author name (operator request; authorship stays in metadata + JSON-LD). Each slide carries speaker notes, and the note cross-references avoid hard slide numbers so they survive reordering.
- **Image assets.** "Inside the cosmos" uses three real screenshots of the grsj306.telaris.ca demo instance (the 3D WebGL visitor view) in one row, at `assets/presentation/{galaxy,space,cluster}.jpg`. Captured with Python Playwright + headless Chromium forcing software WebGL (`--use-gl=angle --use-angle=swiftshader --enable-unsafe-swiftshader`), since the scene is Three.js; device_scale_factor 2, then `convert`-resized to JPG. Swiftshader makes large screenshots slow (raise the Playwright `screenshot` timeout; full-page deck captures of the animated canvas can time out at 60s). To refresh, re-shoot the demo galaxy/cluster/hub URLs and re-convert.
- **Closing QR.** The site asset `assets/qr-telaris.svg` is white-on-dark (`#e8eef0` modules) and is invisible on the deck's white QR card, so the closing slide uses a black-on-white copy at `assets/presentation/qr.svg` (same data, `#e8eef0` recoloured to `#000000`). Keep it black-on-white; the white card plus `0.5rem` padding supplies the quiet zone.
- **Favicon (site-wide).** Added 2026-06-07. `favicon.svg` is the primary (a mint node-cluster on Void, mirroring the landing's `bg.js`: a haloed mint centre node, four pastel satellites on mint links). Raster fallbacks `favicon-16.png`, `favicon-32.png`, `favicon.ico` (16/32/48), and `apple-touch-icon.png` (180, flattened on black) all at web root, generated from the SVG with `convert -density 600`. Wired into `inc/partials/head.php` (covers every PHP page, replacing the old `data:,` icon) and into the standalone `presentation.html`.
- **Controls (identical to the Journeyways deck).** Arrows / space / PageUp / PageDown / Home / End navigate; `1`-`9` digit-jump (multi-digit buffered ~700ms); `O` overview grid; `S` toggles inline speaker notes (`?notes` opens with them visible); `F` fullscreen; `P` print-to-PDF (`@page { size: 16in 9in }`, one slide per page, canvas hidden); `W` opens a presenter window (`?presenter=1`: chapter + h2 + notes + up-next + session timer, `R` resets); both windows sync over `BroadcastChannel('telaris-deck')`. Touch swipe on mobile. Hash deep-links (`#7`).
- **To edit.** Pure HTML/CSS/JS in the one file. No build, no cache-bust query, independent of the PHP app and the VERSION scheme.
