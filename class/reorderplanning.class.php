<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/reorderplanning.class.php
 * \brief   Reorder Planning analysis (Pillar 3)
 *
 * Provides Safety Stock, Reorder Point, EOQ, BOM Explosion.
 * All queries are SELECT-only against core Dolibarr tables.
 */

dol_include_once('/planningintel/class/planningintelconfig.class.php');
dol_include_once('/planningintel/class/demandforecast.class.php');

class ReorderPlanning
{
    /** @var DoliDB */
    public $db;

    /** @var PlanningIntelConfig */
    public $config;

    /** @var DemandForecast */
    public $forecast;

    /** @var string */
    public $error = '';

    /**
     * Constructor.
     *
     * @param DoliDB              $db       Database handler
     * @param PlanningIntelConfig $config   Config manager
     * @param DemandForecast      $forecast Forecast engine (for demand data)
     */
    public function __construct($db, $config, $forecast)
    {
        $this->db = $db;
        $this->config = $config;
        $this->forecast = $forecast;
    }

    /**
     * Get lead time for a product (from supplier prices or config default).
     *
     * @param  int $productId Product ID
     * @return int Lead time in days
     */
    public function getLeadTime($productId)
    {
        $defaultLeadTime = (int) $this->config->get('DEFAULT_LEAD_TIME', 14);

        $sql = "SELECT MIN(delivery_time_days) as lead_time";
        $sql .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
        $sql .= " WHERE fk_product = ".((int) $productId);
        $sql .= " AND delivery_time_days > 0";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj && $obj->lead_time > 0) {
                $this->db->free($resql);
                return (int) $obj->lead_time;
            }
            $this->db->free($resql);
        }

        return $defaultLeadTime;
    }

    /**
     * Safety Stock calculation.
     * SS = Z × σ(daily demand) × √(Lead Time)
     *
     * @param  int $productId Product ID
     * @return array {safety_stock, z_score, std_dev_daily, lead_time, formula, service_level}
     */
    public function getSafetyStock($productId)
    {
        $serviceLevel = (int) $this->config->get('SERVICE_LEVEL', 95);
        $zScore = $this->config->getZScore($serviceLevel);
        $leadTime = $this->getLeadTime($productId);

        // Get monthly demand to compute daily std deviation
        $demand = $this->forecast->getMonthlyDemand($productId, 12);
        $values = array_values($demand);

        // Convert to daily demand (approx 30 days/month)
        $dailyValues = array();
        foreach ($values as $v) {
            $dailyValues[] = $v / 30;
        }

        $n = count($dailyValues);
        $mean = ($n > 0) ? array_sum($dailyValues) / $n : 0;
        $variance = 0;
        foreach ($dailyValues as $d) {
            $variance += ($d - $mean) * ($d - $mean);
        }
        $variance = ($n > 0) ? $variance / $n : 0;
        $stdDevDaily = sqrt($variance);

        $safetyStock = $zScore * $stdDevDaily * sqrt($leadTime);

        $formula = 'SS = '.$zScore.' × '.round($stdDevDaily, 4).' × √'.$leadTime;
        $formula .= ' = '.round($safetyStock, 2);

        return array(
            'safety_stock'   => round($safetyStock, 2),
            'z_score'        => $zScore,
            'std_dev_daily'  => round($stdDevDaily, 4),
            'lead_time'      => $leadTime,
            'formula'        => $formula,
            'service_level'  => $serviceLevel,
        );
    }

    /**
     * Reorder Point calculation.
     * ROP = (Avg Daily Demand × Lead Time) + Safety Stock
     *
     * @param  int $productId Product ID
     * @return array {rop, avg_daily, lead_time, safety_stock, formula, current_stock, needs_reorder}
     */
    public function getReorderPoint($productId)
    {
        $ss = $this->getSafetyStock($productId);
        $leadTime = $ss['lead_time'];

        // Get avg daily demand
        $demand = $this->forecast->getMonthlyDemand($productId, 12);
        $values = array_values($demand);
        $totalQty = array_sum($values);
        $avgDaily = ($totalQty > 0) ? $totalQty / (count($values) * 30) : 0;

        $rop = ($avgDaily * $leadTime) + $ss['safety_stock'];

        // Get current stock
        $sql = "SELECT stock FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $productId);
        $currentStock = 0;
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $currentStock = (float) $obj->stock;
            }
            $this->db->free($resql);
        }

        $formula = 'ROP = ('.round($avgDaily, 3).' × '.$leadTime.') + '.$ss['safety_stock'];
        $formula .= ' = '.round($rop, 2);

        return array(
            'rop'           => round($rop, 2),
            'avg_daily'     => round($avgDaily, 3),
            'lead_time'     => $leadTime,
            'safety_stock'  => $ss['safety_stock'],
            'formula'       => $formula,
            'current_stock' => $currentStock,
            'needs_reorder' => ($currentStock <= $rop),
            'ss_formula'    => $ss['formula'],
            'service_level' => $ss['service_level'],
        );
    }

    /**
     * Economic Order Quantity calculation.
     * EOQ = √(2 × D × S / H)
     *
     * @param  int   $productId  Product ID
     * @param  float $orderCost  Override order cost (0 = use config)
     * @param  float $holdingPct Override holding cost % (0 = use config)
     * @return array {eoq, annual_demand, order_cost, holding_cost, unit_cost, formula}
     */
    public function getEOQ($productId, $orderCost = 0, $holdingPct = 0)
    {
        if ($orderCost <= 0) {
            $orderCost = (float) $this->config->get('DEFAULT_ORDER_COST', 50);
        }
        if ($holdingPct <= 0) {
            $holdingPct = (float) $this->config->get('HOLDING_COST_PERCENT', 25);
        }

        // Get annual demand
        $demand = $this->forecast->getMonthlyDemand($productId, 12);
        $annualDemand = array_sum(array_values($demand));

        // Get unit cost
        $sql = "SELECT cost_price FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".((int) $productId);
        $unitCost = 0;
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $unitCost = (float) $obj->cost_price;
            }
            $this->db->free($resql);
        }

        // If no cost_price, try supplier price
        if ($unitCost <= 0) {
            $sql2 = "SELECT MIN(unitprice) as min_price FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
            $sql2 .= " WHERE fk_product = ".((int) $productId)." AND unitprice > 0";
            $resql2 = $this->db->query($sql2);
            if ($resql2) {
                $obj2 = $this->db->fetch_object($resql2);
                if ($obj2 && $obj2->min_price > 0) {
                    $unitCost = (float) $obj2->min_price;
                }
                $this->db->free($resql2);
            }
        }

        $holdingCost = $unitCost * ($holdingPct / 100);

        $eoq = 0;
        $formula = '';
        if ($annualDemand > 0 && $orderCost > 0 && $holdingCost > 0) {
            $eoq = sqrt((2 * $annualDemand * $orderCost) / $holdingCost);
            $formula = 'EOQ = √(2 × '.round($annualDemand, 1).' × '.$orderCost.' / '.round($holdingCost, 2).')';
            $formula .= ' = '.round($eoq, 1);
        } else {
            $formula = 'Cannot calculate: ';
            if ($annualDemand <= 0) {
                $formula .= 'no annual demand; ';
            }
            if ($holdingCost <= 0) {
                $formula .= 'no unit cost for holding; ';
            }
        }

        return array(
            'eoq'           => round($eoq, 1),
            'annual_demand' => round($annualDemand, 1),
            'order_cost'    => $orderCost,
            'holding_cost'  => round($holdingCost, 2),
            'holding_pct'   => $holdingPct,
            'unit_cost'     => $unitCost,
            'formula'       => $formula,
        );
    }

    /**
     * BOM Explosion: recursive material requirements.
     *
     * @param  int   $productId Product ID (finished good)
     * @param  float $demandQty Quantity of finished good needed
     * @param  int   $maxDepth  Max recursion depth (default 5)
     * @param  int   $depth     Current depth (internal)
     * @param  string $path     BOM path string (internal)
     * @return array  Tree of required materials with quantities
     */
    public function getBOMExplosion($productId, $demandQty = 1, $maxDepth = 5, $depth = 0, $path = '')
    {
        $productId = (int) $productId;
        $results = array();

        if ($depth > $maxDepth) {
            return $results;
        }

        // Check if BOM tables exist (requires MRP module, Dolibarr 13+)
        $checkSql = "SHOW TABLES LIKE '".MAIN_DB_PREFIX."bom_bom'";
        $checkRes = $this->db->query($checkSql);
        if (!$checkRes || $this->db->num_rows($checkRes) == 0) {
            return $results; // BOM tables not available
        }

        // Find active BOM for this product
        $sql = "SELECT b.rowid as bom_id, b.ref as bom_ref";
        $sql .= " FROM ".MAIN_DB_PREFIX."bom_bom b";
        $sql .= " WHERE b.fk_product = ".$productId;
        $sql .= " AND b.status = 1";
        $sql .= " ORDER BY b.rowid DESC LIMIT 1";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return $results;
        }

        $bom = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$bom) {
            return $results; // No BOM — this is a raw material / leaf node
        }

        // Get BOM lines
        $sql2 = "SELECT bl.fk_product as component_id, bl.qty as bom_qty,";
        $sql2 .= " bl.efficiency,";
        $sql2 .= " p.ref, p.label, p.stock as current_stock, p.cost_price,";
        $sql2 .= " bl.fk_bom_child";
        $sql2 .= " FROM ".MAIN_DB_PREFIX."bom_bomline bl";
        $sql2 .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = bl.fk_product";
        $sql2 .= " WHERE bl.fk_bom = ".(int) $bom->bom_id;
        $sql2 .= " ORDER BY bl.position, bl.rowid";

        $resql2 = $this->db->query($sql2);
        if (!$resql2) {
            $this->error = $this->db->lasterror();
            return $results;
        }

        while ($obj = $this->db->fetch_object($resql2)) {
            $efficiency = ($obj->efficiency > 0) ? (float) $obj->efficiency : 1.0;
            $requiredQty = ($demandQty * (float) $obj->bom_qty) / $efficiency;
            $currentStock = (float) $obj->current_stock;
            $shortage = max(0, $requiredQty - $currentStock);

            $componentPath = $path ? $path.' > '.$obj->ref : $obj->ref;

            $item = array(
                'product_id'    => (int) $obj->component_id,
                'ref'           => $obj->ref,
                'label'         => $obj->label,
                'bom_qty'       => (float) $obj->bom_qty,
                'efficiency'    => $efficiency,
                'required_qty'  => round($requiredQty, 2),
                'current_stock' => $currentStock,
                'shortage'      => round($shortage, 2),
                'cost_price'    => (float) $obj->cost_price,
                'depth'         => $depth + 1,
                'path'          => $componentPath,
                'has_children'  => false,
                'children'      => array(),
            );

            // Recurse if component has its own BOM
            if ($obj->fk_bom_child > 0 || $depth < $maxDepth) {
                $children = $this->getBOMExplosion(
                    (int) $obj->component_id,
                    $requiredQty,
                    $maxDepth,
                    $depth + 1,
                    $componentPath
                );
                if (!empty($children)) {
                    $item['has_children'] = true;
                    $item['children'] = $children;
                }
            }

            $results[] = $item;
        }
        $this->db->free($resql2);

        return $results;
    }

    /**
     * Flatten BOM explosion tree for table display.
     *
     * @param  array $tree  BOM tree from getBOMExplosion()
     * @return array Flat list of all components at all depths
     */
    public function flattenBOMTree($tree)
    {
        $flat = array();
        foreach ($tree as $item) {
            $children = $item['children'];
            unset($item['children']);
            $flat[] = $item;
            if (!empty($children)) {
                $flat = array_merge($flat, $this->flattenBOMTree($children));
            }
        }
        return $flat;
    }

    /**
     * Bulk reorder plan for all products.
     *
     * @return array Products with ROP, safety stock, EOQ, current stock, reorder flag
     */
    public function getBulkReorderPlan()
    {
        $defaultLeadTime = (int) $this->config->get('DEFAULT_LEAD_TIME', 14);
        $serviceLevel = (int) $this->config->get('SERVICE_LEVEL', 95);
        $zScore = $this->config->getZScore($serviceLevel);
        $orderCost = (float) $this->config->get('DEFAULT_ORDER_COST', 50);
        $holdingPct = (float) $this->config->get('HOLDING_COST_PERCENT', 25);
        $dataSource = $this->config->get('DATA_SOURCE', 'orders');
        $analysisPeriod = $this->config->getDataMonths();

        // Get monthly demand for all products (reuse the bulk approach)
        if ($dataSource == 'invoices') {
            $sql = "SELECT fd.fk_product as product_id, p.ref, p.label,";
            $sql .= " p.stock as current_stock, p.cost_price,";
            $sql .= " DATE_FORMAT(f.datef, '%Y-%m') as month_key,";
            $sql .= " SUM(fd.qty) as monthly_qty";
            $sql .= " FROM ".MAIN_DB_PREFIX."facturedet fd";
            $sql .= " JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = fd.fk_facture";
            $sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = fd.fk_product";
            $sql .= " WHERE f.fk_statut IN (1, 2)";
            $sql .= " AND f.datef >= DATE_SUB(NOW(), INTERVAL ".$analysisPeriod." MONTH)";
            $sql .= " AND fd.fk_product > 0";
            $sql .= " AND p.fk_product_type = 0";
            $sql .= " AND p.entity IN (".getEntity('product').")";
            $sql .= " GROUP BY fd.fk_product, p.ref, p.label, p.stock, p.cost_price, month_key";
            $sql .= " ORDER BY fd.fk_product, month_key";
        } else {
            $sql = "SELECT cd.fk_product as product_id, p.ref, p.label,";
            $sql .= " p.stock as current_stock, p.cost_price,";
            $sql .= " DATE_FORMAT(c.date_commande, '%Y-%m') as month_key,";
            $sql .= " SUM(cd.qty) as monthly_qty";
            $sql .= " FROM ".MAIN_DB_PREFIX."commandedet cd";
            $sql .= " JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande";
            $sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product";
            $sql .= " WHERE c.fk_statut IN (1, 2, 3)";
            $sql .= " AND c.date_commande >= DATE_SUB(NOW(), INTERVAL ".$analysisPeriod." MONTH)";
            $sql .= " AND cd.fk_product > 0";
            $sql .= " AND p.fk_product_type = 0";
            $sql .= " AND p.entity IN (".getEntity('product').")";
            $sql .= " GROUP BY cd.fk_product, p.ref, p.label, p.stock, p.cost_price, month_key";
            $sql .= " ORDER BY cd.fk_product, month_key";
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return array();
        }

        // Build month range
        $monthRange = array();
        for ($i = $analysisPeriod - 1; $i >= 0; $i--) {
            $monthRange[] = date('Y-m', strtotime("-{$i} months"));
        }

        // Group by product
        $productData = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $pid = (int) $obj->product_id;
            if (!isset($productData[$pid])) {
                $productData[$pid] = array(
                    'ref'           => $obj->ref,
                    'label'         => $obj->label,
                    'current_stock' => (float) $obj->current_stock,
                    'cost_price'    => (float) $obj->cost_price,
                    'months'        => array(),
                );
            }
            $productData[$pid]['months'][$obj->month_key] = (float) $obj->monthly_qty;
        }
        $this->db->free($resql);

        // Get lead times for all products
        $leadTimes = array();
        $sql2 = "SELECT fk_product, MIN(delivery_time_days) as lead_time";
        $sql2 .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
        $sql2 .= " WHERE delivery_time_days > 0 GROUP BY fk_product";
        $resql2 = $this->db->query($sql2);
        if ($resql2) {
            while ($obj = $this->db->fetch_object($resql2)) {
                $leadTimes[(int) $obj->fk_product] = (int) $obj->lead_time;
            }
            $this->db->free($resql2);
        }

        // Calculate ROP/SS/EOQ for each product
        $results = array();
        foreach ($productData as $pid => $data) {
            $quantities = array();
            foreach ($monthRange as $m) {
                $quantities[] = isset($data['months'][$m]) ? $data['months'][$m] : 0;
            }

            // Daily demand stats
            $dailyValues = array();
            foreach ($quantities as $q) {
                $dailyValues[] = $q / 30;
            }
            $n = count($dailyValues);
            $meanDaily = ($n > 0) ? array_sum($dailyValues) / $n : 0;
            $variance = 0;
            foreach ($dailyValues as $d) {
                $variance += ($d - $meanDaily) * ($d - $meanDaily);
            }
            $variance = ($n > 0) ? $variance / $n : 0;
            $stdDevDaily = sqrt($variance);

            $leadTime = isset($leadTimes[$pid]) ? $leadTimes[$pid] : $defaultLeadTime;

            // Safety Stock
            $safetyStock = $zScore * $stdDevDaily * sqrt($leadTime);

            // Reorder Point
            $rop = ($meanDaily * $leadTime) + $safetyStock;

            // EOQ
            $annualDemand = array_sum($quantities);
            $holdingCost = $data['cost_price'] * ($holdingPct / 100);
            $eoq = 0;
            if ($annualDemand > 0 && $orderCost > 0 && $holdingCost > 0) {
                $eoq = sqrt((2 * $annualDemand * $orderCost) / $holdingCost);
            }

            $needsReorder = ($data['current_stock'] <= $rop);
            $suggestedQty = $needsReorder ? max(round($eoq, 0), round($rop - $data['current_stock'] + $safetyStock, 0)) : 0;

            $results[] = array(
                'product_id'    => $pid,
                'ref'           => $data['ref'],
                'label'         => $data['label'],
                'current_stock' => $data['current_stock'],
                'safety_stock'  => round($safetyStock, 1),
                'rop'           => round($rop, 1),
                'eoq'           => round($eoq, 1),
                'lead_time'     => $leadTime,
                'needs_reorder' => $needsReorder,
                'suggested_qty' => $suggestedQty,
            );
        }

        // Sort: needs_reorder first, then by current_stock ascending
        usort($results, function ($a, $b) {
            if ($a['needs_reorder'] !== $b['needs_reorder']) {
                return $b['needs_reorder'] <=> $a['needs_reorder'];
            }
            return $a['current_stock'] <=> $b['current_stock'];
        });

        return $results;
    }
}
