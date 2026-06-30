<?php

namespace iceboxind\crisp;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\web\twig\variables\CraftVariable;
use iceboxind\crisp\models\Settings;
use iceboxind\crisp\services\Optimizer;
use iceboxind\crisp\variables\CrispVariable;
use yii\base\Event;

/**
 * Crisp — perceptually-lossless, content-aware image optimization for Craft.
 *
 * This skeleton uses the Twig-API + `cli` driver path (version-robust across
 * Craft 4.5+/5). Registering a native ImageTransformer (so existing
 * `asset.getUrl({width})` calls auto-optimize) is the documented next step.
 *
 * @property-read Optimizer $optimizer
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '0.1.0';
    public bool $hasCpSettings = true;

    public static Plugin $plugin;

    public static function config(): array
    {
        return [
            'components' => [
                'optimizer' => Optimizer::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // craft.crisp.* Twig API
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('crisp', CrispVariable::class);
        });

        // TODO (layer A): on-upload source optimization — hook
        // craft\services\Assets::EVENT_BEFORE_REPLACE_ASSET / Elements save to cap
        // + perceptually compress the stored original (keep its format). Toggleable.
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('crisp/settings', [
            'settings' => $this->getSettings(),
        ]);
    }
}
