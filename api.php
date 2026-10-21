<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$routeParam = $_GET['route'] ?? null;
if (is_string($routeParam) && $routeParam !== '') {
    $requestPath = $routeParam;
} else {
    $matches = [];
    if (preg_match('#^/ProjectTracker/api\.php(?:/(.*))?$#', $requestPath, $matches) === 1) {
        $subPath = $matches[1] ?? '';
        $requestPath = $subPath === '' ? '/api/health' : '/api/' . $subPath;
    }
}
$method = $_SERVER['REQUEST_METHOD'];

function ensureLogin(): ?array
{
    return currentUser();
}

function isLocalRequest(): bool
{
    return in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
}

function safeName(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'file';
    return trim($name, '. _') ?: 'file';
}

function readJsonSetting(string $key): ?array
{
    $stmt = db()->prepare('SELECT value_json FROM settings WHERE key_name = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if (!is_string($value)) {
        return null;
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : null;
}

function writeJsonSetting(string $key, array $value): void
{
    db()->prepare('INSERT INTO settings (key_name, value_json) VALUES (:key, :value) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)')->execute([
        ':key' => $key,
        ':value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
}

function appSettings(): array
{
    $settings = readJsonSetting('tracker');
    if ($settings !== null) {
        if (in_array((string)($settings['appName'] ?? ''), ['Project Tracker', 'PI Project Tracker'], true)) {
            $settings['appName'] = APP_NAME;
            writeJsonSetting('tracker', $settings);
        }
        return $settings;
    }

    $app = readJsonSetting('app') ?? [];
    $categories = readJsonSetting('categories') ?? [];
    if (isset($categories['items']) && is_array($categories['items'])) {
        $app['categories'] = $categories['items'];
    }
    if (in_array((string)($app['appName'] ?? ''), ['', 'Project Tracker', 'PI Project Tracker'], true)) {
        $app['appName'] = APP_NAME;
    }
    return $app;
}

function syncState(): array
{
    return readJsonSetting('tracker_state') ?? ['rev' => 0, 'deleted' => []];
}

function projectRows(): array
{
    $rows = db()->query('SELECT * FROM projects ORDER BY updated_at DESC')->fetchAll();
    return array_map('projectToOutput', $rows);
}

function requireEditor(array $user): void
{
    if (($user['role'] ?? '') === 'viewer') {
        jsonResponse(403, ['error' => 'Akses Viewer hanya dapat melihat data']);
    }
}

function requireProjectEditor(array $user): void
{
    if (!in_array(($user['role'] ?? ''), ['admin', 'user'], true)) {
        jsonResponse(403, ['error' => 'Akses Kontributor hanya dapat mengubah Improvement']);
    }
}

if ($requestPath === '/api/health') {
    jsonResponse(200, ['ok' => true, 'app' => APP_NAME, 'version' => APP_VERSION]);
}

if ($requestPath === '/api/public' && $method === 'GET') {
    try {
        $count = (int)db()->query("SELECT COUNT(*) FROM users WHERE role <> 'viewer'")->fetchColumn();
        $users = db()->query("SELECT name FROM users WHERE role IN ('admin', 'user') ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
        $contributors = db()->query("SELECT name FROM users WHERE role = 'contributor' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
        $viewers = db()->query("SELECT name FROM users WHERE role = 'viewer' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
        $settings = appSettings();
        jsonResponse(200, [
            'needSetup' => $count === 0,
            'local' => isLocalRequest(),
            'users' => $users,
            'contributors' => $contributors,
            'viewers' => $viewers,
            'appName' => (string)($settings['appName'] ?? APP_NAME),
            'version' => APP_VERSION,
        ]);
    } catch (Throwable $e) {
        jsonResponse(500, ['error' => 'Database belum siap. Pastikan MySQL aktif di XAMPP dan database project_tracker sudah dibuat dengan schema.sql.']);
    }
}

if ($requestPath === '/api/setup' && $method === 'POST') {
    try {
        $count = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Throwable $e) {
        jsonResponse(500, ['error' => 'Database belum dibuat. Import schema.sql terlebih dahulu.']);
    }

    if ($count > 0) {
        jsonResponse(403, ['error' => 'Admin sudah dibuat']);
    }
    if (!isLocalRequest()) {
        jsonResponse(403, ['error' => 'Admin pertama hanya dapat dibuat dari PC server (localhost)']);
    }

    $data = jsonBody();
    $name = trim((string)($data['name'] ?? ''));
    $pin = (string)($data['pin'] ?? '');

    if (strlen($name) < 2 || strlen($pin) < 4) {
        jsonResponse(400, ['error' => 'Nama minimal 2 huruf dan PIN minimal 4 karakter']);
    }

    $userId = uuid4();
    db()->prepare('INSERT INTO users (id, name, password_hash, role, created_at) VALUES (:id, :name, :password_hash, :role, NOW())')->execute([
        ':id' => $userId,
        ':name' => $name,
        ':password_hash' => password_hash($pin, PASSWORD_DEFAULT),
        ':role' => 'admin',
    ]);

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO sessions (id, user_id, token_hash, expires_at, created_at) VALUES (:id, :user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())')->execute([
        ':id' => uuid4(),
        ':user_id' => $userId,
        ':token_hash' => hash_hmac('sha256', $token, SESSION_SECRET),
    ]);
    setSessionCookie($token);

    $row = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $row->execute([':id' => $userId]);
    $user = $row->fetch();

    jsonResponse(200, ['user' => userPublicArray($user), 'serverTime' => (int)round(microtime(true) * 1000)]);
}

if ($requestPath === '/api/login' && $method === 'POST') {
    $data = jsonBody();
    $name = trim((string)($data['name'] ?? ''));
    $pin = (string)($data['pin'] ?? '');
    $pdo = db();
    $loginKey = hash('sha256', strtolower($name) . "\0" . (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT IGNORE INTO login_attempts (login_key, failed_count, locked_until) VALUES (:key, 0, NULL)')->execute([':key' => $loginKey]);
        $attemptStmt = $pdo->prepare('SELECT failed_count, locked_until FROM login_attempts WHERE login_key = :key FOR UPDATE');
        $attemptStmt->execute([':key' => $loginKey]);
        $attempt = $attemptStmt->fetch();
        $waitStmt = $pdo->prepare('SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0) FROM login_attempts WHERE login_key = :key');
        $waitStmt->execute([':key' => $loginKey]);
        $wait = (int)$waitStmt->fetchColumn();
        if ($wait > 0) {
            $pdo->commit();
            jsonResponse(429, ['error' => 'Terlalu banyak percobaan PIN. Coba lagi dalam ' . (int)ceil($wait / 60) . ' menit.']);
        }

        $stmt = $pdo->prepare('SELECT * FROM users WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($pin, $user['password_hash'])) {
            $failedCount = (int)($attempt['failed_count'] ?? 0) + 1;
            if ($failedCount >= 5) {
                $pdo->prepare('UPDATE login_attempts SET failed_count = 0, locked_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE login_key = :key')->execute([':key' => $loginKey]);
                $pdo->commit();
                jsonResponse(429, ['error' => 'Terlalu banyak percobaan PIN. Akun ini dikunci selama 5 menit.']);
            }
            $pdo->prepare('UPDATE login_attempts SET failed_count = :count, locked_until = NULL WHERE login_key = :key')->execute([':count' => $failedCount, ':key' => $loginKey]);
            $pdo->commit();
            jsonResponse(401, ['error' => 'Nama atau PIN salah']);
        }

        $pdo->prepare('DELETE FROM login_attempts WHERE login_key = :key')->execute([':key' => $loginKey]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO sessions (id, user_id, token_hash, expires_at, created_at) VALUES (:id, :user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())')->execute([
        ':id' => uuid4(),
        ':user_id' => $user['id'],
        ':token_hash' => hash_hmac('sha256', $token, SESSION_SECRET),
    ]);
    setSessionCookie($token);

    jsonResponse(200, ['user' => userPublicArray($user), 'serverTime' => (int)round(microtime(true) * 1000)]);
}

$user = ensureLogin();
if (!$user && !in_array($requestPath, ['/api/health', '/api/public', '/api/setup', '/api/login'], true)) {
    jsonResponse(401, ['error' => 'Belum masuk']);
}

if ($requestPath === '/api/me' && $method === 'GET') {
    jsonResponse(200, ['user' => userPublicArray($user), 'serverTime' => (int)round(microtime(true) * 1000), 'version' => APP_VERSION]);
}

if ($requestPath === '/api/logout' && $method === 'POST') {
    $token = $_COOKIE[SESSION_COOKIE] ?? null;
    if (is_string($token) && $token !== '') {
        $hash = hash_hmac('sha256', $token, SESSION_SECRET);
        db()->prepare('DELETE FROM sessions WHERE token_hash = :hash')->execute([':hash' => $hash]);
    }
    clearSessionCookie();
    jsonResponse(200, ['ok' => true]);
}

if ($requestPath === '/api/users' && $method === 'GET') {
    requireAdmin($user);
    $rows = db()->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
    jsonResponse(200, ['users' => array_map('userPublicArray', $rows)]);
}

if ($requestPath === '/api/users' && $method === 'POST') {
    requireAdmin($user);
    $data = jsonBody();
    $name = trim((string)($data['name'] ?? ''));
    $pin = (string)($data['pin'] ?? '');
    $role = in_array((string)($data['role'] ?? 'user'), ['admin', 'user', 'viewer', 'contributor'], true) ? (string)($data['role'] ?? 'user') : 'user';

    if (strlen($name) < 2 || strlen($pin) < 4) {
        jsonResponse(400, ['error' => 'Nama minimal 2 huruf dan PIN minimal 4 karakter']);
    }

    $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE LOWER(name) = LOWER(:name) LIMIT 1');
    $exists->execute([':name' => $name]);
    if ((int)$exists->fetchColumn() > 0) {
        jsonResponse(400, ['error' => 'Nama sudah dipakai']);
    }

    $id = uuid4();
    db()->prepare('INSERT INTO users (id, name, password_hash, role, created_at) VALUES (:id, :name, :password_hash, :role, NOW())')->execute([
        ':id' => $id,
        ':name' => $name,
        ':password_hash' => password_hash($pin, PASSWORD_DEFAULT),
        ':role' => $role,
    ]);

    $users = db()->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
    jsonResponse(200, ['users' => array_map('userPublicArray', $users)]);
}

if (preg_match('#^/api/users/([^/]+)$#', $requestPath, $matches) && $method === 'PUT') {
    requireAdmin($user);
    $targetName = urldecode($matches[1]);
    $data = jsonBody();
    $stmt = db()->prepare('SELECT * FROM users WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $targetName]);
    $target = $stmt->fetch();
    if (!$target) {
        jsonResponse(404, ['error' => 'Pengguna tidak ditemukan']);
    }

    if (isset($data['pin']) && strlen((string)$data['pin']) >= 4) {
        db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute([
            ':hash' => password_hash((string)$data['pin'], PASSWORD_DEFAULT),
            ':id' => $target['id'],
        ]);
    }

    if (array_key_exists('role', $data)) {
        $newRole = in_array((string)$data['role'], ['admin', 'user', 'viewer', 'contributor'], true) ? (string)$data['role'] : 'user';
        if ($target['id'] === $user['id'] && $newRole !== 'admin') {
            jsonResponse(400, ['error' => 'Tidak bisa menurunkan peran diri sendiri']);
        }
        if ($target['role'] === 'admin' && $newRole !== 'admin') {
            $adminCount = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($adminCount <= 1) {
                jsonResponse(400, ['error' => 'Harus ada minimal satu admin']);
            }
        }
        if ($newRole !== $target['role']) {
            db()->prepare('UPDATE users SET role = :role WHERE id = :id')->execute([':role' => $newRole, ':id' => $target['id']]);
            db()->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $target['id']]);
        }
    }

    $users = db()->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
    jsonResponse(200, ['users' => array_map('userPublicArray', $users)]);
}

if (preg_match('#^/api/users/([^/]+)$#', $requestPath, $matches) && $method === 'DELETE') {
    requireAdmin($user);
    $targetName = urldecode($matches[1]);
    $stmt = db()->prepare('SELECT * FROM users WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $targetName]);
    $target = $stmt->fetch();
    if (!$target) {
        jsonResponse(404, ['error' => 'Pengguna tidak ditemukan']);
    }
    if ($target['id'] === $user['id']) {
        jsonResponse(400, ['error' => 'Tidak bisa menghapus akun sendiri']);
    }
    if ($target['role'] === 'admin') {
        $adminCount = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        if ($adminCount <= 1) {
            jsonResponse(400, ['error' => 'Harus ada minimal satu admin']);
        }
    }

    db()->prepare('DELETE FROM sessions WHERE user_id = :id')->execute([':id' => $target['id']]);
    db()->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $target['id']]);
    $users = db()->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
    jsonResponse(200, ['users' => array_map('userPublicArray', $users)]);
}

if ($requestPath === '/api/sync' && $method === 'POST') {
    $payload = jsonBody();
    $push = is_array($payload['push'] ?? null) ? $payload['push'] : [];
    if ($user['role'] === 'viewer' && (!empty($push['projects']) || !empty($push['improvements']) || !empty($push['deleted']))) {
        jsonResponse(403, ['error' => 'Akses Viewer hanya dapat melihat data']);
    }
    if ($user['role'] === 'contributor' && (!empty($push['projects']) || !empty($push['deleted']))) {
        jsonResponse(403, ['error' => 'Akses Kontributor hanya dapat mengubah Improvement']);
    }
    $state = syncState();
    $settings = appSettings();
    $storedSettings = readJsonSetting('tracker');
    $deleted = is_array($state['deleted'] ?? null) ? $state['deleted'] : [];
    $improvements = [];
    foreach (is_array($state['improvements'] ?? null) ? $state['improvements'] : [] as $item) {
        if (is_array($item) && isset($item['id'])) {
            $improvements[(string)$item['id']] = $item;
        }
    }
    $changed = false;
    $rejectedSettings = isset($push['settings']) && $user['role'] !== 'admin';
    $pdo = db();
    $pdo->beginTransaction();

    try {
        foreach (is_array($push['deleted'] ?? null) ? $push['deleted'] : [] as $projectId => $timestamp) {
            $timestamp = (int)$timestamp;
            if ((!is_string($projectId) && !is_int($projectId)) || $timestamp <= (int)($deleted[$projectId] ?? 0)) {
                continue;
            }
            $deleted[(string)$projectId] = $timestamp;
            $exists = $pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
            $exists->execute([':id' => (string)$projectId]);
            $row = $exists->fetch();
            if ($row && $timestamp >= (int)projectToOutput($row)['updatedAt']) {
                $pdo->prepare('DELETE FROM projects WHERE id = :id')->execute([':id' => (string)$projectId]);
            }
            $changed = true;
        }

        foreach (is_array($push['projects'] ?? null) ? $push['projects'] : [] as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }
            $project = normalizeProjectPayload($item);
            $updatedAt = (int)($item['updatedAt'] ?? 0);
            if ($updatedAt <= (int)($deleted[$project['id']] ?? 0)) {
                continue;
            }

            $exists = $pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
            $exists->execute([':id' => $project['id']]);
            $row = $exists->fetch();
            if ($row && $updatedAt < (int)projectToOutput($row)['updatedAt']) {
                continue;
            }

            $project['metadata']['updatedAt'] = $updatedAt > 0 ? $updatedAt : (int)round(microtime(true) * 1000);
            $project['metadata']['updatedBy'] = $user['name'];
            $project['updated_by'] = $user['name'];
            $values = [
                ':id' => $project['id'], ':code' => $project['code'], ':name' => $project['name'],
                ':category' => $project['category'], ':status' => $project['status'], ':priority' => $project['priority'],
                ':pic' => $project['pic'], ':area' => $project['area'],
                ':start_date' => $project['start_date'] !== '' ? $project['start_date'] : null,
                ':end_date' => $project['end_date'] !== '' ? $project['end_date'] : null,
                ':progress' => $project['progress'], ':description' => $project['description'],
                ':vendor' => $project['vendor'], ':budget' => $project['budget'], ':actual' => $project['actual'],
                ':po' => $project['po'], ':metadata' => json_encode($project['metadata'], JSON_UNESCAPED_SLASHES),
                ':updated_by' => $user['name'],
            ];
            if ($row) {
                $values[':id'] = $project['id'];
                $pdo->prepare('UPDATE projects SET code=:code, name=:name, category=:category, status=:status, priority=:priority, pic=:pic, area=:area, start_date=:start_date, end_date=:end_date, progress=:progress, description=:description, vendor=:vendor, budget=:budget, actual=:actual, po=:po, metadata=:metadata, updated_at=NOW(), updated_by=:updated_by WHERE id=:id')->execute($values);
            } else {
                $pdo->prepare('INSERT INTO projects (id, code, name, category, status, priority, pic, area, start_date, end_date, progress, description, vendor, budget, actual, po, metadata, updated_at, updated_by) VALUES (:id,:code,:name,:category,:status,:priority,:pic,:area,:start_date,:end_date,:progress,:description,:vendor,:budget,:actual,:po,:metadata,NOW(),:updated_by)')->execute($values);
            }
            $changed = true;
        }

        foreach (is_array($push['improvements'] ?? null) ? $push['improvements'] : [] as $item) {
            if (!is_array($item) || !isset($item['id']) || (!is_string($item['id']) && !is_int($item['id']))) {
                continue;
            }
            $id = (string)$item['id'];
            if ($id === '' || strlen($id) > 100) {
                continue;
            }
            $updatedAt = (int)($item['updatedAt'] ?? 0);
            $existing = $improvements[$id] ?? null;
            if ($user['role'] === 'contributor') {
                if (is_array($existing) && (string)($existing['submittedBy'] ?? '') !== (string)$user['name']) {
                    continue;
                }
                if (is_array($existing)) {
                    foreach (['submittedBy', 'status', 'decision', 'piOwner', 'piFeedback', 'piSuggestion', 'piAction', 'piReason', 'feedbackLog', 'projectId'] as $field) {
                        if (array_key_exists($field, $existing)) {
                            $item[$field] = $existing[$field];
                        } else {
                            unset($item[$field]);
                        }
                    }
                } else {
                    $item['submittedBy'] = $user['name'];
                    $item['status'] = 'Baru';
                    $item['decision'] = 'Pending';
                    $item['piOwner'] = $item['piFeedback'] = $item['piSuggestion'] = $item['piAction'] = $item['piReason'] = '';
                    $item['feedbackLog'] = [];
                    $item['projectId'] = '';
                }
            } elseif (is_array($existing)) {
                foreach (['title', 'department', 'submittedBy', 'submittedAt', 'type', 'issue', 'background', 'purpose', 'goals', 'savingMp', 'qualityMetric', 'attachments', 'links'] as $field) {
                    if (array_key_exists($field, $existing)) {
                        $item[$field] = $existing[$field];
                    } else {
                        unset($item[$field]);
                    }
                }
            }
            if (isset($improvements[$id]) && $updatedAt < (int)($improvements[$id]['updatedAt'] ?? 0)) {
                continue;
            }
            $item['updatedBy'] = $user['name'];
            $improvements[$id] = $item;
            $changed = true;
        }

        if (isset($push['settings']) && is_array($push['settings']) && $user['role'] === 'admin') {
            $incoming = $push['settings'];
            $incomingAt = (int)($incoming['settingsAt'] ?? 0);
            $currentAt = (int)($settings['settingsAt'] ?? 0);
            if (($storedSettings === null || $incomingAt > $currentAt) && $incoming !== $settings) {
                writeJsonSetting('tracker', $incoming);
                $settings = $incoming;
                $changed = true;
            }
        }

        if ($changed) {
            $state['rev'] = (int)($state['rev'] ?? 0) + 1;
            $state['deleted'] = $deleted;
            $state['improvements'] = array_values($improvements);
            writeJsonSetting('tracker_state', $state);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $revision = (int)($state['rev'] ?? 0);
    $settingsAt = (int)($settings['settingsAt'] ?? 0);
    $hasPush = !empty($push['projects']) || !empty($push['improvements']) || !empty($push['deleted']) || isset($push['settings']);
    if (!$changed && !$rejectedSettings && !$hasPush && (int)($payload['rev'] ?? -1) === $revision) {
        jsonResponse(200, ['rev' => $revision, 'unchanged' => true]);
    }

    $visibleImprovements = $user['role'] === 'contributor'
        ? array_filter($improvements, static fn(array $item): bool => (string)($item['submittedBy'] ?? '') === (string)$user['name'])
        : $improvements;
    $response = ['rev' => $revision, 'projects' => projectRows(), 'improvements' => array_values($visibleImprovements), 'deleted' => $deleted, 'settingsAt' => $settingsAt];
    if ($rejectedSettings || $settingsAt !== (int)($payload['sa'] ?? 0)) {
        $response['settings'] = $settings;
        if ($rejectedSettings) {
            $response['forceSettings'] = true;
        }
    }
    jsonResponse(200, $response);
}

if ($requestPath === '/api/events' && $method === 'GET') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    set_time_limit(0);
    echo "retry: 1000\n\n";
    $lastRevision = -1;
    while (!connection_aborted()) {
        $revision = (int)(syncState()['rev'] ?? 0);
        if ($revision !== $lastRevision) {
            $event = json_encode(['rev' => $revision, 'online' => [$user['name']]], JSON_UNESCAPED_SLASHES);
            echo "event: state\ndata: {$event}\n\n";
            $lastRevision = $revision;
        } else {
            echo ": ping\n\n";
        }
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
        sleep(2);
    }
    exit;
}

if ($requestPath === '/api/projects' && $method === 'GET') {
    $rows = db()->query('SELECT * FROM projects ORDER BY updated_at DESC')->fetchAll();
    jsonResponse(200, ['items' => array_map('projectToOutput', $rows)]);
}

if ($requestPath === '/api/projects' && $method === 'POST') {
    requireProjectEditor($user);
    $data = jsonBody();
    $project = normalizeProjectPayload($data['project'] ?? $data);
    $project['updated_by'] = $user['name'];

    $stmt = db()->prepare('INSERT INTO projects (id, code, name, category, status, priority, pic, area, start_date, end_date, progress, description, vendor, budget, actual, po, metadata, updated_at, updated_by) VALUES (:id, :code, :name, :category, :status, :priority, :pic, :area, :start_date, :end_date, :progress, :description, :vendor, :budget, :actual, :po, :metadata, NOW(), :updated_by)');
    $stmt->execute([
        ':id' => $project['id'], ':code' => $project['code'], ':name' => $project['name'], ':category' => $project['category'], ':status' => $project['status'], ':priority' => $project['priority'], ':pic' => $project['pic'], ':area' => $project['area'], ':start_date' => $project['start_date'] !== '' ? $project['start_date'] : null, ':end_date' => $project['end_date'] !== '' ? $project['end_date'] : null, ':progress' => $project['progress'], ':description' => $project['description'], ':vendor' => $project['vendor'], ':budget' => $project['budget'], ':actual' => $project['actual'], ':po' => $project['po'], ':metadata' => json_encode($project['metadata'], JSON_UNESCAPED_SLASHES), ':updated_by' => $project['updated_by'],
    ]);

    jsonResponse(201, ['ok' => true, 'project' => $project]);
}

if (preg_match('#^/api/projects/([^/]+)$#', $requestPath, $matches) && $method === 'PUT') {
    requireProjectEditor($user);
    $projectId = $matches[1];
    $data = jsonBody();
    $projectData = normalizeProjectPayload($data['project'] ?? $data);
    $projectData['updated_by'] = $user['name'];

    $stmt = db()->prepare('UPDATE projects SET code=:code, name=:name, category=:category, status=:status, priority=:priority, pic=:pic, area=:area, start_date=:start_date, end_date=:end_date, progress=:progress, description=:description, vendor=:vendor, budget=:budget, actual=:actual, po=:po, metadata=:metadata, updated_at=NOW(), updated_by=:updated_by WHERE id=:id');
    $stmt->execute([
        ':id' => $projectId, ':code' => $projectData['code'], ':name' => $projectData['name'], ':category' => $projectData['category'], ':status' => $projectData['status'], ':priority' => $projectData['priority'], ':pic' => $projectData['pic'], ':area' => $projectData['area'], ':start_date' => $projectData['start_date'] !== '' ? $projectData['start_date'] : null, ':end_date' => $projectData['end_date'] !== '' ? $projectData['end_date'] : null, ':progress' => $projectData['progress'], ':description' => $projectData['description'], ':vendor' => $projectData['vendor'], ':budget' => $projectData['budget'], ':actual' => $projectData['actual'], ':po' => $projectData['po'], ':metadata' => json_encode($projectData['metadata'], JSON_UNESCAPED_SLASHES), ':updated_by' => $projectData['updated_by'],
    ]);

    jsonResponse(200, ['ok' => true, 'project' => $projectData]);
}

if (preg_match('#^/api/projects/([^/]+)$#', $requestPath, $matches) && $method === 'DELETE') {
    requireProjectEditor($user);
    $projectId = $matches[1];
    db()->prepare('DELETE FROM projects WHERE id = :id')->execute([':id' => $projectId]);
    jsonResponse(200, ['ok' => true]);
}

if ($requestPath === '/api/pin' && $method === 'POST') {
    requireEditor($user);
    $data = jsonBody();
    $old = (string)($data['old'] ?? '');
    $pin = (string)($data['pin'] ?? '');

    if (!password_verify($old, $user['password_hash'])) {
        jsonResponse(400, ['error' => 'PIN lama salah']);
    }
    if (strlen($pin) < 4) {
        jsonResponse(400, ['error' => 'PIN baru minimal 4 karakter']);
    }

    db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute([
        ':hash' => password_hash($pin, PASSWORD_DEFAULT),
        ':id' => $user['id'],
    ]);
    jsonResponse(200, ['ok' => true]);
}

if ($requestPath === '/api/backup' && $method === 'POST') {
    requireAdmin($user);
    ensureStorageDirs();
    $suffix = date('Y-m-d_His');
    $projectBackup = 'tracker-' . $suffix . '.json';
    $userBackup = 'users-' . $suffix . '.json';
    file_put_contents(BACKUP_DIR . '/' . $projectBackup, json_encode([
        'settings' => appSettings(),
        'projects' => projectRows(),
        'state' => syncState(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $users = db()->query('SELECT id, name, password_hash, role, created_at FROM users ORDER BY name ASC')->fetchAll();
    file_put_contents(BACKUP_DIR . '/' . $userBackup, json_encode(['users' => $users], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    jsonResponse(200, ['files' => [$projectBackup, $userBackup]]);
}

if ($requestPath === '/api/files' && $method === 'GET') {
    $rows = db()->query('SELECT f.id, f.project_id, f.file_name, f.mime_type, f.size_bytes, f.stored_name, f.uploaded_at, p.code AS project_code, p.name AS project_name FROM project_files f LEFT JOIN projects p ON p.id = f.project_id ORDER BY f.uploaded_at DESC')->fetchAll();
    jsonResponse(200, ['files' => $rows]);
}

if ($requestPath === '/api/files' && $method === 'POST') {
    requireEditor($user);
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    $rawUpload = str_starts_with($contentType, 'application/octet-stream');
    $filename = rawurldecode((string)($_SERVER['HTTP_X_FILENAME'] ?? $_FILES['file']['name'] ?? 'file'));
    $prefix = rawurldecode((string)($_SERVER['HTTP_X_PREFIX'] ?? 'PRJ'));
    if ($user['role'] === 'contributor' && preg_match('/^IMP-[A-Za-z0-9]+$/', $prefix) !== 1) {
        jsonResponse(403, ['error' => 'Akses Kontributor hanya dapat mengunggah lampiran Improvement']);
    }
    $fileToken = base_convert((string)(int)round(microtime(true) * 1000), 10, 36) . '_' . bin2hex(random_bytes(3));
    $safeFile = safeName(substr($prefix, 0, 40) . '_' . $fileToken . '_' . substr($filename, -170));
    $projectId = trim((string)($_POST['project_id'] ?? ''));
    if (!$rawUpload && $projectId === '') {
        jsonResponse(400, ['error' => 'Pilih project untuk lampiran']);
    }
    if ($rawUpload && $projectId === '') {
        $projectCheck = db()->prepare('SELECT id FROM projects WHERE code = :code LIMIT 1');
        $projectCheck->execute([':code' => $prefix]);
        $projectId = (string)($projectCheck->fetchColumn() ?: '');
    }

    $target = FILES_DIR . '/' . $safeFile;
    if ($rawUpload) {
        $input = fopen('php://input', 'rb');
        $output = fopen($target, 'wb');
        $copied = $input && $output ? stream_copy_to_stream($input, $output) : false;
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        if ($copied === false) {
            jsonResponse(500, ['error' => 'Gagal menyimpan file']);
        }
    } else {
        $tmp = $_FILES['file']['tmp_name'] ?? null;
        if (!$tmp || !is_uploaded_file($tmp) || !move_uploaded_file($tmp, $target)) {
            jsonResponse(400, ['error' => 'File tidak valid']);
        }
    }

    if (!is_file($target)) {
        jsonResponse(500, ['error' => 'Gagal menyimpan file']);
    }

    $size = filesize($target);
    $mimeType = mime_content_type($target) ?: 'application/octet-stream';
    if ($projectId !== '') {
        $projectCheck = db()->prepare('SELECT id FROM projects WHERE id = :id LIMIT 1');
        $projectCheck->execute([':id' => $projectId]);
        if ($projectCheck->fetch()) {
            $insert = db()->prepare('INSERT INTO project_files (id, project_id, file_name, mime_type, size_bytes, stored_name, uploaded_at) VALUES (:id, :project_id, :file_name, :mime_type, :size_bytes, :stored_name, NOW())');
            $insert->execute([
                ':id' => uuid4(),
                ':project_id' => $projectId,
                ':file_name' => basename($filename),
                ':mime_type' => $mimeType,
                ':size_bytes' => $size,
                ':stored_name' => $safeFile,
            ]);
        } elseif (!$rawUpload) {
            unlink($target);
            jsonResponse(400, ['error' => 'Project tidak ditemukan']);
        }
    }

    jsonResponse(200, ['name' => $safeFile, 'size' => $size, 'mime_type' => $mimeType]);
}

if (preg_match('#^/api/files/(.+)$#', $requestPath, $matches) && $method === 'GET') {
    $name = rawurldecode($matches[1]);
    $safe = safeName($name);
    $target = FILES_DIR . '/' . $safe;
    if (!is_file($target)) {
        jsonResponse(404, ['error' => 'File tidak ditemukan']);
    }
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'm4v' => 'video/x-m4v', 'txt' => 'text/plain', 'json' => 'application/json'];
    header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($target));
    header('Content-Disposition: inline; filename="' . basename($safe) . '"');
    readfile($target);
    exit;
}

if (preg_match('#^/api/files/(.+)$#', $requestPath, $matches) && $method === 'DELETE') {
    requireEditor($user);
    requireAdmin($user);
    $storedName = safeName(rawurldecode($matches[1]));
    $stmt = db()->prepare('SELECT id FROM project_files WHERE stored_name = :stored_name LIMIT 1');
    $stmt->execute([':stored_name' => $storedName]);
    $file = $stmt->fetch();
    if (!$file) {
        jsonResponse(404, ['error' => 'File tidak ditemukan']);
    }

    db()->prepare('DELETE FROM project_files WHERE id = :id')->execute([':id' => $file['id']]);
    $target = FILES_DIR . '/' . $storedName;
    if (is_file($target)) {
        unlink($target);
    }
    jsonResponse(200, ['ok' => true]);
}

if ($requestPath === '/api/export' && $method === 'GET') {
    $rows = db()->query('SELECT * FROM projects ORDER BY updated_at DESC')->fetchAll();
    $blob = json_encode(array_map('projectToOutput', $rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="tracker-data-' . date('Y-m-d') . '.json"');
    echo $blob;
    exit;
}

jsonResponse(404, ['error' => 'Tidak ditemukan']);
