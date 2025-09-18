# GlobalPost PrestaShop Module Architecture

## Overview
This document outlines the proposed architecture and implementation plan for the GlobalPost
logistics module for PrestaShop 8+. The module delivers real-time tariff calculation,
automated shipment creation, and shipment tracking through the GlobalPost REST API while
remaining configurable, multilingual, and secure.

## High-Level Architecture

| Layer | Responsibilities |
|-------|------------------|
| Presentation (Front office) | Show GlobalPost carriers during checkout, display delivery price and ETA per tariff, expose tracking URL to customers in order history. |
| Back office UI | Configuration form (API credentials, defaults, toggles), admin order view widgets (shipment info, action buttons). |
| Application Services | Fetch tariff options, create shipments, download labels/invoices, orchestrate automatic and manual flows, implement caching and retries. |
| Integration | HTTP client that authenticates against GlobalPost API, serialization/validation, logging of requests and responses. |
| Persistence | PrestaShop configuration storage, carrier entities, custom tables for shipment metadata/logs. |

## Data Model

### Configuration Keys (ps_configuration)
* `GLOBALPOST_MODE` – `test` or `production`.
* `GLOBALPOST_TOKEN_TEST`, `GLOBALPOST_TOKEN_PROD` – API tokens per mode.
* `GLOBALPOST_SENDER_COUNTRY`, `GLOBALPOST_SENDER_NAME`, `GLOBALPOST_SENDER_PHONE`, `GLOBALPOST_SENDER_EMAIL`.
* `GLOBALPOST_DEFAULT_DIMENSIONS` – JSON payload (`{"length":30,"width":20,"height":10}`) for fallback parcel sizes.
* `GLOBALPOST_ALLOWED_TYPES` – bitmask or CSV for `docs`/`parcel` availability.
* `GLOBALPOST_AUTO_CREATE` – boolean toggle for automatic shipment creation.
* `GLOBALPOST_TRACKING_URL` – template URL with `@` placeholder.
* `GLOBALPOST_DEFAULT_UNIT`, `GLOBALPOST_DEFAULT_ORIGIN`, `GLOBALPOST_DEFAULT_INCOTERMS`, `GLOBALPOST_DEFAULT_PURPOSE`.
* `GLOBALPOST_LABEL_LOCALE` – `uk`, `ru`, or `en` used for document generation.
* `GLOBALPOST_DEBUG` – enable verbose logging.

### Custom Tables

#### `ps_globalpost_shipment`
| Field | Type | Notes |
|-------|------|-------|
| `id_globalpost_shipment` | INT PK | Autoincrement |
| `id_order` | INT | PrestaShop order reference |
| `weight_type` | ENUM(`docs`,`parcel`) | Selected shipment type |
| `international_tariff_id` | VARCHAR(32) | Tariff ID used for creation |
| `contragent_key` | VARCHAR(64) | Carrier key from API |
| `price_uah` | DECIMAL(20,6) | Tariff cost in UAH |
| `price_eur` | DECIMAL(20,6) | Tariff cost in EUR |
| `shipment_id` | VARCHAR(64) | GlobalPost shipment ID |
| `tracking_number` | VARCHAR(64) | TTN |
| `status` | VARCHAR(32) | `created`, `failed`, etc. |
| `payload` | LONGTEXT | Snapshot of API request/response (masked). |
| `date_add`/`date_upd` | DATETIME | Audit |

A secondary table `ps_globalpost_log` can store debug/error entries when `GLOBALPOST_DEBUG` is enabled.

## Module Installation Flow
1. Register hooks: `actionCarrierProcess`, `actionValidateOrder`, `displayCarrierExtraContent`, `displayAdminOrder`, `actionAdminOrdersListingFieldsModifier`, `actionOrderGridDefinitionModifier`, `displayBackOfficeHeader`, `actionCarrierUpdate`.
2. Create carrier entries:
   * `GlobalPost Documents`
   * `GlobalPost Parcel`
   Set translated names (RU/UA/EN), assign to all zones, and configure delay texts per language (`{n} working days`).
   Configure the tracking URL template.
3. Initialize configuration defaults and create custom tables.
4. Provide translation catalogue (`/translations/ru-RU.php`, etc.).

## Checkout Integration

1. **Tariff Request Trigger** – Use `actionCarrierProcess` (legacy checkout) and `hookActionGetDeliveryOptions` (8.x checkout) to inject GlobalPost options.
2. **Input Assembly**:
   * Derive `country_to`, `weight`, `length/width/height` from cart.
   * Determine shipment type based on module configuration (two carriers or automatic detection).
   * Gather insured amount if enabled.
3. **API Call** – `GET /public/tariff-international/get-options` with query parameters.
   * HTTP client built on PrestaShop's `HttpClient` or Guzzle (via composer) with retries/backoff.
4. **Response Handling** – Map API options to PrestaShop delivery options:
   * Label: "{Carrier Name} – {price_uah} UAH ({price_eur} EUR), {estimate} {days}."
   * Store `international_tariff_id`, `contragent_key`, and prices in cart context (e.g., `Cart::setPackageShippingCost` or custom cache table keyed by cart ID).
5. **Error/Empty Response** – Hide GlobalPost carriers; optionally display message for admin using console/log.

## Order Validation & Shipment Creation

1. On `actionValidateOrder`, detect if selected carrier belongs to GlobalPost.
2. Retrieve stored tariff meta from cart and order detail.
3. Assemble shipment payload:
   * Sender: configuration defaults, override from shop data.
   * Receiver: transliterate address fields to Latin using ICU transliterator (`Transliterator::create('Any-Latin; Latin-ASCII')`).
   * Package: weight, dimensions, `places=1`, description `Order #{reference}`.
   * Tariff: `price`, `contragent_key`, `international_tariff_id`.
   * Customs data: iterate over `OrderDetail` lines to build arrays for names, quantities, units, unit prices (converted to invoice currency), weight, origin, HS codes.
4. Execute `POST /api/create-short-order` with bearer token.
5. Persist results:
   * On success: insert record in `ps_globalpost_shipment`, update `OrderCarrier` with tracking number, add private note to order timeline, optionally set order status or message.
   * On failure: log response, add visible warning block in admin order view, mark status as `failed` for manual retry.
6. Respect auto-creation toggle; if disabled, provide button to trigger this flow via AJAX controller `AdminGlobalPostShipmentsController` with CSRF token.

## Admin Order Interface

### Display Block (`displayAdminOrder`)
* Show shipment status, tariff info, price, TTN, and GlobalPost shipment ID.
* Buttons:
  * "Create GlobalPost Shipment" (if not created or failed and auto mode disabled).
  * "Recreate" (optional) with confirmation dialog.
  * "Download Label" – links to route that proxies `GET /api/orders/print-new/{locale}/{shipment_id}`.
  * "Download Invoice" – available for parcel type.
* Error messages rendered with PrestaShop alerts.

### Controller Endpoints
* `AdminGlobalPostShipmentsController::createShipmentAction` – POST, validates permissions, triggers service, returns JSON.
* `AdminGlobalPostShipmentsController::downloadLabelAction` – streams PDF with proper headers.
* `AdminGlobalPostShipmentsController::downloadInvoiceAction` – same for invoice.

## Services & Components

* `GlobalPost\Api\Client` – wraps base URL, tokens, performs authenticated requests, handles retries and error translation.
* `GlobalPost\Service\TariffService` – builds tariff queries, caches results in cart meta (using `Cache::store` or custom DB table `ps_globalpost_cart_option`).
* `GlobalPost\Service\ShipmentService` – composes payload, interacts with API, persists shipments.
* `GlobalPost\Service\TransliterationService` – converts Cyrillic to Latin; configurable replacements for Ukrainian and Russian alphabets.
* `GlobalPost\Repository\ShipmentRepository` – CRUD over `ps_globalpost_shipment`.
* `GlobalPost\Presenter\DeliveryOptionPresenter` – formats front-office labels per language.
* `GlobalPost\Logger\Logger` – PSR-3 compatible logger bridging to PrestaShop logs.

## Multistore Support

* Wrap configuration getters with `Configuration::get`/`Configuration::updateValue` including `$id_shop_group` and `$id_shop`.
* During installation, create separate carriers per shop context; store IDs in configuration keyed by shop.
* Admin forms respect current multistore context (use `Shop::setContext` aware helpers).

## Security Considerations

* Store tokens encrypted using `Configuration::updateValue($key, $value, false, 0, 0)` (core encrypts automatically when `PS_ENCRYPTION_KEY` is configured).
* Validate and sanitize admin inputs with `Tools::getValue` and `Validate` helpers.
* Mask tokens and personal data in logs.
* Enforce admin controller permissions (`$this->tabAccess['edit']`).
* Use PrestaShop nonce tokens in AJAX forms (`Tools::getAdminTokenLite`).
* Enforce HTTPS endpoints and short timeouts with fallback to disable carrier.

## Error Handling & Logging

* Map API error codes to user-friendly messages in RU/UA/EN translation catalogues.
* For tariff failures during checkout, suppress carrier and store message in session for admins.
* For shipment creation errors, display in admin block and allow manual retry.
* Optional email alert to shop admins when repeated failures occur (future enhancement).

## Testing Strategy

* **Unit tests** (PHPUnit) for payload builders, transliteration, and configuration services.
* **Integration tests** using mocked HTTP client for tariff and shipment flows.
* **Functional tests** on PrestaShop 8.1 demo: checkout scenario (docs & parcel), admin shipment creation, download label, translation switching.
* **Manual QA checklist** covering edge cases (missing weights, unsupported countries, auto-create disabled, multistore context).

## Timeline (Indicative)

1. **Week 1** – Project setup, module skeleton, configuration form, carrier installation, translation scaffolding.
2. **Week 2** – Tariff calculation flow, front-office integration, cache & error handling.
3. **Week 3** – Shipment creation service, admin order block, auto-create hook, persistence.
4. **Week 4** – Label/invoice download, manual actions, logging, multistore adjustments, unit tests.
5. **Week 5** – QA, documentation, packaging, and user acceptance.

## Deliverables

* PrestaShop module package ready for installation on 8.x stores.
* Source code with PSR-12 compliance and inline documentation.
* Translations (RU/UA/EN).
* README with installation/configuration guide and troubleshooting.
* Test report and checklist results.

