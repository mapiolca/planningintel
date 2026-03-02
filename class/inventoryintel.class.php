<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/inventoryintel.class.php
 * \brief   Inventory Intelligence analysis (Pillar 1)
 *
 * Provides ABC, XYZ, dead/slow stock, aging, and stockout risk analysis.
 * All queries are SELECT-only against core Dolibarr tables.
 */

dol_include_once('/planningintel/class/planningintelconfig.class.php');

class InventoryIntel
{
    /** @var DoliDB */
    public $db;

    /** @var PlanningIntelConfig */
    public $config;

    /** @var string */
    public $error = '';

    /**
     * Constructor.
     *
     * @param DoliDB              $db     Database handler
     * @param PlanningIntelConfig $config Config manager
     */
    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * ABC Analysis: classify products by cumulative revenue contribution.
     *
     * @param  int    $months     Analysis period (0 = use config)
     * @param  string $dataSource 'orders' or 'invoices' ('' = use config)
     * @return array  Products sorted by revenue desc with abc_class
     */
    public function getABCAnalysis($months = 0, $dataSource = '')
    {
        if ($months <= 0) {
            $months = $this->config->getDataMonths();
        }
        if (empty($dataSource)) {
            $dataSource = $this->config->get('DATA_SOURCE', 'orders');
        }

        $aPercent = (float) $this->config->get('ABC_A_PERCENT', 80);
        $bPercent = (float) $this->config->get('ABC_B_PERCENT', 95);

        // Build query based on data source
        if ($dataSource == 'invoices') {
            $sql = "SELECT fd.fk_product as product_id, p.ref, p.label,";
            $sql .= " SUM(fd.total_ht) as total_revenue";
            $sql .= " FROM ".MAIN_DB_PREFIX."facturedet fd";
            $sql .= " JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = fd.fk_facture";
            $sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = fd.fk_product";
            $sql .= " WHERE f.fk_statut IN (1, 2)";
            $sql .= " AND f.datef >= DATE_SUB(NOW(), INTERVAL ".((int) $months)." MONTH)";
            $sql .= " AND fd.fk_product > 0";
            $sql .= " AND p.entity IN (".getEntity('product').")";
            $sql .= " GROUP BY fd.fk_product, p.ref, p.label";
            $sql .= " ORDER BY total_revenue DESC";
        } else {
            $sql = "SELECT cd.fk_product as product_id, p.ref, p.label,";
            $sql .= " SUM(cd.total_ht) as total_revenue";
            $sql .= " FROM ".MAIN_DB_PREFIX."commandedet cd";
            $sql .= " JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande";
            $sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product";
            $sql .= " WHERE c.fk_statut IN (1, 2, 3)";
            $sql .= " AND c.date_commande >= DATE_SUB(NOW(), INTERVAL ".((int) $months)." MONTH)";
            $sql .= " AND cd.fk_product > 0";
            $sql .= " AND p.entity IN (".getEntity('product').")";
            $sql .= " GROUP BY cd.fk_product, p.ref, p.label";
            $sql .= " ORDER BY total_revenue DESC";
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return array();
        }

        $results = array();
        $grandTotal = 0;

        while ($obj = $this->db->fetch_object($resql)) {
            $results[] = array(
                'product_id'    => (int) $obj->product_id,
                'ref'           => $obj->ref,
                'label'         => $obj->label,
                'total_revenue' => (float) $obj->total_revenue,
            );
            $grandTotal += (float) $obj->total_revenue;
        }
        $this->db->free($resql);

        // Calculate cumulative % and assign ABC class
        $cumulative = 0;
        $rank = 0;
        foreach ($results as &$row) {
            $rank++;
            $row['rank'] = $rank;
            if ($grandTotal > 0) {
                $cumulative += ($row['total_revenue'] / $grandTotal) * 100;
            }
            $row['cumulative_pct'] = round($cumulative, 2);

            if ($cumulative <= $aPercent) {
                $row['abc_class'] = 'A';
            } elseif ($cumulative <= $bPercent) {
                $row['abc_class'] = 'B';
            } else {
                $row['abc_class'] = 'C';
            }
        }
        unset($row);

        return $results;
    }

    /**
     * XYZ Analysis: classify products by demand coefficient of variation.
     *
     * @param  int    $months     Analysis period (0 = use config)
     * @param  string $dataSource 'orders' or 'invoices' ('' = use config)
     * @return array  Products with cv and xyz_class
     */
    public function getXYZAnalysis($months = 0, $dataSource = '')
    {
        if ($months <= 0) {
            $months = $this->config->getDataMonths();
        }
        if (empty($dataSource)) {
            $dataSource = $this->config->get('DATA_SOURCE', 'orders');
        }

        $xThreshold = (float) $this->config->get('XYZ_X_THRESHOLD', 0.5);
        $yThreshold = (float) $this->config->get('XYZ_Y_THRESHOLD', 1.0);

        // Get monthly demand per product
        if ($dataSource == 'invoices') {
            $sql = "SELECT fd.fk_product as product_id, p.ref, p.label,";
            $sql .= " DATE_FORMAT(f.datef, '%Y-%m') as month_key,";
            $sql .= " SUM(fd.qty) as monthly_qty";
            $sql .= " FROM ".MAIN_DB_PREFIX."facturedet fd";
            $sql .= " JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = fd.fk_facture";
            $sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = fd.fk_product";
            $sql .= " WHERE f.fk_statut IN (1, 2)";
            $sql .= " AND f.datef >= DATE_SUB(NOW(), INTERVAL ".((int) $months)." MONTH)";
            $sql .= " AND fd.fk_product > 0";
            $sql .= " AND p.entity IN (".getEntity('product').")";
            $sql .= " GROUP BY fd.fk_product, p.ref, p.label, month_key";
            $sql .= " ORDER BY fd.fk_product, month_key";
        } else {
            $sql = "SELECT cd.fk_product as product_id, p.ref, p.label,";
            $sql .= " DATE_FORMAT(c.date_commande, '%Y-%m') as month_key,";
            $sql .= " SUM(cd.qty) as monthly_qty";
            $sql .= " FROM ".MAIN_DB_PREFIX."commandedet cd";
            $sql .= " JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande";
            $sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product";
            $sql .= " WHERE c.fk_statut IN (1, 2, 3)";
            $sql .= " AND c.date_commande >= DATE_SUB(NOW(), INTERVAL ".((int) $months)." MONTH)";
            $sql .= " AND cd.fk_product > 0";
            $sql .= " AND p.entity IN (".getEntity('product').")";
            $sql .= " GROUP BY cd.fk_product, p.ref, p.label, month_key";
            $sql .= " ORDER BY cd.fk_product, month_key";
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return array();
        }

        // Build month range
        $monthRange = array();
        for ($i = $months - 1; $i >= 0; $i--) {
            $monthRange[] = date('Y-m', strtotime("-{$i} months"));
        }

        // Group data by product
        $productData = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $pid = (int) $obj->product_id;
            if (!isset($productData[$pid])) {
                $productData[$pid] = array(
                    'product_id' => $pid,
                    'ref'        => $obj->ref,
                    'label'      => $obj->label,
                    'months'     => array(),
                );
            }
            $productData[$pid]['months'][$obj->month_key] = (float) $obj->monthly_qty;
        }
        $this->db->free($resql);

        // Calculate CV for each product
        $results = array();
        foreach ($productData as $pid => $data) {
            // Fill zero months
            $quantities = array();
            foreach ($monthRange as $m) {
                $quantities[] = isset($data['months'][$m]) ? $data['months'][$m] : 0;
            }

            $n = count($quantities);
            $mean = array_sum($quantities) / max($n, 1);
            $variance = 0;
            foreach ($quantities as $q) {
                $variance += ($q - $mean) * ($q - $mean);
            }
            $variance = $variance / max($n, 1);
            $stdDev = sqrt($variance);
            $cv = ($mean > 0) ? $stdDev / $mean : 0;

            if ($cv <= $xThreshold) {
                $xyzClass = 'X';
            } elseif ($cv <= $yThreshold) {
                $xyzClass = 'Y';
            } else {
                $xyzClass = 'Z';
            }

            $results[] = array(
                'product_id' => $pid,
                'ref'        => $data['ref'],
                'label'      => $data['label'],
                'mean_qty'   => round($mean, 2),
                'std_dev'    => round($stdDev, 2),
                'cv'         => round($cv, 3),
                'xyz_class'  => $xyzClass,
            );
        }

        // Sort by CV ascending
        usort($results, function ($a, $b) {
            return $a['cv'] <=> $b['cv'];
        });

        return $results;
    }

    /**
     * Combined ABC-XYZ matrix.
     *
     * @param  int    $months     Analysis period
     * @param  string $dataSource Data source
     * @return array  Products with both abc_class and xyz_class combined
     */
    public function getABCXYZMatrix($months = 0, $dataSource = '')
    {
        $abc = $this->getABCAnalysis($months, $dataSource);
        $xyz = $this->getXYZAnalysis($months, $dataSource);

        // Index XYZ by product_id
        $xyzIndex = array();
        foreach ($xyz as $row) {
            $xyzIndex[$row['product_id']] = $row;
        }

        $results = array();
        foreach ($abc as $row) {
            $pid = $row['product_id'];
            $xyzData = isset($xyzIndex[$pid]) ? $xyzIndex[$pid] : null;
            $results[] = array(
                'product_id'     => $pid,
                'ref'            => $row['ref'],
                'label'          => $row['label'],
                'total_revenue'  => $row['total_revenue'],
                'cumulative_pct' => $row['cumulative_pct'],
                'abc_class'      => $row['abc_class'],
                'mean_qty'       => $xyzData ? $xyzData['mean_qty'] : 0,
                'cv'             => $xyzData ? $xyzData['cv'] : 0,
                'xyz_class'      => $xyzData ? $xyzData['xyz_class'] : 'Z',
                'combined_class' => $row['abc_class'].($xyzData ? $xyzData['xyz_class'] : 'Z'),
            );
        }

        return $results;
    }

    /**
     * Dead Stock: products with zero stock movements in N days.
     *
     * @param  int   $days Days threshold (0 = use config)
     * @return array Products with no movement
     */
    public function getDeadStock($days = 0)
    {
        if ($days <= 0) {
            $days = (int) $this->config->get('DEAD_STOCK_DAYS', 90);
        }

        $sql = "SELECT p.rowid as product_id, p.ref, p.label, p.stock as current_stock,";
        $sql .= " p.cost_price,";
        $sql .= " CASE WHEN p.cost_price > 0 THEN p.stock * p.cost_price ELSE 0 END as stock_value,";
        $sql .= " MAX(sm.datem) as last_movement_date";
        $sql .= " FROM ".MAIN_DB_PREFIX."product p";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."stock_mouvement sm ON sm.fk_product = p.rowid";
        $sql .= " WHERE p.fk_product_type = 0";
        $sql .= " AND p.tosell = 1";
        $sql .= " AND p.stock > 0";
        $sql .= " AND p.entity IN (".getEntity('product').")";
        $sql .= " GROUP BY p.rowid, p.ref, p.label, p.cost_price, p.stock";
        $sql .= " HAVING last_movement_date IS NULL";
        $sql .= " OR last_movement_date < DATE_SUB(NOW(), INTERVAL ".((int) $days)." DAY)";
        $sql .= " ORDER BY stock_value DESC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return array();
        }

        $results = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $results[] = array(
                'product_id'         => (int) $obj->product_id,
                'ref'                => $obj->ref,
                'label'              => $obj->label,
                'current_stock'      => (float) $obj->current_stock,
                'cost_price'         => (float) $obj->cost_price,
                'stock_value'        => (float) $obj->stock_value,
                'last_movement_date' => $obj->last_movement_date,
            );
        }
        $this->db->free($resql);

        return $results;
    }

    /**
     * Slow Stock: products with movements below threshold in N days.
     *
     * @param  int   $threshold Min movement count (0 = use config)
     * @param  int   $days      Period in days (0 = use config)
     * @return array Products with low movement
     */
    public function getSlowStock($threshold = 0, $days = 0)
    {
        if ($threshold <= 0) {
            $threshold = (int) $this->config->get('SLOW_STOCK_THRESHOLD', 5);
        }
        if ($days <= 0) {
            $days = (int) $this->config->get('SLOW_STOCK_DAYS', 90);
        }

        $sql = "SELECT p.rowid as product_id, p.ref, p.label, p.stock as current_stock,";
        $sql .= " COUNT(sm.rowid) as movement_count,";
        $sql .= " MAX(sm.datem) as last_movement_date";
        $sql .= " FROM ".MAIN_DB_PREFIX."product p";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."stock_mouvement sm ON sm.fk_product = p.rowid";
        $sql .= " AND sm.datem >= DATE_SUB(NOW(), INTERVAL ".((int) $days)." DAY)";
        $sql .= " WHERE p.fk_product_type = 0";
        $sql .= " AND p.tosell = 1";
        $sql .= " AND p.stock > 0";
        $sql .= " AND p.entity IN (".getEntity('product').")";
        $sql .= " GROUP BY p.rowid, p.ref, p.label, p.stock";
        $sql .= " HAVING movement_count > 0 AND movement_count < ".((int) $threshold);
        $sql .= " ORDER BY movement_count ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return array();
        }

        $results = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $results[] = array(
                'product_id'         => (int) $obj->product_id,
                'ref'                => $obj->ref,
                'label'              => $obj->label,
                'current_stock'      => (float) $obj->current_stock,
                'movement_count'     => (int) $obj->movement_count,
                'last_movement_date' => $obj->last_movement_date,
            );
        }
        $this->db->free($resql);

        return $results;
    }

    /**
     * Stock Aging: days since last inbound movement per product per warehouse.
     *
     * @return array Products with aging info by warehouse
     */
    public function getStockAging()
    {
        $sql = "SELECT ps.fk_product as product_id, p.ref, p.label,";
        $sql .= " ps.fk_entrepot as warehouse_id, e.ref as warehouse_ref,";
        $sql .= " ps.reel as current_qty,";
        $sql .= " MAX(sm.datem) as last_inbound_date,";
        $sql .= " DATEDIFF(NOW(), MAX(sm.datem)) as age_days";
        $sql .= " FROM ".MAIN_DB_PREFIX."product_stock ps";
        $sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = ps.fk_product";
        $sql .= " JOIN ".MAIN_DB_PREFIX."entrepot e ON e.rowid = ps.fk_entrepot";
        $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."stock_mouvement sm ON sm.fk_product = ps.fk_product";
        $sql .= " AND sm.fk_entrepot = ps.fk_entrepot";
        $sql .= " AND sm.type_mouvement IN (0, 3)"; // inbound only
        $sql .= " WHERE e.statut = 1";
        $sql .= " AND ps.reel > 0";
        $sql .= " AND p.entity IN (".getEntity('product').")";
        $sql .= " GROUP BY ps.fk_product, ps.fk_entrepot, p.ref, p.label, e.ref, ps.reel";
        $sql .= " ORDER BY age_days DESC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return array();
        }

        $results = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $results[] = array(
                'product_id'       => (int) $obj->product_id,
                'ref'              => $obj->ref,
                'label'            => $obj->label,
                'warehouse_id'     => (int) $obj->warehouse_id,
                'warehouse_ref'    => $obj->warehouse_ref,
                'current_qty'      => (float) $obj->current_qty,
                'last_inbound_date'=> $obj->last_inbound_date,
                'age_days'         => $obj->age_days !== null ? (int) $obj->age_days : null,
            );
        }
        $this->db->free($resql);

        return $results;
    }

    /**
     * Stockout Risk Score per product.
     * Score = current_qty / (avg_daily_consumption x lead_time_days)
     *
     * @return array Products with risk scores
     */
    public function getStockoutRisk()
    {
        $analysisMonths = $this->config->getDataMonths();
        $defaultLeadTime = (int) $this->config->get('DEFAULT_LEAD_TIME', 14);
        $riskThreshold = (float) $this->config->get('STOCKOUT_RISK_THRESHOLD', 1.0);

        // Step 1: Get avg daily consumption from outbound stock movements
        $sql = "SELECT sm.fk_product as product_id,";
        $sql .= " SUM(ABS(sm.value)) as total_out,";
        $sql .= " DATEDIFF(NOW(), MIN(sm.datem)) as days_span";
        $sql .= " FROM ".MAIN_DB_PREFIX."stock_mouvement sm";
        $sql .= " WHERE sm.type_mouvement IN (1, 2)"; // outbound
        $sql .= " AND sm.datem >= DATE_SUB(NOW(), INTERVAL ".((int) $analysisMonths)." MONTH)";
        $sql .= " GROUP BY sm.fk_product";

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return array();
        }

        $consumption = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $daysSpan = max((int) $obj->days_span, 1);
            $consumption[(int) $obj->product_id] = (float) $obj->total_out / $daysSpan;
        }
        $this->db->free($resql);

        // Step 2: Get lead times from supplier prices
        $sql2 = "SELECT fk_product, MIN(delivery_time_days) as lead_time";
        $sql2 .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
        $sql2 .= " WHERE delivery_time_days > 0";
        $sql2 .= " GROUP BY fk_product";

        $resql2 = $this->db->query($sql2);
        $leadTimes = array();
        if ($resql2) {
            while ($obj = $this->db->fetch_object($resql2)) {
                $leadTimes[(int) $obj->fk_product] = (int) $obj->lead_time;
            }
            $this->db->free($resql2);
        }

        // Step 3: Get current stock for products with consumption
        if (empty($consumption)) {
            return array();
        }

        $productIds = implode(',', array_keys($consumption));
        $sql3 = "SELECT p.rowid as product_id, p.ref, p.label, p.stock as current_stock";
        $sql3 .= " FROM ".MAIN_DB_PREFIX."product p";
        $sql3 .= " WHERE p.rowid IN (".$productIds.")";
        $sql3 .= " AND p.entity IN (".getEntity('product').")";
        $sql3 .= " AND p.stock > 0";

        $resql3 = $this->db->query($sql3);
        if (!$resql3) {
            $this->error = $this->db->lasterror();
            return array();
        }

        $results = array();
        while ($obj = $this->db->fetch_object($resql3)) {
            $pid = (int) $obj->product_id;
            $avgDaily = isset($consumption[$pid]) ? $consumption[$pid] : 0;
            $leadTime = isset($leadTimes[$pid]) ? $leadTimes[$pid] : $defaultLeadTime;
            $currentStock = (float) $obj->current_stock;

            $denominator = $avgDaily * $leadTime;
            $riskScore = ($denominator > 0) ? $currentStock / $denominator : 999;

            $results[] = array(
                'product_id'            => $pid,
                'ref'                   => $obj->ref,
                'label'                 => $obj->label,
                'current_stock'         => $currentStock,
                'avg_daily_consumption' => round($avgDaily, 3),
                'lead_time_days'        => $leadTime,
                'risk_score'            => round($riskScore, 2),
                'at_risk'               => ($riskScore < $riskThreshold),
                'formula_display'       => round($currentStock, 1).' / ('.round($avgDaily, 3).' x '.$leadTime.') = '.round($riskScore, 2),
            );
        }
        $this->db->free($resql3);

        // Sort by risk score ascending (most at risk first)
        usort($results, function ($a, $b) {
            return $a['risk_score'] <=> $b['risk_score'];
        });

        return $results;
    }

    /**
     * Dashboard KPI summary.
     *
     * @return array Aggregated counts and values for KPI cards
     */
    public function getDashboardKPIs()
    {
        $stockoutData = $this->getStockoutRisk();
        $deadData = $this->getDeadStock();

        $stockoutCount = 0;
        foreach ($stockoutData as $row) {
            if ($row['at_risk']) {
                $stockoutCount++;
            }
        }

        $deadCount = count($deadData);
        $deadValue = 0;
        foreach ($deadData as $row) {
            $deadValue += $row['stock_value'];
        }

        // Overstock: products where stock > desiredstock (and desiredstock > 0)
        $sql = "SELECT COUNT(*) as cnt,";
        $sql .= " SUM((p.stock - p.desiredstock) * COALESCE(p.cost_price, 0)) as overstock_value";
        $sql .= " FROM ".MAIN_DB_PREFIX."product p";
        $sql .= " WHERE p.desiredstock > 0 AND p.stock > p.desiredstock";
        $sql .= " AND p.fk_product_type = 0";
        $sql .= " AND p.entity IN (".getEntity('product').")";

        $overstockValue = 0;
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $overstockValue = $obj ? (float) $obj->overstock_value : 0;
            $this->db->free($resql);
        }

        return array(
            'stockout_risk_count' => $stockoutCount,
            'overstock_value'     => round($overstockValue, 2),
            'dead_stock_count'    => $deadCount,
            'dead_stock_value'    => round($deadValue, 2),
        );
    }
}
