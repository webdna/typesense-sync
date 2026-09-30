<?php

namespace webdna\typesensesync\events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Raised by `targets` when it lists the element types whose saves, deletes and restores it
 * follows.
 *
 * Add a class to follow another element type; an `EVENT_RESOLVE_TARGET` handler then gives its
 * elements a target.
 *
 * @since 1.0.0
 */
class RegisterElementTypesEvent extends Event
{
    /**
     * @var array<int, class-string<ElementInterface>>
     */
    public array $types = [];
}
