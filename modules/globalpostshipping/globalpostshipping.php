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
            && $this->getDatabaseInstaller()->install();
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall()
    {
        return $this->getDatabaseInstaller()->uninstall()
            && parent::uninstall();
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
}
