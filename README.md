# Singles Manager

A private Craft CMS 5 plugin that improves the control panel experience for single sections:

1. **Expands single sections** as individual items in the entry element-index sources sidebar (like the [Expanded Singles](https://github.com/verbb/expanded-singles) plugin).
2. **Opens single entries in a globals-like editor** — a persistent left-nav sidebar stays visible while you edit, and the full native Craft entry editor is used (drafts, preview, publish flow, right-hand metadata sidebar, etc.).
3. **Supports CP pages** — singles are grouped under their assigned page (Craft 5.9+), and the left sidebar only shows sources from the current page.
4. **Correct breadcrumbs** — the breadcrumb always reflects the correct CP page name, not the generic "Entries" fallback.
5. **Responsive sidebar** — the left sidebar is hidden on viewports narrower than **1280px** to avoid a four-column layout.
6. **Hides the right sidebar** per section — useful for settings or SEO pages where metadata fields are irrelevant.
7. **Respects disabled sources** — if a single's source is disabled in the element sources config, the plugin skips sidebar injection entirely (the section is navigated to from a custom link instead).
8. **Shows all source types** — the left sidebar renders headings, single sources, channel/structure section sources, and custom sources.

Requires Craft CMS **5.0+**.

---

## Installation

### With DDEV (one command)

```bash
ddev composer require thupsi/craft-singles-manager:dev-main && ddev craft plugin/install _singles-manager
```

### Manual

#### 1. Add the repository to your project's `composer.json`

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/thupsi/craft-singles-manager"
        }
    ]
}
```

For **private repos** you'll need a GitHub personal access token configured in your Composer auth:

```bash
composer config --global github-oauth.github.com <your-token>
```

#### 2. Require the plugin

```bash
composer require thupsi/craft-singles-manager:dev-main
```

Or pin to a tagged release once you start tagging:

```bash
composer require thupsi/craft-singles-manager:^1.0
```

#### 3. Install in Craft

```bash
php craft plugin/install _singles-manager
```

Or go to **Settings → Plugins** in the control panel and install it from there.

---

## After installation

- In **Settings → Sources** (Entry element sources), reconfigure your source ordering if needed — the old grouped `singles` key is replaced by individual `single:{uid}` keys.
- The `/singles` and `/singles/{handle}` CP URLs now redirect to the correct single entry editor.

---

## Configuration

### Hide right sidebar (per section)

On any single section's settings page (**Settings → Sections → [section name]**), a **"Hide right sidebar"** toggle is available at the bottom of the form. Enabling it hides the right-hand meta panel (slug, post date, authors, etc.) when editing that single — useful for settings/SEO-style pages where the metadata is irrelevant.

The setting is stored in the plugin's project config (`plugins._singles-manager.settings.hideSidebarSections`) and propagates to all environments via `php craft project-config/apply` (or `ddev craft project-config/apply`).

### Disabled sources

If a single section should be navigated to from a custom CP nav link or another section (rather than the built-in sources sidebar), set it to **disabled** in **Settings → Sources**. The plugin will detect this and skip injecting the globals-like sidebar for that section, leaving the standard Craft entry editor intact.
