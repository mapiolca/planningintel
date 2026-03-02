<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * \file    core/modules/modplanningintel.class.php
 * \brief   Module descriptor for Planning & Inventory Intelligence
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Class modplanningintel
 *
 * Module descriptor for Planning & Inventory Intelligence.
 * Provides ABC/XYZ analysis, demand forecasting, reorder planning
 * with explainable formulas. Read-only access to core tables.
 */
class modplanningintel extends DolibarrModules
{
    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // Module identification
        $this->numero = 95100;
        $this->rights_class = 'planningintel';
        $this->family = 'products';
        $this->module_position = 90;
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'planningintelModuleDescription';
        $this->descriptionlong = 'planningintelModuleDescriptionLong';
        $this->editor_name = 'SiliconBlaze';
        $this->editor_url = 'https://siliconblaze.com';
        $this->version = '1.0.1';
        $this->phpmin = array(7, 0);
        $this->need_dolibarr_min_version = array(14, 0);
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'planningintel@planningintel';

        // Module config page
        $this->config_page_url = array('setup.php@planningintel');

        // Dependencies
        $this->hidden = false;
        $this->depends = array('modStock', 'modProduct');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array('planningintel@planningintel');

        // No hooks or triggers needed - read-only module
        $this->module_parts = array();

        // Directories
        $this->dirs = array();

        // Constants
        $this->const = array();

        // Boxes/Widgets
        $this->boxes = array();

        // ----- Permissions -----
        $this->rights = array();
        $r = 0;

        // Read permission - view all analyses
        $this->rights[$r][0] = $this->numero + $r; // 95100
        $this->rights[$r][1] = 'Read planning intelligence data';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 1; // Enabled by default
        $this->rights[$r][4] = 'read';
        $r++;

        // Write permission - modify settings/thresholds
        $this->rights[$r][0] = $this->numero + $r; // 95101
        $this->rights[$r][1] = 'Modify planning intelligence settings';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';
        $r++;

        // Export permission
        $this->rights[$r][0] = $this->numero + $r; // 95102
        $this->rights[$r][1] = 'Export planning intelligence data';
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'export';
        $r++;

        // ----- Menus -----
        $this->menu = array();
        $r = 0;

        // Parent left menu entry under Products/Stock
        $this->menu[$r] = array(
            'fk_menu'   => 'fk_mainmenu=products',
            'type'      => 'left',
            'titre'     => 'PlanningIntel',
            'mainmenu'  => 'products',
            'leftmenu'  => 'planningintel',
            'url'       => '/planningintel/dashboard.php',
            'langs'     => 'planningintel@planningintel',
            'position'  => 300,
            'enabled'   => '$conf->planningintel->enabled',
            'perms'     => '$user->hasRight(\'planningintel\', \'read\')',
            'target'    => '',
            'user'      => 0
        );
        $r++;

        // Sub-menu: Dashboard
        $this->menu[$r] = array(
            'fk_menu'   => 'fk_mainmenu=products,fk_leftmenu=planningintel',
            'type'      => 'left',
            'titre'     => 'Dashboard',
            'mainmenu'  => 'products',
            'leftmenu'  => 'planningintel_dashboard',
            'url'       => '/planningintel/dashboard.php',
            'langs'     => 'planningintel@planningintel',
            'position'  => 301,
            'enabled'   => '$conf->planningintel->enabled',
            'perms'     => '$user->hasRight(\'planningintel\', \'read\')',
            'target'    => '',
            'user'      => 0
        );
        $r++;

        // Sub-menu: Inventory Intelligence
        $this->menu[$r] = array(
            'fk_menu'   => 'fk_mainmenu=products,fk_leftmenu=planningintel',
            'type'      => 'left',
            'titre'     => 'InventoryIntelligence',
            'mainmenu'  => 'products',
            'leftmenu'  => 'planningintel_inventory',
            'url'       => '/planningintel/inventory.php',
            'langs'     => 'planningintel@planningintel',
            'position'  => 302,
            'enabled'   => '$conf->planningintel->enabled',
            'perms'     => '$user->hasRight(\'planningintel\', \'read\')',
            'target'    => '',
            'user'      => 0
        );
        $r++;

        // Sub-menu: Demand Forecasting
        $this->menu[$r] = array(
            'fk_menu'   => 'fk_mainmenu=products,fk_leftmenu=planningintel',
            'type'      => 'left',
            'titre'     => 'DemandForecasting',
            'mainmenu'  => 'products',
            'leftmenu'  => 'planningintel_forecast',
            'url'       => '/planningintel/forecast.php',
            'langs'     => 'planningintel@planningintel',
            'position'  => 303,
            'enabled'   => '$conf->planningintel->enabled',
            'perms'     => '$user->hasRight(\'planningintel\', \'read\')',
            'target'    => '',
            'user'      => 0
        );
        $r++;

        // Sub-menu: Reorder Planning
        $this->menu[$r] = array(
            'fk_menu'   => 'fk_mainmenu=products,fk_leftmenu=planningintel',
            'type'      => 'left',
            'titre'     => 'ReorderPlanning',
            'mainmenu'  => 'products',
            'leftmenu'  => 'planningintel_reorder',
            'url'       => '/planningintel/reorder.php',
            'langs'     => 'planningintel@planningintel',
            'position'  => 304,
            'enabled'   => '$conf->planningintel->enabled',
            'perms'     => '$user->hasRight(\'planningintel\', \'read\')',
            'target'    => '',
            'user'      => 0
        );
        $r++;
    }

    /**
     * Function called when module is enabled.
     * Creates tables and sets up the module.
     *
     * @param  string $options Options when enabling module
     * @return int             1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $sql = array();

        $this->_load_tables('/planningintel/sql/');

        return $this->_init($sql, $options);
    }

    /**
     * Function called when module is disabled.
     * Removes constants, boxes, permissions and menus.
     * Data tables are NOT deleted (preserves user config).
     *
     * @param  string $options Options when disabling module
     * @return int             1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();

        return $this->_remove($sql, $options);
    }
}

