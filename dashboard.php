<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    dashboard.php
 * \brief   Planning Intel Dashboard — KPI cards + charts
 */

// Load Dolibarr environment
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
    $res = @include("../main.inc.php");
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include("../../main.inc.php");
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include("../../../main.inc.php");
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';
dol_include_once('/planningintel/class/planningintelconfig.class.php');
dol_include_once('/planningintel/class/inventoryintel.class.php');
dol_include_once('/planningintel/class/demandforecast.class.php');
dol_include_once('/planningintel/lib/planningintel.lib.php');

// Access control
if (!$user->hasRight('planningintel', 'read')) {
    accessforbidden();
}

$langs->loadLangs(array('planningintel@planningintel', 'products'));

$config = new PlanningIntelConfig($db);
$intel = new InventoryIntel($db, $config);
$forecast = new DemandForecast($db, $config);

/*
 * View
 */
$title = $langs->trans('PlanningIntelDashboard');
llxHeader('', $title);

// Main tabs
$head = planningintelPrepareHead();
print dol_get_fiche_head($head, 'dashboard', $langs->trans('PlanningIntel'), -1, 'planningintel@planningintel');

// ===================== KPI Cards =====================
$kpis = $intel->getDashboardKPIs();

print '<div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">';

// Stockout Risk card
print '<div style="flex: 1; min-width: 200px; padding: 15px; background: #fff; border: 1px solid #dee2e6; border-radius: 5px; border-left: 4px solid #dc3545;">';
print '<div class="opacitymedium small">'.$langs->trans('StockoutRiskCount').'</div>';
print '<div style="font-size: 2em; font-weight: bold; color: #dc3545;">'.$kpis['stockout_risk_count'].'</div>';
print '</div>';

// Dead Stock card
print '<div style="flex: 1; min-width: 200px; padding: 15px; background: #fff; border: 1px solid #dee2e6; border-radius: 5px; border-left: 4px solid #fd7e14;">';
print '<div class="opacitymedium small">'.$langs->trans('DeadStockCount').'</div>';
print '<div style="font-size: 2em; font-weight: bold; color: #fd7e14;">'.$kpis['dead_stock_count'].'</div>';
print '<div class="opacitymedium small">'.$langs->trans('StockValue').': '.price($kpis['dead_stock_value']).'</div>';
print '</div>';

// Overstock Value card
print '<div style="flex: 1; min-width: 200px; padding: 15px; background: #fff; border: 1px solid #dee2e6; border-radius: 5px; border-left: 4px solid #ffc107;">';
print '<div class="opacitymedium small">'.$langs->trans('OverstockValue').'</div>';
print '<div style="font-size: 2em; font-weight: bold; color: #856404;">'.price($kpis['overstock_value']).'</div>';
print '</div>';

// Dead Stock Value card
print '<div style="flex: 1; min-width: 200px; padding: 15px; background: #fff; border: 1px solid #dee2e6; border-radius: 5px; border-left: 4px solid #6c757d;">';
print '<div class="opacitymedium small">'.$langs->trans('DeadStockValue').'</div>';
print '<div style="font-size: 2em; font-weight: bold; color: #6c757d;">'.price($kpis['dead_stock_value']).'</div>';
print '</div>';

print '</div>';

// ===================== Charts Row =====================
print '<div style="display: flex; gap: 20px; flex-wrap: wrap;">';

// ---- Chart 1: Demand Trend (12 months) ----
print '<div style="flex: 2; min-width: 400px;">';
print '<h4>'.$langs->trans('DemandTrend').'</h4>';

$dataSource = $config->get('DATA_SOURCE', 'orders');
$analysisPeriod = $config->getDataMonths();

// Get total monthly demand across all products
if ($dataSource == 'invoices') {
    $sql = "SELECT DATE_FORMAT(f.datef, '%Y-%m') as month_key,";
    $sql .= " SUM(fd.qty) as total_qty";
    $sql .= " FROM ".MAIN_DB_PREFIX."facturedet fd";
    $sql .= " JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = fd.fk_facture";
    $sql .= " WHERE f.fk_statut IN (1, 2)";
    $sql .= " AND f.datef >= DATE_SUB(NOW(), INTERVAL ".$analysisPeriod." MONTH)";
    $sql .= " AND fd.fk_product > 0";
    $sql .= " GROUP BY month_key ORDER BY month_key";
} else {
    $sql = "SELECT DATE_FORMAT(c.date_commande, '%Y-%m') as month_key,";
    $sql .= " SUM(cd.qty) as total_qty";
    $sql .= " FROM ".MAIN_DB_PREFIX."commandedet cd";
    $sql .= " JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande";
    $sql .= " WHERE c.fk_statut IN (1, 2, 3)";
    $sql .= " AND c.date_commande >= DATE_SUB(NOW(), INTERVAL ".$analysisPeriod." MONTH)";
    $sql .= " AND cd.fk_product > 0";
    $sql .= " GROUP BY month_key ORDER BY month_key";
}

$resql = $db->query($sql);
$demandByMonth = array();
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $demandByMonth[$obj->month_key] = (float) $obj->total_qty;
    }
    $db->free($resql);
}

// Fill month range in DolGraph format: array(array('label', val), ...)
$trendGraphData = array();
$hasData = false;
for ($i = $analysisPeriod - 1; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-{$i} months"));
    $val = isset($demandByMonth[$key]) ? $demandByMonth[$key] : 0;
    $trendGraphData[] = array($key, $val);
    if ($val > 0) {
        $hasData = true;
    }
}

if ($hasData) {
    $graph1 = new DolGraph();
    $graph1->SetData($trendGraphData);
    $graph1->SetLegend(array($langs->trans('TotalDemand')));
    $graph1->SetType(array('lines'));
    $graph1->SetTitle($langs->trans('DemandTrend'));
    $graph1->SetWidth(600);
    $graph1->SetHeight(250);
    $graph1->setShowLegend(1);
    $graph1->setShowPointValue(1);
    $graph1->draw('demand_trend_dashboard');
    print $graph1->show();
} else {
    print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
}
print '</div>';

// ---- Chart 2: ABC Distribution (Pie) ----
print '<div style="flex: 1; min-width: 300px;">';
print '<h4>'.$langs->trans('ABCDistribution').'</h4>';

$abcData = $intel->getABCAnalysis();
$abcCounts = array('A' => 0, 'B' => 0, 'C' => 0);
foreach ($abcData as $row) {
    if (isset($abcCounts[$row['abc_class']])) {
        $abcCounts[$row['abc_class']]++;
    }
}

if (array_sum($abcCounts) > 0) {
    $abcGraphData = array(
        array('Class A', $abcCounts['A']),
        array('Class B', $abcCounts['B']),
        array('Class C', $abcCounts['C']),
    );
    $graph2 = new DolGraph();
    $graph2->SetData($abcGraphData);
    $graph2->SetLegend(array($langs->trans('ABCDistribution')));
    $graph2->SetType(array('pie'));
    $graph2->SetTitle($langs->trans('ABCDistribution'));
    $graph2->SetWidth(350);
    $graph2->SetHeight(250);
    $graph2->setShowLegend(2);
    $graph2->setShowPointValue(1);
    $graph2->draw('abc_pie_dashboard');
    print $graph2->show();
} else {
    print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
}
print '</div>';

print '</div>'; // end charts row

// ---- Chart 3: Stock Health Bar ----
print '<div style="margin-top: 20px;">';
print '<h4>'.$langs->trans('StockHealth').'</h4>';

$deadCount = $kpis['dead_stock_count'];
$stockoutCount = $kpis['stockout_risk_count'];

// Count slow stock
$slowData = $intel->getSlowStock();
$slowCount = count($slowData);

// Count healthy (total stocked products minus problematic ones)
$sql = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."product";
$sql .= " WHERE fk_product_type = 0 AND stock > 0";
$sql .= " AND entity IN (".getEntity('product').")";
$totalStocked = 0;
$resql = $db->query($sql);
if ($resql) {
    $obj = $db->fetch_object($resql);
    $totalStocked = $obj ? (int) $obj->cnt : 0;
    $db->free($resql);
}
$healthyCount = max(0, $totalStocked - $deadCount - $slowCount - $stockoutCount);

$healthGraphData = array(
    array($langs->trans('Healthy'), $healthyCount),
    array($langs->trans('SlowStock'), $slowCount),
    array($langs->trans('DeadStock'), $deadCount),
    array($langs->trans('StockoutRisk'), $stockoutCount),
);

if ($totalStocked > 0) {
    $graph3 = new DolGraph();
    $graph3->SetData($healthGraphData);
    $graph3->SetLegend(array($langs->trans('StockHealth')));
    $graph3->SetType(array('bars'));
    $graph3->SetTitle($langs->trans('StockHealth'));
    $graph3->SetWidth(700);
    $graph3->SetHeight(250);
    $graph3->setShowLegend(0);
    $graph3->setShowPointValue(1);
    $graph3->draw('stock_health_dashboard');
    print $graph3->show();
} else {
    print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
}
print '</div>';

// Quick links
print '<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 5px;">';
print '<strong>Quick Links:</strong> ';
print '<a href="'.dol_buildpath('/planningintel/inventory.php', 1).'?view=abc" class="butAction">'.$langs->trans('ABCAnalysis').'</a> ';
print '<a href="'.dol_buildpath('/planningintel/inventory.php', 1).'?view=stockout" class="butAction">'.$langs->trans('StockoutRisk').'</a> ';
print '<a href="'.dol_buildpath('/planningintel/forecast.php', 1).'" class="butAction">'.$langs->trans('DemandForecasting').'</a> ';
print '<a href="'.dol_buildpath('/planningintel/reorder.php', 1).'?view=plan" class="butAction">'.$langs->trans('ReorderPlan').'</a>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
