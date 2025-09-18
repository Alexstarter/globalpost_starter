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

use GlobalPostShipping\Installer\DatabaseInstaller;
use GlobalPostShipping\SDK\GlobalPostClient;
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
     * Localized strings for the configuration interface.
     */
    private const LOCALIZED_STRINGS = [
        'en' => [
            'carrier.documents_name' => 'GlobalPost Documents',
            'carrier.parcel_name' => 'GlobalPost Parcel',
            'carrier.default_delay' => 'International delivery',
            'settings.legend' => 'GlobalPost settings',
            'api.notice_html' => 'API endpoints are selected automatically based on the chosen mode.<br><strong>%s</strong>: %s<br><strong>%s</strong>: %s',
            'api.test_url' => 'Test URL',
            'api.prod_url' => 'Production URL',
            'api.test_token' => 'Test API token',
            'api.prod_token' => 'Production API token',
            'api.mode' => 'API mode',
            'api.mode_desc' => 'Switch between sandbox and live API endpoints.',
            'api.mode_test' => 'Test',
            'api.mode_prod' => 'Production',
            'api.identifier' => 'API identifier',
            'country.origin' => 'Origin country (ISO code)',
            'country.origin_hint' => 'Use a two-letter ISO code, e.g. UA.',
            'types.available' => 'Shipment type mode',
            'types.available_desc' => 'Choose whether to offer both shipment types or fix a single type for all orders.',
            'types.documents' => 'Documents',
            'types.parcel' => 'Parcel',
            'types.mode_both' => 'Offer documents and parcels',
            'types.mode_documents' => 'Documents only',
            'types.mode_parcel' => 'Parcels only',
            'parcel.length' => 'Default parcel length (cm)',
            'parcel.width' => 'Default parcel width (cm)',
            'parcel.height' => 'Default parcel height (cm)',
            'incoterms.label' => 'Incoterms',
            'purpose.label' => 'Shipment purpose',
            'purpose.sale' => 'Sale of goods',
            'purpose.gift' => 'Gift',
            'purpose.sample' => 'Commercial sample',
            'purpose.return' => 'Return',
            'purpose.personal' => 'Personal use',
            'currency.invoice' => 'Invoice currency',
            'currency.uah' => 'Hryvnia (UAH)',
            'currency.usd' => 'US Dollar (USD)',
            'currency.eur' => 'Euro (EUR)',
            'insurance.enable' => 'Enable insurance',
            'insurance.desc' => 'Toggle insurance for generated shipments.',
            'common.disabled' => 'Disabled',
            'common.enabled' => 'Enabled',
            'insurance.rule' => 'Insurance calculation rule',
            'insurance.rule_desc' => 'Select how the insured amount should be calculated for shipments.',
            'insurance.rule_zero' => 'Always send 0 (no insurance)',
            'insurance.rule_order_total' => 'Use the order total amount',
            'autocreate.label' => 'Auto-create shipment after order confirmation',
            'tracking.template' => 'Tracking URL template',
            'tracking.hint' => 'Use @ as a placeholder for the tracking number.',
            'document.language' => 'Document language',
            'language.uk' => 'Ukrainian',
            'language.ru' => 'Russian',
            'language.en' => 'English',
            'button.save' => 'Save',
            'settings.saved' => 'Settings updated successfully.',
            'front.select_tariff' => 'Select a GlobalPost tariff option',
            'front.option_label' => '%s – %s UAH (%s EUR)',
            'front.option_label_no_eur' => '%s – %s UAH',
            'front.estimate_days' => 'Estimated delivery: %s days',
            'error.test_token' => 'Test API token contains invalid characters.',
            'error.prod_token' => 'Production API token contains invalid characters.',
            'error.api_mode' => 'Invalid API mode provided.',
            'error.identifier' => 'API identifier contains invalid characters.',
            'error.country' => 'Origin country must be a valid ISO code (e.g. UA).',
            'error.types' => 'Select at least one shipment type.',
            'error.dimensions' => 'Dimensions must be positive numbers.',
            'error.incoterms' => 'Incoterms value is invalid.',
            'error.purpose' => 'Invalid shipment purpose selected.',
            'error.currency' => 'Invalid invoice currency selected.',
            'error.insurance_option' => 'Invalid insurance option provided.',
            'error.insurance_rule' => 'Insurance rule selection is invalid.',
            'error.autocreate' => 'Invalid auto-creation option provided.',
            'error.tracking_invalid_chars' => 'Tracking URL template contains invalid characters.',
            'error.tracking_placeholder' => 'Tracking URL template must include the @ placeholder.',
            'error.document_language' => 'Invalid document language selected.',
        ],
        'ru' => [
            'carrier.documents_name' => 'GlobalPost Документы',
            'carrier.parcel_name' => 'GlobalPost Посылка',
            'carrier.default_delay' => 'Международная доставка',
            'settings.legend' => 'Настройки GlobalPost',
            'api.notice_html' => 'API-адреса выбираются автоматически в зависимости от режима.<br><strong>%s</strong>: %s<br><strong>%s</strong>: %s',
            'api.test_url' => 'Тестовый URL',
            'api.prod_url' => 'Рабочий URL',
            'api.test_token' => 'Тестовый API-токен',
            'api.prod_token' => 'Рабочий API-токен',
            'api.mode' => 'Режим API',
            'api.mode_desc' => 'Переключение между тестовой и боевой средами API.',
            'api.mode_test' => 'Тест',
            'api.mode_prod' => 'Боевой',
            'api.identifier' => 'Идентификатор API',
            'country.origin' => 'Страна отправления (ISO-код)',
            'country.origin_hint' => 'Используйте двухбуквенный ISO-код, например UA.',
            'types.available' => 'Режим типов отправлений',
            'types.available_desc' => 'Выберите, предлагать ли оба типа отправлений или зафиксировать один тип для всех заказов.',
            'types.documents' => 'Документы',
            'types.parcel' => 'Посылка',
            'types.mode_both' => 'Предлагать документы и посылки',
            'types.mode_documents' => 'Только документы',
            'types.mode_parcel' => 'Только посылки',
            'parcel.length' => 'Длина посылки по умолчанию (см)',
            'parcel.width' => 'Ширина посылки по умолчанию (см)',
            'parcel.height' => 'Высота посылки по умолчанию (см)',
            'incoterms.label' => 'Инкотермс',
            'purpose.label' => 'Цель отправления',
            'purpose.sale' => 'Продажа товаров',
            'purpose.gift' => 'Подарок',
            'purpose.sample' => 'Коммерческий образец',
            'purpose.return' => 'Возврат',
            'purpose.personal' => 'Личное использование',
            'currency.invoice' => 'Валюта инвойса',
            'currency.uah' => 'Гривна (UAH)',
            'currency.usd' => 'Доллар США (USD)',
            'currency.eur' => 'Евро (EUR)',
            'insurance.enable' => 'Страховка',
            'insurance.desc' => 'Включите страховку для создаваемых отправлений.',
            'common.disabled' => 'Выключено',
            'common.enabled' => 'Включено',
            'insurance.rule' => 'Правило расчёта страховки',
            'insurance.rule_desc' => 'Выберите, как рассчитывать страховую сумму для отправлений.',
            'insurance.rule_zero' => 'Всегда передавать 0 (без страховки)',
            'insurance.rule_order_total' => 'Использовать сумму заказа',
            'autocreate.label' => 'Автосоздание отправления после подтверждения заказа',
            'tracking.template' => 'Шаблон ссылки для отслеживания',
            'tracking.hint' => 'Используйте @ как плейсхолдер номера отслеживания.',
            'document.language' => 'Язык документов',
            'language.uk' => 'Украинский',
            'language.ru' => 'Русский',
            'language.en' => 'Английский',
            'button.save' => 'Сохранить',
            'settings.saved' => 'Настройки успешно сохранены.',
            'front.select_tariff' => 'Выберите тариф GlobalPost',
            'front.option_label' => '%s – %s грн (%s евро)',
            'front.option_label_no_eur' => '%s – %s грн',
            'front.estimate_days' => 'Прогноз доставки: %s дн.',
            'error.test_token' => 'Тестовый API-токен содержит недопустимые символы.',
            'error.prod_token' => 'Рабочий API-токен содержит недопустимые символы.',
            'error.api_mode' => 'Указан неверный режим API.',
            'error.identifier' => 'Идентификатор API содержит недопустимые символы.',
            'error.country' => 'Страна отправления должна быть валидным ISO-кодом (например, UA).',
            'error.types' => 'Выберите хотя бы один тип отправлений.',
            'error.dimensions' => 'Габариты должны быть положительными числами.',
            'error.incoterms' => 'Некорректное значение Инкотермс.',
            'error.purpose' => 'Выбрана неверная цель отправления.',
            'error.currency' => 'Выбрана неверная валюта инвойса.',
            'error.insurance_option' => 'Некорректное значение опции страховки.',
            'error.insurance_rule' => 'Выбрано некорректное правило расчёта страховки.',
            'error.autocreate' => 'Некорректное значение опции автосоздания.',
            'error.tracking_invalid_chars' => 'Шаблон ссылки отслеживания содержит недопустимые символы.',
            'error.tracking_placeholder' => 'Шаблон ссылки отслеживания должен содержать плейсхолдер @.',
            'error.document_language' => 'Выбран неверный язык документов.',
        ],
        'uk' => [
            'carrier.documents_name' => 'GlobalPost Документи',
            'carrier.parcel_name' => 'GlobalPost Посилка',
            'carrier.default_delay' => 'Міжнародна доставка',
            'settings.legend' => 'Налаштування GlobalPost',
            'api.notice_html' => 'API-адреси вибираються автоматично залежно від режиму.<br><strong>%s</strong>: %s<br><strong>%s</strong>: %s',
            'api.test_url' => 'Тестовий URL',
            'api.prod_url' => 'Робочий URL',
            'api.test_token' => 'Тестовий API-токен',
            'api.prod_token' => 'Робочий API-токен',
            'api.mode' => 'Режим API',
            'api.mode_desc' => 'Перемикання між тестовим та робочим середовищем API.',
            'api.mode_test' => 'Тест',
            'api.mode_prod' => 'Робочий',
            'api.identifier' => 'Ідентифікатор API',
            'country.origin' => 'Країна відправлення (ISO-код)',
            'country.origin_hint' => 'Використовуйте дволітерний ISO-код, наприклад UA.',
            'types.available' => 'Режим типів відправлень',
            'types.available_desc' => 'Визначте, чи пропонувати обидва типи відправлень, чи зафіксувати один тип для всіх замовлень.',
            'types.documents' => 'Документи',
            'types.parcel' => 'Посилка',
            'types.mode_both' => 'Пропонувати документи та посилки',
            'types.mode_documents' => 'Лише документи',
            'types.mode_parcel' => 'Лише посилки',
            'parcel.length' => 'Довжина посилки за замовчуванням (см)',
            'parcel.width' => 'Ширина посилки за замовчуванням (см)',
            'parcel.height' => 'Висота посилки за замовчуванням (см)',
            'incoterms.label' => 'Інкотермс',
            'purpose.label' => 'Мета відправлення',
            'purpose.sale' => 'Продаж товарів',
            'purpose.gift' => 'Подарунок',
            'purpose.sample' => 'Комерційний зразок',
            'purpose.return' => 'Повернення',
            'purpose.personal' => 'Особисте використання',
            'currency.invoice' => 'Валюта інвойсу',
            'currency.uah' => 'Гривня (UAH)',
            'currency.usd' => 'Долар США (USD)',
            'currency.eur' => 'Євро (EUR)',
            'insurance.enable' => 'Страхування',
            'insurance.desc' => 'Увімкніть страхування для створюваних відправлень.',
            'common.disabled' => 'Вимкнено',
            'common.enabled' => 'Увімкнено',
            'insurance.rule' => 'Правило розрахунку страхування',
            'insurance.rule_desc' => 'Оберіть спосіб розрахунку страхової суми для відправлень.',
            'insurance.rule_zero' => 'Завжди передавати 0 (без страхування)',
            'insurance.rule_order_total' => "Використовувати суму замовлення",
            'autocreate.label' => 'Автозапуск створення відправлення після підтвердження замовлення',
            'tracking.template' => 'Шаблон посилання для відстеження',
            'tracking.hint' => 'Використовуйте @ як плейсхолдер номера відстеження.',
            'document.language' => 'Мова документів',
            'language.uk' => 'Українська',
            'language.ru' => 'Російська',
            'language.en' => 'Англійська',
            'button.save' => 'Зберегти',
            'settings.saved' => 'Налаштування успішно збережено.',
            'front.select_tariff' => 'Оберіть тариф GlobalPost',
            'front.option_label' => '%s – %s грн (%s євро)',
            'front.option_label_no_eur' => '%s – %s грн',
            'front.estimate_days' => 'Орієнтовна доставка: %s дн.',
            'error.test_token' => 'Тестовий API-токен містить недопустимі символи.',
            'error.prod_token' => 'Робочий API-токен містить недопустимі символи.',
            'error.api_mode' => 'Вказано некоректний режим API.',
            'error.identifier' => 'Ідентифікатор API містить недопустимі символи.',
            'error.country' => 'Країна відправлення має бути коректним ISO-кодом (наприклад, UA).',
            'error.types' => 'Оберіть щонайменше один тип відправлення.',
            'error.dimensions' => 'Габарити мають бути додатними числами.',
            'error.incoterms' => 'Некоректне значення Інкотермс.',
            'error.purpose' => 'Обрано некоректну мету відправлення.',
            'error.currency' => 'Обрано некоректну валюту інвойсу.',
            'error.insurance_option' => 'Некоректне значення опції страхування.',
            'error.insurance_rule' => 'Обрано некоректне правило розрахунку страхування.',
            'error.autocreate' => 'Некоректне значення опції автозапуску.',
            'error.tracking_invalid_chars' => 'Шаблон посилання відстеження містить недопустимі символи.',
            'error.tracking_placeholder' => 'Шаблон посилання відстеження має містити плейсхолдер @.',
            'error.document_language' => 'Обрано некоректну мову документів.',
        ],
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

        $this->displayName = $this->l('GlobalPost Shipping');
        $this->description = $this->l('Provides GlobalPost shipping services integration.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the GlobalPost Shipping module?');
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

        return $output . $this->renderForm();
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
            ],
            'id_cart = ' . (int) $cart->id . ' AND type = "' . pSQL($type) . '"'
        );
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

        if ($this->logger === null) {
            $this->logger = new NullLogger();
        }

        $config = [
            'timeout' => 10,
            'connect_timeout' => 5,
        ];

        if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
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
     * Provides module-specific translations for RU/UA/EN locales.
     */
    private function translate(string $key): string
    {
        $iso = strtolower($this->context->language->iso_code ?? 'en');

        if (!isset(self::LOCALIZED_STRINGS[$iso][$key])) {
            $iso = 'en';
        }

        return self::LOCALIZED_STRINGS[$iso][$key] ?? (self::LOCALIZED_STRINGS['en'][$key] ?? $key);
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
