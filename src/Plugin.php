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
use craft\helpers\ArrayHelper;
use craft\helpers\Cp;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\Section;
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

        // After a section is saved, persist any singles-manager POST params.
        // Handled via SectionsController::EVENT_AFTER_ACTION below.

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

        // On element index pages, hide the sources list when there is only
        // one (non-heading) source — it would just show a single item with no value.
        // Keep #source-actions visible so the Customize Sources button remains accessible.
        Craft::$app->getView()->hook('cp.layouts.elementindex', function (array &$context): void {
            $sources = $context['sources'] ?? [];
            $nonHeadings = array_filter($sources, fn($s) => ($s['type'] ?? '') !== 'heading');
            if (count($nonHeadings) <= 1) {
                Craft::$app->getView()->registerCss(
                    '#sidebar-container nav { display: none !important; }'
                );
            }
        });

        // Inject a "Hide right sidebar" lightswitch into the native section
        // edit form (only shown for single sections), and persist the setting
        // when the form is saved (both handled here since we know this event fires).
        Event::on(SectionsController::class, Controller::EVENT_AFTER_ACTION, function (ActionEvent $e) {
            if ($e->action->id === 'edit-section') {
                $this->_injectSectionSettingsField($e);
            } elseif ($e->action->id === 'save-section') {
                $this->_handleSaveSectionAction();
            }
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
        if (!$section) {
            return;
        }

        // Determine the source key for this section.
        $sectionSourceKey = $section->type === Section::TYPE_SINGLE
            ? 'single:' . $section->uid
            : 'section:' . $section->uid;

        // Check whether the source for this section is disabled in element sources config.
        $sourceConfigs = Craft::$app->getProjectConfig()->get('elementSources.' . Entry::class) ?? [];
        $sourceDisabled = false;
        foreach ($sourceConfigs as $src) {
            if (($src['key'] ?? null) === $sectionSourceKey) {
                $sourceDisabled = !empty($src['disabled']);
                break;
            }
        }

        // If the source is disabled and there's a breadcrumb source key stored for
        // this section, fix only the breadcrumb and skip sidebar injection.
        // If disabled with no override, skip entirely (navigated from custom link).
        $settings = $this->getSettings();
        $breadcrumbSourceKey = $settings->breadcrumbSourceKeys[$section->uid] ?? null;

        if ($sourceDisabled) {
            if (!$breadcrumbSourceKey) {
                return;
            }

            $response = Craft::$app->getResponse();
            /** @var CpScreenResponseBehavior|null $behavior */
            $behavior = $response->getBehavior(CpScreenResponseBehavior::NAME);
            if (!$behavior) {
                return;
            }

            // Hide the right-hand meta sidebar if configured for this section.
            $hidden = $settings->hideSidebarSections;
            if (in_array($section->uid, $hidden, true)) {
                $originalPrepareScreen = $behavior->prepareScreen;
                $behavior->prepareScreen = function ($response, $containerId) use ($originalPrepareScreen) {
                    if ($originalPrepareScreen) {
                        ($originalPrepareScreen)($response, $containerId);
                    }
                    $response->getBehavior(CpScreenResponseBehavior::NAME)->metaSidebarHtml = '';
                };
            }

            $behavior->crumbs = function () use ($breadcrumbSourceKey, $element) {                $elementSourcesService = Craft::$app->getElementSources();
                $allSources = $elementSourcesService->getSources(Entry::class, withDisabled: true);
                $currentPage = null;
                $sourceLabel = null;
                foreach ($allSources as $src) {
                    if (($src['key'] ?? null) === $breadcrumbSourceKey) {
                        $currentPage = $src['page'] ?? null;
                        $sourceLabel = $src['label'] ?? null;
                        break;
                    }
                }
                $pageLabel = $currentPage ?? 'Entries';
                $pagePath = 'content/' . ($currentPage ? StringHelper::toKebabCase($currentPage) : 'entries');
                $pageUrl = UrlHelper::cpUrl($pagePath);

                $crumbs = [[
                    'label' => Craft::t('site', $pageLabel),
                    'url' => $pageUrl,
                ]];

                // Only add the source as a second crumb if there are multiple
                // non-heading sources on this page (otherwise it adds no value).
                if ($sourceLabel && $currentPage !== null) {
                    $pageNameId = $elementSourcesService->pageNameId($currentPage);
                    $nonHeadingsOnPage = array_filter(
                        $allSources,
                        fn($s) => ($s['type'] ?? '') !== 'heading'
                            && isset($s['page'])
                            && $elementSourcesService->pageNameId($s['page']) === $pageNameId,
                    );
                    if (count($nonHeadingsOnPage) > 1) {
                        $crumbs[] = [
                            'html' => '<a class="crumb-link singles-manager-crumb-source"'
                                . ' href="' . htmlspecialchars($pageUrl) . '"'
                                . ' data-source-key="' . htmlspecialchars($breadcrumbSourceKey) . '"'
                                . ' data-page-url="' . htmlspecialchars($pageUrl) . '">'
                                . htmlspecialchars(Craft::t('site', $sourceLabel))
                                . '</a>',
                        ];
                    }
                }

                $crumbs[] = [
                    'html' => Cp::elementChipHtml($element, [
                        'showDraftName' => false,
                        'class' => 'chromeless',
                        'hyperlink' => true,
                    ]),
                    'current' => true,
                ];

                return $crumbs;
            };

            // After saving: if the fallback source's page has multiple non-heading
            // sources, redirect to that page; otherwise stay on the edit form.
            $elementSourcesService = Craft::$app->getElementSources();
            $allSources = $elementSourcesService->getSources(Entry::class, withDisabled: true);
            $fallbackPage = null;
            foreach ($allSources as $src) {
                if (($src['key'] ?? null) === $breadcrumbSourceKey) {
                    $fallbackPage = $src['page'] ?? null;
                    break;
                }
            }
            if ($fallbackPage !== null) {
                $pageNameId = $elementSourcesService->pageNameId($fallbackPage);
                $nonHeadingsOnPage = array_filter(
                    $allSources,
                    fn($s) => ($s['type'] ?? '') !== 'heading'
                        && isset($s['page'])
                        && $elementSourcesService->pageNameId($s['page']) === $pageNameId,
                );
                if (count($nonHeadingsOnPage) > 1) {
                    $behavior->redirectUrl = UrlHelper::cpUrl('content/' . StringHelper::toKebabCase($fallbackPage));
                } else {
                    $behavior->redirectUrl = '{cpEditUrl}';
                }
            } else {
                $behavior->redirectUrl = '{cpEditUrl}';
            }

            return;
        }

        // Sidebar injection and breadcrumb fix below are only for singles.
        if ($section->type !== Section::TYPE_SINGLE) {
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

        // Stay on the single's edit form after saving (instead of going to the entries index).
        $behavior->redirectUrl = '{cpEditUrl}';

        // Hide the right-hand meta sidebar if this section is listed in the plugin settings.
        // metaSidebarHtml is set INSIDE the prepareScreen closure (by _prepareEditor), which
        // runs after EVENT_AFTER_ACTION. So we wrap prepareScreen to clear metaSidebarHtml
        // after the original closure runs.
        $hidden = ($this->getSettings())->hideSidebarSections;
        if (in_array($section->uid, $hidden, true)) {
            $originalPrepareScreen = $behavior->prepareScreen;
            $behavior->prepareScreen = function ($response, $containerId) use ($originalPrepareScreen) {
                if ($originalPrepareScreen) {
                    ($originalPrepareScreen)($response, $containerId);
                }
                $response->getBehavior(CpScreenResponseBehavior::NAME)->metaSidebarHtml = '';
            };
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

            $pagePath = $currentPage ? StringHelper::toKebabCase($currentPage) : 'entries';

            return Craft::$app->getView()->renderTemplate(
                '_singles-manager/singles/_sidebar',
                [
                    'sidebarSources' => $sidebarSources,
                    'sectionUrlMap' => $sectionUrlMap,
                    'editableSingleSections' => $editableSingleSections,
                    'currentSectionUid' => $currentSectionUid,
                    'pageIndexUrl' => UrlHelper::cpUrl("content/{$pagePath}"),
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
        // When accessed via CP route (settings/sections/<id>), sectionId is a route
        // param. When opened as a slideout, it's passed as a query param. Both are
        // accessible via getParam().
        $request = Craft::$app->getRequest();
        $sectionId = $request->getParam('sectionId') ?? end($request->getSegments());
        if (!$sectionId) {
            return;
        }

        $section = Craft::$app->getEntries()->getSectionById((int)$sectionId);
        if (!$section) {
            return;
        }

        $response = Craft::$app->getResponse();
        /** @var CpScreenResponseBehavior|null $behavior */
        $behavior = $response->getBehavior(CpScreenResponseBehavior::NAME);
        if (!$behavior) {
            return;
        }

        $hidden = ($this->getSettings())->hideSidebarSections;
        $hideRightSidebar = in_array($section->uid, $hidden, true);

        $breadcrumbSourceKeys = ($this->getSettings())->breadcrumbSourceKeys;
        $currentBreadcrumbSourceKey = $breadcrumbSourceKeys[$section->uid] ?? null;

        // The source key for this section (used to exclude it from the dropdown).
        $ownSourceKey = $section->type === Section::TYPE_SINGLE
            ? 'single:' . $section->uid
            : 'section:' . $section->uid;

        // Collect all non-heading, non-disabled sources as options for the breadcrumb dropdown.
        $allSources = Craft::$app->getElementSources()->getSources(Entry::class, withDisabled: true);
        $breadcrumbSourceOptions = [['label' => '—', 'value' => '']];
        foreach ($allSources as $src) {
            if (($src['type'] ?? '') === 'heading' || !empty($src['disabled'])) {
                continue;
            }
            if (($src['key'] ?? '') === $ownSourceKey) {
                continue;
            }
            $page = $src['page'] ?? null;
            $label = $src['label'] ?? $src['key'];
            if ($page) {
                $label = Craft::t('site', $page) . ' › ' . Craft::t('site', $label);
            }
            $breadcrumbSourceOptions[] = ['label' => $label, 'value' => $src['key']];
        }

        // Wrap the existing contentHtml closure to append our field.
        $originalContent = $behavior->contentHtml;
        $behavior->contentHtml = function () use (
            $originalContent,
            $hideRightSidebar,
            $breadcrumbSourceOptions,
            $currentBreadcrumbSourceKey,
        ) {
            $html = is_callable($originalContent) ? ($originalContent)() : ($originalContent ?? '');
            $html .= Craft::$app->getView()->renderTemplate(
                '_singles-manager/settings/_section-field',
                [
                    'hideRightSidebar' => $hideRightSidebar,
                    'breadcrumbSourceOptions' => $breadcrumbSourceOptions,
                    'currentBreadcrumbSourceKey' => $currentBreadcrumbSourceKey,
                ],
                View::TEMPLATE_MODE_CP,
            );
            return $html;
        };
    }

    /**
     * Persist the singles-manager section settings posted from the section
     * edit form. Called via SectionsController EVENT_AFTER_ACTION for save-section.
     */
    private function _handleSaveSectionAction(): void
    {
        $request = Craft::$app->getRequest();
        $sectionId = $request->getBodyParam('sectionId');
        $smParams = $request->getBodyParam('singlesManager');

        if (!$sectionId || $smParams === null) {
            return;
        }

        $section = Craft::$app->getEntries()->getSectionById((int)$sectionId);
        if (!$section) {
            return;
        }

        $hidden = ($this->getSettings())->hideSidebarSections;
        $breadcrumbSourceKeys = ($this->getSettings())->breadcrumbSourceKeys;

        $shouldHide = !empty($smParams['hideRightSidebar']);

        if ($shouldHide && !in_array($section->uid, $hidden, true)) {
            $hidden[] = $section->uid;
        } elseif (!$shouldHide) {
            $hidden = array_values(array_filter($hidden, fn($uid) => $uid !== $section->uid));
        }

        $breadcrumbKey = $smParams['breadcrumbSourceKey'] ?? '';
        if ($breadcrumbKey !== '') {
            $breadcrumbSourceKeys[$section->uid] = $breadcrumbKey;
        } else {
            unset($breadcrumbSourceKeys[$section->uid]);
        }

        /** @var Settings $settings */
        $settings = $this->getSettings();
        $settings->hideSidebarSections = $hidden;
        $settings->breadcrumbSourceKeys = $breadcrumbSourceKeys;
        Craft::$app->getPlugins()->savePluginSettings($this, $settings->toArray());
    }

    /**
     * Rewrite CP nav item URLs so each page links directly to its first source.
     * If the first source is a single entry, the nav item links to its edit form.
     * If it's a channel/structure section, it links to the section index.
     * If it's a custom source, it links to the page index (Craft's default behaviour).
     */
    private function _fixNavLinks(RegisterCpNavItemsEvent $e): void
    {
        $elementSourcesService = Craft::$app->getElementSources();
        $allSources = $elementSourcesService->getSources(Entry::class);
        $pages = $elementSourcesService->getPages(Entry::class);

        if (empty($pages)) {
            return;
        }

        // Build a uid → Section map for all editable sections.
        $sectionsByUid = [];
        foreach (Craft::$app->getEntries()->getEditableSections() as $s) {
            $sectionsByUid[$s->uid] = $s;
        }

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

            $firstSource = $nonHeadings[0];
            $firstKey = $firstSource['key'] ?? '';
            $url = null;

            if (str_starts_with($firstKey, 'single:')) {
                $uid = substr($firstKey, 7);
                $url = $this->_buildSingleUrl($uid);
            } elseif (str_starts_with($firstKey, 'section:')) {
                $uid = substr($firstKey, 8);
                $section = $sectionsByUid[$uid] ?? null;
                if ($section) {
                    $url = UrlHelper::cpUrl($section->getCpIndexUri());
                }
            }
            // Custom sources and anything else: leave url null → keep default page index URL.

            if (!$url) {
                continue;
            }

            $pageSlug = StringHelper::toKebabCase($page);
            foreach ($e->navItems as &$item) {
                if (($item['url'] ?? '') === "content/$pageSlug") {
                    $item['url'] = $url;
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
