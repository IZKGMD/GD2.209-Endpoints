<?php

declare(strict_types=1);

namespace MuchoCore\Account;

use MuchoCore\Branding\BrandingService;
use MuchoCore\Database\Database;
use MuchoCore\Security\RateLimiter;
use MuchoCore\Security\Turnstile;

final class RecoveryController
{
    private RecoveryService $service;
    private array $branding;

    public function __construct()
    {
        $db = (new Database())->connection();

        $this->branding = (new BrandingService($db))->get();

        $this->service = new RecoveryService(
            new RecoveryRepository($db),
            new RateLimiter()
        );
    }

    public function handle(): void
    {
        $this->securityHeaders();
        $this->session();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handlePost();
            return;
        }

        $token = $this->token();

        if ($token !== '') {
            $this->renderReset(
                $token,
                $this->service->tokenIsValid($token)
            );
            return;
        }

        $this->renderRequest();
    }

    private function handlePost(): void
    {
        if (!hash_equals(
            $this->csrfToken(),
            (string)($_POST['csrf'] ?? '')
        )) {
            $this->renderMessage(
                'Срок действия страницы истёк.',
                'Обновите страницу и попробуйте снова.'
            );
            return;
        }

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'request') {
            if (Turnstile::enabled()) {
                $token = (string)($_POST['cf-turnstile-response'] ?? '');
                $expectedHostname = parse_url(
                    (string)(getenv('MUCHO_ACCOUNT_URL') ?: ''),
                    PHP_URL_HOST
                );

                if (!Turnstile::verify(
                    $token,
                    'recovery',
                    is_string($expectedHostname) ? $expectedHostname : null
                )) {
                    $this->renderRequest('Пройдите проверку безопасности и попробуйте снова.');
                    return;
                }
            }

            $identity = (string)($_POST['identity'] ?? '');

            $this->service->requestReset(
                $identity,
                $this->clientIp(),
                $this->baseUrl()
            );

            $this->renderMessage(
                'Проверьте почту',
                'Если аккаунт существует и к нему привязана почта, мы отправили туда ссылку для восстановления.'
            );
            return;
        }

        if ($action === 'reset') {
            $token = (string)($_POST['token'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $confirmation = (string)($_POST['password_confirmation'] ?? '');

            if (!$this->service->resetPassword(
                $token,
                $password,
                $confirmation,
                $this->clientIp()
            )) {
                $this->renderReset(
                    $token,
                    false,
                    'Ссылка недействительна или срок её действия истёк.'
                );
                return;
            }

            $this->renderMessage(
                'Пароль изменён',
                'Новый пароль сохранён. Теперь можно вернуться в Geometry Dash и войти в аккаунт.'
            );
            return;
        }

        $this->renderMessage(
            'Некорректный запрос',
            'Вернитесь назад и откройте страницу восстановления заново.'
        );
    }

    private function renderRequest(
        string $error = ''
    ): void {
        $turnstile = '';

        if (Turnstile::enabled()) {
            $turnstile =
                '<div class="turnstile-box" style="margin:12px 0;min-height:66px">' .
                '<div class="cf-turnstile" data-sitekey="' .
                $this->e(Turnstile::siteKey()) .
                '" data-theme="auto" data-action="recovery"></div>' .
                '</div>';
        }

        $content = '
            <div class="eyebrow">ACCOUNT RECOVERY</div>
            <h1>Восстановление аккаунта</h1>
            <p class="lead">
                Введите username или email, привязанный к аккаунту.
            </p>
            ' . $this->flash($error, 'error') . '
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="' . $this->e($this->csrfToken()) . '">
                <input type="hidden" name="action" value="request">

                <label for="identity">Username или email</label>
                <input
                    id="identity"
                    name="identity"
                    type="text"
                    maxlength="254"
                    placeholder="Например: Player123"
                    autocomplete="username"
                    required
                >

                ' . $turnstile . '

                <button type="submit">Отправить ссылку</button>
            </form>

            <div class="hint">
                Ссылка для восстановления одноразовая и действует 30 минут.
            </div>
        ';

        $this->page($content);
    }

    private function renderReset(
        string $token,
        bool $valid,
        string $error = ''
    ): void {
        if (!$valid) {
            $content = '
                <div class="eyebrow">ACCOUNT RECOVERY</div>
                <h1>Ссылка недействительна</h1>
                <p class="lead">
                    Возможно, она уже использована или срок её действия истёк.
                </p>
                <a class="button secondary" href="/api/api/accounts/lostusername.php">
                    Запросить новую ссылку
                </a>
            ';

            $this->page($content);
            return;
        }

        $content = '
            <div class="eyebrow">ACCOUNT RECOVERY</div>
            <h1>Новый пароль</h1>
            <p class="lead">
                Придумайте новый пароль для своего аккаунта.
            </p>
            ' . $this->flash($error, 'error') . '
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf" value="' . $this->e($this->csrfToken()) . '">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="token" value="' . $this->e($token) . '">

                <label for="password">Новый пароль</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    minlength="8"
                    maxlength="256"
                    autocomplete="new-password"
                    required
                >

                <label for="password_confirmation">Повторите пароль</label>
                <input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    minlength="8"
                    maxlength="256"
                    autocomplete="new-password"
                    required
                >

                <button type="submit">Сохранить новый пароль</button>
            </form>

            <div class="hint">
                Минимальная длина пароля — 8 символов.
            </div>
        ';

        $this->page($content);
    }

    private function renderMessage(
        string $title,
        string $message
    ): void {
        $content = '
            <div class="eyebrow">MUCHOCORE</div>
            <h1>' . $this->e($title) . '</h1>
            <p class="lead">' . $this->e($message) . '</p>
            <a class="button secondary" href="/api/api/accounts/lostusername.php">
                Вернуться к восстановлению
            </a>
        ';

        $this->page($content);
    }

    private function page(string $content): void
    {


        $serverCredit = '';
        if (
            !empty($this->branding['server_by_name']) &&
            !empty($this->branding['social_url'])
        ) {
            $serverCredit =
                ' · Server by <a href="' .
                $this->e((string)$this->branding['social_url']) .
                '" target="_blank" rel="noopener noreferrer">' .
                $this->e((string)$this->branding['server_by_name']) .
                '</a>';
        }

        echo '<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" href="/assets/muchocore-favicon-v4.png" type="image/png" sizes="64x64">
<title>MuchoCore — восстановление аккаунта</title>
<link rel="stylesheet" href="/recovery/recovery.css">
<link rel="stylesheet" href="/muchocore-theme.css?v=3">
<link rel="stylesheet" href="/assets/product-ui.css?v=2">
<script src="/muchocore-theme.js?v=4" defer></script>
' . (Turnstile::enabled()
    ? '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
    : '') . '
</head>
<body class="mc-workspace">
<div class="aurora aurora-a"></div>
<div class="aurora aurora-b"></div>

<main class="shell">
    <section class="card">
        <div class="brand"><img class="mc-brand-mark" src="/assets/muchocore-icon.png?v=3" alt="" width="44" height="44" decoding="async"><span>MuchoCore</span></div>
        ' . $content . '
    </section>

    <footer>
        <span>Account Recovery' .
            $serverCredit .
            ' · Powered by MuchoCore 🛡️</span>
        <a href="https://github.com/IZKGMD/GMDmucho-core" target="_blank" rel="noopener noreferrer">GitHub</a>
    </footer>
</main>
</body>
</html>';
    }

    private function flash(
        string $text,
        string $type
    ): string {
        if ($text === '') {
            return '';
        }

        return '<div class="flash ' .
            $this->e($type) .
            '">' .
            $this->e($text) .
            '</div>';
    }

    private function token(): string
    {
        $token = (string)($_GET['token'] ?? '');

        return preg_match(
            '/^[a-f0-9]{64}$/i',
            $token
        ) === 1 ? $token : '';
    }

    private function csrfToken(): string
    {
        if (
            empty($_SESSION['recovery_csrf']) ||
            !is_string($_SESSION['recovery_csrf'])
        ) {
            $_SESSION['recovery_csrf'] =
                bin2hex(random_bytes(32));
        }

        return $_SESSION['recovery_csrf'];
    }

    private function session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https =
            (($_SERVER['HTTPS'] ?? '') === 'on') ||
            (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set(
            'session.cookie_secure',
            $https ? '1' : '0'
        );
        ini_set(
            'session.cookie_samesite',
            'Lax'
        );

        session_name('MUCHO_RECOVERY');
        session_start();
    }

    private function clientIp(): string
    {
        return \MuchoCore\Http\ClientIp::resolve($_SERVER);
    }

    private function baseUrl(): string
    {
        $configured = trim(
            (string)(
                $_ENV['MUCHO_PUBLIC_URL']
                ?? getenv('MUCHO_PUBLIC_URL')
                ?? ''
            )
        );

        if ($configured === '') {
            $configured = trim(
                (string)(
                    $_ENV['MUCHO_ACCOUNT_URL']
                    ?? getenv('MUCHO_ACCOUNT_URL')
                    ?? ''
                )
            );
        }

        /*
         * Never derive password-reset links from HTTP_HOST. The Host header is
         * client-controlled and could otherwise turn a reset request into a
         * token-leaking link on an attacker-controlled domain.
         */
        if (
            $configured !== '' &&
            preg_match(
                '~^https://[A-Za-z0-9.-]+(?::\\d+)?$~',
                $configured
            ) === 1
        ) {
            return rtrim($configured, '/');
        }

        return 'https://localhost';
    }

    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header(
            'Content-Security-Policy: '
            . "default-src 'self'; "
            . "style-src 'self'; "
            . "script-src 'self' https://challenges.cloudflare.com; "
            . "frame-src https://challenges.cloudflare.com; "
            . "connect-src 'self' https://challenges.cloudflare.com; "
            . "base-uri 'none'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'"
        );
    }

    private function e(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES |
            ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
