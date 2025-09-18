<?php
/**
 * Handles database setup for the GlobalPost Shipping module.
 */

namespace GlobalPostShipping\Installer;

use Db;

class DatabaseInstaller
{
    /**
     * @var Db
     */
    private $db;

    /**
     * @var string
     */
    private $prefix;

    /**
     * @var string
     */
    private $engine;

    public function __construct(Db $db, string $prefix, string $engine)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->engine = $engine;
    }

    public function install(): bool
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%1$s` (
                `id_globalpost_order` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_cart` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED DEFAULT NULL,
                `country_from` VARCHAR(2) NOT NULL,
                `country_to` VARCHAR(2) NOT NULL,
                `type` ENUM(\'docs\', \'parcel\') NOT NULL,
                `tariff_key` VARCHAR(128) DEFAULT NULL,
                `international_tariff_id` INT UNSIGNED DEFAULT NULL,
                `price_uah` DECIMAL(20,6) DEFAULT NULL,
                `price_eur` DECIMAL(20,6) DEFAULT NULL,
                `estimate_in_days` INT UNSIGNED DEFAULT NULL,
                `shipment_id` VARCHAR(128) DEFAULT NULL,
                `ttn` VARCHAR(128) DEFAULT NULL,
                `payload` LONGTEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_globalpost_order`),
                UNIQUE KEY `idx_globalpost_order_shipment` (`shipment_id`)
            ) ENGINE=%2$s DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            $this->getTableName(),
            \pSQL($this->engine)
        );

        return $this->db->execute($sql);
    }

    public function uninstall(): bool
    {
        $sql = sprintf('DROP TABLE IF EXISTS `%s`', $this->getTableName());

        return $this->db->execute($sql);
    }

    private function getTableName(): string
    {
        return sprintf('%sglobalpost_order', $this->prefix);
    }
}
