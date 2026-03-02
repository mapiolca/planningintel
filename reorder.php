<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    reorder.php
 * \brief   Reorder Planning page — ROP, Safety Stock, EOQ, BOM Explosion
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
dol_include_once('/planningintel/class/demandforecast.class.php');
dol_include_once('/planningintel/class/reorderplanning.class.php');
dol_include_once('/planningintel/lib/planningintel.lib.php');

// Access control
if (!$user->hasRight('planningintel', 'read')) {
    accessforbidden();
}

$langs->loadLangs(array('planningintel@planningintel', 'products'));

$config = new PlanningIntelConfig($db);
$forecast = new DemandForecast($db, $config);
$reorder = new ReorderPlanning($db, $config, $forecast);

// Parameters
$view = GETPOST('view', 'alpha');
if (empty($view)) {
    $view = 'plan';
}
$productId = GETPOST('product_id', 'int');
$demandQty = GETPOST('demand_qty', 'int');
if ($demandQty <= 0) {
    $demandQty = 1;
}

/*
 * View
 */
$title = $langs->trans('ReorderPlanning');
llxHeader('', $title);

// Main tabs
$head = planningintelPrepareHead();
print dol_get_fiche_head($head, 'reorder', $langs->trans('PlanningIntel'), -1, 'planningintel@planningintel');

// Sub-tabs
$subHead = planningintelReorderSubTabs();
print dol_get_fiche_head($subHead, $view, '', 0, '');

// ===================== Reorder Plan =====================
if ($view == 'plan') {
    // Formula cards
    planningintelPrintFormulaCard(
        $langs->trans('ROPFormulaTitle'),
        $langs->trans('ROPFormulaDesc'),
        'ROP = (Avg Daily Demand × Lead Time) + Safety Stock'
    );
    planningintelPrintFormulaCard(
        $langs->trans('SSFormulaTitle'),
        $langs->trans('SSFormulaDesc'),
        'SS = Z × σ(daily) × √(LT)'
    );
    planningintelPrintFormulaCard(
        $langs->trans('EOQFormulaTitle'),
        $langs->trans('EOQFormulaDesc'),
        'EOQ = √(2 × D × S / H)'
    );

    // Config info
    $serviceLevel = (int) $config->get('SERVICE_LEVEL', 95);
    $leadTime = (int) $config->get('DEFAULT_LEAD_TIME', 14);
    print '<div class="opacitymedium small" style="margin-bottom: 10px;">';
    print $langs->trans('ServiceLevel').': '.$serviceLevel.'%';
    print ' | '.$langs->trans('DefaultLeadTime').': '.$leadTime.' '.$langs->trans('Days');
    print ' | '.$langs->trans('OrderCost').': '.price((float) $config->get('DEFAULT_ORDER_COST', 50));
    print ' | '.$langs->trans('HoldingCost').': '.$config->get('HOLDING_COST_PERCENT', 25).'%';
    print '</div>';

    // Product detail mode
    if ($productId > 0) {
        require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
        $product = new Product($db);
        $product->fetch($productId);

        print '<a href="'.$_SERVER['PHP_SELF'].'?view=plan" class="butAction" style="margin-bottom: 15px;">&laquo; '.$langs->trans('BackToList').'</a><br><br>';
        print '<h3>'.$product->ref.' - '.$product->label.'</h3>';

        $ropData = $reorder->getReorderPoint($productId);
        $eoqData = $reorder->getEOQ($productId);

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('ReorderPoint').'</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('CurrentStock').'</td><td class="right"><strong>'.$ropData['current_stock'].'</strong></td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('ReorderPoint').'</td><td class="right"><strong style="color: #4e73df;">'.$ropData['rop'].'</strong></td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('SafetyStock').'</td><td class="right">'.$ropData['safety_stock'].'</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('AvgDailyConsumption').'</td><td class="right">'.$ropData['avg_daily'].'</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('LeadTimeDays').'</td><td class="right">'.$ropData['lead_time'].' '.$langs->trans('Days').'</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('ServiceLevel').'</td><td class="right">'.$ropData['service_level'].'%</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('NeedsReorder').'</td><td class="right">';
        if ($ropData['needs_reorder']) {
            print '<span class="badge" style="background: #dc3545; color: #fff; padding: 2px 10px; border-radius: 3px;">'.$langs->trans('Yes').'</span>';
        } else {
            print '<span class="badge" style="background: #28a745; color: #fff; padding: 2px 10px; border-radius: 3px;">'.$langs->trans('No').'</span>';
        }
        print '</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('ForecastFormula').' (ROP)</td><td class="right"><code>'.$ropData['formula'].'</code></td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('ForecastFormula').' (SS)</td><td class="right"><code>'.$ropData['ss_formula'].'</code></td></tr>';
        print '</table><br>';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('EOQ').'</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('EOQ').'</td><td class="right"><strong style="color: #4e73df;">'.$eoqData['eoq'].'</strong></td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('AnnualDemand').'</td><td class="right">'.$eoqData['annual_demand'].'</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('OrderCost').'</td><td class="right">'.price($eoqData['order_cost']).'</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('HoldingCost').'</td><td class="right">'.price($eoqData['holding_cost']).' ('.$eoqData['holding_pct'].'%)</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('UnitPrice').'</td><td class="right">'.price($eoqData['unit_cost']).'</td></tr>';
        print '<tr class="oddeven"><td>'.$langs->trans('ForecastFormula').'</td><td class="right"><code>'.$eoqData['formula'].'</code></td></tr>';
        print '</table>';
    } else {
        // Bulk reorder plan
        $data = $reorder->getBulkReorderPlan();

        if (empty($data)) {
            print '<div class="opacitymedium">'.$langs->trans('NoDataAvailable').'</div>';
        } else {
            $reorderCount = 0;
            foreach ($data as $row) {
                if ($row['needs_reorder']) {
                    $reorderCount++;
                }
            }

            print '<div class="opacitymedium small" style="margin-bottom: 6px;">';
            print $langs->trans('ProductsAnalyzed', count($data));
            print ' | <span style="color: #dc3545; font-weight: bold;">'.$langs->trans('NeedsReorder').': '.$reorderCount.'</span>';
            print '</div>';

            print '<table class="noborder centpercent">';
            print '<tr class="liste_titre">';
            print '<td>'.$langs->trans('Ref').'</td>';
            print '<td>'.$langs->trans('Label').'</td>';
            print '<td class="right">'.$langs->trans('CurrentStock').'</td>';
            print '<td class="right">'.$langs->trans('SafetyStock').'</td>';
            print '<td class="right">'.$langs->trans('ReorderPoint').'</td>';
            print '<td class="right">'.$langs->trans('EOQ').'</td>';
            print '<td class="right">'.$langs->trans('LeadTimeDays').'</td>';
            print '<td class="center">'.$langs->trans('NeedsReorder').'</td>';
            print '<td class="right">'.$langs->trans('SuggestedQty').'</td>';
            print '</tr>';

            foreach ($data as $row) {
                $rowStyle = $row['needs_reorder'] ? ' style="background-color: #fff5f5;"' : '';
                print '<tr class="oddeven"'.$rowStyle.'>';
                print '<td><a href="'.$_SERVER['PHP_SELF'].'?view=plan&product_id='.$row['product_id'].'">'.$row['ref'].'</a></td>';
                print '<td>'.dol_trunc($row['label'], 40).'</td>';
                print '<td class="right">'.$row['current_stock'].'</td>';
                print '<td class="right">'.$row['safety_stock'].'</td>';
                print '<td class="right">'.$row['rop'].'</td>';
                print '<td class="right">'.$row['eoq'].'</td>';
                print '<td class="right">'.$row['lead_time'].'</td>';
                print '<td class="center">';
                if ($row['needs_reorder']) {
                    print '<span class="badge" style="background: #dc3545; color: #fff; padding: 2px 8px; border-radius: 3px;">'.$langs->trans('Yes').'</span>';
                } else {
                    print '<span class="badge" style="background: #28a745; color: #fff; padding: 2px 8px; border-radius: 3px;">'.$langs->trans('No').'</span>';
                }
                print '</td>';
                print '<td class="right" style="font-weight: bold;">'.($row['suggested_qty'] > 0 ? $row['suggested_qty'] : '-').'</td>';
                print '</tr>';
            }
            print '</table>';
        }

        // CSV export
        print '<div style="margin-top: 15px;">';
        print '<a href="'.dol_buildpath('/planningintel/export.php', 1).'?type=reorder" class="butAction" target="_blank">'.$langs->trans('ExportCSV').'</a>';
        print '</div>';
    }
}

// ===================== BOM Explosion =====================
if ($view == 'bom') {
    planningintelPrintFormulaCard(
        $langs->trans('BOMExplosionFormulaTitle'),
        $langs->trans('BOMExplosionFormulaDesc'),
        $langs->trans('BOMFormulaDisplay')
    );

    // Product selector form
    print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'" style="margin-bottom: 15px;">';
    print '<input type="hidden" name="view" value="bom">';
    print '<table class="noborder">';
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans('SelectProduct').'</td>';
    print '<td>';
    // Simple product selector via select2 or product ref input
    print '<input type="number" name="product_id" value="'.($productId > 0 ? $productId : '').'" placeholder="'.$langs->trans('ProductId').'" class="flat width100">';
    print '</td>';
    print '<td>'.$langs->trans('EnterDemandQty').'</td>';
    print '<td><input type="number" name="demand_qty" value="'.$demandQty.'" min="1" class="flat width75"></td>';
    print '<td><input type="submit" class="button" value="'.$langs->trans('ExplodeBOM').'"></td>';
    print '</tr>';
    print '</table>';
    print '</form>';

    if ($productId > 0) {
        require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
        $product = new Product($db);
        $product->fetch($productId);

        print '<h3>'.$product->ref.' - '.$product->label.' ('.$langs->trans('Qty').': '.$demandQty.')</h3>';

        $bomTree = $reorder->getBOMExplosion($productId, $demandQty);

        if (empty($bomTree)) {
            print '<div class="opacitymedium">'.$langs->trans('NoBOMFound').'</div>';
        } else {
            $flatList = $reorder->flattenBOMTree($bomTree);

            print '<table class="noborder centpercent">';
            print '<tr class="liste_titre">';
            print '<td>'.$langs->trans('Depth').'</td>';
            print '<td>'.$langs->trans('Ref').'</td>';
            print '<td>'.$langs->trans('Label').'</td>';
            print '<td class="right">'.$langs->trans('RequiredQty').'</td>';
            print '<td class="right">'.$langs->trans('CurrentStock').'</td>';
            print '<td class="right">'.$langs->trans('Shortage').'</td>';
            print '<td class="right">'.$langs->trans('CostPrice').'</td>';
            print '<td>'.$langs->trans('BOMPath').'</td>';
            print '</tr>';

            foreach ($flatList as $item) {
                $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $item['depth']);
                $shortageStyle = ($item['shortage'] > 0) ? 'color: #dc3545; font-weight: bold;' : '';

                print '<tr class="oddeven">';
                print '<td>'.$item['depth'].'</td>';
                print '<td>'.$indent.'<a href="'.DOL_URL_ROOT.'/product/card.php?id='.$item['product_id'].'">'.$item['ref'].'</a></td>';
                print '<td>'.dol_trunc($item['label'], 40).'</td>';
                print '<td class="right">'.$item['required_qty'].'</td>';
                print '<td class="right">'.$item['current_stock'].'</td>';
                print '<td class="right" style="'.$shortageStyle.'">'.($item['shortage'] > 0 ? $item['shortage'] : '-').'</td>';
                print '<td class="right">'.price($item['cost_price']).'</td>';
                print '<td class="small opacitymedium">'.$item['path'].'</td>';
                print '</tr>';
            }
            print '</table>';
        }
    }
}

print dol_get_fiche_end();

llxFooter();
$db->close();
