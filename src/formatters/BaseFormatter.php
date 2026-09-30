<?php

namespace webdna\typesensesync\formatters;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\fields\data\SingleOptionFieldData;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTimeInterface;
use Throwable;
use webdna\typesensesync\helpers\Commerce;
use webdna\typesensesync\models\ResolvedTarget;
use webdna\typesensesync\TypesenseSync;

/**
 * The document skeleton every result shares, and helpers for reading fields safely.
 *
 * A site's formatter extends this, returns its own fields from fields(), and declares them by
 * merging onto parent::schema(). Anything a subclass declares should be `optional`: several
 * formatters can write into one collection, and no single document carries every field.
 *
 * Field reads go through fieldValue(), which checks the element's field layout first, so a field
 * taken off a layout reads as null instead of failing a queue job.
 *
 * @since 1.0.0
 */
abstract class BaseFormatter implements FormatterInterface
{
    /**
     * "No expiry" — and, for an entry, "never published": 9999-12-31T23:59:59Z (BR-11).
     *
     * A sentinel rather than an absent field lets every search use one publication-window filter,
     * `postDate:<={now} && expiryDate:>{now}`, with no special case for missing values.
     */
    public const FAR_FUTURE = 253402300799;

    /**
     * Plain text is truncated before indexing: Typesense stores the whole document, and a search
     * snippet does not need a whole body.
     */
    protected const DESCRIPTION_LIMIT = 500;

    /**
     * Harvested keywords are capped, so one long entry cannot bloat the index or an import.
     */
    protected const KEYWORD_LIMIT = 5000;

    /**
     * Field handles never harvested for keywords, whatever their `searchable` setting. A subclass
     * lists fields that must not become searchable by someone ticking a box in the control panel
     * — moderation notes, private documents, an address.
     *
     * @var string[]
     */
    protected const EXCLUDED_KEYWORD_FIELDS = [];

    private ?ResolvedTarget $target = null;

    /**
     * Called by the `targets` service before format(), so a document knows the target it was
     * built for.
     */
    public function setTarget(?ResolvedTarget $target): static
    {
        $this->target = $target;

        return $this;
    }

    public function getTarget(): ?ResolvedTarget
    {
        return $this->target;
    }

    /**
     * Disabled elements leave search; expired and pending ones stay, filtered out at search time.
     *
     * The split follows whether Craft fires an event. Disabling is a save, so the document goes
     * at once. Expiry and a future post date are computed at read time and nothing fires when
     * the clock passes them; removing them here would need a cron, and a cron that stops leaves
     * expired content searchable. So they stay indexed with both timestamps, and every scoped key
     * filters the publication window (BR-18). There is deliberately no `status` field: it would
     * be a snapshot that goes stale for exactly these states.
     */
    public function shouldIndex(ElementInterface $element): bool
    {
        return $element->enabled && $element->getEnabledForSite() !== false;
    }

    /**
     * The base document merged with fields(), with empty values dropped.
     *
     * Typesense rejects null for a typed field, so an optional field must be absent rather than
     * null. False and 0 mean something and are kept.
     */
    public function format(ElementInterface $element): array
    {
        $document = array_merge($this->baseDocument($element), $this->fields($element));

        return array_filter(
            $document,
            static fn($value) => $value !== null && $value !== '' && $value !== [],
        );
    }

    /**
     * The fields every document carries. A subclass merges its own onto these.
     */
    public function schema(SchemaContext $context): array
    {
        return [
            ['name' => 'title', 'type' => 'string', 'sort' => true],
            ['name' => 'type', 'type' => 'string', 'facet' => true],
            ['name' => 'url', 'type' => 'string', 'index' => false, 'optional' => true],
            ['name' => 'priority', 'type' => 'int32'],
            // Required, not optional: the publication-window filter needs both on every document.
            ['name' => 'postDate', 'type' => 'int64', 'sort' => true],
            ['name' => 'expiryDate', 'type' => 'int64', 'sort' => true],
            ['name' => 'keywords', 'type' => 'string', 'optional' => true],
        ];
    }

    /**
     * The document id (BR-11): the element id as a string. Override only with a value that is
     * stable for the element's lifetime, because a delete is addressed by it.
     */
    public function documentId(ElementInterface $element): string
    {
        return (string)$element->id;
    }

    /**
     * Fields specific to this formatter. Null, '' and [] are dropped before indexing, so a value
     * can be returned unconditionally.
     *
     * A field named like a base field replaces it — including `type`, so a subclass that needs
     * `type` for its own attribute should override documentType() instead, or name its field
     * differently.
     *
     * @return array<string, mixed>
     */
    abstract protected function fields(ElementInterface $element): array;

    /**
     * @return array<string, mixed>
     */
    protected function baseDocument(ElementInterface $element): array
    {
        return [
            'id' => $this->documentId($element),
            // Never empty: title is a required field.
            'title' => (string)($element->title ?: '#' . $element->id),
            'type' => $this->documentType($element),
            'url' => $this->url($element),
            // Lower sorts first.
            'priority' => $this->target->priority ?? 100,
            'postDate' => $this->publishedAt($element),
            'expiryDate' => $this->expiresAt($element),
            'keywords' => $this->harvestKeywords($element),
        ];
    }

    /**
     * A label for the kind of thing the document is, for grouping results: an entry's or a
     * product's type name, else the element type's display name.
     */
    protected function documentType(ElementInterface $element): string
    {
        if ($element instanceof Entry) {
            return (string)$element->getType()->name;
        }

        return Commerce::productTypeOf($element)['name'] ?? $element::displayName();
    }

    /**
     * When the element becomes visible, as Unix seconds. An entry or product with no post date
     * is pending, so it is never published; an element with no idea of publishing is always
     * published.
     */
    protected function publishedAt(ElementInterface $element): int
    {
        $date = $this->timestamp($element->postDate ?? null);

        return $date ?? ($element instanceof Entry || Commerce::isProduct($element) ? self::FAR_FUTURE : 0);
    }

    /**
     * When the element stops being visible, as Unix seconds.
     */
    protected function expiresAt(ElementInterface $element): int
    {
        return $this->timestamp($element->expiryDate ?? null) ?? self::FAR_FUTURE;
    }

    /**
     * Craft's own search keywords for every field the layout marks searchable, as one text.
     *
     * Driven by Craft's `searchable` setting rather than a list in code, so marking a field
     * searchable in the control panel is all it takes — except for excludedKeywordFields().
     */
    protected function harvestKeywords(ElementInterface $element): string
    {
        $layout = $element->getFieldLayout();

        if ($layout === null) {
            return '';
        }

        $excluded = $this->excludedKeywordFields();
        $keywords = [];

        foreach ($layout->getCustomFields() as $field) {
            if (!$field->searchable || in_array($field->handle, $excluded, true)) {
                continue;
            }

            try {
                $value = $element->getFieldValue((string)$field->handle);
                $text = trim($field->getSearchKeywords($value, $element));
            } catch (Throwable $e) {
                Craft::warning(sprintf(
                    'Could not harvest keywords from field "%s" on element %s: %s',
                    $field->handle,
                    $element->id,
                    $e->getMessage(),
                ), TypesenseSync::HANDLE);

                continue;
            }

            if ($text !== '') {
                $keywords[] = $text;
            }
        }

        return StringHelper::safeTruncate(
            trim(preg_replace('/\s+/u', ' ', implode(' ', $keywords)) ?? ''),
            static::KEYWORD_LIMIT,
        );
    }

    /**
     * Field handles excluded from the keyword harvest.
     *
     * @return string[]
     */
    protected function excludedKeywordFields(): array
    {
        return static::EXCLUDED_KEYWORD_FIELDS;
    }

    // Helpers ---------------------------------------------------------------------------------

    /**
     * A custom field's value, or null when the element's layout has no such field.
     */
    protected function fieldValue(ElementInterface $element, string $handle): mixed
    {
        $layout = $element->getFieldLayout();

        if ($layout === null || $layout->getFieldByHandle($handle) === null) {
            return null;
        }

        try {
            return $element->getFieldValue($handle);
        } catch (Throwable $e) {
            Craft::warning(sprintf(
                'Could not read field "%s" on element %s: %s',
                $handle,
                $element->id,
                $e->getMessage(),
            ), TypesenseSync::HANDLE);

            return null;
        }
    }

    /**
     * The selected value of a Dropdown or Radio Buttons field.
     */
    protected function optionValue(ElementInterface $element, string $handle): ?string
    {
        $value = $this->fieldValue($element, $handle);

        if ($value instanceof SingleOptionFieldData) {
            $value = $value->value;
        }

        return is_scalar($value) && $value !== '' ? (string)$value : null;
    }

    /**
     * The label of a single-option field's selection, which is what a facet should show.
     */
    protected function optionLabel(ElementInterface $element, string $handle): ?string
    {
        $value = $this->fieldValue($element, $handle);

        if ($value instanceof SingleOptionFieldData) {
            return $value->value !== null && $value->value !== ''
                ? (string)($value->label ?: $value->value)
                : null;
        }

        return is_scalar($value) && $value !== '' ? (string)$value : null;
    }

    /**
     * The labels of a Checkboxes or Multi-select field's selections.
     *
     * @return string[]
     */
    protected function optionLabels(ElementInterface $element, string $handle): array
    {
        $value = $this->fieldValue($element, $handle);

        if (!$value instanceof MultiOptionsFieldData) {
            return [];
        }

        $labels = [];

        foreach ($value as $option) {
            /** @var OptionData $option */
            if ($option->value !== null && $option->value !== '') {
                $labels[] = (string)($option->label ?: $option->value);
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * Titles of related elements, in order.
     *
     * @return string[]
     */
    protected function relatedTitles(ElementInterface $element, string $handle, int $limit = 20): array
    {
        $value = $this->fieldValue($element, $handle);

        if (!$value instanceof ElementQuery) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(ElementInterface $related) => (string)$related->title,
            $value->limit($limit)->all(),
        )));
    }

    /**
     * URLs of related assets, in order. Asset URLs are left as the volume gives them.
     *
     * @return string[]
     */
    protected function assetUrls(ElementInterface $element, string $handle, int $limit = 10): array
    {
        $value = $this->fieldValue($element, $handle);

        if (!$value instanceof ElementQuery) {
            return [];
        }

        $urls = [];

        foreach ($value->limit($limit)->all() as $asset) {
            $url = $asset->getUrl();

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * A field as plain text: tags stripped, whitespace collapsed, truncated.
     */
    protected function plainText(ElementInterface $element, string $handle, ?int $limit = self::DESCRIPTION_LIMIT): string
    {
        $value = $this->fieldValue($element, $handle);

        if ($value === null || is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value)) ?? '');

        return $limit !== null ? StringHelper::safeTruncate($text, $limit) : $text;
    }

    /**
     * The first non-empty plain text among several handles — one formatter often serves entry
     * types that name the same idea differently (summary, intro, description).
     *
     * @param string[] $handles
     */
    protected function firstPlainText(ElementInterface $element, array $handles, ?int $limit = self::DESCRIPTION_LIMIT): string
    {
        foreach ($handles as $handle) {
            $text = $this->plainText($element, $handle, $limit);

            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * The first asset URL among several handles.
     *
     * @param string[] $handles
     */
    protected function firstAssetUrl(ElementInterface $element, array $handles): ?string
    {
        foreach ($handles as $handle) {
            $urls = $this->assetUrls($element, $handle, 1);

            if ($urls !== []) {
                return $urls[0];
            }
        }

        return null;
    }

    protected function number(ElementInterface $element, string $handle): ?float
    {
        $value = $this->fieldValue($element, $handle);

        return is_numeric($value) ? (float)$value : null;
    }

    protected function integer(ElementInterface $element, string $handle): ?int
    {
        $value = $this->fieldValue($element, $handle);

        return is_numeric($value) ? (int)$value : null;
    }

    protected function boolean(ElementInterface $element, string $handle): ?bool
    {
        $value = $this->fieldValue($element, $handle);

        return is_bool($value) ? $value : null;
    }

    protected function timestamp(?DateTimeInterface $date): ?int
    {
        return $date?->getTimestamp();
    }

    /**
     * Root-relative, never absolute: one cluster commonly serves every environment, and an
     * absolute URL names whichever one synced the document (BR-11).
     */
    protected function url(ElementInterface $element): string
    {
        try {
            $url = $element->getUrl();

            return $url !== null ? UrlHelper::rootRelativeUrl($url) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * A facet field. Optional, because one collection holds several document shapes.
     *
     * @return array<string, mixed>
     */
    protected static function facet(string $name, string $type = 'string'): array
    {
        return ['name' => $name, 'type' => $type, 'facet' => true, 'optional' => true];
    }

    /**
     * A numeric field that can be filtered and sorted, but is not a facet.
     *
     * @return array<string, mixed>
     */
    protected static function metric(string $name, string $type = 'float'): array
    {
        return ['name' => $name, 'type' => $type, 'sort' => true, 'optional' => true];
    }

    /**
     * A numeric field that is filterable, sortable and a facet — use it for any number a range
     * widget filters on. Typesense computes min/max stats only for facets, so a range widget on a
     * plain metric() fails with "Could not find a facet field named …", and that fails the whole
     * multi-search, emptying every other widget on the page.
     *
     * @return array<string, mixed>
     */
    protected static function metricFacet(string $name, string $type = 'float'): array
    {
        return ['name' => $name, 'type' => $type, 'facet' => true, 'sort' => true, 'optional' => true];
    }
}
