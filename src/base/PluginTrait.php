<?php

namespace thupsi\singlesmanager\base;

use thupsi\singlesmanager\services\NavRewriter;
use thupsi\singlesmanager\services\SectionSettings;
use thupsi\singlesmanager\services\SidebarInjector;
use thupsi\singlesmanager\services\SourceExpander;

/**
 * Registers the plugin's services as Yii components and provides typed getters.
 *
 * @property-read SourceExpander  $sourceExpander
 * @property-read SidebarInjector $sidebarInjector
 * @property-read SectionSettings $sectionSettings
 * @property-read NavRewriter     $navRewriter
 */
trait PluginTrait
{
    public static function config(): array
    {
        return [
            'components' => [
                'sourceExpander' => ['class' => SourceExpander::class],
                'sidebarInjector' => ['class' => SidebarInjector::class],
                'sectionSettings' => ['class' => SectionSettings::class],
                'navRewriter' => ['class' => NavRewriter::class],
            ],
        ];
    }

    public function getSourceExpander(): SourceExpander
    {
        return $this->get('sourceExpander');
    }

    public function getSidebarInjector(): SidebarInjector
    {
        return $this->get('sidebarInjector');
    }

    public function getSectionSettings(): SectionSettings
    {
        return $this->get('sectionSettings');
    }

    public function getNavRewriter(): NavRewriter
    {
        return $this->get('navRewriter');
    }
}
