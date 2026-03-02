-- Planning Intel - Default configuration values
-- Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>

INSERT INTO llx_planningintel_config (entity, config_key, config_value, config_type, description) VALUES
(1, 'DATA_SOURCE',            'orders', 'string', 'Demand data source: orders or invoices'),
(1, 'SMA_MONTHS',             '3',      'int',    'Simple Moving Average period in months'),
(1, 'WMA_MONTHS',             '3',      'int',    'Weighted Moving Average period in months'),
(1, 'DEAD_STOCK_DAYS',        '90',     'int',    'Days without movement to flag as dead stock'),
(1, 'SLOW_STOCK_THRESHOLD',   '5',      'int',    'Minimum movements per period to avoid slow flag'),
(1, 'SLOW_STOCK_DAYS',        '90',     'int',    'Period in days for slow stock measurement'),
(1, 'ABC_A_PERCENT',          '80',     'int',    'ABC class A cumulative revenue percent'),
(1, 'ABC_B_PERCENT',          '95',     'int',    'ABC class A+B cumulative revenue percent'),
(1, 'XYZ_X_THRESHOLD',        '0.5',    'float',  'CV threshold for X class (stable demand)'),
(1, 'XYZ_Y_THRESHOLD',        '1.0',    'float',  'CV threshold for Y class (variable demand)'),
(1, 'SERVICE_LEVEL',          '95',     'int',    'Service level percent for safety stock Z-score'),
(1, 'DEFAULT_ORDER_COST',     '50',     'float',  'Default ordering cost per order for EOQ'),
(1, 'HOLDING_COST_PERCENT',   '25',     'float',  'Annual holding cost as percent of unit cost'),
(1, 'DEFAULT_LEAD_TIME',      '14',     'int',    'Default lead time in days if no supplier data'),
(1, 'STOCKOUT_RISK_THRESHOLD','1.0',    'float',  'Risk score below this value flags stockout risk'),
(1, 'ANALYSIS_MONTHS',        '12',     'int',    'Months of historical data to analyze');
