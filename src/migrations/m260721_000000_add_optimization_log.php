<?php

namespace iceboxind\lithocinch\migrations;

/**
 * Adds the optimization log table on installs that predate schema 0.2.0
 * (Install.php only runs at plugin-install time; existing installs pick this
 * up via the schemaVersion bump). Delegates to Install, whose safeUp() is
 * guarded by a tableExists() check, so fresh installs — where Install already
 * created the table and this migration is merely marked applied — are safe too.
 */
class m260721_000000_add_optimization_log extends \craft\db\Migration
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
        return (new Install(['db' => $this->db]))->safeDown();
    }
}
