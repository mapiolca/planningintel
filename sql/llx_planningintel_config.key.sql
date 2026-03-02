-- Planning Intel - Configuration table indexes
-- Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>

ALTER TABLE llx_planningintel_config ADD UNIQUE INDEX uk_planningintel_config_key (entity, config_key);
