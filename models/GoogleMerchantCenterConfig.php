<?php

declare(strict_types=1);

namespace Osmium\Services\GoogleMerchantCenter\Models;

use Osmium\Core\Library\StoreFinance;

/**
 * Google Merchant Center configuration.
 *
 * File-based config (app/config/services/google-merchant-center.json.php),
 * matching the Xero/Stripe/PayPal/Analytics convention. The private key is a
 * secret: the settings page never echoes it back, and a blank submission
 * keeps the stored value.
 */
class GoogleMerchantCenterConfig
{
    private static ?object $config = null;
    private static string $configPath = 'app/config/services/google-merchant-center.json.php';

    public static function get(): object
    {
        $configLoaded = self::$config !== null;
        if ($configLoaded) return self::$config;

        $configExists = \file_exists(self::$configPath);
        if (!$configExists) {
            self::$config = self::defaults();
            return self::$config;
        }

        $content = \file_get_contents(self::$configPath);
        $jsonStart = \strpos(haystack: $content, needle: '{');

        $noJsonFound = $jsonStart === false;
        if ($noJsonFound) {
            self::$config = self::defaults();
            return self::$config;
        }

        $decoded = \json_decode(\substr(string: $content, offset: $jsonStart));
        $stored = (array) ($decoded->googleMerchantCenter ?? []);

        self::$config = (object) ($stored + (array) self::defaults());

        return self::$config;
    }

    public static function save(array $values): void
    {
        $dir = \dirname(self::$configPath);
        $dirExists = \is_dir($dir);
        if (!$dirExists) \mkdir(directory: $dir, permissions: 0755, recursive: true);

        $json = \json_encode(
            value: ['googleMerchantCenter' => $values],
            flags: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        \file_put_contents(self::$configPath, "<?php exit(); ?>\n" . $json . "\n");
        self::clearCache();
    }

    public static function clearCache(): void
    {
        self::$config = null;
    }

    /**
     * Builds the API client from the stored credentials. The feed label is the store's
     * country, so it is never a second setting that could disagree with Shop settings.
     */
    public static function buildService(StoreFinance $finance): GoogleMerchantCenterService
    {
        $config = self::get();

        return new GoogleMerchantCenterService(
            merchantId: (string) $config->merchantId,
            dataSourceId: (string) $config->dataSourceId,
            contentLanguage: (string) $config->contentLanguage,
            feedLabel: $finance->country(),
            clientEmail: (string) $config->clientEmail,
            privateKey: (string) $config->privateKey,
        );
    }

    private static function defaults(): object
    {
        return (object) [
            'enabled' => false,
            'merchantId' => '',
            'dataSourceId' => '',
            'contentLanguage' => 'en',
            'clientEmail' => '',
            'privateKey' => '',
        ];
    }
}
