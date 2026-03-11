<?php

namespace thupsi\singlesmanager\services;

use Craft;
use craft\controllers\ElementsController;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\Section;
use craft\web\CpScreenResponseBehavior;
use craft\web\View;
use thupsi\singlesmanager\helpers\SingleUrlHelper;
use thupsi\singlesmanager\models\Settings;
use thupsi\singlesmanager\Plugin;
use yii\base\ActionEvent;
use yii\base\Component;

class SidebarInjector extends Component
{
    /**
     * Inject a globals-like left-sidebar nav into the standard Craft element
     * editor whenever a single entry is being edited.
     */
    public function injectSidebar(ActionEvent $e): void
    {
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

        $sectionSourceKey = $section->type === Section::TYPE_SINGLE
            ? 'single:' . $section->uid
            : 'section:' . $section->uid;

        $sourceConfigs = Craft::$app->getProjectConfig()->get('elementSources.' . Entry::class) ?? [];
        $sourceDisabled = false;
        foreach ($sourceConfigs as $src) {
            if (($src['key'] ?? null) === $sectionSourceKey) {
                $sourceDisabled = !empty($src['disabled']);
                break;
            }
        }

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $breadcrumbSourceKey = $settings->breadcrumbSourceKeys[$section->uid] ?? null;

        if ($sourceDisabled) {
            $this->handleDisabledSource($section, $element, $settings, $breadcrumbSourceKey);
            return;
        }

        // Apply "hide right sidebar" for all section types (enabled sources).
        $response = Craft::$app->getResponse();
        /** @var CpScreenResponseBehavior|null $behavior */
        $behavior = $response->getBehavior(CpScreenResponseBehavior::NAME);
        if ($behavior && in_array($section->uid, $settings->hideSidebarSections, true)) {
            $originalPrepareScreen = $behavior->prepareScreen;
            $behavior->prepareScreen = function ($response, $containerId) use ($originalPrepareScreen) {
                if ($originalPrepareScreen) {
                    ($originalPrepareScreen)($response, $containerId);
                }
                $response->getBehavior(CpScreenResponseBehavior::NAME)->metaSidebarHtml = '';
            };
        }

        // Sidebar injection and breadcrumb fix below are only for singles.
        if ($section->type !== Section::TYPE_SINGLE) {
            return;
        }

        if (!$behavior) {
            return;
        }

        $currentSectionUid = $section->uid;
        $currentSiteId = $element->siteId;

        // Stay on the single's edit form after saving (instead of going to the entries index).
        $behavior->redirectUrl = '{cpEditUrl}';

        // Fix the breadcrumb: Section::getPage() looks for the 'singles' source key
        // which our plugin replaced with 'single:{uid}' keys, so it always returns null
        // and the crumb falls back to "Entries". We find the correct page ourselves.
        $originalCrumbs = is_callable($behavior->crumbs)
            ? ($behavior->crumbs)()
            : ($behavior->crumbs ?? []);

        $currentPage = null;
        foreach (Craft::$app->getElementSources()->getSources(Entry::class) as $src) {
            if (($src['key'] ?? null) === 'single:' . $currentSectionUid) {
                $currentPage = $src['page'] ?? null;
                break;
            }
        }

        $behavior->crumbs = function () use ($originalCrumbs, $currentPage) {
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
            return $this->renderPageSidebar($currentSectionUid, $currentSiteId);
        };
    }

    private function handleDisabledSource(
        Section $section,
        Entry $element,
        Settings $settings,
        ?string $breadcrumbSourceKey,
    ): void {
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

        $behavior->crumbs = function () use ($breadcrumbSourceKey, $element) {
            $elementSourcesService = Craft::$app->getElementSources();
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
    }

    private function renderPageSidebar(string $currentSectionUid, int $currentSiteId): ?string
    {
        $user = Craft::$app->getUser();
        $elementSourcesService = Craft::$app->getElementSources();

        $allSources = $elementSourcesService->getSources(Entry::class);

        $currentPage = null;
        foreach ($allSources as $src) {
            if (($src['key'] ?? null) === 'single:' . $currentSectionUid) {
                $currentPage = $src['page'] ?? null;
                break;
            }
        }

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

        $nonHeadingCount = count(array_filter($sidebarSources, fn($s) => ($s['type'] ?? '') !== 'heading'));
        if ($nonHeadingCount <= 1) {
            return null;
        }

        $singleSections = Craft::$app->getEntries()->getSectionsByType(Section::TYPE_SINGLE);
        $singleEntries = SingleUrlHelper::fetchSingleEntries($singleSections, $currentSiteId);
        $pageByUid = SingleUrlHelper::getPagesByUid();

        $sectionUrlMap = [];
        $editableSingleSections = [];
        foreach (Craft::$app->getEntries()->getEditableSections() as $s) {
            if (!$user->checkPermission('viewEntries:' . $s->uid)) {
                continue;
            }
            if ($s->type === Section::TYPE_SINGLE) {
                $entry = $singleEntries[$s->id] ?? null;
                if ($entry) {
                    $sectionUrlMap[$s->uid] = SingleUrlHelper::buildEditUrl($entry, $pageByUid[$s->uid] ?? null);
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
            View::TEMPLATE_MODE_CP,
        );
    }
}
