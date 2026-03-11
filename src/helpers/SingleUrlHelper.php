<?php

namespace thupsi\singlesmanager\helpers;

use Craft;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\Section;

/**
 * Shared URL-building utilities for single entries.
 *
 * Centralizes the pattern of reading project config for `single:{uid}` page
 * assignments and building `content/{pagePath}/singles/{id}{slug}` URLs,
 * which was previously duplicated in three places.
 */
class SingleUrlHelper
{
    /**
     * Read project config and return a sectionUid => pageName map for singles.
     *
     * @return array<string, string|null>
     */
    public static function getPagesByUid(): array
    {
        $pageByUid = [];
        $sourceConfigs = Craft::$app->getProjectConfig()->get('elementSources.' . Entry::class) ?? [];
        foreach ($sourceConfigs as $src) {
            $key = $src['key'] ?? '';
            if (str_starts_with($key, 'single:')) {
                $pageByUid[substr($key, 7)] = $src['page'] ?? null;
            }
        }
        return $pageByUid;
    }

    /**
     * Fetch all single entries indexed by sectionId.
     *
     * @param Section[] $sections
     * @return array<int, Entry>
     */
    public static function fetchSingleEntries(array $sections, int $siteId): array
    {
        return Entry::find()
            ->sectionId(ArrayHelper::getColumn($sections, 'id'))
            ->siteId($siteId)
            ->status(null)
            ->indexBy('sectionId')
            ->all();
    }

    /**
     * Build a CP edit URL for a single entry.
     */
    public static function buildEditUrl(Entry $entry, ?string $pageName = null): string
    {
        $pagePath = $pageName ? StringHelper::toKebabCase($pageName) : 'entries';
        $slug = $entry->slug && !str_starts_with($entry->slug, '__')
            ? '-' . str_replace('/', '-', $entry->slug)
            : '';
        return UrlHelper::cpUrl("content/{$pagePath}/singles/{$entry->id}{$slug}");
    }

    /**
     * Look up a single section by UID, find its entry, and build the edit URL.
     */
    public static function buildEditUrlByUid(string $sectionUid, ?int $siteId = null): ?string
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

        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $entry = Entry::find()
            ->sectionId($section->id)
            ->siteId($siteId)
            ->status(null)
            ->one();

        if (!$entry) {
            return null;
        }

        $pages = self::getPagesByUid();
        return self::buildEditUrl($entry, $pages[$sectionUid] ?? null);
    }
}
