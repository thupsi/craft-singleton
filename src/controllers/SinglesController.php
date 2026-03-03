<?php

namespace thupsi\singlesmanager\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\models\Section;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class SinglesController extends Controller
{
    /**
     * Redirect to the first editable single's entry edit page.
     */
    public function actionIndex(): Response
    {
        foreach (Craft::$app->getEntries()->getSectionsByType(Section::TYPE_SINGLE) as $section) {
            if (Craft::$app->getUser()->checkPermission('viewEntries:' . $section->uid)) {
                return $this->redirect('singles/' . $section->handle);
            }
        }

        throw new ForbiddenHttpException('User not permitted to edit any singles.');
    }

    /**
     * Redirect `singles/{handle}` to the canonical single entry's standard
     * Craft edit URL. The left-sidebar nav is injected there automatically
     * by the plugin's EVENT_AFTER_ACTION listener.
     */
    public function actionRedirect(string $sectionHandle): Response
    {
        $site = Cp::requestedSite();
        if (!$site) {
            throw new ForbiddenHttpException('User not permitted to edit content in any sites.');
        }

        $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
        if (!$section || $section->type !== Section::TYPE_SINGLE) {
            throw new NotFoundHttpException("Single section not found: $sectionHandle");
        }

        if (!Craft::$app->getUser()->checkPermission('viewEntries:' . $section->uid)) {
            throw new ForbiddenHttpException('User not permitted to edit this single.');
        }

        $entry = Entry::find()
            ->sectionId($section->id)
            ->siteId($site->id)
            ->status(null)
            ->one();

        if (!$entry) {
            throw new NotFoundHttpException('Single entry not found.');
        }

        return $this->redirect($entry->getCpEditUrl());
    }
}
