<?php
declare(strict_types=1);

namespace MuchoCore\V71;

use PDO;
use Throwable;

final class AuthService
{
    private SchemaInspector $schema;

    public function __construct(private readonly PDO $db)
    {
        $this->schema = new SchemaInspector($db);
    }

    public function validateLevelSecret(): bool
    {
        return hash_equals('Wmfd2893gb7', Request::string('secret'));
    }

    public function authenticatedAccountId(): ?int
    {
        $accountId = Request::int('accountID');
        $gjp2 = Request::string('gjp2');

        if ($accountId <= 0 || $gjp2 === '') {
            return null;
        }

        if (
            !$this->schema->tableExists('accounts') ||
            !$this->schema->columnExists('accounts', 'account_id') ||
            !$this->schema->columnExists('accounts', 'gjp2_hash')
        ) {
            return null;
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT gjp2_hash, is_active, is_banned
                 FROM accounts
                 WHERE account_id = :id
                 LIMIT 1'
            );

            $stmt->execute([':id' => $accountId]);
            $row = $stmt->fetch();

            if (!$row || empty($row['gjp2_hash'])) {
                return null;
            }

            if (!$this->verifyGjp2($gjp2, (string)$row['gjp2_hash'])) {
                return null;
            }

            if ((int)$row['is_active'] !== 1) {
                return null;
            }

            if ((int)$row['is_banned'] === 1) {
                return null;
            }

            return $accountId;
        } catch (Throwable) {
            return null;
        }
    }

    private function verifyGjp2(string $plain, string $stored): bool
    {
        $info = password_get_info($stored);

        if (($info['algoName'] ?? 'unknown') !== 'unknown') {
            return password_verify($plain, $stored);
        }

        $sha256 = hash('sha256', $plain);

        return hash_equals($stored, $sha256);
    }
}
