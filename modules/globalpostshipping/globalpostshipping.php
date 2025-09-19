<?php
/**
 * GlobalPost Shipping module.
 *
 * @author    GlobalPost
 * @copyright 2024 GlobalPost
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/src/Installer/DatabaseInstaller.php';

use GlobalPostShipping\Error\ErrorMapper;
use GlobalPostShipping\Installer\DatabaseInstaller;
use GlobalPostShipping\Logger\PrestaShopLoggerAdapter;
use GlobalPostShipping\SDK\GlobalPostClient;
use GlobalPostShipping\SDK\GlobalPostClientException;
use GlobalPostShipping\SDK\LoggerInterface;
use GlobalPostShipping\SDK\NullLogger;
use GlobalPostShipping\Tariff\CartMeasurementCalculator;

class Globalpostshipping extends CarrierModule
{
    /**
     * List of hooks registered by the module.
     *
     * @var array
     */
    private $hooks = [
        'actionCarrierProcess',
        'actionValidateOrder',
        'actionOrderGridDefinitionModifier',
        'actionOrderGridDataModifier',
        'displayAdminOrderMainBottom',
        'displayCarrierExtraContent',
        'actionGetDeliveryOptions',
        'displayOrderDetail',
        'actionGetExtraMailTemplateVars',
    ];

    /**
     * Module configuration keys grouped for convenience.
     */
    private const CONFIGURATION_KEYS = [
        'GLOBALPOST_CARRIER_DOCUMENTS_ID',
        'GLOBALPOST_CARRIER_PARCEL_ID',
        'GLOBALPOST_API_TOKEN_TEST',
        'GLOBALPOST_API_TOKEN_PROD',
        'GLOBALPOST_API_MODE',
        'GLOBALPOST_API_IDENTIFIER',
        'GLOBALPOST_COUNTRY_FROM',
        'GLOBALPOST_TYPE_MODE',
        'GLOBALPOST_TYPE_DOCUMENTS',
        'GLOBALPOST_TYPE_PARCEL',
        'GLOBALPOST_PARCEL_LENGTH',
        'GLOBALPOST_PARCEL_WIDTH',
        'GLOBALPOST_PARCEL_HEIGHT',
        'GLOBALPOST_INCOTERMS',
        'GLOBALPOST_PURPOSE',
        'GLOBALPOST_CURRENCY_INVOICE',
        'GLOBALPOST_INSURANCE_ENABLED',
        'GLOBALPOST_INSURANCE_RULE',
        'GLOBALPOST_AUTO_CREATE_SHIPMENT',
        'GLOBALPOST_TRACKING_TEMPLATE',
        'GLOBALPOST_DOCUMENT_LANGUAGE',
        'GLOBALPOST_DEBUG_LOG',
    ];

    /**
     * Mapping between shipment types and configuration keys that store carrier IDs.
     */
    private const SHIPMENT_TYPE_CONFIGURATION = [
        'documents' => 'GLOBALPOST_CARRIER_DOCUMENTS_ID',
        'parcel' => 'GLOBALPOST_CARRIER_PARCEL_ID',
    ];

    /**
     * Supported shipment types.
     */
    private const SUPPORTED_SHIPMENT_TYPES = ['documents', 'parcel'];

    /**
     * In-memory cache of tariff options per cart and shipment type.
     *
     * @var array<int, array<string, array<int, array<string, mixed>>>>
     */
    private $tariffOptionsCache = [];

    /**
     * Logger used when instantiating the API client.
     */
    private ?LoggerInterface $logger = null;

    /**
     * Flag to avoid checking the order table schema multiple times per request.
     */
    private bool $isOrderTableSchemaEnsured = false;

    /**
     * Map of translation keys to their default (English) messages.
     */
    private const TRANSLATION_MESSAGES = [
        'admin.block_title' => 'GlobalPost',
        'admin.button.create' => 'Create shipment',
        'admin.button.download_invoice' => 'Download invoice (PDF)',
        'admin.button.download_label' => 'Download label (PDF)',
        'admin.error.api_error' => 'Failed to download the document from GlobalPost.',
        'admin.error.api_unavailable' => 'GlobalPost API token is not configured.',
        'admin.error.cart_missing' => 'The original cart linked to the order is missing.',
        'admin.error.creation_failed' => 'Failed to create the shipment. Check the GlobalPost log for details.',
        'admin.error.api_bad_request' => 'GlobalPost rejected the shipment data. Validate the address and contact details.',
        'admin.error.api_auth_failed' => 'GlobalPost authentication failed. Check the API token in the module settings.',
        'admin.error.api_forbidden' => 'Access to the GlobalPost API was denied. Contact GlobalPost support.',
        'admin.error.api_not_found' => 'GlobalPost could not find the requested resource.',
        'admin.error.api_conflict' => 'A shipment with the same reference already exists in GlobalPost.',
        'admin.error.api_validation_failed' => 'GlobalPost validation failed for the shipment payload.',
        'admin.error.api_rate_limited' => 'Too many requests were sent to GlobalPost. Try again in a few minutes.',
        'admin.error.api_service_unavailable' => 'GlobalPost service is temporarily unavailable. Try again later.',
        'admin.error.api_transport' => 'Failed to connect to the GlobalPost API. Please retry.',
        'admin.error.validation_failed' => 'Shipment contains invalid contact details. Update the address, phone or email.',
        'admin.error.empty_document' => 'GlobalPost returned an empty document.',
        'admin.error.invalid_order' => 'The order could not be found.',
        'admin.error.invoice_unavailable' => 'Invoices are only available for parcel shipments.',
        'admin.error.permission' => 'You do not have permission to perform this action.',
        'admin.error.record_missing' => 'No GlobalPost data is stored for this order.',
        'admin.error.shipment_missing' => 'Shipment identifiers are not available for this order.',
        'admin.estimate_days' => '%s business days',
        'admin.label.estimate' => 'Delivery time',
        'admin.label.last_message' => 'Last message',
        'admin.label.price' => 'Price',
        'admin.label.shipment_id' => 'Shipment ID',
        'admin.label.status' => 'Status',
        'admin.label.tariff' => 'Tariff details',
        'admin.label.tariff_id' => 'Tariff ID',
        'admin.label.tariff_key' => 'Option key',
        'admin.label.tracking_url' => 'Tracking link',
        'admin.label.ttn' => 'Tracking number',
        'admin.notice.already_created' => 'Shipment has already been created.',
        'admin.notice.created' => 'Shipment was created successfully.',
        'admin.status.failed' => 'Creation failed',
        'admin.status.pending' => 'Awaiting creation',
        'admin.status.success' => 'Shipment created',
        'admin.type.documents' => 'Documents',
        'admin.type.parcel' => 'Parcel',
        'api.identifier' => 'API identifier',
        'api.mode' => 'API mode',
        'api.mode_desc' => 'Switch between sandbox and live API endpoints.',
        'api.mode_prod' => 'Production',
        'api.mode_test' => 'Test',
        'api.notice_html' => 'API endpoints are selected automatically based on the chosen mode.<br><strong>%s</strong>: %s<br><strong>%s</strong>: %s',
        'api.prod_token' => 'Production API token',
        'api.prod_url' => 'Production URL',
        'api.test_token' => 'Test API token',
        'api.test_url' => 'Test URL',
        'autocreate.label' => 'Auto-create shipment after order confirmation',
        'button.save' => 'Save',
        'carrier.default_delay' => 'International delivery',
        'carrier.documents_name' => 'GlobalPost Documents',
        'carrier.parcel_name' => 'GlobalPost Parcel',
        'common.disabled' => 'Disabled',
        'common.enabled' => 'Enabled',
        'country.origin' => 'Origin country (ISO code)',
        'country.origin_hint' => 'Use a two-letter ISO code, e.g. UA.',
        'currency.eur' => 'Euro (EUR)',
        'currency.invoice' => 'Invoice currency',
        'currency.uah' => 'Hryvnia (UAH)',
        'currency.usd' => 'US Dollar (USD)',
        'document.language' => 'Document language',
        'error.api_mode' => 'Invalid API mode provided.',
        'error.autocreate' => 'Invalid auto-creation option provided.',
        'error.debug_flag' => 'Invalid debug logging option.',
        'error.country' => 'Origin country must be a valid ISO code (e.g. UA).',
        'error.currency' => 'Invalid invoice currency selected.',
        'error.dimensions' => 'Dimensions must be positive numbers.',
        'error.document_language' => 'Invalid document language selected.',
        'error.identifier' => 'API identifier contains invalid characters.',
        'error.incoterms' => 'Incoterms value is invalid.',
        'error.insurance_option' => 'Invalid insurance option provided.',
        'error.insurance_rule' => 'Insurance rule selection is invalid.',
        'error.prod_token' => 'Production API token contains invalid characters.',
        'error.purpose' => 'Invalid shipment purpose selected.',
        'error.test_token' => 'Test API token contains invalid characters.',
        'error.tracking_invalid_chars' => 'Tracking URL template contains invalid characters.',
        'error.tracking_placeholder' => 'Tracking URL template must include the @ placeholder.',
        'error.types' => 'Select at least one shipment type.',
        'front.estimate_days' => 'Estimated delivery: %s days',
        'front.option_label' => '%s – %s UAH (%s EUR)',
        'front.option_label_no_eur' => '%s – %s UAH',
        'front.select_tariff' => 'Select a GlobalPost tariff option',
        'front.tracking_link_label' => 'Track shipment',
        'front.tracking_number_label' => 'Tracking number',
        'front.tracking_title' => 'Shipment tracking',
        'incoterms.label' => 'Incoterms',
        'insurance.desc' => 'Toggle insurance for generated shipments.',
        'insurance.enable' => 'Enable insurance',
        'insurance.rule' => 'Insurance calculation rule',
        'insurance.rule_desc' => 'Select how the insured amount should be calculated for shipments.',
        'insurance.rule_order_total' => 'Use the order total amount',
        'insurance.rule_zero' => 'Always send 0 (no insurance)',
        'language.en' => 'English',
        'language.ru' => 'Russian',
        'language.uk' => 'Ukrainian',
        'parcel.height' => 'Default parcel height (cm)',
        'parcel.length' => 'Default parcel length (cm)',
        'parcel.width' => 'Default parcel width (cm)',
        'purpose.gift' => 'Gift',
        'purpose.label' => 'Shipment purpose',
        'purpose.personal' => 'Personal use',
        'purpose.return' => 'Return',
        'purpose.sale' => 'Sale of goods',
        'purpose.sample' => 'Commercial sample',
        'settings.legend' => 'GlobalPost settings',
        'settings.saved' => 'Settings updated successfully.',
        'settings.carriers_title' => 'Configured GlobalPost carriers',
        'settings.carriers_type' => 'Shipment type',
        'settings.carriers_id' => 'Carrier ID',
        'settings.carriers_name' => 'Carrier name',
        'settings.carriers_delay' => 'Delay (current language)',
        'settings.carriers_default' => 'Default status',
        'settings.carriers_default_yes' => 'Default carrier',
        'settings.carriers_default_no' => 'Not default',
        'settings.carriers_missing' => 'Carrier record is missing or was deleted.',
        'settings.carriers_empty' => 'No GlobalPost carriers are configured yet.',
        'tracking.hint' => 'Use @ as a placeholder for the tracking number.',
        'tracking.template' => 'Tracking URL template',
        'types.available' => 'Shipment type mode',
        'types.available_desc' => 'Choose whether to offer both shipment types or fix a single type for all orders.',
        'types.documents' => 'Documents',
        'types.mode_both' => 'Offer documents and parcels',
        'types.mode_documents' => 'Documents only',
        'types.mode_parcel' => 'Parcels only',
        'types.parcel' => 'Parcel',
        'settings.debug' => 'Enable debug logging',
        'settings.debug_desc' => 'Logs sanitized API calls for troubleshooting. Disable once issues are resolved.',
    ];

    /**
     * Mapping prefixes to translation domains.
     */
    private const TRANSLATION_DOMAIN_MAP = [
        'front.' => 'Modules.Globalpostshipping.Shop',
        'carrier.' => 'Modules.Globalpostshipping.Shop',
        'admin.' => 'Modules.Globalpostshipping.Admin',
        'settings.' => 'Modules.Globalpostshipping.Admin',
        'api.' => 'Modules.Globalpostshipping.Admin',
        'country.' => 'Modules.Globalpostshipping.Admin',
        'types.' => 'Modules.Globalpostshipping.Admin',
        'parcel.' => 'Modules.Globalpostshipping.Admin',
        'incoterms.' => 'Modules.Globalpostshipping.Admin',
        'purpose.' => 'Modules.Globalpostshipping.Admin',
        'currency.' => 'Modules.Globalpostshipping.Admin',
        'insurance.' => 'Modules.Globalpostshipping.Admin',
        'common.' => 'Modules.Globalpostshipping.Admin',
        'autocreate.' => 'Modules.Globalpostshipping.Admin',
        'tracking.' => 'Modules.Globalpostshipping.Admin',
        'document.' => 'Modules.Globalpostshipping.Admin',
        'language.' => 'Modules.Globalpostshipping.Admin',
        'button.' => 'Modules.Globalpostshipping.Admin',
        'error.' => 'Modules.Globalpostshipping.Admin',
    ];

    public function __construct()
    {
        $this->name = 'globalpostshipping';
        $this->tab = 'shipping_logistics';
        $this->version = '0.1.0';
        $this->author = 'GlobalPost';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('GlobalPost Shipping', [], 'Modules.Globalpostshipping.Admin');
        $this->description = $this->trans('Provides GlobalPost shipping services integration.', [], 'Modules.Globalpostshipping.Admin');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall the GlobalPost Shipping module?', [], 'Modules.Globalpostshipping.Admin');
    }

    /**
     * {@inheritDoc}
     */
    public function install()
    {
        return parent::install()
            && $this->registerHooks()
            && $this->getDatabaseInstaller()->install()
            && $this->installConfiguration()
            && $this->installCarriers();
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall()
    {
        return $this->removeCarriers()
            && $this->removeConfiguration()
            && $this->getDatabaseInstaller()->uninstall()
            && parent::uninstall();
    }

    /**
     * {@inheritDoc}
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitGlobalpostshippingModule')) {
            $formData = $this->getFormValuesFromRequest();
            $errors = $this->validateForm($formData);

            if (empty($errors)) {
                $this->saveConfiguration($formData);
                $output .= $this->displayConfirmation($this->translate('settings.saved'));
            } else {
                foreach ($errors as $error) {
                    $output .= $this->displayError($this->translate($error));
                }
            }
        }

        $carrierSummary = $this->buildCarrierSummaryForSettings();
        $hasMissingCarrier = false;

        foreach ($carrierSummary as $carrier) {
            if (!empty($carrier['missing'])) {
                $hasMissingCarrier = true;

                break;
            }
        }

        $this->context->smarty->assign([
            'globalpost_carriers' => $carrierSummary,
            'globalpost_carriers_missing' => $hasMissingCarrier,
            'globalpost_carriers_labels' => [
                'title' => $this->translate('settings.carriers_title'),
                'type' => $this->translate('settings.carriers_type'),
                'id' => $this->translate('settings.carriers_id'),
                'name' => $this->translate('settings.carriers_name'),
                'delay' => $this->translate('settings.carriers_delay'),
                'default' => $this->translate('settings.carriers_default'),
                'default_yes' => $this->translate('settings.carriers_default_yes'),
                'default_no' => $this->translate('settings.carriers_default_no'),
                'missing' => $this->translate('settings.carriers_missing'),
                'empty' => $this->translate('settings.carriers_empty'),
            ],
        ]);

        return $output
            . $this->renderForm()
            . $this->display(__FILE__, 'views/templates/admin/form.tpl');
    }

    /**
     * Registers hooks declared in the module.
     *
     * @return bool
     */
    private function registerHooks()
    {
        $result = true;

        foreach ($this->hooks as $hook) {
            $result = $result && $this->registerHook($hook);
        }

        return $result;
    }

    /**
     * Retrieves the database installer service.
     */
    private function getDatabaseInstaller(): DatabaseInstaller
    {
        require_once _PS_MODULE_DIR_ . 'globalpostshipping/src/Installer/DatabaseInstaller.php';

        return new DatabaseInstaller(Db::getInstance(), _DB_PREFIX_, _MYSQL_ENGINE_);
    }

    /**
     * Saves configuration defaults during installation.
     */
    private function installConfiguration(): bool
    {
        $defaults = [
            'GLOBALPOST_CARRIER_DOCUMENTS_ID' => 0,
            'GLOBALPOST_CARRIER_PARCEL_ID' => 0,
            'GLOBALPOST_API_TOKEN_TEST' => '',
            'GLOBALPOST_API_TOKEN_PROD' => '',
            'GLOBALPOST_API_MODE' => 0,
            'GLOBALPOST_API_IDENTIFIER' => '',
            'GLOBALPOST_COUNTRY_FROM' => 'UA',
            'GLOBALPOST_TYPE_MODE' => 'parcel',
            'GLOBALPOST_TYPE_DOCUMENTS' => 0,
            'GLOBALPOST_TYPE_PARCEL' => 1,
            'GLOBALPOST_PARCEL_LENGTH' => '0',
            'GLOBALPOST_PARCEL_WIDTH' => '0',
            'GLOBALPOST_PARCEL_HEIGHT' => '0',
            'GLOBALPOST_INCOTERMS' => 'DAP',
            'GLOBALPOST_PURPOSE' => 'sale',
            'GLOBALPOST_CURRENCY_INVOICE' => 'UAH',
            'GLOBALPOST_INSURANCE_ENABLED' => 0,
            'GLOBALPOST_INSURANCE_RULE' => 'order_total',
            'GLOBALPOST_AUTO_CREATE_SHIPMENT' => 1,
            'GLOBALPOST_TRACKING_TEMPLATE' => 'https://track.globalpost.com.ua/@',
            'GLOBALPOST_DOCUMENT_LANGUAGE' => 'uk',
            'GLOBALPOST_DEBUG_LOG' => 0,
        ];

        foreach ($defaults as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Creates the carrier entries required for the module.
     */
    private function installCarriers(): bool
    {
        $success = true;

        foreach (self::SUPPORTED_SHIPMENT_TYPES as $type) {
            $success = $this->ensureCarrierExists($type) && $success;
        }

        return $success;
    }

    /**
     * Marks module carriers as deleted during uninstall.
     */
    private function removeCarriers(): bool
    {
        $success = true;

        foreach (self::SUPPORTED_SHIPMENT_TYPES as $type) {
            $configKey = self::SHIPMENT_TYPE_CONFIGURATION[$type] ?? null;
            if ($configKey === null) {
                continue;
            }

            $carrierId = (int) $this->getConfigurationValue($configKey);
            if ($carrierId <= 0) {
                continue;
            }

            $carrier = new Carrier($carrierId);
            if (!Validate::isLoadedObject($carrier)) {
                continue;
            }

            $carrier->active = 0;
            $carrier->deleted = 1;
            $success = $carrier->update() && $success;
        }

        return $success;
    }

    /**
     * Removes module configuration on uninstall.
     */
    private function removeConfiguration(): bool
    {
        foreach (self::CONFIGURATION_KEYS as $key) {
            if (!Configuration::deleteByName($key)) {
                return false;
            }
        }

        return true;
    }

    private function ensureCarrierExists(string $type): bool
    {
        $configKey = self::SHIPMENT_TYPE_CONFIGURATION[$type] ?? null;
        if ($configKey === null) {
            return false;
        }

        $carrierId = (int) $this->getConfigurationValue($configKey);
        if ($carrierId > 0) {
            $carrier = new Carrier($carrierId);
            if (Validate::isLoadedObject($carrier)) {
                return true;
            }
        }

        $carrier = $this->createCarrier($type);
        if ($carrier === null) {
            return false;
        }

        $this->updateConfigurationValue($configKey, (int) $carrier->id);

        return true;
    }

    private function createCarrier(string $type): ?Carrier
    {
        $carrier = new Carrier();
        $carrier->name = $this->getCarrierName($type);
        $carrier->id_tax_rules_group = 0;
        $carrier->active = 1;
        $carrier->deleted = 0;
        $carrier->shipping_handling = false;
        $carrier->range_behavior = 0;
        $carrier->is_module = true;
        $carrier->shipping_external = true;
        $carrier->external_module_name = $this->name;
        $carrier->need_range = true;
        $carrier->url = (string) $this->getConfigurationValue('GLOBALPOST_TRACKING_TEMPLATE');
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_WEIGHT;
        $carrier->is_default = 0;

        foreach (Language::getLanguages(false) as $language) {
            $carrier->delay[(int) $language['id_lang']] = $this->translate('carrier.default_delay');
        }

        if (!$carrier->add()) {
            return null;
        }

        $this->assignCarrierToGroups($carrier);
        $this->assignCarrierToZones($carrier);
        $this->initializeCarrierRanges($carrier);

        return $carrier;
    }

    private function assignCarrierToGroups(Carrier $carrier): void
    {
        $groups = Group::getGroups((int) $this->context->language->id);
        if (!is_array($groups)) {
            return;
        }

        $groupIds = array_map(static function (array $group): int {
            return (int) $group['id_group'];
        }, $groups);

        if (method_exists($carrier, 'setGroups')) {
            $carrier->setGroups($groupIds);

            return;
        }

        Db::getInstance()->delete('carrier_group', 'id_carrier = ' . (int) $carrier->id);

        foreach ($groupIds as $groupId) {
            Db::getInstance()->insert('carrier_group', [
                'id_carrier' => (int) $carrier->id,
                'id_group' => (int) $groupId,
            ]);
        }
    }

    private function assignCarrierToZones(Carrier $carrier): void
    {
        $zones = Zone::getZones(true);
        if (!is_array($zones)) {
            return;
        }

        foreach ($zones as $zone) {
            $carrier->addZone((int) $zone['id_zone']);
        }
    }

    private function initializeCarrierRanges(Carrier $carrier): void
    {
        $rangeWeight = new RangeWeight();
        $rangeWeight->id_carrier = (int) $carrier->id;
        $rangeWeight->delimiter1 = 0;
        $rangeWeight->delimiter2 = 1000;

        if (!$rangeWeight->add()) {
            return;
        }

        $zones = Zone::getZones(true);
        if (!is_array($zones)) {
            return;
        }

        Db::getInstance()->delete('delivery', 'id_carrier = ' . (int) $carrier->id);

        $idShop = isset($this->context->shop->id) ? (int) $this->context->shop->id : 0;
        $idShopGroup = isset($this->context->shop->id_shop_group) ? (int) $this->context->shop->id_shop_group : 0;

        foreach ($zones as $zone) {
            Db::getInstance()->insert('delivery', [
                'id_carrier' => (int) $carrier->id,
                'id_range_price' => null,
                'id_range_weight' => (int) $rangeWeight->id,
                'id_zone' => (int) $zone['id_zone'],
                'price' => 0,
                'id_shop' => $idShop,
                'id_shop_group' => $idShopGroup,
            ]);
        }
    }

    private function getCarrierName(string $type): string
    {
        switch ($type) {
            case 'documents':
                return $this->translate('carrier.documents_name');
            case 'parcel':
                return $this->translate('carrier.parcel_name');
            default:
                return 'GlobalPost';
        }
    }

    public function hookActionCarrierProcess(array $params)
    {
        $this->ensureOrderTableSupportsNullCart();

        $cart = $this->resolveCartFromParams($params);
        if (!$cart instanceof Cart || !$cart->id) {
            return;
        }

        $submitted = Tools::getValue('globalpost_option');
        if (!is_array($submitted)) {
            $submitted = [];
        }

        foreach ($this->getEnabledShipmentTypes() as $type) {
            $carrierId = $this->getCarrierIdForType($type);
            if ($carrierId <= 0) {
                continue;
            }

            $tariffData = $this->getTariffDataForCart($cart, $type);
            $options = $tariffData['options'];
            $context = $tariffData['context'];

            if ($context === null || empty($options)) {
                $this->clearCartSelection((int) $cart->id, $type);
                continue;
            }

            $record = $this->getCartRecord((int) $cart->id, $type);
            $requestedKey = isset($submitted[$type]) ? (string) $submitted[$type] : null;
            $selectedOption = $this->determineSelectedOption($record, $options, $requestedKey);

            if ($selectedOption === null) {
                $this->clearCartSelection((int) $cart->id, $type);
                continue;
            }

            $this->tariffOptionsCache[(int) $cart->id][$type] = [
                'signature' => $context['signature'],
                'options' => $options,
            ];

            $this->saveCartSelection($cart, $type, $selectedOption, $context, $options, $record);
        }
    }

    public function hookActionGetDeliveryOptions(array $params)
    {
        $cart = $this->resolveCartFromParams($params);
        if (!$cart instanceof Cart || !$cart->id) {
            return;
        }

        foreach ($this->getEnabledShipmentTypes() as $type) {
            $this->getTariffDataForCart($cart, $type);
        }
    }

    public function hookDisplayCarrierExtraContent(array $params)
    {
        if (empty($params['carrier']['id'])) {
            return '';
        }

        $carrierId = (int) $params['carrier']['id'];
        $type = $this->resolveCarrierType($carrierId);
        if ($type === null) {
            return '';
        }

        $cart = $this->context->cart;
        if (!$cart instanceof Cart || !$cart->id) {
            return '';
        }

        $tariffData = $this->getTariffDataForCart($cart, $type);
        $options = $tariffData['options'];
        if (empty($options)) {
            return '';
        }

        $record = $this->getCartRecord((int) $cart->id, $type);
        $selectedKey = null;
        if (is_array($record) && !empty($record['tariff_key'])) {
            $selectedKey = (string) $record['tariff_key'];
        }

        $this->context->smarty->assign([
            'globalpost_title' => $this->translate('front.select_tariff'),
            'globalpost_options' => $this->formatOptionsForTemplate($options),
            'globalpost_selected_key' => $selectedKey,
            'globalpost_type' => $type,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/carrier_options.tpl');
    }

    public function hookActionValidateOrder(array $params)
    {
        $this->ensureOrderTableSupportsNullCart();

        if (empty($params['order']) || empty($params['cart'])) {
            return;
        }

        $order = $params['order'];
        $cart = $params['cart'];
        if (!$order instanceof Order || !$cart instanceof Cart) {
            return;
        }

        $type = $this->resolveCarrierType((int) $order->id_carrier);
        if ($type === null) {
            return;
        }

        Db::getInstance()->update(
            'globalpost_order',
            [
                'id_order' => (int) $order->id,
                'id_cart' => null,
            ],
            'id_cart = ' . (int) $cart->id . ' AND type = "' . pSQL($type) . '"'
        );

        if ((int) $this->getConfigurationValue('GLOBALPOST_AUTO_CREATE_SHIPMENT') !== 1) {
            return;
        }

        try {
            $this->createShipmentForOrder($order, $cart, $type);
        } catch (Throwable $exception) {
            $this->logError('Failed to auto-create GlobalPost shipment: ' . $exception->getMessage());
        }
    }

    public function hookDisplayAdminOrderMainBottom(array $params)
    {
        $order = $this->resolveOrderFromParams($params);
        if (!$order instanceof Order || !(int) $order->id) {
            return '';
        }

        $record = $this->getLatestOrderRecord((int) $order->id);
        if (!$record) {
            return '';
        }

        $data = $this->buildAdminOrderViewData($order, $record);
        if ($data === null) {
            return '';
        }

        $flash = $this->buildAdminFlashMessage();
        if ($flash !== null) {
            $data['flash'] = $flash;
        }

        $this->context->smarty->assign([
            'globalpost_admin' => $data,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/admin_order.tpl');
    }

    public function hookDisplayOrderDetail(array $params)
    {
        $order = $this->resolveOrderFromParams($params);
        if (!$order instanceof Order || !(int) $order->id) {
            return '';
        }

        $record = $this->getLatestOrderRecord((int) $order->id);
        if (!$record) {
            return '';
        }

        $trackingNumber = trim((string) ($record['ttn'] ?? ''));
        if ($trackingNumber === '') {
            return '';
        }

        $this->updateTrackingNumber($order, $trackingNumber);
        $trackingUrl = $this->buildTrackingUrl($trackingNumber);

        $this->context->smarty->assign([
            'globalpost_tracking' => [
                'title' => $this->translate('front.tracking_title'),
                'label_number' => $this->translate('front.tracking_number_label'),
                'label_link' => $this->translate('front.tracking_link_label'),
                'number' => $trackingNumber,
                'url' => $trackingUrl,
            ],
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order_tracking.tpl');
    }

    public function hookActionGetExtraMailTemplateVars(array $params)
    {
        if (($params['template'] ?? '') !== 'shipped') {
            return [];
        }

        $orderId = (int) ($params['id_order'] ?? 0);
        if ($orderId <= 0) {
            return [];
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return [];
        }

        $record = $this->getLatestOrderRecord((int) $order->id);
        if (!$record) {
            return [];
        }

        $trackingNumber = trim((string) ($record['ttn'] ?? ''));
        if ($trackingNumber === '') {
            return [];
        }

        $this->updateTrackingNumber($order, $trackingNumber);
        $trackingUrl = $this->buildTrackingUrl($trackingNumber);

        $templateVars = [
            '{shipping_number}' => $trackingNumber,
            '{globalpost_tracking_number}' => $trackingNumber,
        ];

        if ($trackingUrl !== null) {
            $templateVars['{followup}'] = $trackingUrl;
            $templateVars['{globalpost_tracking_url}'] = $trackingUrl;
        }

        return $templateVars;
    }

    private function buildAdminFlashMessage(): ?array
    {
        $notice = trim((string) Tools::getValue('globalpost_notice'));
        if ($notice !== '') {
            return [
                'type' => 'success',
                'message' => $this->translate('admin.notice.' . $notice),
            ];
        }

        $errorMessage = trim((string) Tools::getValue('globalpost_error_message'));
        if ($errorMessage !== '') {
            return [
                'type' => 'error',
                'message' => Tools::htmlentitiesUTF8($errorMessage),
            ];
        }

        $error = trim((string) Tools::getValue('globalpost_error'));
        if ($error !== '') {
            return [
                'type' => 'error',
                'message' => $this->translate('admin.error.' . $error),
            ];
        }

        return null;
    }

    private function buildAdminOrderViewData(Order $order, array $record): ?array
    {
        $type = $this->normalizeShipmentType($record['type'] ?? null);
        if ($type === null) {
            $type = $this->normalizeShipmentType($this->resolveCarrierType((int) $order->id_carrier));
        }

        if ($type === null) {
            $type = 'parcel';
        }

        $selectedOption = $this->resolveSelectedOptionFromRecord($record) ?? [];

        $tariffKey = $record['tariff_key'] ?? ($selectedOption['key'] ?? '');
        $tariffId = $record['international_tariff_id'] ?? ($selectedOption['international_tariff_id'] ?? null);
        $priceUah = $record['price_uah'] ?? ($selectedOption['price_uah'] ?? ($selectedOption['price'] ?? null));
        $priceEur = $record['price_eur'] ?? ($selectedOption['price_eur'] ?? null);
        $estimateRaw = $record['estimate_in_days'] ?? ($selectedOption['estimate_in_days'] ?? null);
        $estimate = is_numeric($estimateRaw) ? (int) $estimateRaw : null;
        if ($estimate !== null && $estimate < 0) {
            $estimate = null;
        }

        $trackingNumber = trim((string) ($record['ttn'] ?? ''));
        if ($trackingNumber !== '') {
            $this->updateTrackingNumber($order, $trackingNumber);
        }

        $status = $this->summarizeShipmentStatus($record);

        $priceText = null;
        if ($priceUah !== null) {
            $priceText = sprintf('%s UAH', $this->formatPriceValue((float) $priceUah));
            if ($priceEur !== null) {
                $priceText .= ' / ' . sprintf('%s EUR', $this->formatPriceValue((float) $priceEur));
            }
        } elseif ($priceEur !== null) {
            $priceText = sprintf('%s EUR', $this->formatPriceValue((float) $priceEur));
        }

        $estimateText = null;
        if ($estimate !== null && $estimate > 0) {
            $estimateText = sprintf($this->translate('admin.estimate_days'), $estimate);
        }

        $trackingUrl = $this->buildTrackingUrl($trackingNumber);

        $actions = [];
        if ($status['code'] !== 'success') {
            $actions['create'] = [
                'url' => $this->context->link->getAdminLink('AdminGlobalPostShipments', true, [], [
                    'action' => 'createShipment',
                    'id_order' => (int) $order->id,
                ]),
                'label' => $this->translate('admin.button.create'),
            ];
        }

        if (!empty($record['shipment_id'])) {
            $actions['label'] = [
                'url' => $this->context->link->getAdminLink('AdminGlobalPostShipments', true, [], [
                    'action' => 'downloadLabel',
                    'id_order' => (int) $order->id,
                ]),
                'label' => $this->translate('admin.button.download_label'),
            ];

            if ($type === 'parcel') {
                $actions['invoice'] = [
                    'url' => $this->context->link->getAdminLink('AdminGlobalPostShipments', true, [], [
                        'action' => 'downloadInvoice',
                        'id_order' => (int) $order->id,
                    ]),
                    'label' => $this->translate('admin.button.download_invoice'),
                ];
            }
        }

        return [
            'title' => $this->translate('admin.block_title'),
            'type_label' => $this->translate('admin.type.' . $type),
            'order_id' => (int) $order->id,
            'labels' => [
                'status' => $this->translate('admin.label.status'),
                'tariff' => $this->translate('admin.label.tariff'),
                'tariff_key' => $this->translate('admin.label.tariff_key'),
                'tariff_id' => $this->translate('admin.label.tariff_id'),
                'price' => $this->translate('admin.label.price'),
                'estimate' => $this->translate('admin.label.estimate'),
                'shipment_id' => $this->translate('admin.label.shipment_id'),
                'ttn' => $this->translate('admin.label.ttn'),
                'tracking' => $this->translate('admin.label.tracking_url'),
                'last_message' => $this->translate('admin.label.last_message'),
            ],
            'status' => [
                'code' => $status['code'],
                'label' => $this->translate('admin.status.' . $status['code']),
                'badge' => $status['badge'],
                'message' => $status['message'],
            ],
            'tariff' => [
                'key' => (string) $tariffKey,
                'id' => $tariffId !== null ? (string) $tariffId : '',
                'price_text' => $priceText,
                'estimate_text' => $estimateText,
            ],
            'shipment_id' => (string) ($record['shipment_id'] ?? ''),
            'ttn' => $trackingNumber,
            'tracking_url' => $trackingUrl,
            'actions' => $actions,
        ];
    }

    private function summarizeShipmentStatus(array $record): array
    {
        $payload = $this->decodePayload($record['payload'] ?? null);
        $entries = [];

        if (!empty($payload['shipment_logs']) && is_array($payload['shipment_logs'])) {
            foreach ($payload['shipment_logs'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $entries[] = [
                    'status' => isset($entry['status']) ? (string) $entry['status'] : '',
                    'message' => isset($entry['message']) ? (string) $entry['message'] : '',
                ];
            }
        }

        $status = 'pending';
        $message = '';

        if (!empty($entries)) {
            $last = $entries[count($entries) - 1];
            if (!empty($last['status'])) {
                $status = Tools::strtolower((string) $last['status']);
            }

            if (!empty($last['message'])) {
                $message = (string) $last['message'];
            }
        }

        if ($status === 'error') {
            $status = 'failed';
        }

        if (!in_array($status, ['pending', 'success', 'failed'], true)) {
            $status = 'pending';
        }

        if ($status === 'pending' && !empty($record['shipment_id']) && !empty($record['ttn'])) {
            $status = 'success';
        }

        $badge = 'secondary';
        if ($status === 'success') {
            $badge = 'success';
        } elseif ($status === 'failed') {
            $badge = 'danger';
        } else {
            $badge = 'warning';
        }

        return [
            'code' => $status,
            'badge' => $badge,
            'message' => $message,
        ];
    }

    private function normalizeShipmentType($type): ?string
    {
        if (!is_string($type) || $type === '') {
            return null;
        }

        $normalized = Tools::strtolower($type);

        if ($normalized === 'docs') {
            $normalized = 'documents';
        }

        return in_array($normalized, ['documents', 'parcel'], true) ? $normalized : null;
    }

    private function buildTrackingUrl($ttn): ?string
    {
        $trackingNumber = trim((string) $ttn);
        if ($trackingNumber === '') {
            return null;
        }

        $template = (string) $this->getConfigurationValue('GLOBALPOST_TRACKING_TEMPLATE');
        if ($template === '' || strpos($template, '@') === false) {
            return null;
        }

        return str_replace('@', rawurlencode($trackingNumber), $template);
    }

    private function buildDocumentFileName(string $prefix, array $record): string
    {
        $identifier = '';
        if (!empty($record['ttn'])) {
            $identifier = (string) $record['ttn'];
        } elseif (!empty($record['shipment_id'])) {
            $identifier = (string) $record['shipment_id'];
        }

        $identifier = trim($identifier);
        if ($identifier === '') {
            $identifier = 'shipment';
        }

        $sanitized = preg_replace('/[^A-Za-z0-9\-_]+/', '-', $identifier);
        if (!is_string($sanitized) || $sanitized === '') {
            $sanitized = 'shipment';
        }

        $sanitized = trim($sanitized, '-');
        if ($sanitized === '') {
            $sanitized = 'shipment';
        }

        return sprintf('%s-%s.pdf', $prefix, $sanitized);
    }

    private function createShipmentForOrder(Order $order, Cart $cart, string $type): array
    {
        $result = ['success' => false, 'code' => 'creation_failed'];

        $record = $this->getOrderRecordByOrderId((int) $order->id, $type);
        if (!$record) {
            return $result;
        }

        if (!empty($record['shipment_id']) || !empty($record['ttn'])) {
            return ['success' => true, 'code' => 'already_created'];
        }

        $client = $this->createApiClient();
        if ($client === null) {
            $message = $this->translate('admin.error.api_unavailable');
            $this->logError('GlobalPost API token is not configured.');

            return ['success' => false, 'code' => 'api_unavailable', 'message' => $message];
        }

        $formData = $this->buildShipmentFormData($order, $cart, $type, $record);
        if ($formData === null) {
            $this->logError('Failed to assemble GlobalPost shipment payload for order #' . (int) $order->id . '.');

            return $result;
        }

        $validationErrors = $this->validateShipmentPayload($formData);
        if (!empty($validationErrors)) {
            $message = $this->translate('admin.error.validation_failed');
            $this->storeShipmentLog($record, $formData, null, 'failed', $message, [
                'validation_errors' => $validationErrors,
            ]);
            $this->logError('GlobalPost shipment creation aborted: invalid contact data.');

            return ['success' => false, 'code' => 'validation_failed', 'message' => $message];
        }

        try {
            $response = $client->createShortOrder($formData);
        } catch (GlobalPostClientException $exception) {
            $mapped = ErrorMapper::fromClientException($exception);
            $responseBody = $exception->getResponseBody();
            $decoded = $responseBody ? json_decode($responseBody, true) : null;
            $this->storeShipmentLog(
                $record,
                $formData,
                is_array($decoded) ? $decoded : $responseBody,
                'failed',
                $mapped['admin_message'],
                [
                    'http_status' => $mapped['status'],
                    'api_code' => $mapped['api_code'],
                ]
            );
            $this->logError('GlobalPost shipment creation failed: ' . $mapped['log_message']);

            return ['success' => false, 'code' => $mapped['code'], 'message' => $mapped['admin_message']];
        } catch (Throwable $exception) {
            $mapped = ErrorMapper::fromTransportException($exception);
            $this->storeShipmentLog($record, $formData, null, 'failed', $mapped['admin_message']);
            $this->logError('GlobalPost shipment creation failed: ' . $mapped['log_message']);

            return ['success' => false, 'code' => $mapped['code'], 'message' => $mapped['admin_message']];
        }

        $shipmentId = isset($response['id']) ? (string) $response['id'] : '';
        $ttn = isset($response['number']) ? (string) $response['number'] : '';

        if ($shipmentId === '' || $ttn === '') {
            $message = 'GlobalPost response missing shipment identifiers.';
            $this->storeShipmentLog($record, $formData, $response, 'failed', $message);
            $this->logError('GlobalPost shipment creation failed: response did not contain shipment identifiers.');

            return ['success' => false, 'code' => 'creation_failed', 'message' => $message];
        }

        $this->updateTrackingNumber($order, $ttn);

        $this->storeShipmentLog($record, $formData, $response, 'success', null, [
            'shipment_id' => $shipmentId,
            'ttn' => $ttn,
        ]);

        Db::getInstance()->update(
            'globalpost_order',
            [
                'shipment_id' => pSQL($shipmentId),
                'ttn' => pSQL($ttn),
            ],
            'id_globalpost_order = ' . (int) $record['id_globalpost_order']
        );

        return ['success' => true, 'code' => 'created'];
    }

    private function getOrderRecordByOrderId(int $orderId, string $type): ?array
    {
        return $this->getOrderRecord($orderId, $type);
    }

    private function getOrderRecord(int $orderId, ?string $type = null): ?array
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('globalpost_order');
        $query->where('id_order = ' . (int) $orderId);
        if ($type !== null && $type !== '') {
            $query->where("type = '" . pSQL($type) . "'");
        }
        $query->orderBy('id_globalpost_order DESC');
        $query->limit(1);

        $row = Db::getInstance()->getRow($query);

        return $row ?: null;
    }

    private function getOrderRecordById(int $recordId): ?array
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('globalpost_order');
        $query->where('id_globalpost_order = ' . (int) $recordId);
        $query->limit(1);

        $row = Db::getInstance()->getRow($query);

        return $row ?: null;
    }

    private function getLatestOrderRecord(int $orderId): ?array
    {
        return $this->getOrderRecord($orderId, null);
    }

    public function handleManualShipmentCreation(int $orderId): array
    {
        if ($orderId <= 0) {
            $this->logError('Manual GlobalPost shipment creation failed: invalid order ID.');

            return ['success' => false, 'code' => 'invalid_order'];
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            $this->logError('Manual GlobalPost shipment creation failed: order #' . (int) $orderId . ' not found.');

            return ['success' => false, 'code' => 'invalid_order'];
        }

        $cart = new Cart((int) $order->id_cart);
        if (!Validate::isLoadedObject($cart)) {
            $this->logError('Manual GlobalPost shipment creation failed: cart not found for order #' . (int) $orderId . '.');

            return ['success' => false, 'code' => 'cart_missing'];
        }

        $type = $this->resolveCarrierType((int) $order->id_carrier);
        $record = null;

        if ($type !== null) {
            $record = $this->getOrderRecordByOrderId((int) $order->id, $type);
        }

        if (!$record) {
            $record = $this->getLatestOrderRecord((int) $order->id);
            if ($record) {
                $type = $this->normalizeShipmentType($record['type'] ?? null);
            }
        }

        if (!$record || $type === null) {
            $this->logError('Manual GlobalPost shipment creation failed: no stored tariff data for order #' . (int) $orderId . '.');

            return ['success' => false, 'code' => 'record_missing'];
        }

        if (!empty($record['shipment_id']) && !empty($record['ttn'])) {
            return ['success' => true, 'code' => 'already_created'];
        }

        $creationResult = $this->createShipmentForOrder($order, $cart, $type);

        if (!empty($creationResult['success'])) {
            return ['success' => true, 'code' => $creationResult['code'] ?? 'created'];
        }

        $updatedRecord = $this->getOrderRecordById((int) $record['id_globalpost_order']);
        if (!$updatedRecord) {
            $updatedRecord = $this->getOrderRecordByOrderId((int) $order->id, $type);
        }

        if (!empty($updatedRecord['shipment_id']) && !empty($updatedRecord['ttn'])) {
            return ['success' => true, 'code' => 'created'];
        }

        if (!empty($creationResult['code'])) {
            return [
                'success' => false,
                'code' => (string) $creationResult['code'],
                'message' => isset($creationResult['message']) ? (string) $creationResult['message'] : null,
            ];
        }

        return ['success' => false, 'code' => 'creation_failed'];
    }

    public function fetchShipmentDocument(int $orderId, string $document): array
    {
        if ($orderId <= 0) {
            return ['success' => false, 'code' => 'invalid_order', 'message' => $this->translate('admin.error.invalid_order')];
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return ['success' => false, 'code' => 'invalid_order', 'message' => $this->translate('admin.error.invalid_order')];
        }

        $record = $this->getLatestOrderRecord((int) $order->id);
        if (!$record || empty($record['shipment_id'])) {
            $this->logError('GlobalPost document download failed: shipment data missing for order #' . (int) $orderId . '.');

            return ['success' => false, 'code' => 'shipment_missing', 'message' => $this->translate('admin.error.shipment_missing')];
        }

        $type = $this->normalizeShipmentType($record['type'] ?? null);
        if ($document === 'invoice' && $type !== 'parcel') {
            return ['success' => false, 'code' => 'invoice_unavailable', 'message' => $this->translate('admin.error.invoice_unavailable')];
        }

        $client = $this->createApiClient();
        if ($client === null) {
            $this->logError('GlobalPost document download failed: API token not configured.');

            return ['success' => false, 'code' => 'api_unavailable', 'message' => $this->translate('admin.error.api_unavailable')];
        }

        try {
            if ($document === 'label') {
                $locale = $this->resolveDocumentLanguage((string) $this->getConfigurationValue('GLOBALPOST_DOCUMENT_LANGUAGE'));
                $content = $client->printLabel($locale, (string) $record['shipment_id']);
                $filename = $this->buildDocumentFileName('globalpost-label', $record);
            } else {
                $content = $client->printInvoice((string) $record['shipment_id']);
                $filename = $this->buildDocumentFileName('globalpost-invoice', $record);
            }
        } catch (GlobalPostClientException $exception) {
            $mapped = ErrorMapper::fromClientException($exception);
            $this->logError('GlobalPost document download failed: ' . $mapped['log_message']);

            return ['success' => false, 'code' => $mapped['code'], 'message' => $mapped['admin_message']];
        } catch (Throwable $exception) {
            $mapped = ErrorMapper::fromTransportException($exception);
            $this->logError('GlobalPost document download failed: ' . $mapped['log_message']);

            return ['success' => false, 'code' => $mapped['code'], 'message' => $mapped['admin_message']];
        }

        if ($content === '') {
            $this->logError('GlobalPost document download failed: empty response for order #' . (int) $orderId . '.');

            return ['success' => false, 'code' => 'empty_document', 'message' => $this->translate('admin.error.empty_document')];
        }

        return [
            'success' => true,
            'filename' => $filename,
            'content' => $content,
        ];
    }

    private function buildShipmentFormData(Order $order, Cart $cart, string $type, array $record): ?array
    {
        $selectedOption = $this->resolveSelectedOptionFromRecord($record);
        if ($selectedOption === null || empty($selectedOption['contragent_key'])) {
            return null;
        }

        $deliveryAddress = new Address((int) $order->id_address_delivery);
        if (!Validate::isLoadedObject($deliveryAddress)) {
            return null;
        }

        $customer = new Customer((int) $order->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return null;
        }

        $sender = $this->buildSenderDetails();
        if ($sender === null) {
            return null;
        }

        $context = $this->buildTariffContext($cart, $type);
        $requestContext = is_array($context) && isset($context['request']) && is_array($context['request']) ? $context['request'] : [];

        $weight = $this->extractWeightFromContext($requestContext);

        $orderProducts = $this->getOrderProductsRaw($order);
        $calculatorProducts = $this->mapProductsForCalculator($orderProducts);
        $calculator = new CartMeasurementCalculator($calculatorProducts);

        $calculatedWeight = $calculator->calculateTotalWeight();
        if ($calculatedWeight > 0.0) {
            $weight = max($weight, $calculatedWeight);
        }

        if ($weight <= 0.0) {
            $weight = (float) $cart->getTotalWeight();
        }

        if ($weight <= 0.0 && method_exists($order, 'getTotalWeight')) {
            $weight = max($weight, (float) $order->getTotalWeight());
        }

        if ($weight < 0.01) {
            $weight = 0.01;
        }

        $insuredAmount = isset($requestContext['insured_amount']) ? (float) $requestContext['insured_amount'] : null;
        if ($insuredAmount === null) {
            $insuredAmount = $this->determineInsuredAmount($cart);
        }

        $insuredAmount = $insuredAmount !== null ? Tools::ps_round((float) $insuredAmount, 2) : null;

        $price = $selectedOption['price_uah'] ?? $record['price_uah'] ?? ($selectedOption['price'] ?? null);
        $price = $price !== null ? Tools::ps_round((float) $price, 2) : null;

        $formData = [
            'lang' => $this->resolveDocumentLanguage((string) $this->getConfigurationValue('GLOBALPOST_DOCUMENT_LANGUAGE')),
            'number_auto' => 1,
            'order_id' => (string) $order->reference,
            'is_international' => 1,
            'weight_type' => $this->mapWeightType($type),
            'weight' => Tools::ps_round($weight, 3),
            'places' => 1,
            'about' => sprintf('Order #%s', $order->reference),
            'contragent_key' => (string) $selectedOption['contragent_key'],
        ];

        if (!empty($selectedOption['international_tariff_id'])) {
            $formData['international_tariff_id'] = (int) $selectedOption['international_tariff_id'];
        } elseif (!empty($record['international_tariff_id'])) {
            $formData['international_tariff_id'] = (int) $record['international_tariff_id'];
        }

        if ($price !== null) {
            $formData['price'] = $price;
        }

        $formData['insured'] = $insuredAmount !== null && $insuredAmount > 0 ? 1 : 0;
        $formData['insured_amount'] = $insuredAmount !== null ? $insuredAmount : 0.0;

        $formData += $this->formatSenderData($sender);
        $formData += $this->formatRecipientData($deliveryAddress, $customer);

        if ($type === 'parcel') {
            $dimensions = $this->extractDimensionsFromContext($requestContext, $calculator);
            foreach ($dimensions as $key => $value) {
                $formData[$key] = $value;
            }

            $customs = $this->buildCustomsPayload($orderProducts, $order, $formData['weight']);
            if (!empty($customs)) {
                $formData += $customs;
            }
        }

        return $formData;
    }

    private function validateShipmentPayload(array $formData): array
    {
        $errors = [];

        foreach (['sender_name', 'recipient_name'] as $field) {
            $value = isset($formData[$field]) ? trim((string) $formData[$field]) : '';
            if ($value === '' || Tools::strlen($value) < 2) {
                $errors[] = ['field' => $field, 'error' => 'required'];
            }
        }

        foreach (['sender_address', 'recipient_address', 'sender_city', 'recipient_city'] as $field) {
            $value = isset($formData[$field]) ? trim((string) $formData[$field]) : '';
            if ($value === '' || Tools::strlen($value) < 3) {
                $errors[] = ['field' => $field, 'error' => 'invalid'];
            }
        }

        foreach (['sender_phone', 'recipient_phone'] as $field) {
            $value = isset($formData[$field]) ? (string) $formData[$field] : '';
            if ($value === '' || !Validate::isPhoneNumber($value)) {
                $errors[] = ['field' => $field, 'error' => 'invalid_phone'];
            }
        }

        foreach (['sender_email', 'recipient_email'] as $field) {
            $value = isset($formData[$field]) ? (string) $formData[$field] : '';
            if ($value === '' || !Validate::isEmail($value)) {
                $errors[] = ['field' => $field, 'error' => 'invalid_email'];
            }
        }

        return $errors;
    }

    private function resolveSelectedOptionFromRecord(array $record): ?array
    {
        $payload = [];

        if (!empty($record['payload'])) {
            $payload = $this->decodePayload((string) $record['payload']);
        }

        if (!empty($payload['selected_option']) && is_array($payload['selected_option'])) {
            return $payload['selected_option'];
        }

        if (!empty($payload['options']) && is_array($payload['options']) && !empty($record['tariff_key'])) {
            foreach ($payload['options'] as $option) {
                if (is_array($option) && isset($option['key']) && (string) $option['key'] === (string) $record['tariff_key']) {
                    return $option;
                }
            }
        }

        return null;
    }

    private function buildSenderDetails(): ?array
    {
        $identifier = trim((string) $this->getConfigurationValue('GLOBALPOST_API_IDENTIFIER'));
        $name = $identifier !== '' ? $identifier : (string) Configuration::get('PS_SHOP_NAME');

        $address1 = (string) Configuration::get('PS_SHOP_ADDR1');
        $address2 = (string) Configuration::get('PS_SHOP_ADDR2');
        $address = trim($address1 . ' ' . $address2);

        $city = (string) Configuration::get('PS_SHOP_CITY');
        $postcode = (string) Configuration::get('PS_SHOP_ZIP');

        $countryIso = '';
        $countryId = (int) Configuration::get('PS_SHOP_COUNTRY_ID');
        if ($countryId > 0) {
            $iso = Country::getIsoById($countryId);
            if (is_string($iso)) {
                $countryIso = $this->formatCountryIso($iso);
            }
        }

        if ($countryIso === '') {
            $countryIso = $this->formatCountryIso((string) $this->getConfigurationValue('GLOBALPOST_COUNTRY_FROM'));
        }

        $stateIso = '';
        $stateId = (int) Configuration::get('PS_SHOP_STATE_ID');
        if ($stateId > 0) {
            $stateIso = $this->resolveStateIso($stateId);
        }

        if ($name === '' || $address === '' || $city === '' || $countryIso === '') {
            return null;
        }

        $phone = (string) Configuration::get('PS_SHOP_PHONE');
        if ($phone === '') {
            $phone = (string) Configuration::get('PS_SHOP_MOBILE');
        }

        return [
            'name' => $name,
            'phone' => $phone,
            'email' => (string) Configuration::get('PS_SHOP_EMAIL'),
            'address' => $address,
            'city' => $city,
            'postcode' => $postcode,
            'country' => $countryIso,
            'state' => $stateIso,
        ];
    }

    private function extractWeightFromContext(array $context): float
    {
        if (isset($context['weight'])) {
            if (is_array($context['weight'])) {
                $value = reset($context['weight']);

                return (float) ($value ?: 0.0);
            }

            if (is_scalar($context['weight'])) {
                return (float) $context['weight'];
            }
        }

        return 0.0;
    }

    private function getOrderProductsRaw(Order $order): array
    {
        $products = [];

        if (method_exists($order, 'getProducts')) {
            try {
                $products = $order->getProducts();
            } catch (Throwable $exception) {
                $products = [];
            }
        }

        return is_array($products) ? $products : [];
    }

    private function mapProductsForCalculator(array $products): array
    {
        $mapped = [];

        foreach ($products as $product) {
            $quantity = (int) ($product['product_quantity'] ?? $product['quantity'] ?? 0);
            $weight = isset($product['product_weight']) ? (float) $product['product_weight'] : (float) ($product['weight'] ?? 0.0);
            $length = isset($product['product_length']) ? (float) $product['product_length'] : (float) ($product['length'] ?? ($product['depth'] ?? 0.0));
            $width = isset($product['product_width']) ? (float) $product['product_width'] : (float) ($product['width'] ?? 0.0);
            $height = isset($product['product_height']) ? (float) $product['product_height'] : (float) ($product['height'] ?? 0.0);

            $mapped[] = [
                'cart_quantity' => $quantity,
                'quantity' => $quantity,
                'weight' => $weight,
                'length' => $length,
                'width' => $width,
                'height' => $height,
            ];
        }

        return $mapped;
    }

    private function formatSenderData(array $sender): array
    {
        return [
            'sender_name' => $this->transliterateForApi($sender['name']),
            'sender_phone' => $this->normalizePhone($sender['phone']),
            'sender_email' => $sender['email'],
            'sender_country' => $this->formatCountryIso($sender['country']),
            'sender_city' => $this->transliterateForApi($sender['city']),
            'sender_address' => $this->transliterateForApi($sender['address']),
            'sender_zip' => $sender['postcode'],
            'sender_state' => $sender['state'],
        ];
    }

    private function formatRecipientData(Address $address, Customer $customer): array
    {
        $name = trim($address->firstname . ' ' . $address->lastname);
        if ($name === '') {
            $name = trim($customer->firstname . ' ' . $customer->lastname);
        }

        $phone = $address->phone_mobile !== '' ? $address->phone_mobile : $address->phone;
        $email = Validate::isEmail($customer->email) ? $customer->email : '';

        $countryIso = $this->formatCountryIso(Country::getIsoById((int) $address->id_country));
        $stateIso = $address->id_state ? $this->resolveStateIso((int) $address->id_state) : '';

        return [
            'recipient_name' => $this->transliterateForApi($name),
            'recipient_phone' => $this->normalizePhone($phone),
            'recipient_email' => $email,
            'recipient_country' => $countryIso,
            'recipient_city' => $this->transliterateForApi($address->city),
            'recipient_address' => $this->transliterateForApi(trim($address->address1 . ' ' . $address->address2)),
            'recipient_zip' => $address->postcode,
            'recipient_state' => $stateIso,
        ];
    }

    private function extractDimensionsFromContext(array $context, CartMeasurementCalculator $calculator): array
    {
        $dimensions = [];

        foreach (['length', 'width', 'height'] as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $value = (float) $context[$key];
                if ($value > 0.0) {
                    $dimensions[$key] = Tools::ps_round($value, 2);
                }
            }
        }

        if (count($dimensions) < 3) {
            $calculated = $calculator->calculateDimensions();
            foreach (['length', 'width', 'height'] as $key) {
                if (!isset($dimensions[$key]) && isset($calculated[$key]) && $calculated[$key] > 0.0) {
                    $dimensions[$key] = Tools::ps_round((float) $calculated[$key], 2);
                }
            }
        }

        return $dimensions;
    }

    private function buildCustomsPayload(array $orderProducts, Order $order, float $totalWeight): array
    {
        $purpose = (string) $this->getConfigurationValue('GLOBALPOST_PURPOSE');
        if ($purpose === '') {
            $purpose = 'sale';
        }

        $incoterms = (string) $this->getConfigurationValue('GLOBALPOST_INCOTERMS');
        if ($incoterms === '') {
            $incoterms = 'DAP';
        }

        $currencyIso = (string) $this->getConfigurationValue('GLOBALPOST_CURRENCY_INVOICE');
        if (!in_array($currencyIso, ['UAH', 'USD', 'EUR'], true)) {
            $currencyIso = 'UAH';
        }

        $invoiceCurrencyId = Currency::getIdByIsoCode($currencyIso);
        $invoiceCurrency = $invoiceCurrencyId ? new Currency($invoiceCurrencyId) : null;
        $orderCurrency = new Currency((int) $order->id_currency);

        $items = [
            'name_item' => [],
            'count_items' => [],
            'unit' => [],
            'price_unit' => [],
            'weight_unit' => [],
            'made_country' => [],
        ];

        $hsCodes = [];
        $sumInvoice = 0.0;
        $sumWeight = 0.0;

        foreach ($orderProducts as $product) {
            $quantity = (int) ($product['product_quantity'] ?? $product['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $name = $product['product_name'] ?? $product['name'] ?? 'Item';
            $name = $this->transliterateForApi($name);

            $unitWeight = isset($product['product_weight']) ? (float) $product['product_weight'] : (float) ($product['weight'] ?? 0.0);
            if ($unitWeight <= 0.0) {
                $unitWeight = max(0.01, $totalWeight > 0 ? $totalWeight / max(1, $quantity) : 0.01);
            }

            $unitPrice = $this->extractOrderProductUnitPrice($product);
            $unitPrice = $this->convertPriceToCurrency($unitPrice, $orderCurrency, $invoiceCurrency);

            $origin = $this->resolveProductOriginCountry($product);

            $items['name_item'][] = Tools::substr($name, 0, 200);
            $items['count_items'][] = $quantity;
            $items['unit'][] = 'pcs';
            $items['price_unit'][] = Tools::ps_round($unitPrice, 2);
            $items['weight_unit'][] = Tools::ps_round($unitWeight, 3);
            $items['made_country'][] = $origin;

            $hsCode = '';
            if (!empty($product['product_hs_code'])) {
                $hsCode = preg_replace('/[^A-Za-z0-9]/', '', (string) $product['product_hs_code']);
            } elseif (!empty($product['hs_code'])) {
                $hsCode = preg_replace('/[^A-Za-z0-9]/', '', (string) $product['hs_code']);
            }
            $hsCodes[] = $hsCode !== null ? (string) $hsCode : '';

            $sumInvoice += $unitPrice * $quantity;
            $sumWeight += $unitWeight * $quantity;
        }

        $sumInvoice = Tools::ps_round($sumInvoice, 2);

        $sumWeight = $sumWeight > 0.0 ? min($sumWeight, $totalWeight) : $totalWeight;
        if ($sumWeight < 0.0) {
            $sumWeight = 0.0;
        }

        $result = [
            'purpose_parcel' => $purpose,
            'incoterms' => $incoterms,
            'currency_invoice' => $currencyIso,
            'name_item' => $items['name_item'],
            'count_items' => $items['count_items'],
            'unit' => $items['unit'],
            'price_unit' => $items['price_unit'],
            'weight_unit' => $items['weight_unit'],
            'made_country' => $items['made_country'],
            'sum_invoice' => $sumInvoice,
            'sum_weight' => Tools::ps_round($sumWeight, 3),
        ];

        if (count(array_filter($hsCodes)) > 0) {
            $result['hs_code'] = $hsCodes;
        }

        return $result;
    }

    private function mapWeightType(string $type): int
    {
        return $type === 'documents' ? 0 : 1024;
    }

    private function resolveDocumentLanguage(string $value): string
    {
        $value = Tools::strtolower($value);

        return in_array($value, ['uk', 'ru', 'en'], true) ? $value : 'en';
    }

    private function transliterateForApi(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($transliterated === false || $transliterated === '') {
            $transliterated = Tools::replaceAccentedChars($value);
        }

        $transliterated = preg_replace('/[^A-Za-z0-9\s\-\.,]/', ' ', (string) $transliterated);
        if ($transliterated === null) {
            $transliterated = $value;
        }

        return trim(preg_replace('/\s+/', ' ', (string) $transliterated));
    }

    private function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $hasPlus = Tools::substr($phone, 0, 1) === '+';
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === null) {
            $digits = '';
        }

        return ($hasPlus ? '+' : '') . $digits;
    }

    private function formatCountryIso($iso): string
    {
        if (!is_string($iso) || $iso === '') {
            return '';
        }

        return Tools::strtoupper(Tools::substr($iso, 0, 2));
    }

    private function resolveStateIso(int $stateId): string
    {
        if ($stateId <= 0) {
            return '';
        }

        $state = new State($stateId);
        if (!Validate::isLoadedObject($state)) {
            return '';
        }

        return $state->iso_code !== '' ? Tools::strtoupper($state->iso_code) : '';
    }

    private function extractOrderProductUnitPrice(array $product): float
    {
        if (isset($product['unit_price_tax_incl'])) {
            return (float) $product['unit_price_tax_incl'];
        }

        if (isset($product['price_wt'])) {
            return (float) $product['price_wt'];
        }

        if (isset($product['total_price_tax_incl'], $product['product_quantity']) && (int) $product['product_quantity'] > 0) {
            return (float) $product['total_price_tax_incl'] / (int) $product['product_quantity'];
        }

        if (isset($product['total_price_tax_excl'], $product['product_quantity']) && (int) $product['product_quantity'] > 0) {
            return (float) $product['total_price_tax_excl'] / (int) $product['product_quantity'];
        }

        return 0.0;
    }

    private function convertPriceToCurrency(float $amount, ?Currency $from = null, ?Currency $to = null): float
    {
        if ($to === null || $from === null) {
            return $amount;
        }

        if (!Validate::isLoadedObject($from) || !Validate::isLoadedObject($to)) {
            return $amount;
        }

        return (float) Tools::convertPriceFull($amount, $from, $to);
    }

    private function resolveProductOriginCountry(array $product): string
    {
        $candidates = [
            $product['product_country_of_origin'] ?? null,
            $product['product_country'] ?? null,
            $product['country_of_origin'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $iso = $this->formatCountryIso($candidate);
            if ($iso !== '') {
                return $iso;
            }
        }

        if (!empty($product['id_country'])) {
            $iso = $this->formatCountryIso(Country::getIsoById((int) $product['id_country']));
            if ($iso !== '') {
                return $iso;
            }
        }

        return $this->formatCountryIso((string) $this->getConfigurationValue('GLOBALPOST_COUNTRY_FROM'));
    }

    private function storeShipmentLog(array $record, array $request, $response, string $status, ?string $message = null, array $meta = []): void
    {
        if (empty($record['id_globalpost_order'])) {
            return;
        }

        $payloadRaw = Db::getInstance()->getValue(
            'SELECT payload FROM `' . _DB_PREFIX_ . 'globalpost_order` WHERE id_globalpost_order = ' . (int) $record['id_globalpost_order']
        );

        $payload = $this->decodePayload($payloadRaw ?: null);
        if (!isset($payload['shipment_logs']) || !is_array($payload['shipment_logs'])) {
            $payload['shipment_logs'] = [];
        }

        $entry = [
            'status' => $status,
            'timestamp' => date('c'),
            'request' => $this->sanitizeShipmentData($request),
        ];

        if ($response !== null) {
            $entry['response'] = is_array($response) ? $this->sanitizeShipmentData($response) : (string) $response;
        }

        if ($message !== null) {
            $entry['message'] = $message;
        }

        if (!empty($meta)) {
            $entry['meta'] = $meta;
        }

        $payload['shipment_logs'][] = $entry;

        $encoded = json_encode($payload);
        if ($encoded === false) {
            return;
        }

        Db::getInstance()->update(
            'globalpost_order',
            ['payload' => pSQL($encoded, true)],
            'id_globalpost_order = ' . (int) $record['id_globalpost_order']
        );
    }

    private function sanitizeShipmentData($data)
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $sanitized[$key] = $this->sanitizeShipmentData($value);
                    continue;
                }

                if (is_scalar($value) || $value === null) {
                    if (in_array((string) $key, ['sender_phone', 'recipient_phone'], true)) {
                        $sanitized[$key] = $this->maskPhone((string) $value);
                    } elseif (in_array((string) $key, ['sender_email', 'recipient_email'], true)) {
                        $sanitized[$key] = $this->maskEmail((string) $value);
                    } elseif (in_array((string) $key, [
                        'sender_name',
                        'recipient_name',
                        'sender_address',
                        'recipient_address',
                        'sender_city',
                        'recipient_city',
                        'sender_zip',
                        'recipient_zip',
                        'sender_state',
                        'recipient_state',
                    ], true)) {
                        $sanitized[$key] = $this->maskText((string) $value);
                    } else {
                        $sanitized[$key] = $value;
                    }
                }
            }

            return $sanitized;
        }

        if (is_scalar($data) || $data === null) {
            return $data;
        }

        return '';
    }

    private function maskPhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $length = Tools::strlen($phone);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 2) . Tools::substr($phone, -2);
    }

    private function maskEmail(string $email): string
    {
        if (!Validate::isEmail($email)) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = Tools::substr($local, 0, 1);
        $maskedLocal = $visible . str_repeat('*', max(0, Tools::strlen($local) - 1));

        return $maskedLocal . '@' . $domain;
    }

    private function maskText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $length = Tools::strlen($value);
        if ($length <= 2) {
            return str_repeat('*', $length);
        }

        return Tools::substr($value, 0, 1) . str_repeat('*', $length - 2) . Tools::substr($value, -1);
    }

    private function updateTrackingNumber(Order $order, string $ttn): void
    {
        $trackingNumber = trim($ttn);
        if ($trackingNumber === '') {
            return;
        }

        $currentOrderTracking = trim((string) $order->shipping_number);
        $orderCarrierId = (int) Db::getInstance()->getValue(
            'SELECT id_order_carrier FROM `' . _DB_PREFIX_ . 'order_carrier` WHERE id_order = ' . (int) $order->id . ' ORDER BY id_order_carrier DESC'
        );

        $orderCarrier = null;
        $currentCarrierTracking = '';

        if ($orderCarrierId > 0) {
            $candidate = new OrderCarrier($orderCarrierId);
            if (Validate::isLoadedObject($candidate)) {
                $orderCarrier = $candidate;
                $currentCarrierTracking = trim((string) $orderCarrier->tracking_number);
            }
        }

        if ($currentOrderTracking === $trackingNumber && $currentCarrierTracking === $trackingNumber) {
            return;
        }

        if ($currentOrderTracking !== $trackingNumber) {
            Db::getInstance()->update(
                'orders',
                ['shipping_number' => pSQL($trackingNumber)],
                'id_order = ' . (int) $order->id
            );

            $order->shipping_number = $trackingNumber;
        }

        if ($orderCarrier !== null && $currentCarrierTracking !== $trackingNumber) {
            $orderCarrier->tracking_number = $trackingNumber;
            try {
                $orderCarrier->save();
            } catch (Throwable $exception) {
                // ignore persistence errors for tracking update
            }
        }
    }

    private function ensureOrderTableSupportsNullCart(): void
    {
        if ($this->isOrderTableSchemaEnsured) {
            return;
        }

        $this->isOrderTableSchemaEnsured = true;

        if (!defined('_DB_PREFIX_')) {
            return;
        }

        $table = _DB_PREFIX_ . 'globalpost_order';

        $tableExists = Db::getInstance()->executeS('SHOW TABLES LIKE "' . pSQL($table) . '"');
        if (!is_array($tableExists) || empty($tableExists)) {
            return;
        }

        $definition = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . pSQL($table) . "` LIKE 'id_cart'");
        if (!is_array($definition) || empty($definition[0])) {
            return;
        }

        $column = $definition[0];
        $allowsNull = isset($column['Null']) && Tools::strtoupper((string) $column['Null']) === 'YES';
        if ($allowsNull) {
            return;
        }

        Db::getInstance()->execute('ALTER TABLE `' . pSQL($table) . '` MODIFY `id_cart` INT UNSIGNED DEFAULT NULL');
    }

    public function getOrderShippingCost($params, $shipping_cost)
    {
        $cart = $params instanceof Cart ? $params : $this->resolveCartFromParams($params);
        if (!$cart instanceof Cart || !$cart->id) {
            return false;
        }

        $carrierId = $this->getCurrentCarrierId();
        if ($carrierId <= 0) {
            return false;
        }

        $type = $this->resolveCarrierType($carrierId);
        if ($type === null) {
            return false;
        }

        $record = $this->getCartRecord((int) $cart->id, $type);
        if (!is_array($record) || $record['price_uah'] === null) {
            return false;
        }

        $priceUah = (float) $record['price_uah'];

        return $this->convertPriceForCart($priceUah, $cart);
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->getOrderShippingCost($params, 0);
    }

    private function resolveCartFromParams(array $params)
    {
        if (!empty($params['cart']) && $params['cart'] instanceof Cart) {
            return $params['cart'];
        }

        if ($this->context->cart instanceof Cart) {
            return $this->context->cart;
        }

        return null;
    }

    private function resolveOrderFromParams(array $params): ?Order
    {
        if (!empty($params['order']) && $params['order'] instanceof Order) {
            return $params['order'];
        }

        $orderId = 0;

        if (!empty($params['id_order'])) {
            $orderId = (int) $params['id_order'];
        }

        if ($orderId <= 0) {
            $orderId = (int) Tools::getValue('id_order');
        }

        if ($orderId <= 0) {
            return null;
        }

        $order = new Order($orderId);

        return Validate::isLoadedObject($order) ? $order : null;
    }

    private function getEnabledShipmentTypes(): array
    {
        $mode = (string) $this->getConfigurationValue('GLOBALPOST_TYPE_MODE');

        switch ($mode) {
            case 'both':
                return ['documents', 'parcel'];
            case 'documents':
                return ['documents'];
            case 'parcel':
                return ['parcel'];
        }

        $types = [];

        if ((int) $this->getConfigurationValue('GLOBALPOST_TYPE_DOCUMENTS') === 1) {
            $types[] = 'documents';
        }

        if ((int) $this->getConfigurationValue('GLOBALPOST_TYPE_PARCEL') === 1) {
            $types[] = 'parcel';
        }

        if (empty($types)) {
            $types[] = 'parcel';
        }

        return $types;
    }

    private function getCarrierIdForType(string $type): int
    {
        $configKey = self::SHIPMENT_TYPE_CONFIGURATION[$type] ?? null;
        if ($configKey === null) {
            return 0;
        }

        return (int) $this->getConfigurationValue($configKey);
    }

    private function resolveCarrierType(int $carrierId): ?string
    {
        foreach (self::SHIPMENT_TYPE_CONFIGURATION as $type => $configKey) {
            if ((int) $this->getConfigurationValue($configKey) === $carrierId) {
                return $type;
            }
        }

        return null;
    }

    private function getCurrentCarrierId(): int
    {
        if (property_exists($this, 'id_carrier') && (int) $this->id_carrier > 0) {
            return (int) $this->id_carrier;
        }

        if ($this->context->cart instanceof Cart && (int) $this->context->cart->id_carrier > 0) {
            return (int) $this->context->cart->id_carrier;
        }

        return 0;
    }

    private function getTariffDataForCart(Cart $cart, string $type): array
    {
        $context = $this->buildTariffContext($cart, $type);
        if ($context === null) {
            return [
                'options' => [],
                'context' => null,
            ];
        }

        $cartId = (int) $cart->id;
        $cacheEntry = $this->tariffOptionsCache[$cartId][$type] ?? null;
        if (is_array($cacheEntry) && $cacheEntry['signature'] === $context['signature']) {
            return [
                'options' => $cacheEntry['options'],
                'context' => $context,
            ];
        }

        $record = $this->getCartRecord($cartId, $type);
        if (is_array($record) && !empty($record['payload'])) {
            $payload = $this->decodePayload($record['payload']);
            if (!empty($payload['signature']) && $payload['signature'] === $context['signature'] && !empty($payload['options']) && is_array($payload['options'])) {
                $this->tariffOptionsCache[$cartId][$type] = [
                    'signature' => $context['signature'],
                    'options' => $payload['options'],
                ];

                return [
                    'options' => $payload['options'],
                    'context' => $context,
                ];
            }
        }

        $client = $this->createApiClient();
        if ($client === null) {
            $this->logError('GlobalPost API token is not configured.');

            return [
                'options' => [],
                'context' => $context,
            ];
        }

        try {
            $response = $client->getOptions($context['request']);
            $options = $this->normalizeTariffOptions($response);
        } catch (Throwable $exception) {
            $this->logError('Failed to fetch GlobalPost tariffs: ' . $exception->getMessage());
            $options = [];
        }

        $this->tariffOptionsCache[$cartId][$type] = [
            'signature' => $context['signature'],
            'options' => $options,
        ];

        return [
            'options' => $options,
            'context' => $context,
        ];
    }

    private function buildTariffContext(Cart $cart, string $type): ?array
    {
        $countryFrom = Tools::strtoupper((string) $this->getConfigurationValue('GLOBALPOST_COUNTRY_FROM'));
        if (Tools::strlen($countryFrom) !== 2) {
            return null;
        }

        $addressId = (int) $cart->id_address_delivery;
        if ($addressId <= 0) {
            return null;
        }

        $address = new Address($addressId);
        if (!Validate::isLoadedObject($address)) {
            return null;
        }

        $countryTo = Country::getIsoById((int) $address->id_country);
        if (!$countryTo) {
            return null;
        }

        $products = [];
        if (method_exists($cart, 'getProducts')) {
            try {
                $products = $cart->getProducts(true);
            } catch (Throwable $exception) {
                $products = [];
            }
        }

        if (!is_array($products)) {
            $products = [];
        }

        $calculator = new CartMeasurementCalculator($products);
        $weight = $calculator->calculateTotalWeight();
        if ($weight <= 0.0) {
            $weight = (float) $cart->getTotalWeight();
        }

        if ($weight <= 0.0) {
            $weight = 0.0;
        }

        if ($weight < 0.01) {
            $weight = 0.01;
        }

        $request = [
            'country_from' => $countryFrom,
            'country_to' => $countryTo,
            'weight_type' => $type,
            'weight' => [Tools::ps_round($weight, 3)],
        ];

        if ($type === 'parcel') {
            $dimensions = $calculator->calculateDimensions();

            $length = $dimensions['length'] ?? null;
            $width = $dimensions['width'] ?? null;
            $height = $dimensions['height'] ?? null;

            $defaultLength = (float) $this->getConfigurationValue('GLOBALPOST_PARCEL_LENGTH');
            $defaultWidth = (float) $this->getConfigurationValue('GLOBALPOST_PARCEL_WIDTH');
            $defaultHeight = (float) $this->getConfigurationValue('GLOBALPOST_PARCEL_HEIGHT');

            if (($length === null || $length <= 0.0) && $defaultLength > 0.0) {
                $length = $defaultLength;
            }

            if (($width === null || $width <= 0.0) && $defaultWidth > 0.0) {
                $width = $defaultWidth;
            }

            if (($height === null || $height <= 0.0) && $defaultHeight > 0.0) {
                $height = $defaultHeight;
            }

            if ($length !== null && $length > 0.0) {
                $request['length'] = Tools::ps_round($length, 2);
            }

            if ($width !== null && $width > 0.0) {
                $request['width'] = Tools::ps_round($width, 2);
            }

            if ($height !== null && $height > 0.0) {
                $request['height'] = Tools::ps_round($height, 2);
            }
        }

        $insuredAmount = $this->determineInsuredAmount($cart);
        if ($insuredAmount !== null) {
            $request['insured_amount'] = $insuredAmount;
        }

        $signature = md5(json_encode($request));

        return [
            'request' => $request,
            'signature' => $signature,
            'country_from' => $countryFrom,
            'country_to' => $countryTo,
        ];
    }

    private function determineInsuredAmount(Cart $cart): ?float
    {
        if ((int) $this->getConfigurationValue('GLOBALPOST_INSURANCE_ENABLED') !== 1) {
            return null;
        }

        $rule = (string) $this->getConfigurationValue('GLOBALPOST_INSURANCE_RULE');

        switch ($rule) {
            case 'zero':
                return Tools::ps_round(0.0, 2);
            case 'order_total':
                $insuredAmount = (float) $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING);

                return $insuredAmount > 0 ? Tools::ps_round($insuredAmount, 2) : null;
            default:
                return null;
        }
    }

    private function normalizeTariffOptions(array $response): array
    {
        $options = [];

        foreach ($response as $item) {
            if (!is_array($item) || !isset($item['key'])) {
                continue;
            }

            $priceUah = null;
            if (isset($item['price_uah'])) {
                $priceUah = (float) $item['price_uah'];
            } elseif (isset($item['price'])) {
                $priceUah = (float) $item['price'];
            }

            $priceEur = isset($item['price_eur']) ? (float) $item['price_eur'] : null;
            if ($priceEur === null && $priceUah !== null) {
                $converted = $this->convertPriceBetweenCurrencies($priceUah, 'UAH', 'EUR');
                if ($converted !== null) {
                    $priceEur = $converted;
                }
            }

            $options[] = [
                'key' => (string) $item['key'],
                'price_uah' => $priceUah,
                'price_eur' => $priceEur,
                'estimate_in_days' => isset($item['estimate_in_days']) ? (int) $item['estimate_in_days'] : null,
                'international_tariff_id' => isset($item['international_tariff_id']) ? (int) $item['international_tariff_id'] : null,
                'contragent_key' => isset($item['contragent_key']) ? (string) $item['contragent_key'] : null,
            ];
        }

        usort($options, static function (array $left, array $right): int {
            $leftPrice = $left['price_uah'] ?? PHP_INT_MAX;
            $rightPrice = $right['price_uah'] ?? PHP_INT_MAX;

            if ($leftPrice === $rightPrice) {
                return strcmp((string) $left['key'], (string) $right['key']);
            }

            return $leftPrice <=> $rightPrice;
        });

        return $options;
    }

    private function determineSelectedOption(?array $record, array $options, ?string $requestedKey): ?array
    {
        if ($requestedKey !== null) {
            foreach ($options as $option) {
                if ((string) $option['key'] === $requestedKey) {
                    return $option;
                }
            }
        }

        if (is_array($record) && !empty($record['tariff_key'])) {
            foreach ($options as $option) {
                if ((string) $option['key'] === (string) $record['tariff_key']) {
                    return $option;
                }
            }
        }

        return $options[0] ?? null;
    }

    private function saveCartSelection(
        Cart $cart,
        string $type,
        array $selectedOption,
        array $context,
        array $options,
        ?array $record
    ): void {
        $payload = json_encode([
            'signature' => $context['signature'],
            'options' => $options,
            'selected_key' => $selectedOption['key'] ?? null,
            'selected_option' => $selectedOption,
        ]);

        $data = [
            'id_cart' => (int) $cart->id,
            'country_from' => pSQL($context['country_from']),
            'country_to' => pSQL($context['country_to']),
            'type' => pSQL($type),
            'tariff_key' => isset($selectedOption['key']) ? pSQL((string) $selectedOption['key']) : null,
            'international_tariff_id' => isset($selectedOption['international_tariff_id']) ? (int) $selectedOption['international_tariff_id'] : null,
            'price_uah' => isset($selectedOption['price_uah']) ? (float) $selectedOption['price_uah'] : null,
            'price_eur' => isset($selectedOption['price_eur']) ? (float) $selectedOption['price_eur'] : null,
            'estimate_in_days' => isset($selectedOption['estimate_in_days']) ? (int) $selectedOption['estimate_in_days'] : null,
            'payload' => $payload !== false ? pSQL($payload, true) : null,
        ];

        if ($record) {
            Db::getInstance()->update(
                'globalpost_order',
                $data,
                'id_globalpost_order = ' . (int) $record['id_globalpost_order']
            );
        } else {
            Db::getInstance()->insert('globalpost_order', $data);
        }
    }

    private function clearCartSelection(int $cartId, string $type): void
    {
        $record = $this->getCartRecord($cartId, $type);
        if (!$record) {
            return;
        }

        Db::getInstance()->update(
            'globalpost_order',
            [
                'tariff_key' => null,
                'international_tariff_id' => null,
                'price_uah' => null,
                'price_eur' => null,
                'estimate_in_days' => null,
                'payload' => null,
            ],
            'id_globalpost_order = ' . (int) $record['id_globalpost_order']
        );
    }

    private function getCartRecord(int $cartId, string $type): ?array
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('globalpost_order');
        $query->where('id_cart = ' . (int) $cartId);
        $query->where("type = '" . pSQL($type) . "'");
        $query->orderBy('id_globalpost_order DESC');
        $query->limit(1);

        $row = Db::getInstance()->getRow($query);

        return $row ?: null;
    }

    private function decodePayload(?string $payload): array
    {
        if ($payload === null || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function formatOptionsForTemplate(array $options): array
    {
        $formatted = [];

        foreach ($options as $option) {
            $priceUah = isset($option['price_uah']) ? $this->formatPriceValue((float) $option['price_uah']) : '0.00';
            $priceEur = isset($option['price_eur']) ? $this->formatPriceValue((float) $option['price_eur']) : null;

            $label = $priceEur !== null
                ? sprintf($this->translate('front.option_label'), $option['key'], $priceUah, $priceEur)
                : sprintf($this->translate('front.option_label_no_eur'), $option['key'], $priceUah);

            $estimate = '';
            if (isset($option['estimate_in_days']) && $option['estimate_in_days'] !== null) {
                $estimate = sprintf($this->translate('front.estimate_days'), (int) $option['estimate_in_days']);
            }

            $formatted[] = [
                'key' => $option['key'],
                'label' => $label,
                'estimate' => $estimate,
            ];
        }

        return $formatted;
    }

    private function formatPriceValue(float $value): string
    {
        return number_format($value, 2, '.', ' ');
    }

    private function createApiClient(): ?GlobalPostClient
    {
        $mode = (int) $this->getConfigurationValue('GLOBALPOST_API_MODE') === 1
            ? GlobalPostClient::MODE_PROD
            : GlobalPostClient::MODE_TEST;

        $tokenKey = $mode === GlobalPostClient::MODE_PROD ? 'GLOBALPOST_API_TOKEN_PROD' : 'GLOBALPOST_API_TOKEN_TEST';
        $token = trim((string) $this->getConfigurationValue($tokenKey));

        if ($token === '') {
            return null;
        }

        $debugEnabled = (int) $this->getConfigurationValue('GLOBALPOST_DEBUG_LOG') === 1;
        if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
            $debugEnabled = true;
        }

        if ($this->logger === null) {
            $this->logger = class_exists('PrestaShopLogger')
                ? new PrestaShopLoggerAdapter($this->name)
                : new NullLogger();
        }

        $config = [
            'timeout' => 10,
            'connect_timeout' => 5,
            'max_retries' => 2,
            'retry_delay' => 1.0,
        ];

        if ($debugEnabled) {
            $config['debug'] = true;
        }

        try {
            return new GlobalPostClient($token, $mode, $config, null, $this->logger);
        } catch (Throwable $exception) {
            $this->logError('Failed to initialise GlobalPost client: ' . $exception->getMessage());

            return null;
        }
    }

    private function convertPriceForCart(float $priceUah, Cart $cart): float
    {
        $currencyId = (int) $cart->id_currency;
        if ($currencyId <= 0) {
            return $priceUah;
        }

        $currency = new Currency($currencyId);
        if (!Validate::isLoadedObject($currency)) {
            return $priceUah;
        }

        $converted = $this->convertPriceBetweenCurrencies($priceUah, 'UAH', $currency->iso_code);

        return $converted !== null ? $converted : $priceUah;
    }

    private function convertPriceBetweenCurrencies(float $amount, string $fromIso, string $toIso): ?float
    {
        if (strcasecmp($fromIso, $toIso) === 0) {
            return $amount;
        }

        $fromId = Currency::getIdByIsoCode($fromIso);
        $toId = Currency::getIdByIsoCode($toIso);

        if (!$fromId || !$toId) {
            return null;
        }

        $fromCurrency = new Currency($fromId);
        $toCurrency = new Currency($toId);

        return (float) Tools::convertPriceFull($amount, $fromCurrency, $toCurrency);
    }

    private function logError(string $message): void
    {
        PrestaShopLogger::addLog($message, 2, null, $this->name, null, true);
    }


    /**
     * Retrieves configuration from user input.
     */
    private function getFormValuesFromRequest(): array
    {
        $length = (string) Tools::getValue('GLOBALPOST_PARCEL_LENGTH');
        $width = (string) Tools::getValue('GLOBALPOST_PARCEL_WIDTH');
        $height = (string) Tools::getValue('GLOBALPOST_PARCEL_HEIGHT');

        if ($length === '') {
            $length = '0';
        }

        if ($width === '') {
            $width = '0';
        }

        if ($height === '') {
            $height = '0';
        }

        $country = Tools::strtoupper((string) Tools::getValue('GLOBALPOST_COUNTRY_FROM'));

        $mode = (string) Tools::getValue('GLOBALPOST_TYPE_MODE', 'parcel');
        if (!in_array($mode, ['both', 'documents', 'parcel'], true)) {
            $mode = 'parcel';
        }

        $documentsEnabled = ($mode === 'both' || $mode === 'documents') ? 1 : 0;
        $parcelEnabled = ($mode === 'both' || $mode === 'parcel') ? 1 : 0;

        $insuranceRule = (string) Tools::getValue('GLOBALPOST_INSURANCE_RULE', 'order_total');
        if (!in_array($insuranceRule, ['zero', 'order_total'], true)) {
            $insuranceRule = 'order_total';
        }

        return [
            'GLOBALPOST_API_TOKEN_TEST' => (string) Tools::getValue('GLOBALPOST_API_TOKEN_TEST'),
            'GLOBALPOST_API_TOKEN_PROD' => (string) Tools::getValue('GLOBALPOST_API_TOKEN_PROD'),
            'GLOBALPOST_API_MODE' => (int) Tools::getValue('GLOBALPOST_API_MODE'),
            'GLOBALPOST_API_IDENTIFIER' => (string) Tools::getValue('GLOBALPOST_API_IDENTIFIER'),
            'GLOBALPOST_COUNTRY_FROM' => $country,
            'GLOBALPOST_TYPE_MODE' => $mode,
            'GLOBALPOST_TYPE_DOCUMENTS' => $documentsEnabled,
            'GLOBALPOST_TYPE_PARCEL' => $parcelEnabled,
            'GLOBALPOST_PARCEL_LENGTH' => $length,
            'GLOBALPOST_PARCEL_WIDTH' => $width,
            'GLOBALPOST_PARCEL_HEIGHT' => $height,
            'GLOBALPOST_INCOTERMS' => Tools::getValue('GLOBALPOST_INCOTERMS'),
            'GLOBALPOST_PURPOSE' => Tools::getValue('GLOBALPOST_PURPOSE'),
            'GLOBALPOST_CURRENCY_INVOICE' => Tools::getValue('GLOBALPOST_CURRENCY_INVOICE'),
            'GLOBALPOST_INSURANCE_ENABLED' => (int) Tools::getValue('GLOBALPOST_INSURANCE_ENABLED', 0),
            'GLOBALPOST_INSURANCE_RULE' => $insuranceRule,
            'GLOBALPOST_AUTO_CREATE_SHIPMENT' => (int) Tools::getValue('GLOBALPOST_AUTO_CREATE_SHIPMENT', 0),
            'GLOBALPOST_TRACKING_TEMPLATE' => Tools::getValue('GLOBALPOST_TRACKING_TEMPLATE'),
            'GLOBALPOST_DOCUMENT_LANGUAGE' => Tools::getValue('GLOBALPOST_DOCUMENT_LANGUAGE'),
            'GLOBALPOST_DEBUG_LOG' => (int) Tools::getValue('GLOBALPOST_DEBUG_LOG', 0),
        ];
    }

    /**
     * Validates submitted form data and returns an array of errors.
     */
    private function validateForm(array $formData): array
    {
        $errors = [];

        if ($formData['GLOBALPOST_API_TOKEN_TEST'] !== '' && !Validate::isGenericName($formData['GLOBALPOST_API_TOKEN_TEST'])) {
            $errors[] = 'error.test_token';
        }

        if ($formData['GLOBALPOST_API_TOKEN_PROD'] !== '' && !Validate::isGenericName($formData['GLOBALPOST_API_TOKEN_PROD'])) {
            $errors[] = 'error.prod_token';
        }

        if (!in_array((int) $formData['GLOBALPOST_API_MODE'], [0, 1], true)) {
            $errors[] = 'error.api_mode';
        }

        if ($formData['GLOBALPOST_API_IDENTIFIER'] !== '' && !Validate::isGenericName($formData['GLOBALPOST_API_IDENTIFIER'])) {
            $errors[] = 'error.identifier';
        }

        if (!Validate::isLanguageIsoCode($formData['GLOBALPOST_COUNTRY_FROM'])) {
            $errors[] = 'error.country';
        }

        if (!in_array($formData['GLOBALPOST_TYPE_MODE'], ['both', 'documents', 'parcel'], true)) {
            $errors[] = 'error.types';
        }

        foreach (['GLOBALPOST_PARCEL_LENGTH', 'GLOBALPOST_PARCEL_WIDTH', 'GLOBALPOST_PARCEL_HEIGHT'] as $dimensionKey) {
            if ($formData[$dimensionKey] === '') {
                $formData[$dimensionKey] = '0';
            }

            if (!Validate::isUnsignedFloat($formData[$dimensionKey])) {
                $errors[] = 'error.dimensions';
                break;
            }
        }

        if ($formData['GLOBALPOST_INCOTERMS'] !== '' && !Validate::isCleanHtml($formData['GLOBALPOST_INCOTERMS'])) {
            $errors[] = 'error.incoterms';
        }

        if (!in_array($formData['GLOBALPOST_PURPOSE'], array_keys($this->getPurposeOptions()), true)) {
            $errors[] = 'error.purpose';
        }

        if (!in_array($formData['GLOBALPOST_CURRENCY_INVOICE'], ['UAH', 'USD', 'EUR'], true)) {
            $errors[] = 'error.currency';
        }

        if (!in_array((int) $formData['GLOBALPOST_INSURANCE_ENABLED'], [0, 1], true)) {
            $errors[] = 'error.insurance_option';
        }

        if (!in_array($formData['GLOBALPOST_INSURANCE_RULE'], ['zero', 'order_total'], true)) {
            $errors[] = 'error.insurance_rule';
        }

        if (!in_array((int) $formData['GLOBALPOST_AUTO_CREATE_SHIPMENT'], [0, 1], true)) {
            $errors[] = 'error.autocreate';
        }

        if (!in_array((int) $formData['GLOBALPOST_DEBUG_LOG'], [0, 1], true)) {
            $errors[] = 'error.debug_flag';
        }

        if (!Validate::isCleanHtml($formData['GLOBALPOST_TRACKING_TEMPLATE'])) {
            $errors[] = 'error.tracking_invalid_chars';
        } elseif (strpos($formData['GLOBALPOST_TRACKING_TEMPLATE'], '@') === false) {
            $errors[] = 'error.tracking_placeholder';
        }

        if (!in_array($formData['GLOBALPOST_DOCUMENT_LANGUAGE'], ['uk', 'ru', 'en'], true)) {
            $errors[] = 'error.document_language';
        }

        return $errors;
    }

    /**
     * Persists configuration values.
     */
    private function saveConfiguration(array $formData): void
    {
        foreach ($formData as $key => $value) {
            $this->updateConfigurationValue($key, $value);
        }
    }

    /**
     * Renders module configuration form.
     */
    private function renderForm(): string
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitGlobalpostshippingModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name
            . '&tab_module=' . $this->tab
            . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(),
            'languages' => Language::getLanguages(false),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()]);
    }

    private function buildCarrierSummaryForSettings(): array
    {
        $summary = [];

        foreach (self::SUPPORTED_SHIPMENT_TYPES as $type) {
            $carrierId = $this->getCarrierIdForType($type);
            $carrierData = [
                'type' => $type,
                'type_label' => $this->translate('admin.type.' . $type),
                'id_carrier' => $carrierId,
                'name' => '',
                'delay' => '',
                'is_default' => false,
                'missing' => true,
            ];

            if ($carrierId > 0) {
                $carrier = new Carrier($carrierId);

                if (Validate::isLoadedObject($carrier)) {
                    $carrierData['name'] = $carrier->name;

                    $languageId = (int) $this->context->language->id;
                    if (isset($carrier->delay[$languageId])) {
                        $carrierData['delay'] = (string) $carrier->delay[$languageId];
                    }

                    $carrierData['is_default'] = (bool) ($carrier->is_default ?? false);
                    $carrierData['missing'] = false;
                }
            }

            $summary[] = $carrierData;
        }

        return $summary;
    }

    /**
     * Configuration form definition.
     */
    private function getConfigForm(): array
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->translate('settings.legend'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'html',
                        'name' => 'GLOBALPOST_API_NOTICE',
                        'html_content' => sprintf(
                            '<div class="alert alert-info">%s</div>',
                            sprintf(
                                $this->translate('api.notice_html'),
                                $this->translate('api.test_url'),
                                'https://test.cabinet.globalpost.com.ua/api/',
                                $this->translate('api.prod_url'),
                                'https://cabinet.globalpost.com.ua/api/'
                            )
                        ),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translate('api.test_token'),
                        'name' => 'GLOBALPOST_API_TOKEN_TEST',
                        'required' => false,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translate('api.prod_token'),
                        'name' => 'GLOBALPOST_API_TOKEN_PROD',
                        'required' => false,
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->translate('api.mode'),
                        'name' => 'GLOBALPOST_API_MODE',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'api_mode_test',
                                'value' => 0,
                                'label' => $this->translate('api.mode_test'),
                            ],
                            [
                                'id' => 'api_mode_prod',
                                'value' => 1,
                                'label' => $this->translate('api.mode_prod'),
                            ],
                        ],
                        'desc' => $this->translate('api.mode_desc'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translate('api.identifier'),
                        'name' => 'GLOBALPOST_API_IDENTIFIER',
                        'required' => false,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translate('country.origin'),
                        'name' => 'GLOBALPOST_COUNTRY_FROM',
                        'required' => true,
                        'hint' => $this->translate('country.origin_hint'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->translate('types.available'),
                        'name' => 'GLOBALPOST_TYPE_MODE',
                        'options' => [
                            'query' => [
                                ['id_option' => 'both', 'name' => $this->translate('types.mode_both')],
                                ['id_option' => 'documents', 'name' => $this->translate('types.mode_documents')],
                                ['id_option' => 'parcel', 'name' => $this->translate('types.mode_parcel')],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                        'desc' => $this->translate('types.available_desc'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translate('parcel.length'),
                        'name' => 'GLOBALPOST_PARCEL_LENGTH',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translate('parcel.width'),
                        'name' => 'GLOBALPOST_PARCEL_WIDTH',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translate('parcel.height'),
                        'name' => 'GLOBALPOST_PARCEL_HEIGHT',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translate('incoterms.label'),
                        'name' => 'GLOBALPOST_INCOTERMS',
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->translate('purpose.label'),
                        'name' => 'GLOBALPOST_PURPOSE',
                        'options' => [
                            'query' => $this->getPurposeSelectOptions(),
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->translate('currency.invoice'),
                        'name' => 'GLOBALPOST_CURRENCY_INVOICE',
                        'options' => [
                            'query' => [
                                ['id_option' => 'UAH', 'name' => $this->translate('currency.uah')],
                                ['id_option' => 'USD', 'name' => $this->translate('currency.usd')],
                                ['id_option' => 'EUR', 'name' => $this->translate('currency.eur')],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->translate('insurance.enable'),
                        'name' => 'GLOBALPOST_INSURANCE_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'insurance_off',
                                'value' => 0,
                                'label' => $this->translate('common.disabled'),
                            ],
                            [
                                'id' => 'insurance_on',
                                'value' => 1,
                                'label' => $this->translate('common.enabled'),
                            ],
                        ],
                        'desc' => $this->translate('insurance.desc'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->translate('insurance.rule'),
                        'name' => 'GLOBALPOST_INSURANCE_RULE',
                        'options' => [
                            'query' => [
                                ['id_option' => 'order_total', 'name' => $this->translate('insurance.rule_order_total')],
                                ['id_option' => 'zero', 'name' => $this->translate('insurance.rule_zero')],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                        'desc' => $this->translate('insurance.rule_desc'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->translate('autocreate.label'),
                        'name' => 'GLOBALPOST_AUTO_CREATE_SHIPMENT',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'auto_create_off',
                                'value' => 0,
                                'label' => $this->translate('common.disabled'),
                            ],
                            [
                                'id' => 'auto_create_on',
                                'value' => 1,
                                'label' => $this->translate('common.enabled'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->translate('settings.debug'),
                        'name' => 'GLOBALPOST_DEBUG_LOG',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'debug_off',
                                'value' => 0,
                                'label' => $this->translate('common.disabled'),
                            ],
                            [
                                'id' => 'debug_on',
                                'value' => 1,
                                'label' => $this->translate('common.enabled'),
                            ],
                        ],
                        'desc' => $this->translate('settings.debug_desc'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->translate('tracking.template'),
                        'name' => 'GLOBALPOST_TRACKING_TEMPLATE',
                        'hint' => $this->translate('tracking.hint'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->translate('document.language'),
                        'name' => 'GLOBALPOST_DOCUMENT_LANGUAGE',
                        'options' => [
                            'query' => [
                                ['id_option' => 'uk', 'name' => $this->translate('language.uk')],
                                ['id_option' => 'ru', 'name' => $this->translate('language.ru')],
                                ['id_option' => 'en', 'name' => $this->translate('language.en')],
                            ],
                            'id' => 'id_option',
                            'name' => 'name',
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->translate('button.save'),
                ],
            ],
        ];
    }

    /**
     * Returns available purpose options for configuration.
     */
    private function getPurposeOptions(): array
    {
        return [
            'sale' => $this->translate('purpose.sale'),
            'gift' => $this->translate('purpose.gift'),
            'sample' => $this->translate('purpose.sample'),
            'return' => $this->translate('purpose.return'),
            'personal' => $this->translate('purpose.personal'),
        ];
    }

    /**
     * Returns purpose options formatted for HelperForm selects.
     */
    private function getPurposeSelectOptions(): array
    {
        $options = [];

        foreach ($this->getPurposeOptions() as $key => $label) {
            $options[] = [
                'id_option' => $key,
                'name' => $label,
            ];
        }

        return $options;
    }

    /**
     * Retrieves configuration values for rendering the form.
     */
    private function getConfigFormValues(): array
    {
        $values = [];

        foreach (self::CONFIGURATION_KEYS as $key) {
            $values[$key] = $this->getConfigurationValue($key);
        }

        return $values;
    }

    /**
     * Gets a configuration value with the correct multistore context.
     */
    private function getConfigurationValue(string $key)
    {
        [$idShopGroup, $idShop] = $this->getMultistoreConstraint();

        return Configuration::get($key, null, $idShopGroup, $idShop);
    }

    /**
     * Updates a configuration value respecting the multistore context.
     */
    private function updateConfigurationValue(string $key, $value): void
    {
        [$idShopGroup, $idShop] = $this->getMultistoreConstraint();
        Configuration::updateValue($key, $value, false, $idShopGroup, $idShop);
    }

    /**
     * Translates a message by key using PrestaShop's translation system.
     */
    private function translate(string $key): string
    {
        $message = self::TRANSLATION_MESSAGES[$key] ?? $key;

        return $this->trans($message, [], $this->getTranslationDomain($key));
    }

    private function getTranslationDomain(string $key): string
    {
        foreach (self::TRANSLATION_DOMAIN_MAP as $prefix => $domain) {
            if (strpos($key, $prefix) === 0) {
                return $domain;
            }
        }

        return 'Modules.Globalpostshipping.Admin';
    }

    /**
     * Determines the current multistore constraint.
     */
    private function getMultistoreConstraint(): array
    {
        if (!Shop::isFeatureActive()) {
            return [null, null];
        }

        switch (Shop::getContext()) {
            case Shop::CONTEXT_SHOP:
                return [null, (int) $this->context->shop->id];
            case Shop::CONTEXT_GROUP:
                return [(int) $this->context->shop->id_shop_group, null];
            default:
                return [null, null];
        }
    }
}
