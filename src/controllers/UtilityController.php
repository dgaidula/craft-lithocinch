<?php

namespace iceboxind\lithocinch\controllers;

use Craft;
use craft\web\Controller;
use iceboxind\lithocinch\jobs\BulkOptimizeJob;
use iceboxind\lithocinch\Plugin;
use iceboxind\lithocinch\utilities\SavingsUtility;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * CP actions for the Savings utility. One job: take the utility form's POST,
 * push the bulk-optimization job onto Craft's queue, and bounce back to the
 * utility page with a flash — the queue does the actual work.
 */
class UtilityController extends Controller
{
    /**
     * Queues a bulk optimization run (optionally scoped to one volume).
     *
     * POST litho/utility/optimize
     */
    public function actionOptimize(): Response
    {
        $this->requirePostRequest();
        // Same authorization Craft applies to viewing the utility page itself.
        $this->requirePermission('utility:' . SavingsUtility::id());

        // The utility isn't registered on Lite, but the route still exists —
        // gate the action too so a hand-crafted POST can't run a Pro feature.
        if (!Plugin::$plugin->isPro()) {
            throw new ForbiddenHttpException('Bulk optimization requires Lithocinch Pro.');
        }

        $volumeId = (int)($this->request->getBodyParam('volumeId') ?: 0) ?: null;

        Craft::$app->getQueue()->push(new BulkOptimizeJob([
            'volumeId' => $volumeId,
        ]));

        $this->setSuccessFlash(Craft::t('litho', 'Bulk optimization queued.'));
        return $this->redirectToPostedUrl();
    }
}
