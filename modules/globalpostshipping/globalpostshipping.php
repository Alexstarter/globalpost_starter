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

class Globalpostshipping extends Module
{
    /**
     * List of hooks registered by the module.
     *
     * @var array
     */
    private $hooks = [
        'actionValidateOrder',
        'actionOrderGridDefinitionModifier',
        'actionOrderGridDataModifier',
        'displayAdminOrderMainBottom',
    ];

    /**
     * Module configuration keys grouped for convenience.
     */
    private const CONFIGURATION_KEYS = [
        'GLOBALPOST_API_TOKEN_TEST',
        'GLOBALPOST_API_TOKEN_PROD',
        'GLOBALPOST_API_MODE',
        'GLOBALPOST_API_IDENTIFIER',
        'GLOBALPOST_COUNTRY_FROM',
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
     * Localized strings for the configuration interface.
     */
    private const LOCALIZED_STRINGS = [
        'en' => [
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
            'types.available' => 'Available shipment types',
            'types.available_desc' => 'Enable the types of shipments that can be created through GlobalPost.',
            'types.documents' => 'Documents',
            'types.parcel' => 'Parcel',
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
            'insurance.rule_desc' => 'Describe how the insured amount should be calculated.',
            'autocreate.label' => 'Auto-create shipment after order confirmation',
            'tracking.template' => 'Tracking URL template',
            'tracking.hint' => 'Use @ as a placeholder for the tracking number.',
            'document.language' => 'Document language',
            'language.uk' => 'Ukrainian',
            'language.ru' => 'Russian',
            'language.en' => 'English',
            'button.save' => 'Save',
            'settings.saved' => 'Settings updated successfully.',
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
            'error.insurance_rule' => 'Insurance rule contains invalid characters.',
            'error.autocreate' => 'Invalid auto-creation option provided.',
            'error.tracking_invalid_chars' => 'Tracking URL template contains invalid characters.',
            'error.tracking_placeholder' => 'Tracking URL template must include the @ placeholder.',
            'error.document_language' => 'Invalid document language selected.',
        ],
        'ru' => [
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
            'types.available' => 'Доступные типы отправлений',
            'types.available_desc' => 'Выберите типы отправлений, доступные для создания через GlobalPost.',
            'types.documents' => 'Документы',
            'types.parcel' => 'Посылка',
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
            'insurance.rule_desc' => 'Опишите, как рассчитывать страховую сумму.',
            'autocreate.label' => 'Автосоздание отправления после подтверждения заказа',
            'tracking.template' => 'Шаблон ссылки для отслеживания',
            'tracking.hint' => 'Используйте @ как плейсхолдер номера отслеживания.',
            'document.language' => 'Язык документов',
            'language.uk' => 'Украинский',
            'language.ru' => 'Русский',
            'language.en' => 'Английский',
            'button.save' => 'Сохранить',
            'settings.saved' => 'Настройки успешно сохранены.',
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
            'error.insurance_rule' => 'Правило расчёта страховки содержит недопустимые символы.',
            'error.autocreate' => 'Некорректное значение опции автосоздания.',
            'error.tracking_invalid_chars' => 'Шаблон ссылки отслеживания содержит недопустимые символы.',
            'error.tracking_placeholder' => 'Шаблон ссылки отслеживания должен содержать плейсхолдер @.',
            'error.document_language' => 'Выбран неверный язык документов.',
        ],
        'uk' => [
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
            'types.available' => 'Доступні типи відправлень',
            'types.available_desc' => 'Оберіть типи відправлень, доступні для створення через GlobalPost.',
            'types.documents' => 'Документи',
            'types.parcel' => 'Посилка',
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
            'insurance.rule_desc' => 'Опишіть, як обчислюється страхова сума.',
            'autocreate.label' => 'Автозапуск створення відправлення після підтвердження замовлення',
            'tracking.template' => 'Шаблон посилання для відстеження',
            'tracking.hint' => 'Використовуйте @ як плейсхолдер номера відстеження.',
            'document.language' => 'Мова документів',
            'language.uk' => 'Українська',
            'language.ru' => 'Російська',
            'language.en' => 'Англійська',
            'button.save' => 'Зберегти',
            'settings.saved' => 'Налаштування успішно збережено.',
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
            'error.insurance_rule' => 'Правило розрахунку страхування містить недопустимі символи.',
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
            && $this->installConfiguration();
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall()
    {
        return $this->removeConfiguration()
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
            'GLOBALPOST_API_TOKEN_TEST' => '',
            'GLOBALPOST_API_TOKEN_PROD' => '',
            'GLOBALPOST_API_MODE' => 0,
            'GLOBALPOST_API_IDENTIFIER' => '',
            'GLOBALPOST_COUNTRY_FROM' => 'UA',
            'GLOBALPOST_TYPE_DOCUMENTS' => 0,
            'GLOBALPOST_TYPE_PARCEL' => 1,
            'GLOBALPOST_PARCEL_LENGTH' => '0',
            'GLOBALPOST_PARCEL_WIDTH' => '0',
            'GLOBALPOST_PARCEL_HEIGHT' => '0',
            'GLOBALPOST_INCOTERMS' => 'DAP',
            'GLOBALPOST_PURPOSE' => 'sale',
            'GLOBALPOST_CURRENCY_INVOICE' => 'UAH',
            'GLOBALPOST_INSURANCE_ENABLED' => 0,
            'GLOBALPOST_INSURANCE_RULE' => 'declared_value',
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

        return [
            'GLOBALPOST_API_TOKEN_TEST' => (string) Tools::getValue('GLOBALPOST_API_TOKEN_TEST'),
            'GLOBALPOST_API_TOKEN_PROD' => (string) Tools::getValue('GLOBALPOST_API_TOKEN_PROD'),
            'GLOBALPOST_API_MODE' => (int) Tools::getValue('GLOBALPOST_API_MODE'),
            'GLOBALPOST_API_IDENTIFIER' => (string) Tools::getValue('GLOBALPOST_API_IDENTIFIER'),
            'GLOBALPOST_COUNTRY_FROM' => $country,
            'GLOBALPOST_TYPE_DOCUMENTS' => Tools::getValue('GLOBALPOST_TYPES_documents') ? 1 : 0,
            'GLOBALPOST_TYPE_PARCEL' => Tools::getValue('GLOBALPOST_TYPES_parcel') ? 1 : 0,
            'GLOBALPOST_PARCEL_LENGTH' => $length,
            'GLOBALPOST_PARCEL_WIDTH' => $width,
            'GLOBALPOST_PARCEL_HEIGHT' => $height,
            'GLOBALPOST_INCOTERMS' => Tools::getValue('GLOBALPOST_INCOTERMS'),
            'GLOBALPOST_PURPOSE' => Tools::getValue('GLOBALPOST_PURPOSE'),
            'GLOBALPOST_CURRENCY_INVOICE' => Tools::getValue('GLOBALPOST_CURRENCY_INVOICE'),
            'GLOBALPOST_INSURANCE_ENABLED' => (int) Tools::getValue('GLOBALPOST_INSURANCE_ENABLED', 0),
            'GLOBALPOST_INSURANCE_RULE' => Tools::getValue('GLOBALPOST_INSURANCE_RULE'),
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

        if (!$formData['GLOBALPOST_TYPE_DOCUMENTS'] && !$formData['GLOBALPOST_TYPE_PARCEL']) {
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

        if ($formData['GLOBALPOST_INSURANCE_RULE'] !== '' && !Validate::isCleanHtml($formData['GLOBALPOST_INSURANCE_RULE'])) {
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
                        'type' => 'checkbox',
                        'label' => $this->translate('types.available'),
                        'name' => 'GLOBALPOST_TYPES',
                        'values' => [
                            'query' => [
                                [
                                    'id_option' => 'documents',
                                    'name' => $this->translate('types.documents'),
                                ],
                                [
                                    'id_option' => 'parcel',
                                    'name' => $this->translate('types.parcel'),
                                ],
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
                        'type' => 'text',
                        'label' => $this->translate('insurance.rule'),
                        'name' => 'GLOBALPOST_INSURANCE_RULE',
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

        $values['GLOBALPOST_TYPES_documents'] = (bool) $values['GLOBALPOST_TYPE_DOCUMENTS'];
        $values['GLOBALPOST_TYPES_parcel'] = (bool) $values['GLOBALPOST_TYPE_PARCEL'];

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
