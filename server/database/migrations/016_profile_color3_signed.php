<?php

declare(strict_types=1);

/*
 * Geometry Dash 2.2 sends color3=-1 when no custom glow color is selected.
 * The previous UNSIGNED column rejected that valid protocol value.
 */

return [
    <<<'SQL'
ALTER TABLE profiles
    MODIFY COLUMN color3 SMALLINT SIGNED NOT NULL DEFAULT -1
SQL,
];
