<?php

class AdminGlobalPostShipmentsController extends ModuleAdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->bootstrap = true;

        if (!$this->module) {
            $this->module = Module::getInstanceByName('globalpostshipping');
        }
    }

    public function init()
    {
        parent::init();

        if (!$this->module || !$this->module->active) {
            $this->redirectToOrdersList();
        }

        $action = (string) Tools::getValue('action');

        switch ($action) {
            case 'createShipment':
                $this->processCreateShipment();
                break;
            case 'downloadLabel':
                $this->processDownloadDocument('label');
                break;
            case 'downloadInvoice':
                $this->processDownloadDocument('invoice');
                break;
            default:
                $this->redirectToOrdersList();
        }
    }

    private function processCreateShipment(): void
    {
        $this->enforceToken();

        $orderId = (int) Tools::getValue('id_order');
        if (!$this->hasPermission('edit')) {
            PrestaShopLogger::addLog(
                'GlobalPost shipment creation denied: insufficient permissions.',
                2,
                null,
                $this->module ? $this->module->name : 'globalpostshipping',
                $orderId ?: null,
                true
            );
            $this->redirectToOrder($orderId, ['globalpost_error' => 'permission']);
        }

        if (!$this->module || !method_exists($this->module, 'handleManualShipmentCreation')) {
            $this->redirectToOrder($orderId, ['globalpost_error' => 'creation_failed']);
        }

        $result = $this->module->handleManualShipmentCreation($orderId);
        if (!empty($result['success'])) {
            $code = isset($result['code']) ? (string) $result['code'] : 'created';
            $this->redirectToOrder($orderId, ['globalpost_notice' => $code]);
        }

        $code = isset($result['code']) ? (string) $result['code'] : 'creation_failed';
        $this->redirectToOrder($orderId, ['globalpost_error' => $code]);
    }

    private function processDownloadDocument(string $type): void
    {
        $this->enforceToken();

        $orderId = (int) Tools::getValue('id_order');
        if (!$this->hasPermission('view')) {
            PrestaShopLogger::addLog(
                'GlobalPost document download denied: insufficient permissions.',
                2,
                null,
                $this->module ? $this->module->name : 'globalpostshipping',
                $orderId ?: null,
                true
            );
            $this->redirectToOrder($orderId, ['globalpost_error' => 'permission']);
        }

        if (!$this->module || !method_exists($this->module, 'fetchShipmentDocument')) {
            $this->redirectToOrder($orderId, ['globalpost_error' => 'api_error']);
        }

        $result = $this->module->fetchShipmentDocument($orderId, $type === 'invoice' ? 'invoice' : 'label');
        if (empty($result['success'])) {
            $code = isset($result['code']) ? (string) $result['code'] : 'api_error';
            $this->redirectToOrder($orderId, ['globalpost_error' => $code]);
        }

        $filename = isset($result['filename']) ? (string) $result['filename'] : 'globalpost-document.pdf';
        $content = (string) $result['content'];

        $this->streamPdf($content, $filename);
    }

    private function streamPdf(string $content, string $filename): void
    {
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private');
        header('Content-Length: ' . Tools::strlen($content));

        echo $content;
        exit;
    }

    private function hasPermission(string $permission): bool
    {
        return isset($this->tabAccess[$permission]) && (int) $this->tabAccess[$permission] === 1;
    }

    private function enforceToken(): void
    {
        $expected = (string) Tools::getAdminTokenLite('AdminGlobalPostShipments');
        $provided = (string) Tools::getValue('token');

        if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
            return;
        }

        PrestaShopLogger::addLog(
            'GlobalPost admin action denied: invalid token.',
            2,
            null,
            $this->module ? $this->module->name : 'globalpostshipping',
            null,
            true
        );

        $this->redirectToOrdersList();
    }

    private function redirectToOrder(int $orderId, array $params = []): void
    {
        if ($orderId <= 0) {
            $this->redirectToOrdersList();
        }

        $baseParams = [
            'vieworder' => 1,
            'id_order' => $orderId,
        ];

        $url = $this->context->link->getAdminLink('AdminOrders', true, [], array_merge($baseParams, $params));
        Tools::redirectAdmin($url . '#globalpost-admin-block');
        exit;
    }

    private function redirectToOrdersList(): void
    {
        $url = $this->context->link->getAdminLink('AdminOrders', true);
        Tools::redirectAdmin($url);
        exit;
    }
}
