<?php

declare(strict_types=1);

/*
 * Expand Map Pack level storage from VARCHAR to TEXT.
 * Map Packs intentionally support an arbitrary number of unique level IDs.
 *
 * Copyright (C) 2026 IZK
 */

return static function(PDO $db): void {
    $db->exec(
        "ALTER TABLE mucho_map_packs
         MODIFY COLUMN levels TEXT NOT NULL"
    );
};
