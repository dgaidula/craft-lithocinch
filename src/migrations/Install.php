<?php

namespace iceboxind\lithocinch\migrations;

use craft\db\Migration;

/**
 * Install migration: creates the two tables that back the Savings utility's
 * totals (bulk (re)optimization, SPEC §4 item 4).
 *
 * Two-table design, specifically to avoid an unbounded table backing the
 * dashboard's headline numbers:
 *
 *  - `TABLE_TOTALS` — a single-row, lifetime running total (variants, bytes
 *    before/after), maintained by atomic increment in
 *    `Optimizer::logSavings()`. This is what the dashboard reads for its core
 *    numbers — an O(1) lookup that stays fast and accurate forever, however
 *    large the site's history gets.
 *  - `TABLE` (`litho_optimization_log`) — an APPEND-ONLY detail ledger:
 *    `Optimizer::store()` inserts one row every time it actually generates a
 *    variant, from real `url()`/`picture()` template hits AND bulk-optimize
 *    runs alike (a single asset legitimately generates many rows — multiple
 *    widths, multiple formats via picture()). This table is a BOUNDED,
 *    recent-activity window, not a lifetime archive — it's pruned by
 *    `Plugin`'s `Gc::EVENT_RUN` handler (see `Plugin::registerGc()`) past a
 *    configurable retention window, so it never grows unbounded on a busy
 *    site. Pruning it never touches the lifetime totals above.
 *
 * Both must survive cache clears (they're the source of truth for "bytes
 * saved"), which is why they're real tables and not Craft's data cache.
 */
class Install extends Migration
{
    /** The lifetime running-totals table (single row). */
    public const TABLE_TOTALS = '{{%litho_optimization_totals}}';

    /** The bounded, recent-activity detail ledger. */
    public const TABLE = '{{%litho_optimization_log}}';

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Guarded so the numbered migrations (for pre-existing installs) can
        // reuse this without tripping over an already-installed table.
        if (!$this->db->tableExists(self::TABLE)) {
            $this->createTable(self::TABLE, [
                'id' => $this->primaryKey(),
                'assetId' => $this->integer()->notNull(),
                'bytesBefore' => $this->bigInteger()->unsigned()->notNull(),
                'bytesAfter' => $this->bigInteger()->unsigned()->notNull(),
                'format' => $this->string(10),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::TABLE, ['assetId'], false); // not unique — append-only ledger
            // Powers the GC prune query (WHERE dateCreated < :cutoff) without a table scan.
            $this->createIndex(null, self::TABLE, ['dateCreated'], false);
            $this->addForeignKey(null, self::TABLE, ['assetId'], '{{%assets}}', ['id'], 'CASCADE', null);
        }

        if (!$this->db->tableExists(self::TABLE_TOTALS)) {
            $this->createTable(self::TABLE_TOTALS, [
                'id' => $this->primaryKey(),
                'variants' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
                'bytesBefore' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
                'bytesAfter' => $this->bigInteger()->unsigned()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            // Seed the single totals row up front so logSavings() can always
            // UPDATE (atomic increment) rather than needing an upsert-on-first-write.
            $this->insert(self::TABLE_TOTALS, [
                'variants' => 0,
                'bytesBefore' => 0,
                'bytesAfter' => 0,
            ]);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);
        $this->dropTableIfExists(self::TABLE_TOTALS);
        return true;
    }
}
