<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/planningintel.lib.php
 * \brief   Library of functions for Planning Intel module
 */

/**
 * Prepare array of tabs for the main Planning Intel pages.
 *
 * @return array Array of tabs
 */
function planningintelPrepareHead()
{
    global $langs, $conf;

    $langs->load('planningintel@planningintel');

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/planningintel/dashboard.php', 1);
    $head[$h][1] = $langs->trans('Dashboard');
    $head[$h][2] = 'dashboard';
    $h++;

    $head[$h][0] = dol_buildpath('/planningintel/inventory.php', 1);
    $head[$h][1] = $langs->trans('InventoryIntelligence');
    $head[$h][2] = 'inventory';
    $h++;

    $head[$h][0] = dol_buildpath('/planningintel/forecast.php', 1);
    $head[$h][1] = $langs->trans('DemandForecasting');
    $head[$h][2] = 'forecast';
    $h++;

    $head[$h][0] = dol_buildpath('/planningintel/reorder.php', 1);
    $head[$h][1] = $langs->trans('ReorderPlanning');
    $head[$h][2] = 'reorder';
    $h++;

    return $head;
}

/**
 * Prepare array of tabs for the admin settings page.
 *
 * @return array Array of tabs
 */
function planningintelAdminPrepareHead()
{
    global $langs;

    $langs->load('planningintel@planningintel');

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/planningintel/admin/setup.php', 1);
    $head[$h][1] = $langs->trans('Settings');
    $head[$h][2] = 'settings';
    $h++;

    return $head;
}

/**
 * Prepare array of sub-tabs for the inventory intelligence page.
 *
 * @param  string $currentView Current active view
 * @return array  Array of tabs
 */
function planningintelInventorySubTabs($currentView = 'abc')
{
    global $langs;

    $langs->load('planningintel@planningintel');

    $h = 0;
    $head = array();

    $views = array(
        'abc'      => 'ABCAnalysis',
        'xyz'      => 'XYZAnalysis',
        'abcxyz'   => 'ABCXYZMatrix',
        'dead'     => 'DeadStock',
        'slow'     => 'SlowStock',
        'aging'    => 'StockAging',
        'stockout' => 'StockoutRisk',
    );

    foreach ($views as $key => $label) {
        $head[$h][0] = dol_buildpath('/planningintel/inventory.php', 1).'?view='.$key;
        $head[$h][1] = $langs->trans($label);
        $head[$h][2] = $key;
        $h++;
    }

    return $head;
}

/**
 * Prepare array of sub-tabs for the reorder planning page.
 *
 * @return array Array of tabs
 */
function planningintelReorderSubTabs()
{
    global $langs;

    $langs->load('planningintel@planningintel');

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath('/planningintel/reorder.php', 1).'?view=plan';
    $head[$h][1] = $langs->trans('ReorderPlan');
    $head[$h][2] = 'plan';
    $h++;

    $head[$h][0] = dol_buildpath('/planningintel/reorder.php', 1).'?view=bom';
    $head[$h][1] = $langs->trans('BOMExplosion');
    $head[$h][2] = 'bom';
    $h++;

    return $head;
}

/**
 * Print a formula explanation card.
 *
 * @param string $title   Formula title (e.g. 'ABC Classification')
 * @param string $desc    Formula description with optional substitution values
 * @param string $formula Optional specific formula string with actual values
 * @return void
 */
function planningintelPrintFormulaCard($title, $desc, $formula = '')
{
    print '<div class="info-box" style="margin-bottom: 12px; padding: 10px 15px; background: #f8f9fa; border-left: 4px solid #4e73df; border-radius: 3px;">';
    print '<strong>'.$title.'</strong><br>';
    print '<span class="opacitymedium">'.$desc.'</span>';
    if ($formula) {
        print '<br><code style="display: inline-block; margin-top: 6px; padding: 4px 8px; background: #e9ecef; border-radius: 3px;">'.$formula.'</code>';
    }
    print '</div>';
}
