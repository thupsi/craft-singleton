<?php

namespace thupsi\singlesmanager\models;

use craft\base\Model;

/**
 * Plugin settings model.
 *
 * Settings are stored in the database (craft_info) and editable via the
 * native Craft section edit form (for single sections).
 */
class Settings extends Model
{
    /**
     * UIDs of single sections whose entry edit form should have the right-hand
     * meta sidebar (slug, post date, authors, etc.) hidden.
     *
     * @var string[]
     */
    public array $hideSidebarSections = [];
}
