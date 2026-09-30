<?php

declare(strict_types=1);

namespace Osmium\Services\GoogleMerchantCenter\Controllers;

use Osmium\Modules\Admin\Core\AdminController;
use Osmium\Services\GoogleMerchantCenter\Models\GoogleMerchantCenterConfig;
use Osmium\Services\GoogleMerchantCenter\Models\GoogleMerchantCenterSync;

/**
 * Google Merchant Center settings controller - full-page form POST/redirect.
 * One form saves settings; two small forms run "Test connection" and
 * "Sync all products" and report the outcome via a session flash message.
 *
 * Routes:
 *   - index() → /admin/settings/google-merchant-center/
 */
class GoogleMerchantCenterController extends AdminController
{
    private const FLASH_KEY = 'gmc_flash';

    public function index(): void
    {
        $isPost = $this->isPost();
        if ($isPost) $this->handleSubmit();

        $this->data['admin']['config']['gmc'] = (array) GoogleMerchantCenterConfig::get();
        $this->data['admin']['flash'] = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);

        $this->setView('google-merchant-center/index.phtml');
    }

    private function handleSubmit(): void
    {
        $csrfValid = $this->admin->auth->validateCsrf();
        if (!$csrfValid) $this->flashAndRedirect('danger', 'Invalid form submission. Please try again.');

        $action = $_POST['action'] ?? 'save';

        $message = match ($action) {
            'test' => $this->testConnection(),
            'sync_all' => $this->syncAll(),
            default => $this->saveSettings(),
        };

        $this->flashAndRedirect($message['type'], $message['text']);
    }

    private function saveSettings(): array
    {
        $current = GoogleMerchantCenterConfig::get();

        $postedKey = \trim($_POST['private_key'] ?? '');
        $privateKey = $postedKey === '' ? (string) $current->privateKey : $postedKey; // Blank keeps the stored secret

        GoogleMerchantCenterConfig::save([
            'enabled' => isset($_POST['enabled']),
            'merchantId' => \trim($_POST['merchant_id'] ?? ''),
            'dataSourceId' => \trim($_POST['data_source_id'] ?? ''),
            'contentLanguage' => \trim($_POST['content_language'] ?? 'en'),
            'feedLabel' => \trim($_POST['feed_label'] ?? 'GB'),
            'clientEmail' => \trim($_POST['client_email'] ?? ''),
            'privateKey' => $privateKey,
        ]);

        $this->admin->model->changelog->log(
            description: 'Updated Google Merchant Center settings',
            recordType: 'settings',
        );

        return ['type' => 'success', 'text' => 'Settings saved successfully!'];
    }

    private function testConnection(): array
    {
        $result = GoogleMerchantCenterConfig::buildService()->testConnection();
        $name = $result['accountName'] ?? null;

        $text = $result['success']
            ? 'Connection successful' . ($name ? " - account: {$name}" : '')
            : "Connection failed: {$result['message']}";

        return ['type' => $result['success'] ? 'success' : 'danger', 'text' => $text];
    }

    private function syncAll(): array
    {
        $notConfigured = !GoogleMerchantCenterSync::isConfigured();
        if ($notConfigured) return ['type' => 'danger', 'text' => 'Enable and save the settings before syncing.'];

        \set_time_limit(300); // One API call per product

        $shop = new \OsmiumShop($this->osmium);
        $counts = GoogleMerchantCenterSync::syncAll(osmium: $this->osmium, product: $shop->model->product);

        $this->admin->model->changelog->log(
            description: 'Synced all products to Google Merchant Center',
            recordType: 'settings',
        );

        $failed = $counts['failed'];
        $text = "{$counts['pushed']} pushed, {$counts['skipped']} skipped (discontinued/unpriced), {$failed} failed.";

        return ['type' => $failed > 0 ? 'warning' : 'success', 'text' => $text];
    }

    private function flashAndRedirect(string $type, string $text): void
    {
        $_SESSION[self::FLASH_KEY] = ['type' => $type, 'text' => $text];
        $this->redirect('settings/google-merchant-center/');
    }

    private function isPost(): bool
    {
        return $_SERVER['REQUEST_METHOD'] === 'POST';
    }
}
