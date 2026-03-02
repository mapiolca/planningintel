<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    export.php
 * \brief   CSV export dispatcher for Planning Intel module
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

dol_include_once('/planningintel/class/planningintelconfig.class.php');
dol_include_once('/planningintel/class/inventoryintel.class.php');
dol_include_once('/planningintel/class/demandforecast.class.php');
dol_include_once('/planningintel/class/reorderplanning.class.php');

// Access control
if (!$user->hasRight('planningintel', 'export')) {
    accessforbidden();
}

$config = new PlanningIntelConfig($db);
$intel = new InventoryIntel($db, $config);
$forecast = new DemandForecast($db, $config);
$reorder = new ReorderPlanning($db, $config, $forecast);

$type = GETPOST('type', 'alpha');
$view = GETPOST('view', 'alpha');
$method = GETPOST('method', 'alpha');

$sep = ';';
$filename = 'planningintel_export_'.date('Y-m-d').'.csv';
$headers = array();
$rows = array();

// ===================== Inventory Exports =====================
if ($type == 'inventory') {
    if ($view == 'abc') {
        $filename = 'abc_analysis_'.date('Y-m-d').'.csv';
        $headers = array('Rank', 'Product Ref', 'Product Label', 'Total Revenue', 'Cumulative %', 'ABC Class');
        $data = $intel->getABCAnalysis();
        foreach ($data as $row) {
            $rows[] = array($row['rank'], $row['ref'], $row['label'], $row['total_revenue'], $row['cumulative_pct'], $row['abc_class']);
        }
    } elseif ($view == 'xyz') {
        $filename = 'xyz_analysis_'.date('Y-m-d').'.csv';
        $headers = array('Product Ref', 'Product Label', 'Mean Qty', 'Std Dev', 'CV', 'XYZ Class');
        $data = $intel->getXYZAnalysis();
        foreach ($data as $row) {
            $rows[] = array($row['ref'], $row['label'], $row['mean_qty'], $row['std_dev'], $row['cv'], $row['xyz_class']);
        }
    } elseif ($view == 'abcxyz') {
        $filename = 'abcxyz_matrix_'.date('Y-m-d').'.csv';
        $headers = array('Product Ref', 'Product Label', 'Total Revenue', 'Cumulative %', 'ABC Class', 'CV', 'XYZ Class', 'Combined');
        $data = $intel->getABCXYZMatrix();
        foreach ($data as $row) {
            $rows[] = array($row['ref'], $row['label'], $row['total_revenue'], $row['cumulative_pct'], $row['abc_class'], $row['cv'], $row['xyz_class'], $row['combined_class']);
        }
    } elseif ($view == 'dead') {
        $filename = 'dead_stock_'.date('Y-m-d').'.csv';
        $headers = array('Product Ref', 'Product Label', 'Current Stock', 'Cost Price', 'Stock Value', 'Last Movement');
        $data = $intel->getDeadStock();
        foreach ($data as $row) {
            $rows[] = array($row['ref'], $row['label'], $row['current_stock'], $row['cost_price'], $row['stock_value'], $row['last_movement_date']);
        }
    } elseif ($view == 'slow') {
        $filename = 'slow_stock_'.date('Y-m-d').'.csv';
        $headers = array('Product Ref', 'Product Label', 'Current Stock', 'Movement Count', 'Last Movement');
        $data = $intel->getSlowStock();
        foreach ($data as $row) {
            $rows[] = array($row['ref'], $row['label'], $row['current_stock'], $row['movement_count'], $row['last_movement_date']);
        }
    } elseif ($view == 'aging') {
        $filename = 'stock_aging_'.date('Y-m-d').'.csv';
        $headers = array('Product Ref', 'Product Label', 'Warehouse', 'Qty', 'Last Inbound', 'Age Days');
        $data = $intel->getStockAging();
        foreach ($data as $row) {
            $rows[] = array($row['ref'], $row['label'], $row['warehouse_ref'], $row['current_qty'], $row['last_inbound_date'], $row['age_days']);
        }
    } elseif ($view == 'stockout') {
        $filename = 'stockout_risk_'.date('Y-m-d').'.csv';
        $headers = array('Product Ref', 'Product Label', 'Current Stock', 'Avg Daily Consumption', 'Lead Time Days', 'Risk Score', 'At Risk', 'Formula');
        $data = $intel->getStockoutRisk();
        foreach ($data as $row) {
            $rows[] = array($row['ref'], $row['label'], $row['current_stock'], $row['avg_daily_consumption'], $row['lead_time_days'], $row['risk_score'], $row['at_risk'] ? 'Yes' : 'No', $row['formula_display']);
        }
    }
}

// ===================== Forecast Export =====================
if ($type == 'forecast') {
    if (empty($method)) {
        $method = 'sma';
    }
    $filename = 'forecast_'.$method.'_'.date('Y-m-d').'.csv';
    $headers = array('Product Ref', 'Product Label', 'Last Month Actual', 'Forecast', 'Method');
    $data = $forecast->getBulkForecast($method);
    foreach ($data as $row) {
        $rows[] = array($row['ref'], $row['label'], $row['last_month_actual'], $row['forecast'], $row['method']);
    }
}

// ===================== Reorder Export =====================
if ($type == 'reorder') {
    $filename = 'reorder_plan_'.date('Y-m-d').'.csv';
    $headers = array('Product Ref', 'Product Label', 'Current Stock', 'Safety Stock', 'Reorder Point', 'EOQ', 'Lead Time Days', 'Needs Reorder', 'Suggested Qty');
    $data = $reorder->getBulkReorderPlan();
    foreach ($data as $row) {
        $rows[] = array($row['ref'], $row['label'], $row['current_stock'], $row['safety_stock'], $row['rop'], $row['eoq'], $row['lead_time'], $row['needs_reorder'] ? 'Yes' : 'No', $row['suggested_qty']);
    }
}

// ===================== Output CSV =====================
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');

$output = fopen('php://output', 'w');

// BOM (for Excel)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Headers
fputcsv($output, $headers, $sep);

// Data rows
foreach ($rows as $row) {
    fputcsv($output, $row, $sep);
}

fclose($output);
$db->close();
