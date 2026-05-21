#!/usr/bin/env python3
"""Build the multi-page Telaris Pluriverse website.

Writes 18 HTML files (6 pages × 3 locales) into /var/www/www.telaris.ca/.
Each file shares the same chrome (navbar + footer) localized to its
language, with active-page and active-language indicators.

This is a small build helper, not a real build system. Two modes of
editing the site:

  * Small content change (typo, doc caption tweak, new instance row):
    edit the I18N dict in this file or the page renderer, re-run the
    script. Do not edit the generated HTML files directly, or your edits
    will be lost the next time the script runs.
  * Chrome / structure change (new page, new locale, navbar tweak):
    edit the page renderer or the chrome helpers, re-run.

Run with:

    python3 build.py

Dependencies (already on this host for the documentation pipeline):
    pip install markdown-it-py
"""

from __future__ import annotations

import re
from pathlib import Path

from markdown_it import MarkdownIt

ROOT = Path("/var/www/www.telaris.ca")
# Markdown source for the long-form pages (Manifest, Privacy, Terms) lives in
# the documentation repo. The website repo reads from it directly; the source
# of truth for each document's prose is the docs repo, not duplicated here.
DOCS_REPO = Path.home() / "apps" / "telaris" / "documentation"

LOCALES = ["en", "es", "pt"]


# ---------------------------------------------------------------------------
# Markdown rendering. Mirrors the documentation repo's callout handling so
# the Manifest / Privacy / Terms HTML pages match the look of the PDFs.
# ---------------------------------------------------------------------------

_md = (
    MarkdownIt("commonmark", {"breaks": False, "html": True, "linkify": True})
    .enable("table")
    .enable("strikethrough")
)

_CALLOUT_KINDS = {"note", "warning", "tip", "important", "danger", "example"}
_CALLOUT_FIRST_RE = re.compile(r"^\s*\[!(?P<kind>[a-z]+)\](?:\s+(?P<title>.*))?$")
_CALLOUT_BLOCK_RE = re.compile(
    r"<!--CALLOUT kind=(?P<kind>[a-z]+) title=(?P<title>[^>]*)-->"
    r"(?P<body>.*?)"
    r"<!--CALLOUT-END-->",
    re.DOTALL,
)


def _transform_callouts(src: str) -> str:
    """Rewrite Obsidian-style callouts to marker pairs the second pass replaces."""
    lines = src.splitlines()
    out: list[str] = []
    i = 0
    while i < len(lines):
        line = lines[i]
        if line.startswith(">"):
            block: list[str] = []
            while i < len(lines) and lines[i].startswith(">"):
                block.append(lines[i].lstrip(">").lstrip(" "))
                i += 1
            if block:
                m = _CALLOUT_FIRST_RE.match(block[0])
                if m and m.group("kind") in _CALLOUT_KINDS:
                    kind = m.group("kind")
                    title = (m.group("title") or kind.capitalize()).strip()
                    body_md = "\n".join(block[1:]).strip()
                    out.append(f"<!--CALLOUT kind={kind} title={title}-->")
                    out.append(body_md)
                    out.append("<!--CALLOUT-END-->")
                    continue
            for orig in block:
                out.append("> " + orig if orig else ">")
            continue
        out.append(line)
        i += 1
    return "\n".join(out)


def _render_callouts(html: str) -> str:
    def repl(m: re.Match[str]) -> str:
        kind = m.group("kind")
        title = m.group("title").strip()
        body = m.group("body").strip()
        body_html = _md.render(body) if body else ""
        return (
            f'<div class="callout callout-{kind}">'
            f'<div class="callout-title">{title}</div>'
            f'<div class="callout-body">{body_html}</div>'
            "</div>"
        )

    return _CALLOUT_BLOCK_RE.sub(repl, html)


def render_doc_markdown(slug: str, lang: str) -> str:
    """Render the markdown source for a doc to HTML body content.

    `slug` is the docs-repo slug (manifest, privacy, tos). `lang` is en/es/pt.
    Returns the rendered HTML body, ready to be injected inside a `.prose` div.
    The leading H1 is stripped (the page's own H1 carries the title).
    """
    suffix = f"-{lang}" if lang != "en" else ""
    src = (DOCS_REPO / f"src/{slug}{suffix}/01-{slug}.md").read_text(encoding="utf-8")
    if src.startswith("# "):
        src = src.split("\n", 1)[1].lstrip()
    with_markers = _transform_callouts(src)
    raw_html = _md.render(with_markers)
    return _render_callouts(raw_html)

# i18n strings for the chrome.
I18N = {
    "en": {
        "html_lang": "en",
        "title_suffix": "Telaris",
        "weaving": "weaving memory",
        "tagline_desc": "A decolonial knowledge archive project. Relational, P2P, non-hierarchical. Threaded by meaning.",
        "nav": {
            "home": "Home",
            "documentation": "Documentation",
            "instances": "Instances",
            "manifest": "Manifest",
            "source_code": "Source code",
        },
        "footer": {
            "status": "System: Online",
            "privacy": "Privacy",
            "terms": "Terms",
        },
        "doc_page": {
            "title": "Documentation",
            "lead": "Telaris documentation, available as downloadable PDFs. Each document is also available in Spanish and Portuguese; use the language toggle in the navigation bar to switch.",
            "download": "Download PDF",
        },
        "docs": [
            {"slug": "manifest", "name": "Manifest", "color": "#00ffcc", "caption": "Position statement. What Telaris is, what it refuses, the six principles that hold it together. Approximately five pages."},
            {"slug": "editor-quick-start", "name": "Editor Quick Start", "color": "#fde047", "caption": "Five steps to your first wormhole. For new editors who want the shortest possible path. Six pages."},
            {"slug": "editor-manual", "name": "Editor Manual", "color": "#86efac", "caption": "Complete reference for editors authoring galaxies, wormholes, keywords, portals, tours, and visitor views. Fifteen chapters, seventy-two pages."},
            {"slug": "admin-manual", "name": "Admin Manual", "color": "#7dd3fc", "caption": "For operators running a Telaris instance: deployment, configuration, federation, key management, backups. Draft pending.", "draft": True},
        ],
        "instance_page": {
            "title": "Active instances",
            "lead": "Telaris is run by independent operators. Each instance is governed by the operator who runs it and the editors and source communities who contribute to it. Below are the instances currently active.",
        },
        "instances": [
            {
                "url": "https://telaris.polivoxia.ca",
                "host": "telaris.polivoxia.ca",
                "color": "#7dd3fc",
                "caption": "Polivoxia production. The first Telaris instance in continuous use, hosted by Adri M. (UBC GRSJ) at Polivoxia.",
                "tags": ["production", "english"],
            },
            {
                "url": "https://starmaps.polivoxia.ca",
                "host": "starmaps.polivoxia.ca",
                "color": "#86efac",
                "caption": "Polivoxia development. The source-of-truth working instance where new features and editorial work are validated before reaching production.",
                "tags": ["development", "english"],
            },
            {
                "url": "https://telaris.baobaxia.net",
                "host": "telaris.baobaxia.net",
                "color": "#fdba74",
                "caption": "Baobáxia / Mocambos. A Telaris instance hosted alongside the Mocambos quilombola community archive, in dialogue with the Baobáxia tradition of communal digital archiving.",
                "tags": ["community", "portuguese"],
            },
        ],
        "manifest_page": {
            "title": "Manifest",
            "lead": "A statement of position, kept short on purpose. What Telaris is, what it refuses, and the six principles that hold it together.",
            "download_pdf": "Download as PDF",
        },
        "privacy_page": {
            "title": "Privacy",
            "lead": "How Telaris handles data, at www.telaris.ca and across the network of independent instances.",
            "download_pdf": "Download as PDF",
        },
        "terms_page": {
            "title": "Terms of Use",
            "lead": "How the website at www.telaris.ca and the Telaris software are offered to visitors, operators, and editors.",
            "download_pdf": "Download as PDF",
        },
    },
    "es": {
        "html_lang": "es",
        "title_suffix": "Telaris",
        "weaving": "tejiendo memoria",
        "tagline_desc": "Un proyecto de archivo de conocimiento decolonial. Relacional, P2P, no jerárquico. Hilado por el significado.",
        "nav": {
            "home": "Inicio",
            "documentation": "Documentación",
            "instances": "Instancias",
            "manifest": "Manifiesto",
            "source_code": "Código fuente",
        },
        "footer": {
            "status": "Sistema: En línea",
            "privacy": "Privacidad",
            "terms": "Términos",
        },
        "doc_page": {
            "title": "Documentación",
            "lead": "Documentación de Telaris, disponible como PDF descargable. Cada documento existe también en inglés y portugués; usa el selector de idioma de la barra de navegación para cambiar.",
            "download": "Descargar PDF",
        },
        "docs": [
            {"slug": "manifest", "name": "Manifiesto", "color": "#00ffcc", "caption": "Declaración de posición. Qué es Telaris, qué rechaza, los seis principios que lo sostienen. Aproximadamente cinco páginas."},
            {"slug": "editor-quick-start", "name": "Inicio rápido para editoras", "color": "#fde047", "caption": "Cinco pasos hasta tu primer agujero de gusano. Para editoras que quieren el camino más corto posible. Seis páginas."},
            {"slug": "editor-manual", "name": "Manual del editor", "color": "#86efac", "caption": "Referencia completa para editoras que crean galaxias, agujeros de gusano, palabras clave, portales, recorridos y vistas de visitante. Quince capítulos, setenta y dos páginas."},
            {"slug": "admin-manual", "name": "Manual de administración", "color": "#7dd3fc", "caption": "Para quienes operan una instancia de Telaris: despliegue, configuración, federación, gestión de claves, copias de seguridad. Borrador pendiente.", "draft": True},
        ],
        "instance_page": {
            "title": "Instancias activas",
            "lead": "Telaris lo operan personas independientes. Cada instancia se gobierna por quien la opera y por las editoras y comunidades de origen que contribuyen a ella. A continuación, las instancias actualmente activas.",
        },
        "instances": [
            {
                "url": "https://telaris.polivoxia.ca",
                "host": "telaris.polivoxia.ca",
                "color": "#7dd3fc",
                "caption": "Polivoxia producción. La primera instancia de Telaris en uso continuo, alojada por Adri M. (UBC GRSJ) en Polivoxia.",
                "tags": ["producción", "inglés"],
            },
            {
                "url": "https://starmaps.polivoxia.ca",
                "host": "starmaps.polivoxia.ca",
                "color": "#86efac",
                "caption": "Polivoxia desarrollo. La instancia de trabajo fuente-de-verdad donde se validan nuevas funcionalidades y trabajo editorial antes de llegar a producción.",
                "tags": ["desarrollo", "inglés"],
            },
            {
                "url": "https://telaris.baobaxia.net",
                "host": "telaris.baobaxia.net",
                "color": "#fdba74",
                "caption": "Baobáxia / Mocambos. Una instancia de Telaris alojada junto al archivo comunitario quilombola Mocambos, en diálogo con la tradición Baobáxia del archivo digital comunitario.",
                "tags": ["comunidad", "portugués"],
            },
        ],
        "manifest_page": {
            "title": "Manifiesto",
            "lead": "Una declaración de posición, mantenida corta a propósito. Qué es Telaris, qué rechaza, y los seis principios que lo sostienen.",
            "download_pdf": "Descargar como PDF",
        },
        "privacy_page": {
            "title": "Privacidad",
            "lead": "Cómo Telaris maneja los datos, en www.telaris.ca y en la red de instancias independientes.",
            "download_pdf": "Descargar como PDF",
        },
        "terms_page": {
            "title": "Términos de uso",
            "lead": "Cómo se ofrece el sitio www.telaris.ca y el software de Telaris a quienes visitan, operan y editan.",
            "download_pdf": "Descargar como PDF",
        },
    },
    "pt": {
        "html_lang": "pt-BR",
        "title_suffix": "Telaris",
        "weaving": "tecendo memória",
        "tagline_desc": "Um projeto de arquivo de conhecimento decolonial. Relacional, P2P, não hierárquico. Fiado por significado.",
        "nav": {
            "home": "Início",
            "documentation": "Documentação",
            "instances": "Instâncias",
            "manifest": "Manifesto",
            "source_code": "Código fonte",
        },
        "footer": {
            "status": "Sistema: Online",
            "privacy": "Privacidade",
            "terms": "Termos",
        },
        "doc_page": {
            "title": "Documentação",
            "lead": "Documentação de Telaris, disponível em PDF para download. Cada documento também existe em inglês e espanhol; use o seletor de idioma na barra de navegação para alternar.",
            "download": "Baixar PDF",
        },
        "docs": [
            {"slug": "manifest", "name": "Manifesto", "color": "#00ffcc", "caption": "Declaração de posição. O que Telaris é, o que recusa, os seis princípios que o sustentam. Aproximadamente cinco páginas."},
            {"slug": "editor-quick-start", "name": "Início rápido para editoras", "color": "#fde047", "caption": "Cinco passos até seu primeiro buraco de minhoca. Para editoras que querem o caminho mais curto possível. Seis páginas."},
            {"slug": "editor-manual", "name": "Manual do editor", "color": "#86efac", "caption": "Referência completa para editoras que criam galáxias, buracos de minhoca, palavras-chave, portais, percursos e vistas de visitante. Quinze capítulos, setenta e duas páginas."},
            {"slug": "admin-manual", "name": "Manual de administração", "color": "#7dd3fc", "caption": "Para quem opera uma instância de Telaris: implantação, configuração, federação, gestão de chaves, backups. Rascunho pendente.", "draft": True},
        ],
        "instance_page": {
            "title": "Instâncias ativas",
            "lead": "Telaris é operado por pessoas independentes. Cada instância é governada por quem a opera e pelas editoras e comunidades de origem que contribuem para ela. Abaixo, as instâncias atualmente ativas.",
        },
        "instances": [
            {
                "url": "https://telaris.polivoxia.ca",
                "host": "telaris.polivoxia.ca",
                "color": "#7dd3fc",
                "caption": "Polivoxia produção. A primeira instância de Telaris em uso contínuo, hospedada por Adri M. (UBC GRSJ) na Polivoxia.",
                "tags": ["produção", "inglês"],
            },
            {
                "url": "https://starmaps.polivoxia.ca",
                "host": "starmaps.polivoxia.ca",
                "color": "#86efac",
                "caption": "Polivoxia desenvolvimento. A instância de trabalho que serve como fonte-de-verdade onde novas funcionalidades e trabalho editorial são validados antes de chegar à produção.",
                "tags": ["desenvolvimento", "inglês"],
            },
            {
                "url": "https://telaris.baobaxia.net",
                "host": "telaris.baobaxia.net",
                "color": "#fdba74",
                "caption": "Baobáxia / Mocambos. Uma instância de Telaris hospedada junto ao arquivo comunitário quilombola Mocambos, em diálogo com a tradição Baobáxia de arquivo digital comunitário.",
                "tags": ["comunidade", "português"],
            },
        ],
        "manifest_page": {
            "title": "Manifesto",
            "lead": "Uma declaração de posição, mantida curta de propósito. O que Telaris é, o que recusa, e os seis princípios que o sustentam.",
            "download_pdf": "Baixar como PDF",
        },
        "privacy_page": {
            "title": "Privacidade",
            "lead": "Como Telaris lida com dados, em www.telaris.ca e na rede de instâncias independentes.",
            "download_pdf": "Baixar como PDF",
        },
        "terms_page": {
            "title": "Termos de uso",
            "lead": "Como o site www.telaris.ca e o software de Telaris são oferecidos a quem visita, opera e edita.",
            "download_pdf": "Baixar como PDF",
        },
    },
}


def url_prefix(lang: str) -> str:
    """The URL prefix for a given locale. EN at root; ES under /es/; PT under /pt/."""
    return "" if lang == "en" else f"/{lang}"


def page_url(lang: str, slug: str) -> str:
    """Resolve the URL for a (lang, slug) pair. slug == '' is the home page."""
    prefix = url_prefix(lang)
    if slug == "":
        return f"{prefix}/" if prefix else "/"
    return f"{prefix}/{slug}/"


def pdf_url(lang: str, slug: str) -> str:
    """Resolve the PDF URL for a (lang, slug) pair. PDFs live at /docs/<slug>[-lang].pdf."""
    if lang == "en":
        return f"/docs/{slug}.pdf"
    return f"/docs/{slug}-{lang}.pdf"


def output_path(lang: str, slug: str) -> Path:
    """Where to write the HTML file for a (lang, slug) pair."""
    prefix = url_prefix(lang).lstrip("/")
    parts = [ROOT]
    if prefix:
        parts.append(prefix)
    if slug:
        parts.append(slug)
    return Path(*parts) / "index.html"


def render_navbar(lang: str, active_slug: str) -> str:
    """Render the top navigation bar for a given locale, marking the active page."""
    s = I18N[lang]
    nav = s["nav"]
    items = [
        ("", nav["home"]),
        ("documentation", nav["documentation"]),
        ("instances", nav["instances"]),
        ("manifest", nav["manifest"]),
    ]
    parts = []
    for i, (slug, label) in enumerate(items):
        if i > 0:
            parts.append('<span class="nav-sep" aria-hidden="true">·</span>')
        active = " active" if slug == active_slug else ""
        parts.append(f'<a class="nav-link{active}" href="{page_url(lang, slug)}">{label}</a>')

    # External: source code on GitHub. Always present, never active (the page
    # opens in a new tab and lives outside this site).
    parts.append('<span class="nav-sep" aria-hidden="true">·</span>')
    parts.append(
        f'<a class="nav-link" href="https://github.com/theagitist/telaris" '
        f'target="_blank" rel="noopener noreferrer">{nav["source_code"]}</a>'
    )

    lang_parts = []
    other_langs = [("en", "EN"), ("es", "ES"), ("pt", "PT")]
    for i, (lcode, label) in enumerate(other_langs):
        if i > 0:
            lang_parts.append('<span class="nav-sep" aria-hidden="true">·</span>')
        active = " active" if lcode == lang else ""
        lang_parts.append(f'<a class="nav-link{active}" href="{page_url(lcode, active_slug)}">{label}</a>')

    return (
        '<nav class="navbar" aria-label="Site">'
        '<div class="nav-main">' + "".join(parts) + "</div>"
        '<div class="nav-lang" aria-label="Language">' + "".join(lang_parts) + "</div>"
        "</nav>"
    )


def render_footer(lang: str) -> str:
    """Render the bottom footer for a given locale.

    Single line: status indicator, then Privacy and Terms, separated by
    middle dots. The whole line shares one weight; the status carries a
    coloured dot, the links carry an underline rule so they read as
    actionable.
    """
    s = I18N[lang]["footer"]
    return (
        '<footer class="site-footer">'
        '<span class="dot" aria-hidden="true"></span>'
        f'<span class="status">{s["status"]}</span>'
        '<span class="sep" aria-hidden="true">·</span>'
        f'<a href="{page_url(lang, "privacy")}">{s["privacy"]}</a>'
        '<span class="sep" aria-hidden="true">·</span>'
        f'<a href="{page_url(lang, "terms")}">{s["terms"]}</a>'
        "</footer>"
    )


def page_head(lang: str, page_title: str, body_class: str = "", include_bg: bool = False) -> str:
    s = I18N[lang]
    desc = s["tagline_desc"]
    full_title = f"{page_title} · {s['title_suffix']}" if page_title else s["title_suffix"]
    bg_canvas = '<canvas id="bg" aria-hidden="true"></canvas>' if include_bg else ""
    # Map body_class to the active-page slug. home → '', else strip 'page-'.
    active_slug = ""
    if body_class.startswith("page-"):
        active_slug = body_class.removeprefix("page-")
    nav = render_navbar(lang, active_slug)
    return f'''<!doctype html>
<html lang="{s["html_lang"]}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{full_title}</title>
  <meta name="description" content="{desc}">
  <link rel="icon" href="data:,">
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body class="{body_class}">
{bg_canvas}
{nav}
'''


def page_tail(lang: str, include_bg: bool = False) -> str:
    bg_script = '<script src="/assets/bg.js"></script>' if include_bg else ""
    return f"\n{render_footer(lang)}\n{bg_script}\n</body>\n</html>\n"


# ---------------------------------------------------------------------------
# Page renderers
# ---------------------------------------------------------------------------

def render_home(lang: str) -> str:
    s = I18N[lang]
    head = page_head(lang, "", body_class="home page-home", include_bg=True)
    main = f'''
<main class="home">
  <h1 class="wordmark">Telaris</h1>
  <p class="tagline">{s["weaving"]}</p>
  <div class="hud-line"></div>
  <p class="desc">{s["tagline_desc"]}</p>
</main>
'''
    tail = page_tail(lang, include_bg=True)
    return head + main + tail


def render_documentation(lang: str) -> str:
    s = I18N[lang]
    p = s["doc_page"]
    head = page_head(lang, p["title"], body_class="page-documentation")

    doc_rows = []
    for doc in s["docs"]:
        draft_marker = ""
        download_link = ""
        if doc.get("draft"):
            # Admin manual is not yet downloadable
            download_link = ""
        else:
            download_link = (
                f'<a class="doc-download" href="{pdf_url(lang, doc["slug"])}">'
                f'{p["download"]} &rarr;</a>'
            )
        doc_rows.append(f'''
    <li class="doc-list-item" style="--c:{doc["color"]}">
      <span class="dot" aria-hidden="true"></span>
      <div class="doc-meta">
        <span class="doc-name">{doc["name"]}</span>
        <span class="doc-caption">{doc["caption"]}</span>
      </div>
      {download_link}
    </li>''')

    main = f'''
<main class="page">
  <p class="page-eyebrow">{p["title"]}</p>
  <h1 class="page-title">{p["title"]}</h1>
  <p class="page-lead">{p["lead"]}</p>
  <ul class="doc-list">{"".join(doc_rows)}
  </ul>
</main>
'''
    return head + main + page_tail(lang)


def render_instances(lang: str) -> str:
    s = I18N[lang]
    p = s["instance_page"]
    head = page_head(lang, p["title"], body_class="page-instances")

    instance_rows = []
    for inst in s["instances"]:
        tag_html = "".join(f'<span class="instance-tag">{t}</span>' for t in inst["tags"])
        instance_rows.append(f'''
    <li class="instance-list-item" style="--c:{inst["color"]}">
      <span class="dot" aria-hidden="true"></span>
      <div>
        <a class="instance-url" href="{inst["url"]}" target="_blank" rel="noopener noreferrer">{inst["host"]} &rarr;</a>
        <p class="instance-caption">{inst["caption"]}</p>
        <div class="instance-tags">{tag_html}</div>
      </div>
    </li>''')

    main = f'''
<main class="page">
  <p class="page-eyebrow">{p["title"]}</p>
  <h1 class="page-title">{p["title"]}</h1>
  <p class="page-lead">{p["lead"]}</p>
  <ul class="instance-list">{"".join(instance_rows)}
  </ul>
</main>
'''
    return head + main + page_tail(lang)


def render_content_page(lang: str, kind: str, slug: str) -> str:
    """Render Manifest / Privacy / Terms by rendering the docs-repo markdown.

    `kind` is the I18N section key (manifest/privacy/terms); `slug` is the
    docs-repo slug used to find the markdown file (manifest, privacy, tos).
    The URL slug ('/terms/') and the docs-repo slug ('tos') can differ.
    """
    s = I18N[lang]
    p = s[f"{kind}_page"]
    head = page_head(lang, p["title"], body_class=f"page-{kind}")

    body_html = render_doc_markdown(slug, lang)

    download_pdf = (
        f'<a class="pdf-cta" href="{pdf_url(lang, slug)}">'
        f'{p["download_pdf"]} &rarr;</a>'
    )

    main = f'''
<main class="page">
  <p class="page-eyebrow">{p["title"]}</p>
  <h1 class="page-title">{p["title"]}</h1>
  <p class="page-lead">{p["lead"]}</p>
  <div class="prose">
{body_html}
  </div>
  {download_pdf}
</main>
'''
    return head + main + page_tail(lang)


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------

def write(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(content, encoding="utf-8")
    print(f"wrote {path}")


def main() -> None:
    for lang in LOCALES:
        # Home
        write(output_path(lang, ""), render_home(lang))
        # Documentation
        write(output_path(lang, "documentation"), render_documentation(lang))
        # Instances
        write(output_path(lang, "instances"), render_instances(lang))
        # Manifest
        write(
            output_path(lang, "manifest"),
            render_content_page(lang, "manifest", "manifest"),
        )
        # Privacy
        write(
            output_path(lang, "privacy"),
            render_content_page(lang, "privacy", "privacy"),
        )
        # Terms (PDF / docs slug is 'tos' but the URL is /terms/)
        write(
            output_path(lang, "terms"),
            render_content_page(lang, "terms", "tos"),
        )


if __name__ == "__main__":
    main()
