<?php

namespace webdna\typesensesync\events;

use craft\base\ElementInterface;
use webdna\typesensesync\models\ResolvedTarget;
use yii\base\Event;

/**
 * Raised by `targets` after it has resolved an element against the declared sources.
 *
 * A handler may replace `$target` — to route an element to another collection or formatter, or to
 * index an element type the plugin does not know — or set it to null to keep the element out of
 * search. A replacement must name a declared collection and a real formatter, or it is ignored.
 *
 * @since 1.0.0
 */
class ResolveTargetEvent extends Event
{
    public ElementInterface $element;

    /**
     * The declared target, or null when nothing declares the element.
     */
    public ?ResolvedTarget $target = null;
}
