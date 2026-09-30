<?php

namespace webdna\typesensesync\tests\unit;

use Codeception\Test\Unit;
use Craft;
use modules\search\formatters\ArticleFormatter;
use modules\search\formatters\CategoryFormatter;
use modules\search\formatters\ContentFormatter;
use modules\search\formatters\ProductFormatter;
use modules\search\formatters\UserFormatter;
use webdna\typesensesync\formatters\FormatterInterface;
use webdna\typesensesync\formatters\SchemaContext;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\tests\Support\Examples;
use webdna\typesensesync\TypesenseSync;

/**
 * The copy-in examples (task 8.1): every config validates as a developer would copy it, alone
 * and with each snippet merged in (BR-4, BR-5); nothing is indexed that the base does not
 * declare (BR-1); and the example Twig renders, before and after the plugin is set up (BR-20).
 * TS-2, TS-9 and the joins example run against the real server in the integration suite.
 */
class ExamplesTest extends Unit
{
    protected function _after(): void
    {
        TypesenseSync::getInstance()->targets->setSettings(null);
    }

    public function testTheBaseExampleDeclaresOneSectionAndHasNoProblems(): void
    {
        $config = Examples::config('typesense-sync');
        $settings = new Settings($config);

        $this->assertSame([], $settings->getProblems($config));
        $this->assertSame([], $settings->getWarnings());
        $this->assertSame(['content'], array_keys($settings->getCollectionConfigs()));
        $this->assertSame(['news'], array_map(fn($source) => $source->handle, $settings->getSourceConfigs()));
        $this->assertNotNull($settings->resolveTarget('section', 'news'));
        $this->assertNull($settings->resolveTarget('section', 'pages'), 'an undeclared section resolves to nothing (BR-1)');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function snippets(): array
    {
        return array_combine(Examples::SNIPPETS, array_map(fn($name) => [$name], Examples::SNIPPETS));
    }

    /**
     * @dataProvider snippets
     */
    public function testEachSnippetMergedIntoTheBaseHasNoProblems(string $snippet): void
    {
        $config = Examples::merged($snippet);

        $this->assertSame([], (new Settings($config))->getProblems($config));
    }

    public function testEverySnippetTogetherHasNoProblems(): void
    {
        $config = Examples::merged(...Examples::SNIPPETS);
        $settings = new Settings($config);

        $this->assertSame([], $settings->getProblems($config));
        $this->assertSame(['content', 'people', 'topics', 'products'], array_keys($settings->getCollectionConfigs()));
    }

    public function testTheProductsSnippetOnlyWarnsWithoutCommerce(): void
    {
        $settings = new Settings(Examples::merged('products'));

        $this->assertCount(1, $settings->getWarnings(), 'BR-5: a warning, not an error');
        $this->assertNull($settings->resolveTarget('productType', 'clothing'));
    }

    public function testTheAnalyticsSnippetDeclaresThreeRulesAndACounter(): void
    {
        $settings = new Settings(Examples::merged('analytics'));

        $this->assertSame(['popular', 'noResults', 'views'], array_keys($settings->getAnalyticsRules()));
        $this->assertSame(['popularity'], $settings->getCounterFields('content'));
    }

    public function testTheJoinsExampleReferencesPeopleAndHasNoProblems(): void
    {
        $config = Examples::config('joins');
        $settings = new Settings($config);

        $this->assertSame([], $settings->getProblems($config));
        $this->assertSame(['people', 'content'], array_keys($settings->getCollectionConfigs()), 'the joined-to collection comes first');

        $fields = array_column((new ArticleFormatter())->schema(new SchemaContext('content', ['people' => 'prod_people'])), null, 'name');
        $this->assertSame('prod_people.id', $fields['authorId']['reference']);
        $this->assertFalse($fields['authorId']['cascade_delete'], 'removing a person must not delete what they wrote');
    }

    public function testEveryExampleFormatterDeclaresOptionalExtrasOnly(): void
    {
        $base = ['title', 'type', 'url', 'priority', 'postDate', 'expiryDate', 'keywords'];

        foreach ([ContentFormatter::class, ArticleFormatter::class, UserFormatter::class, CategoryFormatter::class, ProductFormatter::class] as $class) {
            $formatter = new $class();
            $this->assertInstanceOf(FormatterInterface::class, $formatter);
            $fields = $formatter->schema(new SchemaContext('content', ['people' => 'people']));
            $names = array_column($fields, 'name');
            $this->assertSame($names, array_unique($names), "$class declares a field twice");

            // Several formatters share a collection, so a field only one of them writes must be
            // optional or every other document is rejected.
            foreach ($fields as $field) {
                if (!in_array($field['name'], $base, true)) {
                    $this->assertTrue($field['optional'] ?? false, "$class: {$field['name']} is not optional");
                }
            }
        }
    }

    public function testTheSearchTemplateRendersAKeyAndNeverTheAdminKey(): void
    {
        TypesenseSync::getInstance()->targets->setSettings(new Settings(Examples::config('typesense-sync') + [
            'host' => 'search.example.test',
            'port' => '443',
            'protocol' => 'https',
            'apiKey' => 'admin-key-never-rendered',
            'searchApiKey' => 'search-only-key',
            'collectionPrefix' => 'prod_',
        ]));

        $html = $this->render('alpine/search.twig');

        $this->assertStringContainsString('data-typesense-config="', $html);
        $this->assertStringContainsString('prod_content', $html);
        $this->assertStringContainsString('&quot;apiKey&quot;', $html);
        $this->assertStringNotContainsString('admin-key-never-rendered', $html);
        $this->assertStringNotContainsString('search-only-key', $html, 'the key is scoped, never the raw search key');
    }

    public function testTheExampleTemplatesRenderBeforeThePluginIsSetUp(): void
    {
        TypesenseSync::getInstance()->targets->setSettings(new Settings());

        $this->assertStringContainsString('data-typesense-config="null"', $this->render('alpine/search.twig'));
        $this->assertStringNotContainsString('typesenseViewCounter', $this->render('alpine/view-counter.twig', ['entry' => ['id' => 1]]));
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $example, array $variables = []): string
    {
        return Craft::$app->getView()->renderString((string)file_get_contents(Examples::path($example)), $variables);
    }
}
