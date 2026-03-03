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
        // direct cpEditUrl (used by the JS redirect).
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $singleEntries = Entry::find()
            ->sectionId(ArrayHelper::getColumn($singleSections, 'id'))
            ->siteId($currentSiteId)
            ->status(null)
            ->indexBy('sectionId')
            ->all();

        foreach ($singleSections as $section) {
            if (!Craft::$app->getUser()->checkPermission('viewEntries:' . $section->uid)) {
                continue;
            }

            $entry = $singleEntries[$section->id] ?? null;
            $editUrl = $entry?->getCpEditUrl() ?? UrlHelper::cpUrl('singles/' . $section->handle);

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

        $behavior->pageSidebarHtml = function () use ($currentSectionUid, $currentSiteId) {
            $user = Craft::$app->getUser();
            $elementSourcesService = Craft::$app->getElementSources();

            // Determine the page (Craft 5.9+) the current single belongs to.
            $pages = $elementSourcesService->getPages(Entry::class);
            $currentPage = null;
            if (!empty($pages)) {
                foreach ($elementSourcesService->getSources(Entry::class) as $src) {
                    if (($src['key'] ?? null) === 'single:' . $currentSectionUid) {
                        $currentPage = $src['page'] ?? null;
                        break;
                    }
                }
            }

            $sidebarSources = $elementSourcesService->getSources(Entry::class, page: $currentPage);

            // Build uid → URL map for all editable sections.
            // For singles we need the entry's cpEditUrl; for everything else
            // we use the section's index URI.
            $singleSections = Craft::$app->getEntries()->getSectionsByType(Section::TYPE_SINGLE);
            $singleEntries = Entry::find()
                ->sectionId(ArrayHelper::getColumn($singleSections, 'id'))
                ->siteId($currentSiteId)
                ->status(null)
                ->indexBy('sectionId')
                ->all();

            $sectionUrlMap = [];
            $editableSingleSections = [];
            foreach (Craft::$app->getEntries()->getEditableSections() as $s) {
                if (!$user->checkPermission('viewEntries:' . $s->uid)) {
                    continue;
                }
                if ($s->type === Section::TYPE_SINGLE) {
                    $entry = $singleEntries[$s->id] ?? null;
                    if ($entry) {
                        $sectionUrlMap[$s->uid] = $entry->getCpEditUrl();
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
                    'pages' => $pages,
                    'currentPage' => $currentPage,
                    'currentSectionUid' => $currentSectionUid,
                ],
                View::TEMPLATE_MODE_CP
            );
        };
    }
}
