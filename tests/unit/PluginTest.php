<?php

namespace webdna\typesensesync\tests\unit;

use Codeception\Test\Unit;
use Craft;
use webdna\typesensesync\TypesenseSync;

/**
 * The plugin installs and boots under Craft's test harness.
 */
class PluginTest extends Unit
{
    public function testPluginIsInstalledAndBooted(): void
    {
        $plugin = Craft::$app->getPlugins()->getPlugin(TypesenseSync::HANDLE);

        $this->assertInstanceOf(TypesenseSync::class, $plugin);
        $this->assertSame($plugin, TypesenseSync::getInstance());
    }

    public function testHandleMatchesComposerMetadata(): void
    {
        $composer = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertSame(TypesenseSync::HANDLE, $composer['extra']['handle']);
        $this->assertSame(TypesenseSync::class, $composer['extra']['class']);
    }
}
