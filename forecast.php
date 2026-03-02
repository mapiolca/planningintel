<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    forecast.php
 * \brief   Demand Forecasting page — SMA, WMA, seasonal index, product detail
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
dol_include_once('/planningintel/class/demandforecast.class.php');
dol_include_once('/planningintel/lib/planningintel.lib.php');

// Access control
if (!$user->hasRight('planningintel', 'read')) {
    accessforbidden();
}

$langs->loadLangs(array('planningintel@planningintel', 'products'));

$config = new PlanningIntelConfig($db);
$forecast = new DemandForecast($db, $config);

// Parameters
$productId = GETPOST('product_id', 'int');
$method = GETPOST('method', 'alpha');
if (empty($method)) {
    $method = 'sma';
}

/*
 * View
 */
$title = $langs->trans('DemandForecasting');
llxHeader('', $title);

// Main tabs
$head = planningintelPrepareHead();
print dol_get_fiche_head($head, 'forecast', $langs->trans('PlanningIntel'), -1, 'planningintel@planningintel');

// Data source info
$dataSource = $config->get('DATA_SOURCE', 'orders');
$smaPeriods = (int) $config->get('SMA_MONTHS', 3);
$wmaPeriods = (int) $config->get('WMA_MONTHS', 3);

print '<div class="opacitymedium small" style="margin-bottom: 10px;">';
print $langs->trans('ConfiguredDataSource', ucfirst($dataSource));
print ' | SMA: '.$smaPeriods.' '.$langs->trans('MonthsShort').' | WMA: '.$wmaPeriods.' '.$langs->trans('MonthsShort');
print '</div>';

// ===================== Product Detail View =====================
if ($productId > 0) {
    // Load product info
    require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
    $product = new Product($db);
    $product->fetch($productId);

    // Back link
    print '<a href="'.$_SERVER['PHP_SELF'].'" class="butAction" style="margin-bottom: 15px;">&laquo; '.$langs->trans('BackToModuleList').'</a><br><br>';

    print '<h3>'.$product->ref.' - '.$product->label.'</h3>';

    // Method selector
    print '<div style="margin-bottom: 15px;">';
    print '<a href="'.$_SERVER['PHP_SELF'].'?product_id='.$productId.'&method=sma" class="butAction'.($method == 'sma' ? 'Refused' : '').'">'.$langs->trans('SimpleMovingAverage').'</a> ';
    print '<a href="'.$_SERVER['PHP_SELF'].'?product_id='.$productId.'&method=wma" class="butAction'.($method == 'wma' ? 'Refused' : '').'">'.$langs->trans('WeightedMovingAverage').'</a> ';
    print '<a href="'.$_SERVER['PHP_SELF'].'?product_id='.$productId.'&method=seasonal" class="butAction'.($method == 'seasonal' ? 'Refused' : '').'">'.$langs->trans('SeasonalIndex').'</a>';
    print '</div>';

    // ---- SMA Detail ----
    if ($method == 'sma') {
        planningintelPrintFormulaCard(
            $langs->trans('SMAFormulaTitle'),
            $langs->trans('SMAFormulaDesc', $smaPeriods, $smaPeriods)
        );

        $result = $forecast->getSMA($productId);
        if ($result['forecast'] == 0 && $result['formula'] == 'Insufficient data') {
            print '<div class="opacitymedium">'.$langs->trans('NoForecastData', $smaPeriods).'</div>';
        } else {
            print '<table class="noborder centpercent">';
            print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('ForecastResult').'</td></tr>';
            print '<tr class="oddeven"><td>'.$langs->trans('ForecastNextMonth').'</td><td class="right"><strong style="font-size: 1.2em; color: #4e73df;">'.$result['forecast'].'</strong></td></tr>';
            print '<tr class="oddeven"><td>'.$langs->trans('Periods').'</td><td class="right">'.$result['periods'].'</td></tr>';
            print '<tr class="oddeven"><td>'.$langs->trans('ForecastFormula').'</td><td class="right"><code>'.$result['formula'].'</code></td></tr>';
            print '</table><br>';

            // Inputs table
            print '<table class="noborder centpercent">';
            print '<tr class="liste_titre"><td>'.$langs->trans('Month').'</td><td class="right">'.$langs->trans('Qty').'</td></tr>';
            foreach ($result['inputs'] as $month => $qty) {
                print '<tr class="oddeven"><td>'.$month.'</td><td class="right">'.$qty.'</td></tr>';
            }
            print '</table><br>';
        }

        // Accuracy
        $mape = $forecast->getForecastAccuracy($productId);
        if ($mape > 0) {
            planningintelPrintFormulaCard(
                $langs->trans('ForecastAccuracy'),
                $langs->trans('MAPEFormulaDesc'),
                'MAPE = '.$mape.'%'
            );
        }
    }

    // ---- WMA Detail ----
    if ($method == 'wma') {
        planningintelPrintFormulaCard(
            $langs->trans('WMAFormulaTitle'),
            $langs->trans('WMAFormulaDesc')
        );

        $result = $forecast->getWMA($productId);
        if ($result['forecast'] == 0 && $result['formula'] == 'Insufficient data') {
            print '<div class="opacitymedium">'.$langs->trans('NoForecastData', $wmaPeriods).'</div>';
        } else {
            print '<table class="noborder centpercent">';
            print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('ForecastResult').'</td></tr>';
            print '<tr class="oddeven"><td>'.$langs->trans('ForecastNextMonth').'</td><td class="right"><strong style="font-size: 1.2em; color: #4e73df;">'.$result['forecast'].'</strong></td></tr>';
            print '<tr class="oddeven"><td>'.$langs->trans('Periods').'</td><td class="right">'.$result['periods'].'</td></tr>';
            print '<tr class="oddeven"><td>'.$langs->trans('Weights').'</td><td class="right">'.implode(', ', $result['weights']).'</td></tr>';
            print '<tr class="oddeven"><td>'.$langs->trans('ForecastFormula').'</td><td class="right"><code>'.$result['formula'].'</code></td></tr>';
            print '</table><br>';

            // Inputs table
            print '<table class="noborder centpercent">';
            print '<tr class="liste_titre"><td>'.$langs->trans('Month').'</td><td class="right">'.$langs->trans('Qty').'</td><td class="right">'.$langs->trans('Weights').'</td></tr>';
            $w = 0;
            foreach ($result['inputs'] as $month => $qty) {
                $weight = isset($result['weights'][$w]) ? $result['weights'][$w] : '-';
                print '<tr class="oddeven"><td>'.$month.'</td><td class="right">'.$qty.'</td><td class="right">'.$weight.'</td></tr>';
                $w++;
            }
            print '</table><br>';
        }
    }

    // ---- Seasonal Index ----
    if ($method == 'seasonal') {
        planningintelPrintFormulaCard(
            $langs->trans('SeasonalFormulaTitle'),
            $langs->trans('SeasonalFormulaDesc')
        );

        $seasonalData = $forecast->getSeasonalIndex($productId);
        $monthNames = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'May', 6 => 'Jun',
                            7 => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec');

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre"><td>'.$langs->trans('Month').'</td><td class="right">'.$langs->trans('Index').'</td><td>'.$langs->trans('Visual').'</td></tr>';
        foreach ($seasonalData as $m => $idx) {
            $barWidth = min(max($idx * 100, 5), 300);
            $barColor = ($idx >= 1) ? '#28a745' : '#ffc107';
            if ($idx > 1.3) {
                $barColor = '#4e73df';
            }
            if ($idx < 0.7) {
                $barColor = '#dc3545';
            }

            print '<tr class="oddeven">';
            print '<td>'.$monthNames[$m].'</td>';
            print '<td class="right">'.$idx.'</td>';
            print '<td><div style="display:inline-block; height:14px; width:'.$barWidth.'px; background:'.$barColor.'; border-radius:2px;"></div></td>';
            print '</tr>';
        }
        print '</table><br>';
    }

    // ---- Demand History Chart ----
    $historyMonths = (int) $config->get('ANALYSIS_MONTHS', 12);
    $demandData = $forecast->getMonthlyDemand($productId, $historyMonths);
    if (!empty($demandData)) {
        print '<h4>'.$langs->trans('MonthlyDemandHistory').'</h4>';

        $graphDataFormatted = array();
        foreach ($demandData as $month => $qty) {
            $graphDataFormatted[] = array($month, $qty);
        }

        $graph = new DolGraph();
        $graph->SetData($graphDataFormatted);
        $graph->SetLegend(array($langs->trans('Actual')));
        $graph->SetType(array('lines'));
        $graph->SetTitle($langs->trans('MonthlyDemandHistory'));
        $graph->SetWidth(800);
        $graph->SetHeight(300);
        $graph->setShowLegend(1);
        $graph->setShowPointValue(1);
        $graph->draw('demand_history_'.$productId);
        print $graph->show();
    }

} else {
    // ===================== Bulk Forecast List =====================
    planningintelPrintFormulaCard(
        $langs->trans('SMAFormulaTitle').' / '.$langs->trans('WMAFormulaTitle'),
        $langs->trans('SMAFormulaDesc', $smaPeriods, $smaPeriods).'<br>'.$langs->trans('WMAFormulaDesc')
    );

    // Method selector for bulk list
    print '<div style="margin-bottom: 15px;">';
    print '<a href="'.$_SERVER['PHP_SELF'].'?method=sma" class="butAction'.($method == 'sma' ? 'Refused' : '').'">'.$langs->trans('SimpleMovingAverage').'</a> ';
    print '<a href="'.$_SERVER['PHP_SELF'].'?method=wma" class="butAction'.($method == 'wma' ? 'Refused' : '').'">'.$langs->trans('WeightedMovingAverage').'</a>';
    print '</div>';

    $data = $forecast->getBulkForecast($method);

    if (empty($data)) {
        print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
    } else {
        print '<div class="opacitymedium small" style="margin-bottom: 6px;">'.$langs->trans('ProductsAnalyzed', count($data)).'</div>';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans('Ref').'</td>';
        print '<td>'.$langs->trans('Label').'</td>';
        print '<td class="right">'.$langs->trans('LastMonthActual').'</td>';
        print '<td class="right">'.$langs->trans('ForecastNextMonth').'</td>';
        print '<td class="center">'.$langs->trans('Method').'</td>';
        print '<td class="center">'.$langs->trans('FormulaExplanation').'</td>';
        print '</tr>';

        foreach ($data as $row) {
            // Color the forecast: green if up, red if down, gray if same
            $trendColor = '#6c757d';
            $trendIcon = '&rarr;';
            if ($row['forecast'] > $row['last_month_actual'] && $row['last_month_actual'] > 0) {
                $trendColor = '#28a745';
                $trendIcon = '&uarr;';
            } elseif ($row['forecast'] < $row['last_month_actual']) {
                $trendColor = '#dc3545';
                $trendIcon = '&darr;';
            }

            print '<tr class="oddeven">';
            print '<td><a href="'.$_SERVER['PHP_SELF'].'?product_id='.$row['product_id'].'&method='.$method.'">'.$row['ref'].'</a></td>';
            print '<td>'.dol_trunc($row['label'], 50).'</td>';
            print '<td class="right">'.$row['last_month_actual'].'</td>';
            print '<td class="right" style="color:'.$trendColor.'; font-weight:bold;">'.$trendIcon.' '.$row['forecast'].'</td>';
            print '<td class="center"><span class="badge" style="background: #4e73df; color: #fff; padding: 2px 8px; border-radius: 3px;">'.$row['method'].'</span></td>';
            print '<td class="center"><a href="'.$_SERVER['PHP_SELF'].'?product_id='.$row['product_id'].'&method='.strtolower($row['method']).'" class="small">'.$langs->trans('ShowFormula').'</a></td>';
            print '</tr>';
        }
        print '</table>';
    }

    // CSV Export link
    print '<div style="margin-top: 15px;">';
    print '<a href="'.dol_buildpath('/planningintel/export.php', 1).'?type=forecast&method='.$method.'" class="butAction" target="_blank">'.$langs->trans('ExportCSV').'</a>';
    print '</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
