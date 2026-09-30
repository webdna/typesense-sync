<?php

namespace webdna\typesensesync\tests\fixtures\formatters;

use craft\base\ElementInterface;
use craft\elements\User;

/**
 * Lists a user only while their status is active, so suspending, deactivating or locking one
 * takes them out of search (TS-9).
 */
class ActiveUserFormatter extends DocumentFormatter
{
    public function shouldIndex(ElementInterface $element): bool
    {
        return parent::shouldIndex($element)
            && $element instanceof User
            && $element->getStatus() === User::STATUS_ACTIVE;
    }

    protected function fields(ElementInterface $element): array
    {
        return $element instanceof User ? ['username' => (string)$element->username] : [];
    }
}
