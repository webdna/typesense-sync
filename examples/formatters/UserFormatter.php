<?php

namespace modules\search\formatters;

use craft\base\ElementInterface;
use craft\elements\User;
use webdna\typesensesync\formatters\BaseFormatter;
use webdna\typesensesync\formatters\SchemaContext;

/**
 * Example formatter for users (`examples/config/users.php`): active people with a name, and
 * nothing about them a visitor should not see.
 *
 * Who is listed is decided here, not in the config. This one lists a user only while their
 * status is active, so suspending, deactivating or locking someone takes them out of search
 * without anyone saving them — the plugin follows those changes itself when a users source is
 * declared (BR-7).
 *
 * A search index is readable by anyone holding a search key for it, so a document holds only
 * what the page shows. No email address, no username (with `useEmailAsUsername` it is the
 * email address), no group names.
 */
class UserFormatter extends BaseFormatter
{
    public function shouldIndex(ElementInterface $element): bool
    {
        return parent::shouldIndex($element)
            && $element instanceof User
            && $element->getStatus() === User::STATUS_ACTIVE
            // Craft's getName() falls back to the username, which may be an email address, so a
            // person is listed only once they have a name of their own.
            && trim((string)$element->fullName) !== '';
    }

    public function schema(SchemaContext $context): array
    {
        return [
            ...parent::schema($context),
            ['name' => 'photo', 'type' => 'string', 'index' => false, 'optional' => true],
        ];
    }

    protected function fields(ElementInterface $element): array
    {
        if (!$element instanceof User) {
            return [];
        }

        return [
            // Replaces the base title: a user has none.
            'title' => trim((string)$element->fullName),
            'photo' => $element->getPhoto()?->getUrl(),
        ];
    }
}
