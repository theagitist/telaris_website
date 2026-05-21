# telaris_website

Source for the Pluriverse website at <https://www.telaris.ca>. Static multi-page site, served by nginx, no application code. The federation plan (v10) frames `www.telaris.ca` as the eventual home of the Pluriverse application proper; the current implementation is the public-facing surface that lands when federation ships.

## Pages

Six pages × three locales = 18 HTML files.

| Page | EN | ES | PT |
|---|---|---|---|
| Home | `/` | `/es/` | `/pt/` |
| Documentation | `/documentation/` | `/es/documentation/` | `/pt/documentation/` |
| Instances | `/instances/` | `/es/instances/` | `/pt/instances/` |
| Manifest | `/manifest/` | `/es/manifest/` | `/pt/manifest/` |
| Privacy | `/privacy/` | `/es/privacy/` | `/pt/privacy/` |
| Terms | `/terms/` | `/es/terms/` | `/pt/terms/` |

URL slugs stay English across all three locales; only the navbar labels and page content are localized. Each page has a language toggle in the navbar that preserves the page across locales (e.g. `/es/manifest/` ↔ `/manifest/` ↔ `/pt/manifest/`).

## Build

```sh
python3 build.py
```

One script (`build.py`) generates all 18 HTML files. Edits go through the script:

* **Small content change** (typo, doc caption tweak, new instance row): edit the `I18N` dict in `build.py`, re-run. Do not edit the generated HTML files directly, or your edits get blown away the next time the script runs.
* **Chrome / structure change** (new page, new locale, navbar tweak): edit the page renderers or chrome helpers in `build.py`, re-run.

Long-form pages (Manifest, Privacy, Terms) read their prose directly from the documentation repo at `~/apps/telaris/documentation/src/<slug>{,-es,-pt}/`; the website does not duplicate that content. The PDFs that are also offered for download are produced by the docs repo's build pipeline.

Dependencies: `markdown-it-py` (the docs repo already installs it; the build script reuses the same renderer to keep callouts and prose consistent with the PDFs).

## File layout

```
.
├── build.py                # Generator for all 18 HTML files.
├── README.md
├── .gitignore
├── index.html              # Generated (EN home).
├── documentation/
│   └── index.html          # Generated.
├── instances/index.html    # Generated.
├── manifest/index.html     # Generated.
├── privacy/index.html      # Generated.
├── terms/index.html        # Generated.
├── es/                     # Spanish locale, same six pages.
├── pt/                     # Portuguese locale, same six pages.
├── assets/
│   ├── styles.css          # Shared stylesheet.
│   └── bg.js               # Home-only canvas animation.
└── docs/                   # PDF downloads, gitignored. Populated by the
                            # docs repo's build pipeline with
                            # TELARIS_WWW_DOCS_DIR set to this directory.
```

## Deployment

Pull from git, run `python3 build.py`, and nginx serves the result. The vhost lives at `/etc/nginx/sites-available/www.telaris.ca.conf`; the document root is `/var/www/www.telaris.ca/`.

The PDFs in `docs/` are gitignored because they are generated artefacts whose source lives in [telaris-documentation](https://github.com/theagitist/telaris-documentation). They are copied here automatically whenever the docs repo's build runs with `TELARIS_WWW_DOCS_DIR=/var/www/www.telaris.ca/docs/` set in the shell rcfile.

## Future

The federation plan v10 anticipates this surface growing into the Pluriverse application (operator accounts, peer admission, federation message relay, etc.) on a PHP 8.3 + MySQL stack matching the existing Telaris instance code. The current build script is intentionally minimal so the upgrade path is open; when the dynamic surfaces land, the static HTML files for the public-facing pages can either stay as is or move into PHP includes that share the same chrome.
