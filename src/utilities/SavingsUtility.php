<?php

namespace iceboxind\lithocinch\utilities;

use Craft;
use craft\base\Utility;
use craft\db\Query;
use iceboxind\lithocinch\migrations\Install;
use iceboxind\lithocinch\Plugin;

/**
 * Savings — the Pro CP utility (Utilities → Lithocinch Savings): a running
 * total of bytes saved plus the bulk-(re)optimization trigger (SPEC §4 item 4).
 *
 * The number that matters here is REAL, ongoing savings: Optimizer::store()
 * logs every genuinely new variant it generates — organic url()/picture()
 * template hits AND bulk-optimize runs alike — so this dashboard reflects
 * actual bytes served to real visitors, not a one-time bulk-run snapshot.
 *
 * The headline numbers (variants, bytesBefore/After/Saved) come from a
 * single-row lifetime-totals table, an O(1) read that stays fast and
 * accurate forever, however large the site's history gets. `assets`
 * (distinct assets touched) is the one figure derived from the append-only
 * detail ledger, which is a BOUNDED, pruned window (see
 * `Plugin::registerGc()`/`savingsLogRetentionDays`) — so it deliberately
 * reads "distinct assets touched recently," not a lifetime count; the label
 * says so.
 *
 * Deliberately minimal for v1: a couple of stat rows, no charts, no history.
 * Registration is Pro-gated in Plugin::init(), so on Lite this class is never
 * even offered to Craft.
 */
class SavingsUtility extends Utility
{
    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('litho', 'Lithocinch Savings');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'litho-savings';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return 'chart-line';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $volumeOptions = [
            ['label' => Craft::t('litho', 'All volumes'), 'value' => ''],
        ];
        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $volumeOptions[] = ['label' => $volume->name, 'value' => $volume->id];
        }

        return Craft::$app->getView()->renderTemplate('litho/utility', [
            'stats' => self::stats(),
            'volumeOptions' => $volumeOptions,
        ]);
    }

    /**
     * Read the lifetime totals (O(1) — one row, no aggregation) and pair them
     * with a distinct-assets count from the bounded detail ledger. Belt-and-
     * suspenders table checks: render an empty/partial dashboard rather than
     * a 500 if a migration hasn't run yet on this install.
     *
     * @return array{variants: int, assets: int, assetsWindowDays: int, bytesBefore: int, bytesAfter: int, bytesSaved: int, percentSaved: float}
     */
    private static function stats(): array
    {
        $settings = Plugin::$plugin->getSettings();
        $empty = [
            'variants' => 0, 'assets' => 0, 'assetsWindowDays' => $settings->savingsLogRetentionDays,
            'bytesBefore' => 0, 'bytesAfter' => 0, 'bytesSaved' => 0, 'percentSaved' => 0.0,
        ];

        $db = Craft::$app->getDb();
        if (!$db->tableExists(Install::TABLE_TOTALS)) {
            return $empty;
        }

        $totals = (new Query())
            ->select(['variants', 'bytesBefore', 'bytesAfter'])
            ->from(Install::TABLE_TOTALS)
            ->one();

        if (!$totals) {
            return $empty;
        }

        $before = (int)$totals['bytesBefore'];
        $after = (int)$totals['bytesAfter'];
        $saved = max(0, $before - $after);

        // "Assets touched" comes from the bounded detail ledger, so it's a
        // recent-window count, not a lifetime one — the template labels it
        // that way. Missing table (e.g. mid-migration) degrades to 0, not an error.
        $assets = $db->tableExists(Install::TABLE)
            ? (int)((new Query())->from(Install::TABLE)->select('COUNT(DISTINCT [[assetId]])')->scalar())
            : 0;

        return [
            'variants' => (int)$totals['variants'],
            'assets' => $assets,
            'assetsWindowDays' => $settings->savingsLogRetentionDays,
            'bytesBefore' => $before,
            'bytesAfter' => $after,
            'bytesSaved' => $saved,
            'percentSaved' => $before > 0 ? round($saved / $before * 100, 1) : 0.0,
        ];
    }
}
