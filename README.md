# Singles Manager

A private Craft CMS 5 plugin that:

1. **Expands single sections** as individual items in the entry element-index sources sidebar (like the [Expanded Singles](https://github.com/verbb/expanded-singles) plugin).
2. **Opens single entries in a globals-like editor** — a persistent left-nav sidebar stays visible while you edit, and the full native Craft entry editor is used (drafts, preview, publish flow, right-hand metadata sidebar, etc.).

Requires Craft CMS **5.0+**.

---

## Installation

### 1. Add the repository to your project's `composer.json`

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

### 2. Require the plugin

```bash
composer require thupsi/craft-singles-manager:dev-main
```

Or pin to a tagged release once you start tagging:

```bash
composer require thupsi/craft-singles-manager:^1.0
```

### 3. Install in Craft

```bash
php craft plugin/install _singles-manager
```

Or go to **Settings → Plugins** in the control panel and install it from there.

---

## After installation

- In **Settings → Sources** (Entry element sources), reconfigure your source ordering if needed — the old grouped `singles` key is replaced by individual `single:{uid}` keys.
- The `/singles` and `/singles/{handle}` CP URLs now redirect to the correct single entry editor.

---

## Responsive behaviour

The injected left sidebar is hidden on viewports narrower than **1280px** to avoid a four-column layout on smaller screens.
