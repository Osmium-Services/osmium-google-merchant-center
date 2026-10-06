<?php

/**
 * Google Merchant Center sync service (Merchant API, not the deprecated
 * Content API for Shopping - that shut down 2026-08-18).
 *
 * Authenticates as a Google Cloud service account (JWT bearer flow) and
 * pushes product data into a primary API data source via the Products
 * sub-API.
 *
 * API Documentation: https://developers.google.com/merchant/api
 */

namespace Osmium\Services\GoogleMerchantCenter\Models;

use Osmium\Core\Library\StoreFinance;

use Osmium\Core\Library\Cache;
use Osmium\Modules\Shop\Models\ShopProduct;

class GoogleMerchantCenterService
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/content';
    private const PRODUCTS_API_BASE = 'https://merchantapi.googleapis.com/products/v1/';
    private const ACCOUNTS_API_BASE = 'https://merchantapi.googleapis.com/accounts/v1/';
    private const DATASOURCES_API_BASE = 'https://merchantapi.googleapis.com/datasources/v1/';
    private const TOKEN_TTL_SECONDS = 3000; // Google tokens last 3600s; cache a bit under that

    private string $merchantId;
    private string $dataSourceId;
    private string $contentLanguage;
    private string $feedLabel;
    private string $clientEmail;
    private string $privateKey;
    private Cache $tokenCache;

    public function __construct(
        string $merchantId,
        string $dataSourceId,
        string $contentLanguage,
        string $feedLabel,
        string $clientEmail,
        string $privateKey,
    ) {
        $this->merchantId = $merchantId;
        $this->dataSourceId = $dataSourceId;
        $this->contentLanguage = $contentLanguage;
        $this->feedLabel = $feedLabel;
        $this->clientEmail = $clientEmail;
        $this->privateKey = $privateKey;
        $this->tokenCache = new Cache(namespace: 'merchant-center-token', ttl: self::TOKEN_TTL_SECONDS);
    }

    // ----------------------------------------
    // Products API
    // ----------------------------------------

    /**
     * List all data sources on the account - diagnostic/setup use.
     */
    public function listDataSources(): array
    {
        return $this->callApi(method: 'GET', base: self::DATASOURCES_API_BASE, path: "accounts/{$this->merchantId}/dataSources");
    }

    /**
     * Delete a data source and everything sourced from it (all its products).
     * Diagnostic/setup use - not called from the normal sync flow.
     */
    public function deleteDataSource(string $dataSourceId): void
    {
        $this->callApi(method: 'DELETE', base: self::DATASOURCES_API_BASE, path: "accounts/{$this->merchantId}/dataSources/{$dataSourceId}");
    }

    /**
     * Fetch exactly what was submitted for one product - reflects the last
     * upsertProduct() call immediately, unlike listProducts()'s processed
     * view which can lag behind by minutes. Diagnostic use.
     */
    public function getProductInput(string $offerId): array
    {
        $name = "accounts/{$this->merchantId}/productInputs/{$this->contentLanguage}~{$this->feedLabel}~{$offerId}";

        return $this->callApi(method: 'GET', base: self::PRODUCTS_API_BASE, path: $name);
    }

    /**
     * List processed products on the account (one page - pass pageToken for more).
     * Diagnostic use - this is the processed Products view, not productInputs.
     */
    public function listProducts(?string $pageToken = null): array
    {
        $query = $pageToken ? '?' . \http_build_query(['pageToken' => $pageToken]) : '';

        return $this->callApi(method: 'GET', base: self::PRODUCTS_API_BASE, path: "accounts/{$this->merchantId}/products{$query}");
    }

    /**
     * Insert or update (upsert) a product. Merchant API's productInputs
     * resource has no separate "update" call - inserting with the same
     * offerId overwrites it.
     *
     * @param string $offerId Stable identifier for this product (this app's product ID)
     * @param array $attributes Merchant API ProductInput.attributes fields
     */
    public function upsertProduct(string $offerId, array $attributes): array
    {
        $path = "accounts/{$this->merchantId}/productInputs:insert";
        $query = \http_build_query([
            'dataSource' => "accounts/{$this->merchantId}/dataSources/{$this->dataSourceId}",
        ]);

        $body = [
            'offerId' => $offerId,
            'contentLanguage' => $this->contentLanguage,
            'feedLabel' => $this->feedLabel,
            'productAttributes' => $attributes,
        ];

        return $this->callApi(method: 'POST', base: self::PRODUCTS_API_BASE, path: "{$path}?{$query}", body: $body);
    }

    /**
     * Remove a product from the data source.
     */
    public function deleteProduct(string $offerId): void
    {
        $name = "accounts/{$this->merchantId}/productInputs/{$this->contentLanguage}~{$this->feedLabel}~{$offerId}";
        $query = \http_build_query([
            'dataSource' => "accounts/{$this->merchantId}/dataSources/{$this->dataSourceId}",
        ]);

        $this->callApi(method: 'DELETE', base: self::PRODUCTS_API_BASE, path: "{$name}?{$query}");
    }

    /**
     * One-time setup call: register the service account's GCP project as an
     * API developer on the merchant account. Required before any other API
     * call is accepted - see
     * https://developers.google.com/merchant/api/guides/quickstart/direct-api-calls
     */
    public function registerDeveloper(string $developerEmail): array
    {
        $path = "accounts/{$this->merchantId}/developerRegistration:registerGcp";

        return $this->callApi(method: 'POST', base: self::ACCOUNTS_API_BASE, path: $path, body: [
            'developerEmail' => $developerEmail,
        ]);
    }

    /**
     * One-time setup call: create the primary product data source that
     * upsertProduct()/deleteProduct() push into. Returns the new data
     * source's numeric ID (the last path segment of its resource name) -
     * store that as dataSourceId in the service's config.
     */
    public function createPrimaryDataSource(string $displayName, string $country): string
    {
        $response = $this->callApi(method: 'POST', base: self::DATASOURCES_API_BASE, path: "accounts/{$this->merchantId}/dataSources", body: [
            'displayName' => $displayName,
            'primaryProductDataSource' => [
                'countries' => [$country],
                'contentLanguage' => $this->contentLanguage,
                'feedLabel' => $this->feedLabel,
            ],
        ]);

        $name = $response['name']; // accounts/{account}/dataSources/{id}
        $segments = \explode('/', $name);

        return \end($segments);
    }

    /**
     * Confirm the connection works by fetching the merchant account.
     */
    public function testConnection(): array
    {
        try {
            $account = $this->callApi(method: 'GET', base: self::ACCOUNTS_API_BASE, path: "accounts/{$this->merchantId}");

            return [
                'success' => true,
                'message' => 'Connection successful',
                'accountName' => $account['accountName'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    // ----------------------------------------
    // Field mapping helpers
    // ----------------------------------------

    /**
     * Map this app's product status schema reference to Google's
     * availability enum. schema_ref already mirrors schema.org's
     * ItemAvailability values (see product-schema.phtml), which is why
     * that's what gets stored on the product status row.
     */
    public static function availabilityFromSchemaRef(string $schemaRef): string
    {
        return match ($schemaRef) {
            'InStock' => 'in_stock',
            'OutOfStock' => 'out_of_stock',
            'PreOrder' => 'preorder',
            'BackOrder' => 'backorder',
            default => 'out_of_stock',
        };
    }

    /**
     * Merchant API hard-requires a real positive price - discovered when
     * Google rejected a discontinued product ("Call for pricing" and
     * "Coming soon" statuses have the same problem: no reliable price,
     * same as product-schema.phtml already treats them - see there for the
     * status IDs). Discontinued products are excluded outright regardless
     * of price, since they're not purchasable at all.
     */
    public static function isSyncable(ShopProduct $product): bool
    {
        $isDiscontinued = $product->schemaRef === 'Discontinued';
        $hasNoRealPrice = $product->priceExcTax <= 0;

        return !$isDiscontinued && !$hasNoRealPrice;
    }

    /**
     * Merchant API prices are integer micros (amount * 1,000,000) as a string.
     */
    public static function priceMicros(float $amountIncTax): string
    {
        return (string) (int) \round($amountIncTax * 1_000_000);
    }

    /**
     * Map a loaded ShopProduct to Merchant API productAttributes. Shared by
     * the product.saved hook and the admin "Sync all products" action, so
     * both stay in sync.
     */
    public static function buildAttributesFromProduct(
        ShopProduct $product,
        string $fqdn,
        string $imagesPath,
        StoreFinance $finance,
        ?float $deliveryChargeExcTax = null,
    ): array {
        $currency = $finance->currencyCode();
        $taxRatePercent = $finance->taxRatePercent();
        $fqdn = \rtrim($fqdn, '/');
        $priceIncTax = $product->priceExcTax * (1 + ($taxRatePercent / 100));
        $availability = self::availabilityFromSchemaRef($product->schemaRef);
        [$mainImagePath, $additionalImagePaths] = self::resolveImagePaths($imagesPath, $product->brandPath, $product->modelPath);

        $attributes = [
            'title' => $product->title,
            'description' => self::plainTextDescription($product->descr, fallback: $product->title),
            'link' => $fqdn . '/' . \ltrim($product->productStub, '/'),
            'imageLink' => $fqdn . $mainImagePath,
            'condition' => 'new',
            'availability' => $availability,
            'price' => [
                'amountMicros' => self::priceMicros($priceIncTax),
                'currencyCode' => $currency,
            ],
        ];

        $hasAdditionalImages = !empty($additionalImagePaths);
        if ($hasAdditionalImages) {
            $attributes['additionalImageLinks'] = \array_map(
                static fn(string $path): string => $fqdn . $path,
                $additionalImagePaths,
            );
        }

        $hasBrand = !empty($product->brandName);
        if ($hasBrand) $attributes['brand'] = $product->brandName;

        $hasGtin = !empty($product->barcode);
        if ($hasGtin) $attributes['gtins'] = [$product->barcode];

        $hasSku = !empty($product->reference);
        if ($hasSku) $attributes['mpn'] = $product->reference;

        // Required by Merchant API whenever availability isn't in_stock.
        // Falls back to a week out if there's no known restock date yet -
        // an approximate value passes validation; omitting it doesn't.
        $needsAvailabilityDate = $availability === 'preorder' || $availability === 'backorder';
        if ($needsAvailabilityDate) {
            $restockTimestamp = $product->restockDate ? \strtotime($product->restockDate) : \strtotime('+7 days');
            $attributes['availabilityDate'] = \date('c', $restockTimestamp);
        }

        $hasDeliveryCharge = $deliveryChargeExcTax !== null;
        if ($hasDeliveryCharge) {
            $deliveryChargeIncTax = $deliveryChargeExcTax * (1 + ($taxRatePercent / 100));
            $attributes['shipping'] = [[
                'country' => $finance->country(),
                'price' => [
                    'amountMicros' => self::priceMicros($deliveryChargeIncTax),
                    'currencyCode' => $currency,
                ],
                'minHandlingTime' => '0',
                'maxHandlingTime' => '1',
                'minTransitTime' => '1',
                'maxTransitTime' => '5',
            ]];
        }

        return $attributes;
    }

    /**
     * Merchant Center wants plain text, not the HTML the admin description
     * field stores - required, so a title fallback beats submitting empty.
     */
    private static function plainTextDescription(string $html, string $fallback): string
    {
        $text = \html_entity_decode(\strip_tags($html), ENT_QUOTES);
        $text = \trim(\preg_replace('/\s+/', ' ', $text));

        $isEmpty = $text === '';
        if ($isEmpty) return $fallback;

        return \mb_substr($text, 0, 5000);
    }

    /**
     * Suffixes listThumbnails() (product-full.php) cycles through for a
     * product's gallery. 'warranty' is excluded - that's a badge graphic,
     * not a photo of the product, so it doesn't belong in a Shopping image
     * gallery.
     */
    private const GALLERY_IMAGE_SUFFIXES = ['', 'extra1', 'extra2', 'extra3', 'extra4', 'extra5'];

    /**
     * The main image plus up to 5 gallery images, in whatever format each
     * actually exists on disk. Not imagePath()'s avif-first negotiation -
     * Merchant Center doesn't accept avif, so this only offers
     * jpg/png/webp, in that order, and checks the filesystem directly
     * rather than assuming .jpg exists.
     *
     * @return array{0: string, 1: string[]} [mainImagePath, additionalImagePaths]
     */
    private static function resolveImagePaths(string $imagesPath, string $brandPath, string $modelPath): array
    {
        $found = [];
        foreach (self::GALLERY_IMAGE_SUFFIXES as $suffix) {
            $path = self::resolveOneImagePath($imagesPath, $brandPath, $modelPath, $suffix);
            if ($path !== null) $found[] = $path;
        }

        $noneFound = empty($found);
        if ($noneFound) {
            // Nothing on disk - fall back to the expected main-image path so
            // Merchant Center reports the 404 as a real, visible issue.
            return [self::mainImageCandidate($imagesPath, $brandPath, $modelPath), []];
        }

        $main = \array_shift($found);

        return [$main, $found];
    }

    private static function resolveOneImagePath(string $imagesPath, string $brandPath, string $modelPath, string $suffix): ?string
    {
        // Extra shots live in their own "extra" size directory, not inside
        // "large" with a suffixed filename - see listThumbnails() in
        // product-full.php, which switches imagePath()'s size param the
        // same way.
        $sizeDir = $suffix === '' ? 'large' : 'extra';
        $base = "{$imagesPath}/shop/{$brandPath}/{$modelPath}/{$sizeDir}";
        $fileName = $suffix === '' ? "{$brandPath}-{$modelPath}" : "{$brandPath}-{$modelPath}-{$suffix}";

        $candidates = [
            "{$base}/{$fileName}.jpg",
            "{$base}/{$fileName}.png",
            "{$base}/webp/{$fileName}.webp",
        ];

        foreach ($candidates as $candidate) {
            $exists = \file_exists($_SERVER['DOCUMENT_ROOT'] . $candidate);
            if ($exists) return $candidate;
        }

        return null;
    }

    private static function mainImageCandidate(string $imagesPath, string $brandPath, string $modelPath): string
    {
        return "{$imagesPath}/shop/{$brandPath}/{$modelPath}/large/{$brandPath}-{$modelPath}.jpg";
    }

    // ----------------------------------------
    // Auth (service account JWT bearer flow)
    // ----------------------------------------

    private function getAccessToken(): string
    {
        return $this->tokenCache->get(
            key: $this->clientEmail,
            fallback: fn() => $this->requestAccessToken(),
        );
    }

    private function requestAccessToken(): string
    {
        $assertion = $this->buildSignedJwt();

        $ch = \curl_init(self::TOKEN_URI);
        \curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = \curl_exec($ch);
        $curlError = \curl_error($ch);

        if ($curlError) throw new \Exception("Merchant Center token request curl error: {$curlError}");

        $decoded = \json_decode(json: $response, associative: true);

        $hasError = isset($decoded['error']);
        if ($hasError) {
            $description = $decoded['error_description'] ?? $decoded['error'];
            throw new \Exception("Merchant Center token request failed: {$description}");
        }

        return $decoded['access_token'];
    }

    /**
     * Sign a Google service-account JWT assertion (RS256) by hand - no
     * Google API client library is installed in this codebase.
     */
    private function buildSignedJwt(): string
    {
        $now = \time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $this->clientEmail,
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URI,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $encodedHeader = $this->base64UrlEncode(\json_encode($header, JSON_THROW_ON_ERROR));
        $encodedClaims = $this->base64UrlEncode(\json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = "{$encodedHeader}.{$encodedClaims}";

        $signature = '';
        $signed = \openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        if (!$signed) throw new \Exception('Merchant Center JWT signing failed - check the service account private key');

        $encodedSignature = $this->base64UrlEncode($signature);

        return "{$signingInput}.{$encodedSignature}";
    }

    private function base64UrlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }

    // ----------------------------------------
    // HTTP
    // ----------------------------------------

    private function callApi(string $method, string $base, string $path, ?array $body = null): array
    {
        $accessToken = $this->getAccessToken();

        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_URL => $base . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];

        $hasBody = $body !== null;
        if ($hasBody) {
            $options[CURLOPT_POSTFIELDS] = \json_encode($body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        $ch = \curl_init();
        \curl_setopt_array($ch, $options);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo(handle: $ch, option: CURLINFO_HTTP_CODE);
        $curlError = \curl_error($ch);

        if ($curlError) throw new \Exception("Merchant Center API curl error: {$curlError}");

        $isEmptyResponse = $response === '' || $response === null;
        if ($isEmptyResponse) return [];

        $decoded = \json_decode(json: $response, associative: true);

        $jsonDecodeError = \json_last_error() !== JSON_ERROR_NONE;
        if ($jsonDecodeError) throw new \Exception("Merchant Center API returned invalid JSON (HTTP {$httpCode}): {$response}");

        $isError = $httpCode >= 400;
        if ($isError) {
            $message = $decoded['error']['message'] ?? "HTTP {$httpCode}";
            throw new \Exception("Merchant Center API error: {$message}");
        }

        return $decoded;
    }
}
