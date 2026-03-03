<?php

namespace thupsi\singlesmanager;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\controllers\ElementsController;
use craft\controllers\SectionsController;
use craft\elements\Entry;
use craft\events\RegisterCpNavItemsEvent;
use craft\events\RegisterElementSourcesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\SectionEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\Section;
use craft\services\Entries;
use craft\web\CpScreenResponseBehavior;
use craft\web\twig\variables\Cp as CpVariable;
use craft\web\UrlManager;
use craft\web\View;
use thupsi\singlesmanager\assetbundles\SinglesManagerAsset;
use thupsi\singlesmanager\models\Settings;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;

/**
 * Singles Manager plugin.
 *
 * 1. Expands single sections as individual sources in the entry sources sidebar.
 * 2. Injects a globals-like left-sidebar nav into the standard Craft element
 *    editor whenever a single entry is being edited — giving you all native
 *    Craft features (drafts, preview, right-hand meta sidebar, etc.) while
 *    keeping a persistent section nav on the left.
 */
class Plugin extends BasePlugin
{
    public function init(): void
    {
        parent::init();

        // Expand singles in the entry element sources
        Event::on(Entry::class, Element::EVENT_REGISTER_SOURCES, function (RegisterElementSourcesEvent $event) {
            $this->_expandSingles($event);
        });

        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        // Keep `singles` and `singles/<handle>` as clean URL shortcuts that
        // redirect to the actual entry edit page.
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event) {
            $event->rules = array_merge([
                'singles' => '_singles-manager/singles/index',
                'singles/<sectionHandle:{handle}>' => '_singles-manager/singles/redirect',
            ], $event->rules);
        });

        // After ElementsController::actionEdit() runs, inject our left-sidebar
        // nav when the element being edited is a single entry.
        Event::on(ElementsController::class, Controller::EVENT_AFTER_ACTION, function (ActionEvent $e) {
            if ($e->action->id !== 'edit') {
                return;
            }
            $this->_injectSinglesSidebar($e);
        });

        // Rewrite CP nav URLs for pages that contain only a single section so
        // clicking the nav item goes directly to that single's edit form.
        Event::on(CpVariable::class, CpVariable::EVENT_REGISTER_CP_NAV_ITEMS, function (RegisterCpNavItemsEvent $e) {
            $this->_fixNavLinks($e);
        });

        // Inject a "Hide right sidebar" lightswitch into the native section
        // edit form (only shown for single sections).
        Event::on(SectionsController::class, Controller::EVENT_AFTER_ACTION, function (ActionEvent $e) {
            if ($e->action->id !== 'edit-section') {
                return;
            }
            $this->_injectSectionSettingsField($e);
        });

        // After a section is saved, persist any singles-manager POST params.
        Event::on(Entries::class, Entries::EVENT_AFTER_SAVE_SECTION, function (SectionEvent $e) {
            $this->_saveSectionSettings($e->section);
        });
    }

    // -------------------------------------------------------------------------

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    // -------------------------------------------------------------------------

    private function _expandSingles(RegisterElementSourcesEvent $event): void
    {
        $singlesIndex = null;
        foreach ($event->sources as $i => $source) {
            if (isset($source['key']) && $source['key'] === 'singles') {
                $singlesIndex = $i;
                break;
            }
        }

        if ($singlesIndex === null) {
            return;
        }

        $expanded = [['heading' => Craft::t('app', 'Singles')]];

        $singleSections = Craft::$app->getEntries()->getSectionsByType(Section::TYPE_SINGLE);

        // Read the page for each single directly from project config. We CANNOT
        // use Section::getPage() here because we are inside EVENT_REGISTER_SOURCES;
        // getPage() calls findSource() which calls getSources() which would try to
        // rebuild sources recursively, giving wrong results.
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $singleEntries = Entry::find()
            ->sectionId(ArrayHelper::getColumn($singleSections, 'id'))
            ->siteId($currentSiteId)
            ->status(null)
            ->indexBy('sectionId')
            ->all();

        $pageByUid = [];
        $sourceConfigs = Craft::$app->getProjectConfig()->get('elementSources.' . Entry::class) ?? [];
        foreach ($sourceConfigs as $src) {
            $key = $src['key'] ?? '';
            if (str_starts_with($key, 'single:')) {
                $pageByUid[substr($key, 7)] = $src['page'] ?? null;
            }
        }

        foreach ($singleSections as $section) {
            if (!Craft::$app->getUser()->checkPermission('viewEntries:' . $section->uid)) {
                continue;
            }

            $entry = $singleEntries[$section->id] ?? null;

            if ($entry) {
                $page = $pageByUid[$section->uid] ?? null;
                $pagePath = $page ? StringHelper::toKebabCase($page) : 'entries';
                $slug = $entry->slug && !str_starts_with($entry->slug, '__') ? '-' . str_replace('/', '-', $entry->slug) : '';
                $editUrl = UrlHelper::cpUrl("content/{$pagePath}/singles/{$entry->id}{$slug}");
            } else {
                $editUrl = UrlHelper::cpUrl('singles/' . $section->handle);
            }

            $expanded[] = [
                'key' => 'single:' . $section->uid,
                'label' => Craft::t('site', $section->name),
                'data' => [
                    'handle' => $section->handle,
                    'singles-manager-url' => $editUrl,
                ],
                'criteria' => [
                    'sectionId' => $section->id,
                ],
            ];
        }

        array_splice($event->sources, $singlesIndex, 1, $expanded);

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Craft::$app->getView()->registerAssetBundle(SinglesManagerAsset::class);
        }
    }

    private function _injectSinglesSidebar(ActionEvent $e): void
    {
        // Skip AJAX / JSON requests — the element editor handles those itself.
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return;
        }

        /** @var ElementsController $controller */
        $controller = $e->action->controller;
        $element = $controller->element;

        if (!$element instanceof Entry) {
            return;
        }

        $section = $element->getSection();
        if (!$section || $section->type !== Section::TYPE_SINGLE) {
            return;
        }

        $response = Craft::$app->getResponse();
        /** @var CpScreenResponseBehavior|null $behavior */
        $behavior = $response->getBehavior(CpScreenResponseBehavior::NAME);
        if (!$behavior) {
            return;
        }

        $currentSectionUid = $section->uid;
        $currentSiteId = $element->siteId;

        // Hide the right-hand meta sidebar if this section is listed in the
        // plugin settings' hideSidebarSections array.
        /** @var Settings $settings */
        $settings = $this->getSettings();
        if (in_array($section->uid, $settings->hideSidebarSections, true)) {
            $behavior->metaSidebarHtml = '';
        }

        // Fix the breadcrumb: Section::getPage() looks for the 'singles' source key
        // which our plugin replaced with 'single:{uid}' keys, so it always returns null
        // and the crumb falls back to "Entries". We find the correct page ourselves and
        // override the first crumb accordingly.
        $originalCrumbs = is_callable($behavior->crumbs)
            ? ($behavior->crumbs)()
            : ($behavior->crumbs ?? []);

        $behavior->crumbs = function () use ($originalCrumbs, $currentSectionUid) {
            $elementSourcesService = Craft::$app->getElementSources();
            $currentPage = null;
            foreach ($elementSourcesService->getSources(Entry::class) as $src) {
                if (($src['key'] ?? null) === 'single:' . $currentSectionUid) {
                    $currentPage = $src['page'] ?? null;
                    break;
                }
            }

            $pageLabel = $currentPage ?? 'Entries';
            $pageUrl = 'content/' . ($currentPage ? StringHelper::toKebabCase($currentPage) : 'entries');

            $crumbs = $originalCrumbs;
            if (!empty($crumbs)) {
                $crumbs[0] = [
                    'label' => Craft::t('site', $pageLabel),
                    'url' => UrlHelper::cpUrl($pageUrl),
                ];
            } else {
                $crumbs = [[
                    'label' => Craft::t('site', $pageLabel),
                    'url' => UrlHelper::cpUrl($pageUrl),
                ]];
            }
            return $crumbs;
        };

        $behavior->pageSidebarHtml = function () use ($currentSectionUid, $currentSiteId) {
            $user = Craft::$app->getUser();
            $elementSourcesService = Craft::$app->getElementSources();

            // Get all sources so we can find which page the current single is on.
            $allSources = $elementSourcesService->getSources(Entry::class);

            // Determine the page (Craft 5.9+) the current single belongs to.
            $currentPage = null;
            foreach ($allSources as $src) {
                if (($src['key'] ?? null) === 'single:' . $currentSectionUid) {
                    $currentPage = $src['page'] ?? null;
                    break;
                }
            }

            // Filter to the current page's sources. We do this manually rather than
            // relying on getSources(page:) because Craft's built-in filter checks
            // isset($sources[0]['page']) after array_filter removes disabled sources
            // (preserving keys), so when source 0 ('*') is disabled the check silently
            // falls through and returns all sources.
            if ($currentPage !== null) {
                $pageNameId = $elementSourcesService->pageNameId($currentPage);
                $sidebarSources = array_values(array_filter(
                    $allSources,
                    fn(array $src) => isset($src['page']) &&
                        $elementSourcesService->pageNameId($src['page']) === $pageNameId,
                ));
            } else {
                $sidebarSources = $allSources;
            }

            // If there's only one non-heading source on this page, the sidebar
            // is redundant — skip rendering it entirely.
            $nonHeadingCount = count(array_filter($sidebarSources, fn($s) => ($s['type'] ?? '') !== 'heading'));
            if ($nonHeadingCount <= 1) {
                return null;
            }

            // Build uid → URL map for all editable sections.
            // For singles: compute URL from project config (same approach as _expandSingles,
            // to avoid Section::getPage() issues). For channels/structures: use getCpIndexUri().
            $singleSections = Craft::$app->getEntries()->getSectionsByType(Section::TYPE_SINGLE);
            $singleEntries = Entry::find()
                ->sectionId(ArrayHelper::getColumn($singleSections, 'id'))
                ->siteId($currentSiteId)
                ->status(null)
                ->indexBy('sectionId')
                ->all();

            $pageByUid = [];
            $sourceConfigs = Craft::$app->getProjectConfig()->get('elementSources.' . Entry::class) ?? [];
            foreach ($sourceConfigs as $src) {
                $key = $src['key'] ?? '';
                if (str_starts_with($key, 'single:')) {
                    $pageByUid[substr($key, 7)] = $src['page'] ?? null;
                }
            }

            $sectionUrlMap = [];
            $editableSingleSections = [];
            foreach (Craft::$app->getEntries()->getEditableSections() as $s) {
                if (!$user->checkPermission('viewEntries:' . $s->uid)) {
                    continue;
                }
                if ($s->type === Section::TYPE_SINGLE) {
                    $entry = $singleEntries[$s->id] ?? null;
                    if ($entry) {
                        $page = $pageByUid[$s->uid] ?? null;
                        $pagePath = $page ? StringHelper::toKebabCase($page) : 'entries';
                        $slug = $entry->slug && !str_starts_with($entry->slug, '__') ? '-' . str_replace('/', '-', $entry->slug) : '';
                        $sectionUrlMap[$s->uid] = UrlHelper::cpUrl("content/{$pagePath}/singles/{$entry->id}{$slug}");
                    }
                    $editableSingleSections[$s->handle] = $s;
                } else {
                    $sectionUrlMap[$s->uid] = UrlHelper::cpUrl($s->getCpIndexUri());
                }
            }

            return Craft::$app->getView()->renderTemplate(
                '_singles-manager/singles/_sidebar',
                [
                    'sidebarSources' => $sidebarSources,
                    'sectionUrlMap' => $sectionUrlMap,
                    'editableSingleSections' => $editableSingleSections,
                    'currentSectionUid' => $currentSectionUid,
                ],
                View::TEMPLATE_MODE_CP
            );
        };
    }

    /**
     * Inject a "Hide right sidebar" lightswitch into the native section edit
     * form. Fires after SectionsController::actionEditSection().
     */
    private function _injectSectionSettingsField(ActionEvent $e): void
    {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return;
        }

        // Only show the field for existing single sections.
        // sectionId comes from the URL pattern settings/sections/<sectionId:\d+>
        // — read the last URL segment since route params aren't exposed via getParam().
        $segments = Craft::$app->getRequest()->getSegments();
        $sectionId = end($segments);
        if (!$sectionId) {
            return;
        }

        $section = Craft::$app->getEntries()->getSectionById((int)$sectionId);
        if (!$section || $section->type !== Section::TYPE_SINGLE) {
            return;
        }

        $response = Craft::$app->getResponse();
        /** @var CpScreenResponseBehavior|null $behavior */
        $behavior = $response->getBehavior(CpScreenResponseBehavior::NAME);
        if (!$behavior) {
            return;
        }

        /** @var Settings $settings */
        $settings = $this->getSettings();
        $hideRightSidebar = in_array($section->uid, $settings->hideSidebarSections, true);

        // Wrap the existing contentHtml closure to append our field.

        // Wrap the existing contentHtml closure to append our field.
        $originalContent = $behavior->contentHtml;
        $behavior->contentHtml = function () use ($originalContent, $hideRightSidebar) {
            $html = is_callable($originalContent) ? ($originalContent)() : ($originalContent ?? '');
            $html .= Craft::$app->getView()->renderTemplate(
                '_singles-manager/settings/_section-field',
                ['hideRightSidebar' => $hideRightSidebar],
                View::TEMPLATE_MODE_CP,
            );
            return $html;
        };
    }

    /**
     * Persist the singles-manager section settings posted from the section
     * edit form into the plugin's settings.
     */
    private function _saveSectionSettings(Section $section): void
    {
        if ($section->type !== Section::TYPE_SINGLE) {
            return;
        }

        $request = Craft::$app->getRequest();
        $smParams = $request->getBodyParam('singlesManager');
        if ($smParams === null) {
            // Not posted from our form (e.g. programmatic save) — leave as-is.
            return;
        }

        /** @var Settings $settings */
        $settings = $this->getSettings();
        $hidden = $settings->hideSidebarSections;

        $shouldHide = !empty($smParams['hideRightSidebar']);

        if ($shouldHide && !in_array($section->uid, $hidden, true)) {
            $hidden[] = $section->uid;
        } elseif (!$shouldHide) {
            $hidden = array_values(array_filter($hidden, fn($uid) => $uid !== $section->uid));
        }

        $settings->hideSidebarSections = $hidden;
        Craft::$app->getPlugins()->savePluginSettings($this, $settings->toArray());
    }

    /**
     * Rewrite CP nav item URLs for any page that contains only one source and
     * that source is a single entry. Instead of linking to the element index,
     * the nav item links directly to the single's edit form.
     */
    private function _fixNavLinks(RegisterCpNavItemsEvent $e): void
    {
        $elementSourcesService = Craft::$app->getElementSources();
        $allSources = $elementSourcesService->getSources(Entry::class);
        $pages = $elementSourcesService->getPages(Entry::class);

        if (empty($pages)) {
            return;
        }

        // Read page → single URL map from project config (avoids Section::getPage() recursion).
        $pageByUid = [];
        $sourceConfigs = Craft::$app->getProjectConfig()->get('elementSources.' . Entry::class) ?? [];
        foreach ($sourceConfigs as $src) {
            $key = $src['key'] ?? '';
            if (str_starts_with($key, 'single:')) {
                $pageByUid[substr($key, 7)] = $src['page'] ?? null;
            }
        }

        // For each page, check if ALL non-heading sources are singles.
        // If so, rewrite the nav URL to go directly to the first single's edit form
        // (analogous to how /globals links straight to the first global set).
        foreach ($pages as $page) {
            $pageNameId = $elementSourcesService->pageNameId($page);
            $pageSources = array_values(array_filter(
                $allSources,
                fn($src) => isset($src['page']) &&
                    $elementSourcesService->pageNameId($src['page']) === $pageNameId,
            ));

            $nonHeadings = array_values(array_filter($pageSources, fn($s) => ($s['type'] ?? '') !== 'heading'));
            if (empty($nonHeadings)) {
                continue;
            }

            // Skip this page if it contains any non-single source (channel, structure, custom).
            $allAreSingles = array_reduce($nonHeadings, fn(bool $carry, array $s) =>
                $carry && str_starts_with($s['key'] ?? '', 'single:'), true);
            if (!$allAreSingles) {
                continue;
            }

            // Link to the first single on this page.
            $firstUid = substr($nonHeadings[0]['key'], 7);
            $singleUrl = $this->_buildSingleUrl($firstUid);
            if (!$singleUrl) {
                continue;
            }

            // Find the nav item for this page and rewrite its URL.
            $pageSlug = StringHelper::toKebabCase($page);
            foreach ($e->navItems as &$item) {
                if (($item['url'] ?? '') === "content/$pageSlug") {
                    $item['url'] = $singleUrl;
                    break;
                }
            }
            unset($item);
        }
    }

    /**
     * Build a single entry's CP edit URL using project config page data,
     * avoiding Section::getPage() which would cause recursive source rebuilds.
     */
    private function _buildSingleUrl(string $sectionUid): ?string
    {
        $section = null;
        foreach (Craft::$app->getEntries()->getSectionsByType(Section::TYPE_SINGLE) as $s) {
            if ($s->uid === $sectionUid) {
                $section = $s;
                break;
            }
        }

        if (!$section) {
            return null;
        }

        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $entry = Entry::find()->sectionId($section->id)->siteId($currentSiteId)->status(null)->one();
        if (!$entry) {
            return null;
        }

        $sourceConfigs = Craft::$app->getProjectConfig()->get('elementSources.' . Entry::class) ?? [];
        $page = null;
        foreach ($sourceConfigs as $src) {
            if (($src['key'] ?? '') === 'single:' . $sectionUid) {
                $page = $src['page'] ?? null;
                break;
            }
        }

        $pagePath = $page ? StringHelper::toKebabCase($page) : 'entries';
        $slug = $entry->slug && !str_starts_with($entry->slug, '__') ? '-' . str_replace('/', '-', $entry->slug) : '';
        return UrlHelper::cpUrl("content/{$pagePath}/singles/{$entry->id}{$slug}");
    }
}
