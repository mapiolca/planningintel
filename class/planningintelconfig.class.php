<?php
/* Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * \file    class/planningintelconfig.class.php
 * \brief   Configuration manager for Planning Intel module
 *
 * Reads and writes the llx_planningintel_config table.
 * This is the ONLY table the module writes to.
 */
class PlanningIntelConfig
{
    /** @var DoliDB */
    public $db;

    /** @var int */
    public $entity;

    /** @var string */
    public $error = '';

    /** @var array<string, mixed> Internal cache */
    private $cache = array();

    /** @var bool Whether cache has been loaded */
    private $cacheLoaded = false;

    /** @var int Cached result of getDataMonths() */
    private $dataMonthsCache = 0;

    /**
     * Z-score lookup table for service levels.
     * Maps service level percentage to Z-score for safety stock calculation.
     * @var array<int, float>
     */
    private static $zScoreTable = array(
        80 => 0.84,
        85 => 1.04,
        90 => 1.28,
        92 => 1.41,
        95 => 1.65,
        97 => 1.88,
        98 => 2.05,
        99 => 2.33,
    );

    /**
     * Constructor.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;
        $this->db = $db;
        $this->entity = (int) $conf->entity;
    }

    /**
     * Get a config value by key.
     *
     * @param  string $key     Config key (e.g. 'SMA_MONTHS')
     * @param  mixed  $default Default value if not found
     * @return mixed           Typed value (int, float, or string)
     */
    public function get($key, $default = null)
    {
        // Load all values into cache on first access
        if (!$this->cacheLoaded) {
            $this->getAll();
        }

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        return $default;
    }

    /**
     * Set a config value (insert or update).
     *
     * @param  string $key   Config key
     * @param  mixed  $value Config value
     * @param  string $type  Value type: 'string', 'int', 'float'
     * @return int           1 if OK, < 0 if error
     */
    public function set($key, $value, $type = 'string')
    {
        $key = $this->db->escape($key);
        $value = $this->db->escape((string) $value);
        $type = $this->db->escape($type);

        // Check if key exists
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."planningintel_config";
        $sql .= " WHERE config_key = '".$key."'";
        $sql .= " AND entity = ".$this->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        if ($this->db->num_rows($resql) > 0) {
            // Update existing
            $sql = "UPDATE ".MAIN_DB_PREFIX."planningintel_config";
            $sql .= " SET config_value = '".$value."'";
            $sql .= ", config_type = '".$type."'";
            $sql .= ", tms = '".$this->db->idate(dol_now())."'";
            $sql .= " WHERE config_key = '".$key."'";
            $sql .= " AND entity = ".$this->entity;
        } else {
            // Insert new
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."planningintel_config";
            $sql .= " (entity, config_key, config_value, config_type, date_creation)";
            $sql .= " VALUES (".$this->entity.", '".$key."', '".$value."', '".$type."'";
            $sql .= ", '".$this->db->idate(dol_now())."')";
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return -1;
        }

        // Update cache
        $this->cache[$key] = $this->castValue($value, $type);

        return 1;
    }

    /**
     * Get all config values as associative array.
     *
     * @return array<string, mixed> Key => typed value
     */
    public function getAll()
    {
        if ($this->cacheLoaded) {
            return $this->cache;
        }

        $this->cache = array();

        $sql = "SELECT config_key, config_value, config_type";
        $sql .= " FROM ".MAIN_DB_PREFIX."planningintel_config";
        $sql .= " WHERE entity = ".$this->entity;

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $this->cache[$obj->config_key] = $this->castValue($obj->config_value, $obj->config_type);
            }
            $this->db->free($resql);
            $this->cacheLoaded = true;
        } else {
            $this->error = $this->db->lasterror();
        }

        return $this->cache;
    }

    /**
     * Map service level percentage to Z-score.
     *
     * @param  int   $serviceLevel Service level percent (e.g. 90, 95, 99)
     * @return float Z-score (e.g. 1.28, 1.65, 2.33)
     */
    public function getZScore($serviceLevel)
    {
        $serviceLevel = (int) $serviceLevel;

        if (isset(self::$zScoreTable[$serviceLevel])) {
            return self::$zScoreTable[$serviceLevel];
        }

        // Find nearest match
        $closest = 95;
        $minDiff = PHP_INT_MAX;
        foreach (self::$zScoreTable as $level => $z) {
            $diff = abs($level - $serviceLevel);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $level;
            }
        }

        return self::$zScoreTable[$closest];
    }

    /**
     * Auto-detect the effective analysis period from actual data.
     *
     * Queries the oldest record date from the configured data source
     * and returns the number of months between that date and now.
     * Falls back to the ANALYSIS_MONTHS config value.
     *
     * @return int Number of months covering all available data
     */
    public function getDataMonths()
    {
        if ($this->dataMonthsCache > 0) {
            return $this->dataMonthsCache;
        }

        $dataSource = $this->get('DATA_SOURCE', 'orders');

        if ($dataSource == 'invoices') {
            $sql = "SELECT MIN(datef) as min_date FROM ".MAIN_DB_PREFIX."facture WHERE fk_statut IN (1, 2) AND datef IS NOT NULL";
        } else {
            $sql = "SELECT MIN(date_commande) as min_date FROM ".MAIN_DB_PREFIX."commande WHERE fk_statut IN (1, 2, 3) AND date_commande IS NOT NULL";
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj && $obj->min_date) {
                $months = (int) ceil((time() - strtotime($obj->min_date)) / (30.44 * 86400));
                $this->dataMonthsCache = max($months, 1);
                $this->db->free($resql);
                return $this->dataMonthsCache;
            }
            $this->db->free($resql);
        }

        // Fallback to config
        $this->dataMonthsCache = (int) $this->get('ANALYSIS_MONTHS', 12);
        return $this->dataMonthsCache;
    }

    /**
     * Clear the internal cache to force reload on next get.
     *
     * @return void
     */
    public function clearCache()
    {
        $this->cache = array();
        $this->cacheLoaded = false;
        $this->dataMonthsCache = 0;
    }

    /**
     * Cast a string value to its configured type.
     *
     * @param  string $value Raw string value
     * @param  string $type  Type: 'int', 'float', or 'string'
     * @return mixed         Typed value
     */
    private function castValue($value, $type)
    {
        switch ($type) {
            case 'int':
                return (int) $value;
            case 'float':
                return (float) $value;
            default:
                return (string) $value;
        }
    }
}
