<?php

namespace webdna\typesensesync\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\web\View;
use ReflectionMethod;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Client;
use webdna\typesensesync\tests\fixtures\formatters\NewsFormatter;
use webdna\typesensesync\TypesenseSync;

/**
 * The plugin installs and boots under Craft's test harness, and its settings screen keeps the
 * admin key out of the page (BR-17).
 */
class PluginTest extends Unit
{
    public function testPluginIsInstalledAndBooted(): void
    {
        $plugin = Craft::$app->getPlugins()->getPlugin(TypesenseSync::HANDLE);

        $this->assertInstanceOf(TypesenseSync::class, $plugin);
        $this->assertSame($plugin, TypesenseSync::getInstance());
    }

    public function testSettingsAreTheSettingsModel(): void
    {
        $plugin = TypesenseSync::getInstance();

        $this->assertNotNull($plugin);
        $this->assertInstanceOf(Settings::class, $plugin->getSettings());
        $this->assertTrue($plugin->hasCpSettings);
    }

    public function testClientServiceIsRegistered(): void
    {
        $this->assertInstanceOf(Client::class, TypesenseSync::getInstance()?->client);
    }

    public function testSettingsScreenHasTheTestConnectionButton(): void
    {
        $this->assertStringContainsString('data-ts-action="test-connection"', $this->renderSettings([]));
    }

    public function testSettingsScreenNeverRendersALiteralAdminKey(): void
    {
        $html = $this->renderSettings(['apiKey' => 'admin-secret-1234']);

        $this->assertStringNotContainsString('admin-secret-1234', $html);
        $this->assertStringContainsString('data-attribute="apiKey"', $html);
        $this->assertStringContainsString('placeholder="••••1234"', $html);
    }

    public function testSettingsScreenShowsAnEnvReference(): void
    {
        $this->assertStringContainsString('$TYPESENSE_ADMIN_KEY', $this->renderSettings(['apiKey' => '$TYPESENSE_ADMIN_KEY']));
    }

    public function testSettingsScreenListsConfigProblems(): void
    {
        $html = $this->renderSettings([
            'sources' => [['handle' => 'news', 'collection' => 'nowhere', 'formatter' => NewsFormatter::class]],
        ]);

        $this->assertStringContainsString('data-ts-problems', $html);
        $this->assertStringContainsString('routes to undeclared collection', $html);
    }

    public function testHandleMatchesComposerMetadata(): void
    {
        $composer = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertSame(TypesenseSync::HANDLE, $composer['extra']['handle']);
        $this->assertSame(TypesenseSync::class, $composer['extra']['class']);
    }

    /**
     * Renders the settings screen with the given settings, restoring the originals afterwards.
     *
     * @param array<string, mixed> $attributes
     */
    private function renderSettings(array $attributes): string
    {
        $plugin = TypesenseSync::getInstance();
        $this->assertNotNull($plugin);
        $settings = $plugin->getSettings();
        $original = $settings->getAttributes();

        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
        $settings->setAttributes($attributes, false);

        try {
            return (string)(new ReflectionMethod($plugin, 'settingsHtml'))->invoke($plugin);
        } finally {
            $settings->setAttributes($original, false);
        }
    }
}
