<?php
declare(strict_types=1);

namespace MuchoCore\Url;

final class UrlController
{
    /** @var array{account_url:string,custom_content_url:string} */
    private array $config;

    public function __construct()
    {
        /** @var array{account_url:string,custom_content_url:string} $config */
        $config = require dirname(__DIR__, 2) . '/config/mucho_v71.php';
        $this->config = $config;
    }

    public function accountUrl(): string
    {
        return rtrim((string)$this->config['account_url'], '/');
    }

    public function customContentUrl(): string
    {
        return rtrim((string)$this->config['custom_content_url'], '/');
    }
}
