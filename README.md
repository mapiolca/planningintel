# Stock Intelligence

Analyse intelligente des stocks, prévision de la demande et planification des réapprovisionnements pour l’ERP Dolibarr — avec des formules transparentes et explicables pour chaque calcul.

## Fonctionnalités

Intelligence des stocks

### Classification ABC selon la contribution au chiffre d’affaires
- Classification XYZ selon la variabilité de la demande
- Analyse matricielle combinée ABC-XYZ
- Détection des stocks dormants et des articles à faible rotation
- Analyse de l’ancienneté du stock par entrepôt
- Score de risque de rupture de stock

### Prévision de la demande
- Moyenne mobile simple (SMA)
- Moyenne mobile pondérée (WMA)
- Calcul de l’indice saisonnier
- Précision des prévisions (MAPE)

### Planification des réapprovisionnements
- Calcul du point de commande (ROP)
- Stock de sécurité avec niveau de service configurable
- Quantité économique de commande (EOQ)
- Explosion de nomenclature (BOM) avec besoins matières récursifs

### Tableau de bord & export
- Cartes KPI avec les indicateurs clés de santé des stocks
- Graphiques des tendances de demande et de la répartition ABC
- Export CSV pour tous les types d’analyse

## Prérequis
- Dolibarr 14.0+
- PHP 7.0+
- MySQL 5.7+ ou MariaDB 10.3+
- Modules Produits et Stock activés

## Installation

- Télécharger le fichier ZIP du module
- Dans Dolibarr : Accueil > Configuration > Modules/Applications > Déployer un module externe
- Importer le fichier ZIP
- Activer « Stock Intelligence » dans la liste des modules

## Configuration

- Après activation du module, aller sur la page de configuration pour paramétrer :
- Source des données de demande (commandes ou factures)-
- Période d’analyse
- Seuils de classification ABC/XYZ
- Paramètres de détection des stocks dormants / faible rotation
- Réglages de prévision (périodes SMA/WMA)
- Paramètres de réapprovisionnement (niveau de service, coût de commande, coût de possession, délai d’approvisionnement)
- Seuils d’alerte

## Licence

Ce module est distribué sous licence GNU General Public License v3.0 ou ultérieure. Voir le fichier LICENSE pour le texte complet.

## Auteur

SiliconBlaze / Pierre Ardoin

-------------------------------------------------------

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

SiliconBlaze / Pierre Ardoin



