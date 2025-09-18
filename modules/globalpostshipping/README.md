# GlobalPost Shipping Module

GlobalPost Shipping is a PrestaShop 8 compatible module that introduces database entities and scaffolding required for future integrations with the GlobalPost logistics platform.

## Requirements

- PHP 8.0 or 8.1
- PrestaShop 8.0 or newer
- MySQL 5.7+ / MariaDB 10.3+

## Installation

1. Copy the module folder `globalpostshipping` into your PrestaShop `/modules` directory or install it through the Module Manager by uploading the archive.
2. In the PrestaShop back office, navigate to **Modules > Module Manager**.
3. Locate **GlobalPost Shipping** and click **Install**.

Upon installation the module will automatically create the database table `ps_globalpost_order` (prefix depends on your store configuration).

## Uninstallation

1. In the back office go to **Modules > Module Manager**.
2. Find **GlobalPost Shipping** in the list of installed modules.
3. Click **Uninstall** and confirm the prompt.

The database table created by the module is dropped during uninstallation.

## Configuration

After installing the module open the GlobalPost configuration page in the back office and provide the required API credentials. The form allows you to define sender contact details, enable or disable automatic shipment creation, configure default parcel dimensions, customs declaration defaults, and the tracking URL template.

### Automatic shipment creation

When the option **Auto-create shipment after order confirmation** is enabled the module will automatically call the GlobalPost `POST /api/create-short-order` endpoint once an order that uses a GlobalPost carrier is validated. The generated shipment identifier and TTN are saved inside the `ps_globalpost_order` table and applied as the order tracking number. Each API request and response is logged in a sanitized JSON payload stored alongside the order record for troubleshooting.

An example of the stored log structure is available in [`docs/logs/sample_auto_shipment_log.json`](../../docs/logs/sample_auto_shipment_log.json).

## Development

Install Composer dependencies and enable PSR-4 autoloading with:

```bash
composer install
```

This command will generate the `vendor/` folder and the Composer autoloader used by PrestaShop when loading the module classes.

## License

This module is released under the Academic Free License 3.0 (AFL-3.0).
