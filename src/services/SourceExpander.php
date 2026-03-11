<?php

namespace thupsi\singlesmanager\services;

use Craft;
use craft\elements\Entry;
use craft\events\RegisterElementSourcesEvent;
use craft\helpers\UrlHelper;
use craft\models\Section;
use thupsi\singlesmanager\assetbundles\SinglesManagerAsset;
use thupsi\singlesmanager\helpers\SingleUrlHelper;
use yii\base\Component;

class SourceExpander extends Component
{
    /**
     * Expand the collapsed "Singles" source into individual per-section sources.
     */
    public function expandSources(RegisterElementSourcesEvent $event): void
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
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $singleEntries = SingleUrlHelper::fetchSingleEntries($singleSections, $currentSiteId);
        $pageByUid = SingleUrlHelper::getPagesByUid();

        foreach ($singleSections as $section) {
            if (!Craft::$app->getUser()->checkPermission('viewEntries:' . $section->uid)) {
                continue;
            }

            $entry = $singleEntries[$section->id] ?? null;

            if ($entry) {
                $editUrl = SingleUrlHelper::buildEditUrl($entry, $pageByUid[$section->uid] ?? null);
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
}
