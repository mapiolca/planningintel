<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    inventory.php
 * \brief   Inventory Intelligence page — ABC, XYZ, dead/slow stock, aging, stockout risk
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
dol_include_once('/planningintel/class/planningintelconfig.class.php');
dol_include_once('/planningintel/class/inventoryintel.class.php');
dol_include_once('/planningintel/lib/planningintel.lib.php');

// Access control
if (!$user->hasRight('planningintel', 'read')) {
    accessforbidden();
}

$langs->loadLangs(array('planningintel@planningintel', 'products'));

$config = new PlanningIntelConfig($db);
$intel = new InventoryIntel($db, $config);

// Get current view
$view = GETPOST('view', 'alpha');
if (empty($view)) {
    $view = 'abc';
}

/*
 * View
 */
$title = $langs->trans('InventoryIntelligence');
llxHeader('', $title);

// Main tabs
$head = planningintelPrepareHead();
print dol_get_fiche_head($head, 'inventory', $langs->trans('PlanningIntel'), -1, 'planningintel@planningintel');

// Sub-tabs for inventory views
$subHead = planningintelInventorySubTabs($view);
print dol_get_fiche_head($subHead, $view, '', 0, '');

// Data source & period info
$dataSource = $config->get('DATA_SOURCE', 'orders');
$analysisPeriod = (int) $config->get('ANALYSIS_MONTHS', 12);
print '<div class="opacitymedium small" style="margin-bottom: 10px;">';
print $langs->trans('ConfiguredDataSource', ucfirst($dataSource));
print ' | '.$langs->trans('ConfiguredPeriod', $analysisPeriod);
print '</div>';

// ===================== ABC Analysis =====================
if ($view == 'abc') {
    $aPercent = (int) $config->get('ABC_A_PERCENT', 80);
    $bPercent = (int) $config->get('ABC_B_PERCENT', 95);
    $cPercent = 100 - $bPercent;
    $bOnly = $bPercent - $aPercent;

    planningintelPrintFormulaCard(
        $langs->trans('ABCFormulaTitle'),
        $langs->trans('ABCFormulaDesc', $aPercent, $bOnly, $cPercent),
        'Cumulative Revenue % => A (<='.$aPercent.'%) | B (<='.$bPercent.'%) | C (>'.$bPercent.'%)'
    );

    $data = $intel->getABCAnalysis();

    if (empty($data)) {
        print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
    } else {
        print '<div class="opacitymedium small" style="margin-bottom: 6px;">'.$langs->trans('ProductsAnalyzed', count($data)).'</div>';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans('Rank').'</td>';
        print '<td>'.$langs->trans('Ref').'</td>';
        print '<td>'.$langs->trans('Label').'</td>';
        print '<td class="right">'.$langs->trans('TotalRevenue').'</td>';
        print '<td class="right">'.$langs->trans('CumulativePercent').'</td>';
        print '<td class="center">'.$langs->trans('ABCClass').'</td>';
        print '</tr>';

        foreach ($data as $row) {
            $classColor = '';
            if ($row['abc_class'] == 'A') {
                $classColor = 'background: #d4edda; color: #155724;';
            } elseif ($row['abc_class'] == 'B') {
                $classColor = 'background: #fff3cd; color: #856404;';
            } else {
                $classColor = 'background: #f8d7da; color: #721c24;';
            }

            print '<tr class="oddeven">';
            print '<td>'.$row['rank'].'</td>';
            print '<td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$row['product_id'].'">'.$row['ref'].'</a></td>';
            print '<td>'.dol_trunc($row['label'], 50).'</td>';
            print '<td class="right">'.price($row['total_revenue']).'</td>';
            print '<td class="right">'.$row['cumulative_pct'].'%</td>';
            print '<td class="center"><span class="badge" style="padding: 3px 10px; border-radius: 3px; font-weight: bold; '.$classColor.'">'.$row['abc_class'].'</span></td>';
            print '</tr>';
        }
        print '</table>';
    }
}

// ===================== XYZ Analysis =====================
if ($view == 'xyz') {
    $xThreshold = (float) $config->get('XYZ_X_THRESHOLD', 0.5);
    $yThreshold = (float) $config->get('XYZ_Y_THRESHOLD', 1.0);

    planningintelPrintFormulaCard(
        $langs->trans('XYZFormulaTitle'),
        $langs->trans('XYZFormulaDesc', $xThreshold, $yThreshold, $yThreshold),
        'CV = StdDev / Mean | X (CV<='.$xThreshold.') | Y (CV<='.$yThreshold.') | Z (CV>'.$yThreshold.')'
    );

    $data = $intel->getXYZAnalysis();

    if (empty($data)) {
        print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
    } else {
        print '<div class="opacitymedium small" style="margin-bottom: 6px;">'.$langs->trans('ProductsAnalyzed', count($data)).'</div>';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans('Ref').'</td>';
        print '<td>'.$langs->trans('Label').'</td>';
        print '<td class="right">'.$langs->trans('MeanQty').'</td>';
        print '<td class="right">'.$langs->trans('StdDev').'</td>';
        print '<td class="right">'.$langs->trans('CoefficientOfVariation').'</td>';
        print '<td class="center">'.$langs->trans('XYZClass').'</td>';
        print '</tr>';

        foreach ($data as $row) {
            $classColor = '';
            if ($row['xyz_class'] == 'X') {
                $classColor = 'background: #d4edda; color: #155724;';
            } elseif ($row['xyz_class'] == 'Y') {
                $classColor = 'background: #fff3cd; color: #856404;';
            } else {
                $classColor = 'background: #f8d7da; color: #721c24;';
            }

            print '<tr class="oddeven">';
            print '<td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$row['product_id'].'">'.$row['ref'].'</a></td>';
            print '<td>'.dol_trunc($row['label'], 50).'</td>';
            print '<td class="right">'.$row['mean_qty'].'</td>';
            print '<td class="right">'.$row['std_dev'].'</td>';
            print '<td class="right">'.$row['cv'].'</td>';
            print '<td class="center"><span class="badge" style="padding: 3px 10px; border-radius: 3px; font-weight: bold; '.$classColor.'">'.$row['xyz_class'].'</span></td>';
            print '</tr>';
        }
        print '</table>';
    }
}

// ===================== ABC-XYZ Matrix =====================
if ($view == 'abcxyz') {
    planningintelPrintFormulaCard(
        $langs->trans('ABCXYZFormulaTitle'),
        $langs->trans('ABCXYZFormulaDesc'),
        'Matrix cells: AX, AY, AZ, BX, BY, BZ, CX, CY, CZ'
    );

    $data = $intel->getABCXYZMatrix();

    if (empty($data)) {
        print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
    } else {
        // Build matrix counts
        $matrixCounts = array();
        $matrixColors = array(
            'AX' => '#28a745', 'AY' => '#5cb85c', 'AZ' => '#ffc107',
            'BX' => '#5cb85c', 'BY' => '#ffc107', 'BZ' => '#fd7e14',
            'CX' => '#ffc107', 'CY' => '#fd7e14', 'CZ' => '#dc3545',
        );
        foreach (array('A', 'B', 'C') as $a) {
            foreach (array('X', 'Y', 'Z') as $x) {
                $matrixCounts[$a.$x] = 0;
            }
        }
        foreach ($data as $row) {
            $key = $row['combined_class'];
            if (isset($matrixCounts[$key])) {
                $matrixCounts[$key]++;
            }
        }

        // Matrix summary table
        print '<table class="noborder" style="width: 400px; margin-bottom: 15px;">';
        print '<tr class="liste_titre"><td></td><td class="center">X (Stable)</td><td class="center">Y (Variable)</td><td class="center">Z (Erratic)</td></tr>';
        foreach (array('A' => 'A (High)', 'B' => 'B (Medium)', 'C' => 'C (Low)') as $abc => $abcLabel) {
            print '<tr class="oddeven">';
            print '<td><strong>'.$abcLabel.'</strong></td>';
            foreach (array('X', 'Y', 'Z') as $xyz) {
                $key = $abc.$xyz;
                $color = $matrixColors[$key];
                $cnt = $matrixCounts[$key];
                print '<td class="center"><span style="display:inline-block; padding:4px 12px; border-radius:3px; background:'.$color.'; color:#fff; font-weight:bold;">'.$key.' ('.$cnt.')</span></td>';
            }
            print '</tr>';
        }
        print '</table>';

        // Detail table
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans('Ref').'</td>';
        print '<td>'.$langs->trans('Label').'</td>';
        print '<td class="right">'.$langs->trans('TotalRevenue').'</td>';
        print '<td class="right">'.$langs->trans('CoefficientOfVariation').'</td>';
        print '<td class="center">'.$langs->trans('ABCClass').'</td>';
        print '<td class="center">'.$langs->trans('XYZClass').'</td>';
        print '<td class="center">'.$langs->trans('CombinedClass').'</td>';
        print '</tr>';

        foreach ($data as $row) {
            $key = $row['combined_class'];
            $color = isset($matrixColors[$key]) ? $matrixColors[$key] : '#6c757d';

            print '<tr class="oddeven">';
            print '<td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$row['product_id'].'">'.$row['ref'].'</a></td>';
            print '<td>'.dol_trunc($row['label'], 50).'</td>';
            print '<td class="right">'.price($row['total_revenue']).'</td>';
            print '<td class="right">'.$row['cv'].'</td>';
            print '<td class="center">'.$row['abc_class'].'</td>';
            print '<td class="center">'.$row['xyz_class'].'</td>';
            print '<td class="center"><span style="display:inline-block; padding:3px 10px; border-radius:3px; background:'.$color.'; color:#fff; font-weight:bold;">'.$key.'</span></td>';
            print '</tr>';
        }
        print '</table>';
    }
}

// ===================== Dead Stock =====================
if ($view == 'dead') {
    $deadDays = (int) $config->get('DEAD_STOCK_DAYS', 90);

    planningintelPrintFormulaCard(
        $langs->trans('DeadStockFormulaTitle'),
        $langs->trans('DeadStockFormulaDesc', $deadDays),
        $langs->trans('DeadStockFilterFormulaDisplay', $deadDays)
    );

    $data = $intel->getDeadStock();

    if (empty($data)) {
        print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
    } else {
        $totalValue = 0;
        foreach ($data as $row) {
            $totalValue += $row['stock_value'];
        }

        print '<div class="opacitymedium small" style="margin-bottom: 6px;">';
        print $langs->trans('ProductsAnalyzed', count($data));
        print ' | '.$langs->trans('StockValue').': <strong>'.price($totalValue).'</strong>';
        print '</div>';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans('Ref').'</td>';
        print '<td>'.$langs->trans('Label').'</td>';
        print '<td class="right">'.$langs->trans('CurrentStock').'</td>';
        print '<td class="right">'.$langs->trans('CostPrice').'</td>';
        print '<td class="right">'.$langs->trans('StockValue').'</td>';
        print '<td class="center">'.$langs->trans('LastMovement').'</td>';
        print '</tr>';

        foreach ($data as $row) {
            print '<tr class="oddeven">';
            print '<td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$row['product_id'].'">'.$row['ref'].'</a></td>';
            print '<td>'.dol_trunc($row['label'], 50).'</td>';
            print '<td class="right">'.$row['current_stock'].'</td>';
            print '<td class="right">'.price($row['cost_price']).'</td>';
            print '<td class="right">'.price($row['stock_value']).'</td>';
            print '<td class="center">'.($row['last_movement_date'] ? dol_print_date(strtotime($row['last_movement_date']), 'day') : '<span class="badge badge-danger">Never</span>').'</td>';
            print '</tr>';
        }
        print '</table>';
    }
}

// ===================== Slow Stock =====================
if ($view == 'slow') {
    $slowThreshold = (int) $config->get('SLOW_STOCK_THRESHOLD', 5);
    $slowDays = (int) $config->get('SLOW_STOCK_DAYS', 90);

    planningintelPrintFormulaCard(
        $langs->trans('SlowStockFormulaTitle'),
        $langs->trans('SlowStockFormulaDesc', $slowThreshold, $slowDays),
        $langs->trans('SlowStockFilterFormulaDisplay', $slowThreshold, $slowDays)
    );

    $data = $intel->getSlowStock();

    if (empty($data)) {
        print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
    } else {
        print '<div class="opacitymedium small" style="margin-bottom: 6px;">'.$langs->trans('ProductsAnalyzed', count($data)).'</div>';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans('Ref').'</td>';
        print '<td>'.$langs->trans('Label').'</td>';
        print '<td class="right">'.$langs->trans('CurrentStock').'</td>';
        print '<td class="right">'.$langs->trans('MovementCount').'</td>';
        print '<td class="center">'.$langs->trans('LastMovement').'</td>';
        print '</tr>';

        foreach ($data as $row) {
            print '<tr class="oddeven">';
            print '<td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$row['product_id'].'">'.$row['ref'].'</a></td>';
            print '<td>'.dol_trunc($row['label'], 50).'</td>';
            print '<td class="right">'.$row['current_stock'].'</td>';
            print '<td class="right">'.$row['movement_count'].'</td>';
            print '<td class="center">'.($row['last_movement_date'] ? dol_print_date(strtotime($row['last_movement_date']), 'day') : '-').'</td>';
            print '</tr>';
        }
        print '</table>';
    }
}

// ===================== Stock Aging =====================
if ($view == 'aging') {
    planningintelPrintFormulaCard(
        $langs->trans('StockAgingFormulaTitle'),
        $langs->trans('StockAgingFormulaDesc'),
        'Age = DATEDIFF(NOW(), last inbound date)'
    );

    $data = $intel->getStockAging();

    if (empty($data)) {
        print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
    } else {
        print '<div class="opacitymedium small" style="margin-bottom: 6px;">'.$langs->trans('ProductsAnalyzed', count($data)).'</div>';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans('Ref').'</td>';
        print '<td>'.$langs->trans('Label').'</td>';
        print '<td>'.$langs->trans('Warehouse').'</td>';
        print '<td class="right">'.$langs->trans('Qty').'</td>';
        print '<td class="center">'.$langs->trans('LastInbound').'</td>';
        print '<td class="right">'.$langs->trans('AgeDays').'</td>';
        print '</tr>';

        foreach ($data as $row) {
            $ageColor = '';
            if ($row['age_days'] === null) {
                $ageColor = 'color: #dc3545; font-weight: bold;';
            } elseif ($row['age_days'] > 180) {
                $ageColor = 'color: #dc3545;';
            } elseif ($row['age_days'] > 90) {
                $ageColor = 'color: #fd7e14;';
            } elseif ($row['age_days'] > 30) {
                $ageColor = 'color: #ffc107;';
            }

            print '<tr class="oddeven">';
            print '<td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$row['product_id'].'">'.$row['ref'].'</a></td>';
            print '<td>'.dol_trunc($row['label'], 40).'</td>';
            print '<td>'.$row['warehouse_ref'].'</td>';
            print '<td class="right">'.$row['current_qty'].'</td>';
            print '<td class="center">'.($row['last_inbound_date'] ? dol_print_date(strtotime($row['last_inbound_date']), 'day') : '-').'</td>';
            print '<td class="right" style="'.$ageColor.'">'.($row['age_days'] !== null ? $row['age_days'] : $langs->trans('NotAvailable')).'</td>';
            print '</tr>';
        }
        print '</table>';
    }
}

// ===================== Stockout Risk =====================
if ($view == 'stockout') {
    $riskThreshold = (float) $config->get('STOCKOUT_RISK_THRESHOLD', 1.0);

    planningintelPrintFormulaCard(
        $langs->trans('StockoutRiskFormulaTitle'),
        $langs->trans('StockoutRiskFormulaDesc', $riskThreshold),
        $langs->trans('StockoutRiskFormulaDisplay')
    );

    $data = $intel->getStockoutRisk();

    if (empty($data)) {
        print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
    } else {
        $atRiskCount = 0;
        foreach ($data as $row) {
            if ($row['at_risk']) {
                $atRiskCount++;
            }
        }

        print '<div class="opacitymedium small" style="margin-bottom: 6px;">';
        print $langs->trans('ProductsAnalyzed', count($data));
        print ' | <span style="color: #dc3545; font-weight: bold;">'.$langs->trans('AtRisk').': '.$atRiskCount.'</span>';
        print '</div>';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans('Ref').'</td>';
        print '<td>'.$langs->trans('Label').'</td>';
        print '<td class="right">'.$langs->trans('CurrentStock').'</td>';
        print '<td class="right">'.$langs->trans('AvgDailyConsumption').'</td>';
        print '<td class="right">'.$langs->trans('LeadTimeDays').'</td>';
        print '<td class="right">'.$langs->trans('RiskScore').'</td>';
        print '<td class="center">'.$langs->trans('AtRisk').'</td>';
        print '<td>'.$langs->trans('FormulaExplanation').'</td>';
        print '</tr>';

        foreach ($data as $row) {
            $riskStyle = '';
            $riskBadge = '';
            if ($row['at_risk']) {
                $riskStyle = 'color: #dc3545; font-weight: bold;';
                $riskBadge = '<span class="badge" style="background: #dc3545; color: #fff; padding: 2px 8px; border-radius: 3px;">'.$langs->trans('Yes').'</span>';
            } else {
                $riskBadge = '<span class="badge" style="background: #28a745; color: #fff; padding: 2px 8px; border-radius: 3px;">'.$langs->trans('No').'</span>';
            }

            print '<tr class="oddeven">';
            print '<td><a href="'.DOL_URL_ROOT.'/product/card.php?id='.$row['product_id'].'">'.$row['ref'].'</a></td>';
            print '<td>'.dol_trunc($row['label'], 40).'</td>';
            print '<td class="right">'.$row['current_stock'].'</td>';
            print '<td class="right">'.$row['avg_daily_consumption'].'</td>';
            print '<td class="right">'.$row['lead_time_days'].'</td>';
            print '<td class="right" style="'.$riskStyle.'">'.$row['risk_score'].'</td>';
            print '<td class="center">'.$riskBadge.'</td>';
            print '<td class="small opacitymedium"><code>'.$row['formula_display'].'</code></td>';
            print '</tr>';
        }
        print '</table>';
    }
}

// CSV Export link
print '<div style="margin-top: 15px;">';
print '<a href="'.dol_buildpath('/planningintel/export.php', 1).'?type=inventory&view='.$view.'" class="butAction" target="_blank">'.$langs->trans('ExportCSV').'</a>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
