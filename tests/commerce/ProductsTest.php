<?php

namespace webdna\typesensesync\tests\commerce;

use Codeception\Test\Unit;
use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\fieldlayoutelements\ProductTitleField;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as CommercePlugin;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use Typesense\Client as TypesenseClient;
use Typesense\Exceptions\ObjectNotFound;
use webdna\typesensesync\helpers\Commerce;
use webdna\typesensesync\jobs\DeleteElement;
use webdna\typesensesync\jobs\SyncElement;
use webdna\typesensesync\models\Settings;
use webdna\typesensesync\services\Collections;
use webdna\typesensesync\tests\fixtures\formatters\DocumentFormatter;
use webdna\typesensesync\tests\Support\Examples;
use webdna\typesensesync\tests\Support\TestCollections;
use webdna\typesensesync\TypesenseSync;

/**
 * Products with Commerce installed (TS-10 step 2, BR-5): a declared product type is indexed
 * through real saves, deletes, restores and the real queue, and nothing else is.
 *
 * Runs only in the Commerce leg (tests/commerce/run.sh); TS-10 step 1, without Commerce, is in
 * the integration suite. Each test writes into its own collection prefix, deleted afterwards.
 */
class ProductsTest extends Unit
{
    private TypesenseSync $plugin;

    private TypesenseClient $admin;

    private string $prefix;

    /**
     * @var array<string, ProductType>
     */
    private array $types = [];

    protected function _before(): void
    {
        $this->plugin = TypesenseSync::getInstance();
        $this->prefix = 'it' . bin2hex(random_bytes(4)) . '_';
        Craft::$app->getProjectConfig()->writeYamlAutomatically = false;
        Craft::$app->getCache()->flush();

        $this->types['shoes'] = $this->createProductType('shoes');
        $this->types['hats'] = $this->createProductType('hats');
        $this->useSettings($this->settings());
        $this->admin = $this->plugin->client->createClient($this->settings(), 0);

        $applied = $this->plugin->collections->apply('products');
        $this->assertSame(Collections::ACTION_CREATE, $applied['action'], $applied['message']);

        $this->clearQueue();
    }

    protected function _after(): void
    {
        TestCollections::deleteAll($this->admin, $this->prefix);
        $this->plugin->targets->setSettings(null);
        $this->plugin->client->setClient(null);
    }

    public function testWithCommerceAProductsSourceCountsAndIsFollowed(): void
    {
        $this->assertTrue(Commerce::isInstalled(), 'this leg runs with Commerce');
        $this->assertSame([], $this->plugin->targets->getSettings()->getWarnings(), 'no warning once Commerce is there');
        $this->assertContains(Product::class, $this->plugin->targets->getElementTypes());
    }

    public function testAProductOfTheDeclaredTypeFollowsItsLifecycle(): void
    {
        $product = $this->saveProduct('shoes', 'Harbour boot');
        $this->assertSame(1, $this->countJobs(SyncElement::class), 'one save, one job');
        $this->runQueue();

        $document = $this->document($product);
        $this->assertSame('Harbour boot', $document['title'] ?? null, 'TS-10 step 2: document present');
        $this->assertSame($this->types['shoes']->name, $document['type'] ?? null, "the document type is the product type's name");
        $this->assertStringStartsWith('/', (string)($document['url'] ?? ''), 'a root-relative URL');

        $product->title = 'Harbour boot, waxed';
        $this->save($product);
        $this->runQueue();
        $this->assertSame('Harbour boot, waxed', $this->document($product)['title'] ?? null);

        $this->assertTrue(Craft::$app->getElements()->deleteElement($product));
        $this->assertSame(1, $this->countJobs(DeleteElement::class));
        $this->runQueue();
        $this->assertNull($this->document($product));

        $this->assertTrue(Craft::$app->getElements()->restoreElement($product));
        $this->runQueue();
        $this->assertNotNull($this->document($product));
    }

    public function testAProductOfAnUndeclaredTypeQueuesNothing(): void
    {
        $this->saveProduct('hats', 'Fisherman cap');

        $this->assertSame(0, $this->countJobs(SyncElement::class));
    }

    public function testASwitchedOffProductTypeQueuesNothing(): void
    {
        $this->useSettings($this->settings(['enabled' => false]));

        $this->saveProduct('shoes', 'Switched off');

        $this->assertSame(0, $this->countJobs(SyncElement::class));
    }

    public function testDisablingAProductRemovesItsDocument(): void
    {
        $product = $this->saveProduct('shoes', 'Withdrawn');
        $this->runQueue();
        $this->assertNotNull($this->document($product));

        $product->enabled = false;
        $this->save($product);
        $this->assertSame(1, $this->countJobs(SyncElement::class), 'a disabled product is still synced, so its document goes');
        $this->runQueue();

        $this->assertNull($this->document($product));
    }

    public function testAReindexWalksTheDeclaredTypeOnly(): void
    {
        $kept = [$this->saveProduct('shoes', 'One'), $this->saveProduct('shoes', 'Two')];
        $other = $this->saveProduct('hats', 'Elsewhere');
        $this->clearQueue();

        $run = $this->plugin->sync->reindex('products');

        $this->assertSame(2, $run['indexed']);
        foreach ($kept as $product) {
            $this->assertNotNull($this->document($product));
        }
        $this->assertNull($this->document($other));
    }

    /**
     * `examples/config/products.php` and the example ProductFormatter, against Commerce's real
     * classes (task 8.1): its type handle renamed to this run's, and nothing else.
     */
    public function testTheProductsExampleIndexesPriceSkuAndAvailability(): void
    {
        $snippet = Examples::config('products');
        $this->useSettings(new Settings(Examples::withHandles($snippet, ['clothing' => (string)$this->types['shoes']->handle]) + [
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'collectionPrefix' => $this->prefix,
        ]));
        // The example sorts by priority, which the collection _before() made cannot be altered
        // to, so it starts from nothing, as a site copying it would.
        TestCollections::deleteAll($this->admin, $this->prefix);
        $applied = $this->plugin->collections->apply('products');
        $this->assertSame(Collections::ACTION_CREATE, $applied['action'], $applied['message']);

        $product = $this->saveProduct('shoes', 'Harbour boot');
        // The variant saveProduct() sets on the new product is not saved in this harness
        // (Commerce 5.7: no id, no errors), and the other tests need none. This one does, so it
        // saves one as a nested element of the product, then saves the product again.
        $variant = new Variant(['sku' => 'BOOT-' . bin2hex(random_bytes(4)), 'basePrice' => 10, 'ownerId' => $product->id, 'primaryOwnerId' => $product->id]);
        $this->assertTrue(Craft::$app->getElements()->saveElement($variant), implode(' ', $variant->getFirstErrors()));
        $product = Product::find()->id($product->id)->status(null)->one();
        $this->assertInstanceOf(Product::class, $product);
        $this->save($product);
        $this->runQueue();

        $document = $this->document($product) ?? [];
        $this->assertSame('Harbour boot', $document['title'] ?? null);
        $this->assertEquals(10, $document['price'] ?? null, "the default variant's price");
        $this->assertSame($variant->getSku(), $document['sku'] ?? null);
        $this->assertTrue($document['available'] ?? null);
    }

    // Helpers -----------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $shoes
     */
    private function settings(array $shoes = []): Settings
    {
        return new Settings([
            'host' => (string)getenv('TYPESENSE_TEST_HOST'),
            'port' => (string)getenv('TYPESENSE_TEST_PORT'),
            'protocol' => (string)getenv('TYPESENSE_TEST_PROTOCOL'),
            'apiKey' => (string)getenv('TYPESENSE_TEST_API_KEY'),
            'collectionPrefix' => $this->prefix,
            'collections' => ['products' => []],
            'sources' => [$shoes + [
                'kind' => 'productType',
                'handle' => $this->types['shoes']->handle,
                'collection' => 'products',
                'formatter' => DocumentFormatter::class,
            ]],
        ]);
    }

    /**
     * Injects settings and follows whatever element types they declare, as a site booting with
     * that config file would.
     */
    private function useSettings(Settings $settings): void
    {
        $this->assertSame([], $settings->getProblems());
        $this->plugin->targets->setSettings($settings);
        $this->plugin->client->setClient($this->plugin->client->createClient($settings, 0));
        $this->plugin->followElementTypes();
    }

    private function createProductType(string $name): ProductType
    {
        $handle = $name . substr($this->prefix, 2, 8);
        $type = new ProductType([
            'name' => ucfirst($name) . ' ' . $handle,
            'handle' => $handle,
            'hasVariantTitleField' => false,
            'variantTitleFormat' => '{sku}',
        ]);

        $layout = new FieldLayout(['type' => Product::class]);
        $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => [new ProductTitleField()]])]);
        $type->setFieldLayout($layout);

        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $type->setSiteSettings([$siteId => new ProductTypeSite([
            'siteId' => $siteId,
            'hasUrls' => true,
            'uriFormat' => $name . '/{slug}',
            'template' => '_product',
        ])]);

        $this->assertTrue(CommercePlugin::getInstance()->getProductTypes()->saveProductType($type), implode(' ', $type->getFirstErrors()));

        return $type;
    }

    private function saveProduct(string $type, string $title): Product
    {
        $variant = new Variant(['sku' => 'SKU-' . bin2hex(random_bytes(4)), 'basePrice' => 10]);
        $product = new Product([
            'typeId' => $this->types[$type]->id,
            'title' => $title,
            'slug' => ElementHelper::generateSlug($title),
        ]);
        $product->setVariants([$variant]);
        $this->save($product);

        return $product;
    }

    private function save(Product $product): void
    {
        $this->assertTrue(Craft::$app->getElements()->saveElement($product), implode(' ', $product->getFirstErrors()));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function document(Product $product): ?array
    {
        try {
            return $this->admin->collections[$this->prefix . 'products']->documents[(string)$product->id]->retrieve();
        } catch (ObjectNotFound) {
            return null;
        }
    }

    private function runQueue(): void
    {
        $queue = Craft::$app->getQueue();
        $this->assertInstanceOf(\craft\queue\Queue::class, $queue);
        $queue->run();
    }

    private function clearQueue(): void
    {
        Db::delete(Table::QUEUE);
        Craft::$app->getCache()->flush();
    }

    private function countJobs(string $class): int
    {
        return count(array_filter($this->queuedJobs(), fn(object $job) => $job instanceof $class));
    }

    /**
     * @return array<int, object>
     */
    private function queuedJobs(): array
    {
        $rows = (new Query())->select(['job'])->from(Table::QUEUE)->where(['fail' => false])->orderBy('id')->column();

        return array_map(
            fn($job) => Craft::$app->getQueue()->serializer->unserialize(is_resource($job) ? stream_get_contents($job) : $job),
            $rows,
        );
    }
}
