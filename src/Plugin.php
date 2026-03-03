<?php

namespace thupsi\singlesmanager;

use Craft;
use craft\base\Element;
use craft\base\Plugin as BasePlugin;
use craft\controllers\ElementsController;
use craft\elements\Entry;
use craft\events\RegisterElementSourcesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\Section;
use craft\web\CpScreenResponseBehavior;
use craft\web\UrlManager;
use craft\web\View;
use thupsi\singlesmanager\assetbundles\SinglesManagerAsset;
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

        // Fetch entries for the current site so we can give each source its
        // direct edit URL (used by the JS redirect).
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $singleEntries = Entry::find()
            ->sectionId(ArrayHelper::getColumn($singleSections, 'id'))
            ->siteId($currentSiteId)
            ->status(null)
            ->indexBy('sectionId')
            ->all();

        // Read the page for each single directly from project config. We CANNOT
        // use Section::getPage() here because we are inside EVENT_REGISTER_SOURCES;
        // getPage() calls findSource() which calls getSources() which would try to
        // rebuild sources recursively, giving wrong results.
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

            // Build the CP edit URL directly so we don't depend on Section::getPage().
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
}
