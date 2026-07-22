<?php

namespace iceboxind\lithocinch\migrations;

/**
 * Adds the lifetime savings-totals table on installs that already ran the
 * 0.2.0 schema (which had `litho_optimization_log` but not
 * `litho_optimization_totals`). Delegates to Install, whose safeUp() is
 * guarded by tableExists() checks on both tables, so fresh installs — where
 * Install already created both and this migration is merely marked applied —
 * are safe too.
 */
class m260721_000100_add_optimization_totals extends \craft\db\Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        return (new Install(['db' => $this->db]))->safeUp();
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // dropTableIfExists() is idempotent, so delegating here even though
        // the sibling m260721_000000 migration's safeDown() drops the same
        // tables is harmless either order they run in.
        return (new Install(['db' => $this->db]))->safeDown();
    }
}
