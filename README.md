# Singleton

> **Disclaimer:** This plugin was built with the assistance of [Claude](https://claude.ai) (Anthropic) and is provided as a prototype/proof of concept. It is offered as-is, without warranty of any kind. The author accepts no liability for any data loss, site breakage, or other issues arising from its use. The plugin only modifies control panel behaviour — it does not read, write, or touch user content or the database.

A private Craft CMS 5 plugin that improves the control panel experience for single sections, with some bonus features for all section types and index pages.

> The internal plugin handle is `_singles-manager` for backwards compatibility with existing project configs.

---

## Background

This plugin is a proof of concept, built to explore potential improvements to the Craft CMS control panel for singles and global-style sections. The goal is to validate these ideas on real private projects before making relevant proposals to the Craft P&T team.

Specific areas explored:

- **Entrified globals feel off.** As of v5.9, Craft has mostly resolved the UI friction from "entrification". The one remaining rough edge is entrified Global Sets — having them display authors, post dates, etc. feels redundant, and lateral navigation between them via an index is cumbersome. Light UI adjustments can fix this. Combined with the new (5.9) ability to define custom index pages, you can now get entries with a Global Sets-style UI.
- **The "Singles" group is too opinionated.** The [Expanded Singles](https://github.com/verbb/expanded-singles) plugin addresses this, but ideally it would be a core feature. Since custom sources have been available for a while, it makes more sense to ungroup singles by default and selectively group them into one or more custom sources.
- **Breadcrumb bugs with custom sources.** Custom sources and custom entry indexes can cause confusing breadcrumb behaviour, especially when a source is disabled and its entries appear in other sources. A new per-section "fallback breadcrumb source" setting addresses this.

---

## Features

### Expanded singles in the sources sidebar

Single sections are listed individually in the sources sidebar instead of being grouped under a single "Singles" item — similar to the [Expanded Singles](https://github.com/verbb/expanded-singles) plugin.

### Globals-style editor for singles

Clicking a single in the sources sidebar opens the entry editor with a persistent left-nav sidebar, just like globals in Craft. The full native entry editor is used (drafts, revisions, preview, publish flow, right-hand metadata panel, etc.).

### Direct nav links

Main nav items whose first source is a single link directly to that single's edit form, bypassing the element index.

### Auto-hide sources sidebar

When a page has only one source, the sources sidebar is hidden automatically. The "Customize Sources" button remains accessible.

### Hide right sidebar (per section)

A toggle on each section's settings page hides the right-hand metadata panel (slug, post date, authors, etc.) when editing entries in that section. Useful for settings-style sections where that metadata is irrelevant.

### Fallback breadcrumb source

When a section's source is disabled (e.g. grouped inside a custom source), you can assign a fallback breadcrumb source. The breadcrumb will then show the correct page and — when the page has multiple sources — the custom source label, instead of a broken or generic breadcrumb segment. Has no effect when the section's own source is enabled.

### Smart post-save redirect

After saving a single:

| Condition | Behaviour |
|---|---|
| Source is enabled | Stays on the single's edit form |
| Source disabled, fallback set, page has multiple sources | Redirects to the fallback source's page |
| Source disabled, fallback set, page has only one source | Stays on the edit form |

---

## Requirements

- Craft CMS **5.9+**

---

## Installation

### With DDEV
```bash
ddev composer require thupsi/craft-singleton && ddev craft plugin/install _singles-manager
```

### Manual

**1. Add the repository to `composer.json`**
```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/thupsi/craft-singleton"
        }
    ]
}
```

For private repos, configure a GitHub personal access token:
```bash
composer config --global github-oauth.github.com <your-token>
```

**2. Require the package**
```bash
composer require thupsi/craft-singleton
```

**3. Install the plugin**
```bash
php craft plugin/install _singles-manager
```

Or go to **Settings → Plugins** in the control panel.

---

## After installation

Review your source ordering — the old grouped `singles` key is replaced by individual `single:{uid}` keys.

---

## Configuration

All settings are stored in project config under `plugins._singles-manager.settings` and propagate to all environments via `php craft project-config/apply`.

### Hide right sidebar

On any section's settings page (or via the section settings slideout on an entry), enable **"Hide right sidebar"** to remove the right-hand metadata panel for that section.

### Fallback breadcrumb source

On the same settings form, use the **"Fallback breadcrumb source"** dropdown to choose any enabled source. This applies to all section types and takes effect only when the section's own source is disabled. The dropdown is grouped by page for easy navigation.