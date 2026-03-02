# Stock Intelligence

Smart inventory analysis, demand forecasting, and reorder planning for Dolibarr ERP — with transparent, explainable formulas for every calculation.

## Features

### Inventory Intelligence
- ABC classification by revenue contribution
- XYZ classification by demand variability
- Combined ABC-XYZ matrix analysis
- Dead stock and slow-moving stock detection
- Stock aging analysis by warehouse
- Stockout risk scoring

### Demand Forecasting
- Simple Moving Average (SMA)
- Weighted Moving Average (WMA)
- Seasonal Index calculation
- Forecast accuracy (MAPE)

### Reorder Planning
- Reorder Point (ROP) calculation
- Safety Stock with configurable service level
- Economic Order Quantity (EOQ)
- BOM explosion with recursive material requirements

### Dashboard & Export
- KPI cards with key stock health metrics
- Charts for demand trends and ABC distribution
- CSV export for all analysis types

## Requirements

- Dolibarr 14.0+
- PHP 7.0+
- MySQL 5.7+ or MariaDB 10.3+
- Products and Stock modules enabled

## Installation

1. Download the module zip file
2. In Dolibarr, go to **Home > Setup > Modules/Applications > Deploy external module**
3. Upload the zip file
4. Enable "Stock Intelligence" in the module list

## Configuration

After enabling the module, navigate to the setup page to configure:
- Demand data source (orders or invoices)
- Analysis period
- ABC/XYZ classification thresholds
- Dead/slow stock detection parameters
- Forecast settings (SMA/WMA periods)
- Reorder parameters (service level, ordering cost, holding cost, lead time)
- Alert thresholds

## License

This module is licensed under the GNU General Public License v3.0 or later. See [LICENSE](LICENSE) for the full text.

## Author

SiliconBlaze
