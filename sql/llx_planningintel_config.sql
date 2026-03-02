-- Planning Intel - Configuration table
-- Copyright (C) 2026 SiliconBlaze <https://siliconblaze.com>
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.

CREATE TABLE IF NOT EXISTS llx_planningintel_config (
    rowid           int(11)       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity          int(11)       NOT NULL DEFAULT 1,
    config_key      varchar(128)  NOT NULL,
    config_value    varchar(255)  DEFAULT NULL,
    config_type     varchar(32)   NOT NULL DEFAULT 'string',
    description     varchar(255)  DEFAULT NULL,
    date_creation   datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tms             timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
