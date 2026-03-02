<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \brief   Planning Intel module settings page
 */

// Load Dolibarr environment
if (function_exists('opcache_reset')) {
    @opcache_reset();
}

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include("../../main.inc.php");
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include("../../../main.inc.php");
}
if (!$res && file_exists("../../../../main.inc.php")) {
    $res = @include("../../../../main.inc.php");
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
dol_include_once('/planningintel/class/planningintelconfig.class.php');
dol_include_once('/planningintel/lib/planningintel.lib.php');

// Access control - admin only
if (!$user->admin) {
    accessforbidden();
}

// Load translations
$langs->loadLangs(array('admin', 'planningintel@planningintel'));

// Initialize config
$config = new PlanningIntelConfig($db);

// Get action
$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */
if ($action == 'update' && GETPOST('token', 'alpha') == newToken()) {
    $error = 0;

    // Data source
    $val = GETPOST('DATA_SOURCE', 'alpha');
    if (in_array($val, array('orders', 'invoices'))) {
        $config->set('DATA_SOURCE', $val, 'string');
    }

    // Analysis period
    $val = GETPOST('ANALYSIS_MONTHS', 'int');
    if ($val > 0 && $val <= 60) {
        $config->set('ANALYSIS_MONTHS', $val, 'int');
    }

    // ABC thresholds
    $val = GETPOST('ABC_A_PERCENT', 'int');
    if ($val > 0 && $val < 100) {
        $config->set('ABC_A_PERCENT', $val, 'int');
    }
    $val = GETPOST('ABC_B_PERCENT', 'int');
    if ($val > 0 && $val <= 100) {
        $config->set('ABC_B_PERCENT', $val, 'int');
    }

    // XYZ thresholds
    $val = GETPOST('XYZ_X_THRESHOLD', 'alpha');
    if (is_numeric($val) && $val > 0) {
        $config->set('XYZ_X_THRESHOLD', (float) $val, 'float');
    }
    $val = GETPOST('XYZ_Y_THRESHOLD', 'alpha');
    if (is_numeric($val) && $val > 0) {
        $config->set('XYZ_Y_THRESHOLD', (float) $val, 'float');
    }

    // Dead stock
    $val = GETPOST('DEAD_STOCK_DAYS', 'int');
    if ($val > 0) {
        $config->set('DEAD_STOCK_DAYS', $val, 'int');
    }

    // Slow stock
    $val = GETPOST('SLOW_STOCK_THRESHOLD', 'int');
    if ($val > 0) {
        $config->set('SLOW_STOCK_THRESHOLD', $val, 'int');
    }
    $val = GETPOST('SLOW_STOCK_DAYS', 'int');
    if ($val > 0) {
        $config->set('SLOW_STOCK_DAYS', $val, 'int');
    }

    // Forecast
    $val = GETPOST('SMA_MONTHS', 'int');
    if ($val > 0 && $val <= 24) {
        $config->set('SMA_MONTHS', $val, 'int');
    }
    $val = GETPOST('WMA_MONTHS', 'int');
    if ($val > 0 && $val <= 24) {
        $config->set('WMA_MONTHS', $val, 'int');
    }

    // Reorder parameters
    $val = GETPOST('SERVICE_LEVEL', 'int');
    if ($val >= 80 && $val <= 99) {
        $config->set('SERVICE_LEVEL', $val, 'int');
    }
    $val = GETPOST('DEFAULT_ORDER_COST', 'alpha');
    if (is_numeric($val) && $val >= 0) {
        $config->set('DEFAULT_ORDER_COST', (float) $val, 'float');
    }
    $val = GETPOST('HOLDING_COST_PERCENT', 'alpha');
    if (is_numeric($val) && $val >= 0 && $val <= 100) {
        $config->set('HOLDING_COST_PERCENT', (float) $val, 'float');
    }
    $val = GETPOST('DEFAULT_LEAD_TIME', 'int');
    if ($val > 0) {
        $config->set('DEFAULT_LEAD_TIME', $val, 'int');
    }

    // Alerts
    $val = GETPOST('STOCKOUT_RISK_THRESHOLD', 'alpha');
    if (is_numeric($val) && $val > 0) {
        $config->set('STOCKOUT_RISK_THRESHOLD', (float) $val, 'float');
    }

    // Clear cache and reload
    $config->clearCache();

    if (!$error) {
        setEventMessages($langs->trans('SettingsSaved'), null, 'mesgs');
    }
}

/*
 * View
 */
$page_name = 'PlanningIntelSetup';

llxHeader('', $langs->trans($page_name));

// Subheader with back link
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'object_planningintel@planningintel');

// Admin tabs
$head = planningintelAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('PlanningIntelSettings'), -1, 'planningintel@planningintel');

// Load current config values
$allConfig = $config->getAll();

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

// ===== Data Source Section =====
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3"><strong>'.$langs->trans('DataSource').'</strong></td></tr>';
print '<tr class="oddeven">';
print '<td width="50%">'.$langs->trans('DataSource').'</td>';
print '<td class="center" width="20">&nbsp;</td>';
print '<td class="right">';
$currentSource = isset($allConfig['DATA_SOURCE']) ? $allConfig['DATA_SOURCE'] : 'orders';
print '<select name="DATA_SOURCE" class="flat minwidth200">';
print '<option value="orders"'.($currentSource == 'orders' ? ' selected' : '').'>'.$langs->trans('UseOrders').'</option>';
print '<option value="invoices"'.($currentSource == 'invoices' ? ' selected' : '').'>'.$langs->trans('UseInvoices').'</option>';
print '</select>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('AnalysisPeriod').'</td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$currentMonths = isset($allConfig['ANALYSIS_MONTHS']) ? $allConfig['ANALYSIS_MONTHS'] : 12;
print '<select name="ANALYSIS_MONTHS" class="flat">';
foreach (array(3, 6, 12, 18, 24) as $m) {
    print '<option value="'.$m.'"'.($currentMonths == $m ? ' selected' : '').'>'.$m.' '.$langs->trans('Month').'</option>';
}
print '</select>';
print '</td></tr>';
print '</table><br>';

// ===== ABC Thresholds =====
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3"><strong>'.$langs->trans('ABCThresholds').'</strong></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClassAPercent').'</td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['ABC_A_PERCENT']) ? $allConfig['ABC_A_PERCENT'] : 80;
print '<input type="number" name="ABC_A_PERCENT" value="'.$val.'" min="50" max="99" class="flat width75"> %';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClassBPercent').'</td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['ABC_B_PERCENT']) ? $allConfig['ABC_B_PERCENT'] : 95;
print '<input type="number" name="ABC_B_PERCENT" value="'.$val.'" min="60" max="100" class="flat width75"> %';
print '</td></tr>';
print '</table><br>';

// ===== XYZ Thresholds =====
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3"><strong>'.$langs->trans('XYZThresholds').'</strong></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClassXThreshold').'</td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['XYZ_X_THRESHOLD']) ? $allConfig['XYZ_X_THRESHOLD'] : 0.5;
print '<input type="number" name="XYZ_X_THRESHOLD" value="'.$val.'" min="0.1" max="5" step="0.1" class="flat width75">';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ClassYThreshold').'</td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['XYZ_Y_THRESHOLD']) ? $allConfig['XYZ_Y_THRESHOLD'] : 1.0;
print '<input type="number" name="XYZ_Y_THRESHOLD" value="'.$val.'" min="0.1" max="10" step="0.1" class="flat width75">';
print '</td></tr>';
print '</table><br>';

// ===== Dead & Slow Stock =====
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3"><strong>'.$langs->trans('DeadSlowStock').'</strong></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('DeadStockDays').'<br><span class="opacitymedium small">'.$langs->trans('DeadStockDaysHelp').'</span></td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['DEAD_STOCK_DAYS']) ? $allConfig['DEAD_STOCK_DAYS'] : 90;
print '<input type="number" name="DEAD_STOCK_DAYS" value="'.$val.'" min="7" max="365" class="flat width75"> days';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('SlowStockThreshold').'<br><span class="opacitymedium small">'.$langs->trans('SlowStockThresholdHelp').'</span></td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['SLOW_STOCK_THRESHOLD']) ? $allConfig['SLOW_STOCK_THRESHOLD'] : 5;
print '<input type="number" name="SLOW_STOCK_THRESHOLD" value="'.$val.'" min="1" max="100" class="flat width75">';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('SlowStockDays').'</td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['SLOW_STOCK_DAYS']) ? $allConfig['SLOW_STOCK_DAYS'] : 90;
print '<input type="number" name="SLOW_STOCK_DAYS" value="'.$val.'" min="7" max="365" class="flat width75"> days';
print '</td></tr>';
print '</table><br>';

// ===== Forecast Settings =====
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3"><strong>'.$langs->trans('ForecastSettings').'</strong></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('SMAMonths').'</td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['SMA_MONTHS']) ? $allConfig['SMA_MONTHS'] : 3;
print '<select name="SMA_MONTHS" class="flat">';
foreach (array(3, 6, 12) as $m) {
    print '<option value="'.$m.'"'.($val == $m ? ' selected' : '').'>'.$m.'</option>';
}
print '</select>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('WMAMonths').'</td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['WMA_MONTHS']) ? $allConfig['WMA_MONTHS'] : 3;
print '<select name="WMA_MONTHS" class="flat">';
foreach (array(3, 6, 12) as $m) {
    print '<option value="'.$m.'"'.($val == $m ? ' selected' : '').'>'.$m.'</option>';
}
print '</select>';
print '</td></tr>';
print '</table><br>';

// ===== Reorder Settings =====
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3"><strong>'.$langs->trans('ReorderSettings').'</strong></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('ServiceLevelPercent').'<br><span class="opacitymedium small">'.$langs->trans('ServiceLevelHelp').'</span></td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['SERVICE_LEVEL']) ? $allConfig['SERVICE_LEVEL'] : 95;
print '<select name="SERVICE_LEVEL" class="flat">';
foreach (array(80, 85, 90, 92, 95, 97, 98, 99) as $sl) {
    print '<option value="'.$sl.'"'.($val == $sl ? ' selected' : '').'>'.$sl.'%</option>';
}
print '</select>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('DefaultOrderCost').'<br><span class="opacitymedium small">'.$langs->trans('DefaultOrderCostHelp').'</span></td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['DEFAULT_ORDER_COST']) ? $allConfig['DEFAULT_ORDER_COST'] : 50;
print '<input type="number" name="DEFAULT_ORDER_COST" value="'.$val.'" min="0" step="0.01" class="flat width75">';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('HoldingCostPercent').'<br><span class="opacitymedium small">'.$langs->trans('HoldingCostPercentHelp').'</span></td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['HOLDING_COST_PERCENT']) ? $allConfig['HOLDING_COST_PERCENT'] : 25;
print '<input type="number" name="HOLDING_COST_PERCENT" value="'.$val.'" min="0" max="100" step="0.1" class="flat width75"> %';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('DefaultLeadTime').'<br><span class="opacitymedium small">'.$langs->trans('DefaultLeadTimeHelp').'</span></td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['DEFAULT_LEAD_TIME']) ? $allConfig['DEFAULT_LEAD_TIME'] : 14;
print '<input type="number" name="DEFAULT_LEAD_TIME" value="'.$val.'" min="1" max="365" class="flat width75"> days';
print '</td></tr>';
print '</table><br>';

// ===== Alert Settings =====
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="3"><strong>'.$langs->trans('AlertSettings').'</strong></td></tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans('StockoutRiskThreshold').'<br><span class="opacitymedium small">'.$langs->trans('StockoutRiskThresholdHelp').'</span></td>';
print '<td class="center">&nbsp;</td>';
print '<td class="right">';
$val = isset($allConfig['STOCKOUT_RISK_THRESHOLD']) ? $allConfig['STOCKOUT_RISK_THRESHOLD'] : 1.0;
print '<input type="number" name="STOCKOUT_RISK_THRESHOLD" value="'.$val.'" min="0.1" max="10" step="0.1" class="flat width75">';
print '</td></tr>';
print '</table><br>';

// Save button
print '<div class="center">';
print '<input type="submit" class="button" value="'.$langs->trans('SaveSettings').'">';
print '</div>';

print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
