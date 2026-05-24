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

Federation tables (`peers`, `key_events`, `registry_admins`) are deferred — they land when federation implementation extends the Pluriverse beyond a marketing surface.

## Dependencies

- PHP 8.3, PHP-FPM, ext-pdo_mysql, ext-mbstring.
- MySQL 8.x.
- One Composer package: [`league/commonmark`](https://commonmark.thephpleague.com/) for markdown rendering.

```sh
composer install --no-dev
```

## File layout

```
.
├── README.md
├── .gitignore                # /config.php, /vendor/, /composer.lock, /docs/*.pdf
├── composer.json
├── config.php                # Per-instance; gitignored.
├── index.php                 # Front controller.
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

Pull from git, run `composer install --no-dev`, and nginx serves the result. The vhost lives at `/etc/nginx/sites-available/www.telaris.ca.conf`; the document root is `/var/www/www.telaris.ca/`. The vhost routes every request through `index.php` (front controller); direct access to `config.php`, `/inc/`, `/vendor/`, and `composer.json` returns 404.

The PHP-FPM worker user (`www-data` on this host) needs read access to the docs source tree. On this host that is granted via filesystem ACLs:

```sh
sudo setfacl -m u:www-data:x /home/<user> /home/<user>/apps /home/<user>/apps/telaris /home/<user>/apps/telaris/documentation
sudo setfacl -R -m u:www-data:rX /home/<user>/apps/telaris/documentation/src
```

The PDFs in `docs/` are gitignored because they are generated artefacts whose source lives in [telaris-documentation](https://github.com/theagitist/telaris-documentation). They are copied here automatically whenever the docs repo's build runs with `TELARIS_WWW_DOCS_DIR=/var/www/www.telaris.ca/docs/` set in the shell rcfile.

## License

This repo is **AGPL-3.0-or-later**. Full license text in `LICENSE` (the canonical GNU text). The choice is deliberate: under GPL v3, a hostile or commercial operator could fork the Pluriverse and run a modified version as a network service without sharing changes; AGPL v3 closes that SaaS loophole and preserves the project's commitment that *any operator can fork the Pluriverse and run a parallel one if the network needs governance recovery* with full source visibility. See the federation plan v10 and the project Manifest for the political framing.

The Telaris **instance** code at <https://github.com/theagitist/telaris> is GPL v3 (different scope — operators distribute the code; the source-sharing requirement bites at distribution).
