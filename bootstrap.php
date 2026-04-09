<?php

declare(strict_types=1);

session_start();

const SUPERX_DB = __DIR__ . '/superx.sqlite';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . SUPERX_DB);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

function initDb(): void
{
    $pdo = db();

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK (role IN ("admin", "merchant", "courier", "customer")),
            active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS shipments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tracking_no TEXT UNIQUE NOT NULL,
            merchant_id INTEGER NOT NULL,
            courier_id INTEGER,
            customer_name TEXT NOT NULL,
            customer_phone TEXT NOT NULL,
            customer_address TEXT NOT NULL,
            city TEXT NOT NULL,
            cod_amount REAL NOT NULL,
            status TEXT NOT NULL CHECK (status IN ("new", "in_transit", "delivered", "failed", "delayed", "returned")),
            notes TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (merchant_id) REFERENCES users(id),
            FOREIGN KEY (courier_id) REFERENCES users(id)
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS shipment_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            shipment_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            comment TEXT,
            actor_id INTEGER,
            created_at TEXT NOT NULL,
            FOREIGN KEY (shipment_id) REFERENCES shipments(id),
            FOREIGN KEY (actor_id) REFERENCES users(id)
        )'
    );

    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($userCount === 0) {
        $seedUsers = [
            ['SuperX Admin', 'admin@superx.local', 'admin123', 'admin'],
            ['متجر السريع', 'merchant@superx.local', 'merchant123', 'merchant'],
            ['مندوب رئيسي', 'courier@superx.local', 'courier123', 'courier'],
            ['عميل تجريبي', 'customer@superx.local', 'customer123', 'customer'],
        ];

        $stmt = $pdo->prepare('INSERT INTO users(name, email, password_hash, role, created_at) VALUES(?,?,?,?,?)');
        $now = date('Y-m-d H:i:s');
        foreach ($seedUsers as $row) {
            $stmt->execute([$row[0], $row[1], password_hash($row[2], PASSWORD_DEFAULT), $row[3], $now]);
        }

        $merchantId = (int) $pdo->query("SELECT id FROM users WHERE role='merchant' LIMIT 1")->fetchColumn();
        $courierId = (int) $pdo->query("SELECT id FROM users WHERE role='courier' LIMIT 1")->fetchColumn();

        $shipmentStmt = $pdo->prepare('INSERT INTO shipments(tracking_no, merchant_id, courier_id, customer_name, customer_phone, customer_address, city, cod_amount, status, notes, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $shipmentStmt->execute(['SX1001', $merchantId, $courierId, 'أحمد علي', '01000000001', 'مدينة نصر', 'القاهرة', 350, 'in_transit', 'تواصل قبل التسليم', $now, $now]);
        $shipmentStmt->execute(['SX1002', $merchantId, $courierId, 'منى السيد', '01000000002', 'سموحة', 'الإسكندرية', 420, 'new', null, $now, $now]);
    }
}

function currentUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT id, name, email, role, active FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['active'] !== 1) {
        logout();
        return null;
    }

    return $user;
}

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        header('Location: ?page=login');
        exit;
    }

    return $user;
}

function requireRole(array $roles): array
{
    $user = requireLogin();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('ليس لديك صلاحية للوصول إلى هذه الصفحة.');
    }

    return $user;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function statusLabel(string $status): string
{
    return match ($status) {
        'new' => 'جديد',
        'in_transit' => 'قيد التوصيل',
        'delivered' => 'تم التسليم',
        'failed' => 'فشل التسليم',
        'delayed' => 'مؤجل',
        'returned' => 'مرتجع',
        default => $status,
    };
}

function generateTrackingNo(): string
{
    return 'SX' . random_int(10000, 99999);
}

function pushWhatsappMessage(string $phone, string $message): array
{
    $apiUrl = getenv('SUPERX_WHATSAPP_API_URL') ?: '';
    $token = getenv('SUPERX_WHATSAPP_TOKEN') ?: '';

    if ($apiUrl === '' || $token === '') {
        return ['ok' => false, 'reason' => 'Whatsapp API is not configured'];
    }

    $payload = json_encode(['phone' => $phone, 'message' => $message], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'reason' => $error ?: 'Unknown cURL error'];
    }

    return ['ok' => $statusCode >= 200 && $statusCode < 300, 'status_code' => $statusCode, 'response' => $response];
}

initDb();
