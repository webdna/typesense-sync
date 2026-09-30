<?php

namespace webdna\typesensesync\controllers;

use Craft;
use craft\web\Controller;
use webdna\typesensesync\TypesenseSync;
use yii\web\Response;

/**
 * Actions the plugin's settings screen calls. CP-only, admin-only, POST (BR-23).
 *
 * @since 1.0.0
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireCpRequest();
        // Testing changes nothing, so it does not need allowAdminChanges.
        $this->requireAdmin(false);

        return parent::beforeAction($action);
    }

    /**
     * Tests the connection details as they stand in the settings form, saved or not (AC-1).
     *
     * Always answers 200 with `{ok, version, problems}`: a failed test is a result, not an error.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $plugin = TypesenseSync::getInstance();
        assert($plugin !== null);

        $posted = $this->request->getBodyParam('settings');
        $locked = array_keys(Craft::$app->getConfig()->getConfigFromFile(TypesenseSync::HANDLE));
        $settings = $plugin->getSettings()->withConnection(is_array($posted) ? $posted : [], $locked);

        return $this->asJson($plugin->client->testConnection($settings));
    }
}
