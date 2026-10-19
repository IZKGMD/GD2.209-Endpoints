<?php

declare(strict_types=1);

namespace MuchoCore\Plugin;

interface PluginInterface
{
    public function register(PluginContext $context): void;
}
