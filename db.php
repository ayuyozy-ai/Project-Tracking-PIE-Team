<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function ensureStorageDirs(): void
{
    foreach ([STORAGE_DIR, FILES_DIR, BACKUP_DIR] as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    $pdo = new PDO(
        $dsn,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $roleColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    $roleType = strtolower((string)($roleColumn['Type'] ?? ''));
    if ($roleColumn && (!str_contains($roleType, "'viewer'") || !str_contains($roleType, "'contributor'"))) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin', 'user', 'viewer', 'contributor') NOT NULL DEFAULT 'user'");
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        login_key CHAR(64) NOT NULL,
        failed_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (login_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    return $pdo;
}

function uuid4(): string
{
    return sprintf(
        '%s-%s-%s-%s-%s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6))
    );
}

function currentUser(): ?array
{
    $token = $_COOKIE[SESSION_COOKIE] ?? null;
    if (!is_string($token) || trim($token) === '') {
        return null;
    }

    $hash = hash_hmac('sha256', $token, SESSION_SECRET);
    $stmt = db()->prepare(
        'SELECT u.* FROM sessions s INNER JOIN users u ON u.id = s.user_id WHERE s.token_hash = :hash AND s.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([':hash' => $hash]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function requireAuth(): array
{
    $user = currentUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Belum masuk'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    return $user;
}

function requireAdmin(array $user): void
{
    if (($user['role'] ?? 'user') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Hanya admin'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function jsonResponse(int $status, mixed $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function setSessionCookie(string $token): void
{
    setcookie(
        SESSION_COOKIE,
        $token,
        [
            'expires' => time() + SESSION_LIFETIME,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

function clearSessionCookie(): void
{
    setcookie(
        SESSION_COOKIE,
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}

function userPublicArray(array $user): array
{
    return [
        'id' => $user['id'],
        'name' => $user['name'],
        'role' => $user['role'],
        'created_at' => $user['created_at'],
    ];
}

function normalizeProjectPayload(array $data): array
{
    $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
    $projectFields = [
        'id', 'code', 'name', 'category', 'status', 'priority', 'pic', 'area',
        'start', 'start_date', 'end', 'end_date', 'progress', 'desc', 'description',
        'vendor', 'budget', 'actual', 'po', 'updatedAt', 'updated_at', 'updatedBy',
        'updated_by', 'metadata',
    ];
    foreach ($data as $key => $value) {
        if (!in_array($key, $projectFields, true)) {
            $metadata[$key] = $value;
        }
    }

    return [
        'id' => (string)($data['id'] ?? uuid4()),
        'code' => (string)($data['code'] ?? ''),
        'name' => (string)($data['name'] ?? ''),
        'category' => (string)($data['category'] ?? 'General'),
        'status' => (string)($data['status'] ?? 'On Progress'),
        'priority' => (string)($data['priority'] ?? 'Medium'),
        'pic' => (string)($data['pic'] ?? ''),
        'area' => (string)($data['area'] ?? ''),
        'start_date' => (string)($data['start_date'] ?? $data['start'] ?? ''),
        'end_date' => (string)($data['end_date'] ?? $data['end'] ?? ''),
        'progress' => (int)($data['progress'] ?? 0),
        'description' => (string)($data['description'] ?? $data['desc'] ?? ''),
        'vendor' => (string)($data['vendor'] ?? ''),
        'budget' => (float)($data['budget'] ?? 0),
        'actual' => (float)($data['actual'] ?? 0),
        'po' => (string)($data['po'] ?? ''),
        'metadata' => $metadata,
        'updated_by' => (string)($data['updated_by'] ?? $data['updatedBy'] ?? ''),
    ];
}

function projectToOutput(array $row): array
{
    $metadata = json_decode((string)($row['metadata'] ?? '{}'), true);
    if (!is_array($metadata)) {
        $metadata = [];
    }

    $updatedAt = isset($metadata['updatedAt'])
        ? (int)$metadata['updatedAt']
        : ($row['updated_at'] ? strtotime($row['updated_at']) * 1000 : 0);
    unset($metadata['metadata']);

    $project = [
        'id' => $row['id'],
        'code' => $row['code'],
        'name' => $row['name'],
        'category' => $row['category'],
        'status' => $row['status'],
        'priority' => $row['priority'],
        'pic' => $row['pic'],
        'area' => $row['area'],
        'start' => $row['start_date'],
        'end' => $row['end_date'],
        'progress' => (int)$row['progress'],
        'desc' => $row['description'],
        'vendor' => $row['vendor'],
        'budget' => (float)$row['budget'],
        'actual' => (float)$row['actual'],
        'po' => $row['po'],
        'updatedAt' => $updatedAt,
        'updatedBy' => (string)($metadata['updatedBy'] ?? $row['updated_by']),
    ];

    return array_merge($metadata, $project);
}

ensureStorageDirs();
