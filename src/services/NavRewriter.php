<?php

namespace thupsi\singlesmanager\services;

use Craft;
use craft\elements\Entry;
use craft\events\RegisterCpNavItemsEvent;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use thupsi\singlesmanager\helpers\SingleUrlHelper;
use yii\base\Component;

class NavRewriter extends Component
{
    /**
     * Rewrite CP nav item URLs so each page links directly to its first source.
     * If the first source is a single entry, the nav item links to its edit form.
     * If it's a channel/structure section, it links to the section index.
     * If it's a custom source, it links to the page index (Craft's default behaviour).
     */
    public function rewriteNavLinks(RegisterCpNavItemsEvent $e): void
    {
        $elementSourcesService = Craft::$app->getElementSources();
        $allSources = $elementSourcesService->getSources(Entry::class);
        $pages = $elementSourcesService->getPages(Entry::class);

        if (empty($pages)) {
            return;
        }

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
                $url = SingleUrlHelper::buildEditUrlByUid($uid);
            } elseif (str_starts_with($firstKey, 'section:')) {
                $uid = substr($firstKey, 8);
                $section = $sectionsByUid[$uid] ?? null;
                if ($section) {
                    $url = UrlHelper::cpUrl($section->getCpIndexUri());
                }
            }

            if (!$url) {
                continue;
            }

            $pageSlug = StringHelper::toKebabCase($page);
            foreach ($e->navItems as &$item) {
                if (($item['url'] ?? '') === "content/$pageSlug") {
                    $item['linkAttributes'] = array_merge($item['linkAttributes'] ?? [], [
                        'href' => UrlHelper::cpUrl($url),
                    ]);
                    break;
                }
            }
            unset($item);
        }
    }
}
