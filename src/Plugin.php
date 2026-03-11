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
use craft\web\twig\variables\Cp as CpVariable;
use craft\web\UrlManager;
use thupsi\singlesmanager\base\PluginTrait;
use thupsi\singlesmanager\models\Settings;
use yii\base\ActionEvent;
use yii\base\Controller;
use yii\base\Event;

/**
 * Singleton plugin.
 *
 * 1. Expands single sections as individual sources in the entry sources sidebar.
 * 2. Injects a globals-like left-sidebar nav into the standard Craft element
 *    editor whenever a single entry is being edited — giving you all native
 *    Craft features (drafts, preview, right-hand meta sidebar, etc.) while
 *    keeping a persistent section nav on the left.
 */
class Plugin extends BasePlugin
{
    use PluginTrait;

    public function init(): void
    {
        parent::init();

        // Expand singles in the entry element sources
        Event::on(Entry::class, Element::EVENT_REGISTER_SOURCES, function (RegisterElementSourcesEvent $event) {
            $this->getSourceExpander()->expandSources($event);
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
            $this->getSidebarInjector()->injectSidebar($e);
        });

        // Rewrite CP nav URLs for pages that contain only a single section so
        // clicking the nav item goes directly to that single's edit form.
        Event::on(CpVariable::class, CpVariable::EVENT_REGISTER_CP_NAV_ITEMS, function (RegisterCpNavItemsEvent $e) {
            $this->getNavRewriter()->rewriteNavLinks($e);
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
        // when the form is saved.
        Event::on(SectionsController::class, Controller::EVENT_AFTER_ACTION, function (ActionEvent $e) {
            if ($e->action->id === 'edit-section') {
                $this->getSectionSettings()->injectSettingsField($e);
            } elseif ($e->action->id === 'save-section') {
                $this->getSectionSettings()->handleSave();
            }
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
