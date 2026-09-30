<?php

declare(strict_types=1);

namespace Osmium\Services\GoogleMerchantCenter\Models;

/**
 * Product sync logic, called from the product.saved / product.removed hooks
 * (see service.json) and from the settings page's "Sync all products".
 *
 * Best-effort: errors are logged, never thrown, so a Merchant Center outage
 * can't fail a product save. ServiceHooks also catches anything that escapes.
 */
class GoogleMerchantCenterSync
{
    /**
     * @param array{productId: int, osmium: object, shop: object} $payload
     */
    public static function onProductSaved(array $payload): void
    {
        $notConfigured = !self::isConfigured();
        if ($notConfigured) return;

        $productId = (int) $payload['productId'];

        try {
            $service = GoogleMerchantCenterConfig::buildService();
            $product = $payload['shop']->model->product;

            self::syncProduct(service: $service, product: $product, osmium: $payload['osmium'], productId: $productId);
        } catch (\Exception $e) {
            \error_log("Google Merchant Center sync failed for product {$productId}: " . $e->getMessage());
        }
    }

    /**
     * @param array{productId: int, osmium: object, shop: object} $payload
     */
    public static function onProductRemoved(array $payload): void
    {
        $notConfigured = !self::isConfigured();
        if ($notConfigured) return;

        $productId = (int) $payload['productId'];

        try {
            GoogleMerchantCenterConfig::buildService()->deleteProduct((string) $productId);
        } catch (\Exception $e) {
            \error_log("Google Merchant Center removal failed for product {$productId}: " . $e->getMessage());
        }
    }

    /**
     * Pushes every active product - the initial catalogue load. Returns
     * counts instead of throwing so the settings page can report them.
     *
     * @return array{pushed: int, skipped: int, failed: int}
     */
    public static function syncAll(object $osmium, object $product): array
    {
        $service = GoogleMerchantCenterConfig::buildService();
        $productIds = \array_column($product->all(), 'id');
        $counts = ['pushed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($productIds as $productId) {
            try {
                $outcome = self::syncProduct(service: $service, product: $product, osmium: $osmium, productId: (int) $productId);
                $counts[$outcome]++;
            } catch (\Exception $e) {
                $counts['failed']++;
                \error_log("Google Merchant Center sync failed for product {$productId}: " . $e->getMessage());
            }
        }

        return $counts;
    }

    public static function isConfigured(): bool
    {
        return !empty(GoogleMerchantCenterConfig::get()->enabled);
    }

    /**
     * @return string 'pushed' | 'skipped' (missing, or unsyncable and removed)
     */
    private static function syncProduct(
        GoogleMerchantCenterService $service,
        object $product,
        object $osmium,
        int $productId,
    ): string {
        $loaded = $product->loadByID($productId);
        $productMissingOrUnrouted = !$loaded;
        if ($productMissingOrUnrouted) return 'skipped';

        $notSyncable = !GoogleMerchantCenterService::isSyncable($product);
        if ($notSyncable) {
            // A product edited into an unsyncable state (discontinued, price
            // 0) after being listed is removed rather than left stale.
            self::deleteIgnoringNotFound(service: $service, productId: $productId);
            return 'skipped';
        }

        $service->upsertProduct(
            offerId: (string) $productId,
            attributes: self::buildAttributes(product: $product, osmium: $osmium),
        );

        return 'pushed';
    }

    private static function deleteIgnoringNotFound(GoogleMerchantCenterService $service, int $productId): void
    {
        try {
            $service->deleteProduct((string) $productId);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $notFound = \str_contains($message, 'not found') || \str_contains($message, 'NOT_FOUND');
            if (!$notFound) throw $e; // Never listed is fine; anything else is a real failure
        }
    }

    private static function buildAttributes(object $product, object $osmium): array
    {
        $config = $osmium->config;
        $deliveryCharge = $config->checkout->deliveryCharge ?? null;

        return GoogleMerchantCenterService::buildAttributesFromProduct(
            product: $product,
            fqdn: $config->site->FQDN ?? '',
            imagesPath: $config->site->imagesPath,
            currency: $config->shop->currency ?? 'GBP',
            taxRatePercent: (float) ($config->shop->taxRatePercent ?? 20),
            deliveryChargeExcTax: $deliveryCharge !== null ? (float) $deliveryCharge : null,
        );
    }
}
