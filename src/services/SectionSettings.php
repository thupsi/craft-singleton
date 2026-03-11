<?php

namespace thupsi\singlesmanager\services;

use Craft;
use craft\elements\Entry;
use craft\models\Section;
use craft\web\CpScreenResponseBehavior;
use craft\web\View;
use thupsi\singlesmanager\models\Settings;
use thupsi\singlesmanager\Plugin;
use yii\base\ActionEvent;
use yii\base\Component;

class SectionSettings extends Component
{
    /**
     * Inject a "Hide right sidebar" lightswitch into the native section edit
     * form. Fires after SectionsController::actionEditSection().
     */
    public function injectSettingsField(ActionEvent $e): void
    {
        $request = Craft::$app->getRequest();
        $segments = $request->getSegments();
        $sectionId = $request->getParam('sectionId') ?? end($segments);
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

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $hidden = $settings->hideSidebarSections;
        $hideRightSidebar = in_array($section->uid, $hidden, true);

        $breadcrumbSourceKeys = $settings->breadcrumbSourceKeys;
        $currentBreadcrumbSourceKey = $breadcrumbSourceKeys[$section->uid] ?? null;

        $ownSourceKey = $section->type === Section::TYPE_SINGLE
            ? 'single:' . $section->uid
            : 'section:' . $section->uid;

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
    public function handleSave(): void
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

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();

        $hidden = $settings->hideSidebarSections;
        $breadcrumbSourceKeys = $settings->breadcrumbSourceKeys;

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

        $settings->hideSidebarSections = $hidden;
        $settings->breadcrumbSourceKeys = $breadcrumbSourceKeys;
        Craft::$app->getPlugins()->savePluginSettings(Plugin::getInstance(), $settings->toArray());
    }
}
