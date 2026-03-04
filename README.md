# Singles Manager

A private Craft CMS 5 plugin that improves the control panel experience for single sections.

---

## Features

### Expanded singles in the sources sidebar

Single sections are listed individually in the entry element-index sources sidebar instead of being grouped under a single "Singles" item (similar to the [Expanded Singles](https://github.com/verbb/expanded-singles) plugin).

### Globals-style editor for singles

Clicking a single in the sources sidebar opens the entry editor with a persistent left-nav sidebar — just like globals work in Craft. The full native entry editor is used (drafts, revisions, preview, publish flow, right-hand metadata panel, etc.).

### Direct nav links

Main nav items that lead to a page containing a single as their first source link directly to that single's edit form, skipping the element index entirely.

### Auto-hide sources sidebar

When a page has only one source, the sources sidebar is hidden automatically — there is nothing useful to show. The "Customize Sources" button remains accessible.

### Customize Sources button in the singles sidebar

An icon button in the injected left sidebar opens the native **Customize Sources** modal, giving editors access to source configuration without leaving the single edit form. (Admin-only.)

### Hide right sidebar (per section)

A toggle on each single section's settings page hides the right-hand metadata panel (slug, post date, authors, etc.) when editing that single. Useful for settings or SEO-style pages where the metadata fields are irrelevant.

### Fallback breadcrumb source for disabled sources

When any section's source is disabled in the element sources config (e.g. it is grouped inside a custom source), you can assign a **fallback breadcrumb source**. The breadcrumb will then show the correct page and, when the page has multiple sources, the custom source label — instead of the generic "Entries" fallback. The setting has no effect when the section's source is enabled.

### Smart post-save redirect

After saving a single:
- **Enabled source** — stays on the single's edit form.
- **Disabled source with a fallback source, page has multiple sources** — redirects to the fallback source's page.
- **Disabled source with a fallback source, page has only one source** — stays on the edit form.

### Section settings in slideouts

The "Hide right sidebar" toggle and "Fallback breadcrumb source" selector appear both on the full section settings page (**Settings → Sections → [section]**) and inside the slideout that opens when editing section settings from an entry's action menu.

---

## Requirements

- Craft CMS **5.0+**

---

## Installation

### With DDEV (one command)

```bash
ddev composer require thupsi/craft-singles-manager:dev-main && ddev craft plugin/install _singles-manager
```

### Manual

#### 1. Add the repository to `composer.json`

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

For private repos, configure a GitHub personal access token:

```bash
composer config --global github-oauth.github.com <your-token>
```

#### 2. Require the plugin

```bash
composer require thupsi/craft-singles-manager:dev-main
```

Or pin to a tagged release:

```bash
composer require thupsi/craft-singles-manager:^0.1
```

#### 3. Install in Craft

```bash
php craft plugin/install _singles-manager
```

Or go to **Settings → Plugins** in the control panel.

---

## After installation

In **Settings → Sources** (Entry element sources), review your source ordering — the old grouped `singles` key is replaced by individual `single:{uid}` keys.

---

## Configuration

All settings are stored in project config under `plugins._singles-manager.settings` and propagate to all environments via `php craft project-config/apply`.

### Hide right sidebar

On any section's settings page (or via the section settings slideout on an entry), enable **"Hide right sidebar"** to remove the right-hand metadata panel for that section.

### Fallback breadcrumb source

On the same settings form, use the **"Fallback breadcrumb source"** dropdown to choose any enabled source. This applies to **all section types** (singles, channels, structures) and takes effect only when the section's own source is disabled — ensuring the breadcrumb points to a meaningful location rather than the generic "Entries" page. The dropdown is pre-grouped by page for easy navigation.

### Disabled sources

If a section should be navigated to via a custom CP nav link or custom source rather than the built-in sources sidebar, mark its source as **disabled** in **Settings → Sources**. For singles, the plugin also skips sidebar injection. Configure a fallback breadcrumb source to keep breadcrumbs accurate.

