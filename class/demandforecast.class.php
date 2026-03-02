<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/demandforecast.class.php
 * \brief   Demand Forecasting analysis (Pillar 2)
 *
 * Provides SMA, WMA, seasonal index, and forecast accuracy.
 * All queries are SELECT-only against core Dolibarr tables.
 */

dol_include_once('/planningintel/class/planningintelconfig.class.php');

class DemandForecast
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
     * Get monthly demand history for a product.
     *
     * @param  int    $productId  Product ID
     * @param  int    $months     Number of months (0 = use config)
     * @param  string $dataSource 'orders' or 'invoices' ('' = use config)
     * @return array  ['2025-01' => 150.0, '2025-02' => 200.0, ...]
     */
    public function getMonthlyDemand($productId, $months = 0, $dataSource = '')
    {
        if ($months <= 0) {
            $months = $this->config->getDataMonths();
        }
        if (empty($dataSource)) {
            $dataSource = $this->config->get('DATA_SOURCE', 'orders');
        }

        $productId = (int) $productId;

        if ($dataSource == 'invoices') {
            $sql = "SELECT DATE_FORMAT(f.datef, '%Y-%m') as month_key,";
            $sql .= " SUM(fd.qty) as monthly_qty";
            $sql .= " FROM ".MAIN_DB_PREFIX."facturedet fd";
            $sql .= " JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = fd.fk_facture";
            $sql .= " WHERE fd.fk_product = ".$productId;
            $sql .= " AND f.fk_statut IN (1, 2)";
            $sql .= " AND f.datef >= DATE_SUB(NOW(), INTERVAL ".$months." MONTH)";
            $sql .= " GROUP BY month_key ORDER BY month_key";
        } else {
            $sql = "SELECT DATE_FORMAT(c.date_commande, '%Y-%m') as month_key,";
            $sql .= " SUM(cd.qty) as monthly_qty";
            $sql .= " FROM ".MAIN_DB_PREFIX."commandedet cd";
            $sql .= " JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande";
            $sql .= " WHERE cd.fk_product = ".$productId;
            $sql .= " AND c.fk_statut IN (1, 2, 3)";
            $sql .= " AND c.date_commande >= DATE_SUB(NOW(), INTERVAL ".$months." MONTH)";
            $sql .= " GROUP BY month_key ORDER BY month_key";
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return array();
        }

        $rawData = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rawData[$obj->month_key] = (float) $obj->monthly_qty;
        }
        $this->db->free($resql);

        // Fill complete month range with zeros
        $result = array();
        for ($i = $months - 1; $i >= 0; $i--) {
            $key = date('Y-m', strtotime("-{$i} months"));
            $result[$key] = isset($rawData[$key]) ? $rawData[$key] : 0;
        }

        return $result;
    }

    /**
     * Simple Moving Average forecast.
     *
     * @param  int $productId Product ID
     * @param  int $periods   Number of months (0 = use config SMA_MONTHS)
     * @return array  {forecast, inputs, formula, periods}
     */
    public function getSMA($productId, $periods = 0)
    {
        if ($periods <= 0) {
            $periods = (int) $this->config->get('SMA_MONTHS', 3);
        }

        // Get enough history
        $demand = $this->getMonthlyDemand($productId, $periods + 1);
        $values = array_values($demand);
        $keys = array_keys($demand);

        // Take last N periods (exclude current/most recent for the forecast)
        $n = count($values);
        if ($n < $periods) {
            return array(
                'forecast' => 0,
                'inputs'   => $demand,
                'formula'  => 'Insufficient data',
                'periods'  => $periods,
            );
        }

        // Use the last $periods values (the most recent complete months)
        $slice = array_slice($values, max(0, $n - $periods), $periods);
        $sliceKeys = array_slice($keys, max(0, $n - $periods), $periods);
        $sum = array_sum($slice);
        $forecast = $sum / $periods;

        // Build formula string
        $parts = array();
        $inputs = array();
        foreach ($sliceKeys as $idx => $month) {
            $parts[] = round($slice[$idx], 1);
            $inputs[$month] = $slice[$idx];
        }
        $formula = 'SMA = ('.implode(' + ', $parts).') / '.$periods.' = '.round($forecast, 2);

        return array(
            'forecast' => round($forecast, 2),
            'inputs'   => $inputs,
            'formula'  => $formula,
            'periods'  => $periods,
        );
    }

    /**
     * Weighted Moving Average forecast.
     *
     * @param  int $productId Product ID
     * @param  int $periods   Number of months (0 = use config WMA_MONTHS)
     * @return array  {forecast, inputs, weights, formula}
     */
    public function getWMA($productId, $periods = 0)
    {
        if ($periods <= 0) {
            $periods = (int) $this->config->get('WMA_MONTHS', 3);
        }

        $demand = $this->getMonthlyDemand($productId, $periods + 1);
        $values = array_values($demand);
        $keys = array_keys($demand);

        $n = count($values);
        if ($n < $periods) {
            return array(
                'forecast' => 0,
                'inputs'   => $demand,
                'weights'  => array(),
                'formula'  => 'Insufficient data',
                'periods'  => $periods,
            );
        }

        $slice = array_slice($values, max(0, $n - $periods), $periods);
        $sliceKeys = array_slice($keys, max(0, $n - $periods), $periods);

        // Generate weights: most recent gets highest weight
        $weights = array();
        for ($i = 1; $i <= $periods; $i++) {
            $weights[] = $i;
        }
        $weightSum = array_sum($weights);

        $weightedSum = 0;
        $parts = array();
        $inputs = array();
        for ($i = 0; $i < $periods; $i++) {
            $weightedSum += $slice[$i] * $weights[$i];
            $parts[] = round($slice[$i], 1).'x'.$weights[$i];
            $inputs[$sliceKeys[$i]] = $slice[$i];
        }

        $forecast = $weightedSum / $weightSum;
        $formula = 'WMA = ('.implode(' + ', $parts).') / '.$weightSum.' = '.round($forecast, 2);

        return array(
            'forecast' => round($forecast, 2),
            'inputs'   => $inputs,
            'weights'  => $weights,
            'formula'  => $formula,
            'periods'  => $periods,
        );
    }

    /**
     * Seasonal Index for a product.
     *
     * @param  int $productId Product ID
     * @return array  [1 => 1.2, 2 => 0.8, ..., 12 => 1.1]
     */
    public function getSeasonalIndex($productId)
    {
        // Use 24 months of data for seasonal analysis
        $demand = $this->getMonthlyDemand($productId, 24);

        // Group by month number
        $monthTotals = array_fill(1, 12, 0);
        $monthCounts = array_fill(1, 12, 0);

        foreach ($demand as $monthKey => $qty) {
            $m = (int) date('n', strtotime($monthKey.'-01'));
            $monthTotals[$m] += $qty;
            $monthCounts[$m]++;
        }

        // Calculate monthly averages
        $monthAvgs = array();
        $totalAvg = 0;
        $validMonths = 0;
        for ($m = 1; $m <= 12; $m++) {
            if ($monthCounts[$m] > 0) {
                $monthAvgs[$m] = $monthTotals[$m] / $monthCounts[$m];
                $totalAvg += $monthAvgs[$m];
                $validMonths++;
            } else {
                $monthAvgs[$m] = 0;
            }
        }

        $overallAvg = ($validMonths > 0) ? $totalAvg / $validMonths : 0;

        // Calculate seasonal index
        $result = array();
        for ($m = 1; $m <= 12; $m++) {
            $result[$m] = ($overallAvg > 0) ? round($monthAvgs[$m] / $overallAvg, 3) : 0;
        }

        return $result;
    }

    /**
     * Forecast Accuracy (MAPE) for a product.
     *
     * @param  int   $productId Product ID
     * @return float MAPE percentage (0 to 100). Lower is better.
     */
    public function getForecastAccuracy($productId)
    {
        $periods = (int) $this->config->get('SMA_MONTHS', 3);
        // Need enough history: periods for SMA window + 6 months to test against
        $totalNeeded = $periods + 6;
        $demand = $this->getMonthlyDemand($productId, $totalNeeded);
        $values = array_values($demand);

        $n = count($values);
        if ($n < $periods + 1) {
            return 0;
        }

        $errors = array();
        // For each of the last 6 months (or fewer), compare SMA forecast vs actual
        $testStart = max($periods, $n - 6);
        for ($i = $testStart; $i < $n; $i++) {
            $actual = $values[$i];
            if ($actual <= 0) {
                continue;
            }

            // SMA from preceding periods
            $window = array_slice($values, $i - $periods, $periods);
            $forecast = array_sum($window) / $periods;

            $errors[] = abs($actual - $forecast) / $actual;
        }

        if (empty($errors)) {
            return 0;
        }

        $mape = (array_sum($errors) / count($errors)) * 100;
        return round($mape, 1);
    }

    /**
     * Bulk forecast for all products (for listing pages).
     *
     * @param  string $method  'sma' or 'wma'
     * @param  int    $periods Override period
     * @return array  All products with forecasted demand
     */
    public function getBulkForecast($method = 'sma', $periods = 0)
    {
        if ($periods <= 0) {
            $periods = (int) $this->config->get(strtoupper($method).'_MONTHS',
                $this->config->get('SMA_MONTHS', 3));
        }

        $dataSource = $this->config->get('DATA_SOURCE', 'orders');
        $months = max($periods + 1, $this->config->getDataMonths()); // cover all available data

        // Get all product monthly demands in one query
        if ($dataSource == 'invoices') {
            $sql = "SELECT fd.fk_product as product_id, p.ref, p.label,";
            $sql .= " DATE_FORMAT(f.datef, '%Y-%m') as month_key,";
            $sql .= " SUM(fd.qty) as monthly_qty";
            $sql .= " FROM ".MAIN_DB_PREFIX."facturedet fd";
            $sql .= " JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = fd.fk_facture";
            $sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = fd.fk_product";
            $sql .= " WHERE f.fk_statut IN (1, 2)";
            $sql .= " AND f.datef >= DATE_SUB(NOW(), INTERVAL ".$months." MONTH)";
            $sql .= " AND fd.fk_product > 0";
            $sql .= " AND p.entity IN (".getEntity('product').")";
            $sql .= " GROUP BY fd.fk_product, month_key";
            $sql .= " ORDER BY fd.fk_product, month_key";
        } else {
            $sql = "SELECT cd.fk_product as product_id, p.ref, p.label,";
            $sql .= " DATE_FORMAT(c.date_commande, '%Y-%m') as month_key,";
            $sql .= " SUM(cd.qty) as monthly_qty";
            $sql .= " FROM ".MAIN_DB_PREFIX."commandedet cd";
            $sql .= " JOIN ".MAIN_DB_PREFIX."commande c ON c.rowid = cd.fk_commande";
            $sql .= " JOIN ".MAIN_DB_PREFIX."product p ON p.rowid = cd.fk_product";
            $sql .= " WHERE c.fk_statut IN (1, 2, 3)";
            $sql .= " AND c.date_commande >= DATE_SUB(NOW(), INTERVAL ".$months." MONTH)";
            $sql .= " AND cd.fk_product > 0";
            $sql .= " AND p.entity IN (".getEntity('product').")";
            $sql .= " GROUP BY cd.fk_product, month_key";
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

        // Group by product
        $productData = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $pid = (int) $obj->product_id;
            if (!isset($productData[$pid])) {
                $productData[$pid] = array(
                    'ref'    => $obj->ref,
                    'label'  => $obj->label,
                    'months' => array(),
                );
            }
            $productData[$pid]['months'][$obj->month_key] = (float) $obj->monthly_qty;
        }
        $this->db->free($resql);

        $results = array();
        foreach ($productData as $pid => $data) {
            // Fill zero months
            $quantities = array();
            foreach ($monthRange as $m) {
                $quantities[] = isset($data['months'][$m]) ? $data['months'][$m] : 0;
            }

            $n = count($quantities);
            $lastMonthActual = ($n > 0) ? $quantities[$n - 1] : 0;

            // Calculate forecast from the available periods
            $forecastQty = 0;
            if ($n >= $periods) {
                $slice = array_slice($quantities, max(0, $n - $periods), $periods);

                if ($method == 'wma') {
                    $weights = array();
                    for ($i = 1; $i <= $periods; $i++) {
                        $weights[] = $i;
                    }
                    $weightSum = array_sum($weights);
                    $weightedSum = 0;
                    for ($i = 0; $i < $periods; $i++) {
                        $weightedSum += $slice[$i] * $weights[$i];
                    }
                    $forecastQty = $weightedSum / $weightSum;
                } else {
                    $forecastQty = array_sum($slice) / $periods;
                }
            }

            $results[] = array(
                'product_id'       => $pid,
                'ref'              => $data['ref'],
                'label'            => $data['label'],
                'last_month_actual'=> round($lastMonthActual, 2),
                'forecast'         => round($forecastQty, 2),
                'method'           => strtoupper($method),
            );
        }

        // Sort by forecast desc
        usort($results, function ($a, $b) {
            return $b['forecast'] <=> $a['forecast'];
        });

        return $results;
    }
}
