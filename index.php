<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$page = $_GET['page'] ?? 'dashboard';
$error = null;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($page === 'logout') {
    logout();
    header('Location: ?page=login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'login') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash']) && (int) $user['active'] === 1) {
        $_SESSION['user_id'] = (int) $user['id'];
        header('Location: ?page=dashboard');
        exit;
    }

    $error = 'بيانات الدخول غير صحيحة.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'create-shipment') {
    $user = requireRole(['merchant', 'admin']);

    $courierId = !empty($_POST['courier_id']) ? (int) $_POST['courier_id'] : null;
    $merchantId = $user['role'] === 'merchant' ? (int) $user['id'] : (int) ($_POST['merchant_id'] ?? 0);
    if ($merchantId <= 0) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'يجب اختيار تاجر صالح.'];
        header('Location: ?page=dashboard');
        exit;
    }

    $tracking = generateTrackingNo();
    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare('INSERT INTO shipments(tracking_no, merchant_id, courier_id, customer_name, customer_phone, customer_address, city, cod_amount, status, notes, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $tracking,
        $merchantId,
        $courierId,
        trim((string) $_POST['customer_name']),
        trim((string) $_POST['customer_phone']),
        trim((string) $_POST['customer_address']),
        trim((string) $_POST['city']),
        (float) $_POST['cod_amount'],
        'new',
        trim((string) ($_POST['notes'] ?? '')),
        $now,
        $now,
    ]);

    $shipmentId = (int) db()->lastInsertId();
    $event = db()->prepare('INSERT INTO shipment_events(shipment_id, status, comment, actor_id, created_at) VALUES(?,?,?,?,?)');
    $event->execute([$shipmentId, 'new', 'تم إنشاء الشحنة', (int) $user['id'], $now]);

    $_SESSION['flash'] = ['type' => 'success', 'text' => "تم إنشاء شحنة برقم $tracking"];
    header('Location: ?page=dashboard');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'update-status') {
    $user = requireRole(['courier', 'admin']);
    $shipmentId = (int) ($_POST['shipment_id'] ?? 0);
    $nextStatus = (string) ($_POST['status'] ?? '');
    $allowed = ['in_transit', 'delivered', 'failed', 'delayed', 'returned'];

    if (!in_array($nextStatus, $allowed, true) || $shipmentId <= 0) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'تحديث غير صالح'];
        header('Location: ?page=dashboard');
        exit;
    }

    $query = 'SELECT s.*, u.email AS merchant_email FROM shipments s LEFT JOIN users u ON u.id = s.merchant_id WHERE s.id = ? LIMIT 1';
    $stmt = db()->prepare($query);
    $stmt->execute([$shipmentId]);
    $shipment = $stmt->fetch();

    if (!$shipment) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'الشحنة غير موجودة'];
        header('Location: ?page=dashboard');
        exit;
    }

    if ($user['role'] === 'courier' && (int) $shipment['courier_id'] !== (int) $user['id']) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'لا يمكنك تعديل شحنة غير مسندة لك'];
        header('Location: ?page=dashboard');
        exit;
    }

    $now = date('Y-m-d H:i:s');
    $update = db()->prepare('UPDATE shipments SET status = ?, updated_at = ? WHERE id = ?');
    $update->execute([$nextStatus, $now, $shipmentId]);

    $event = db()->prepare('INSERT INTO shipment_events(shipment_id, status, comment, actor_id, created_at) VALUES(?,?,?,?,?)');
    $event->execute([$shipmentId, $nextStatus, trim((string) ($_POST['comment'] ?? '')), (int) $user['id'], $now]);

    $message = "شحنة {$shipment['tracking_no']} أصبحت " . statusLabel($nextStatus);
    $waResult = pushWhatsappMessage((string) $shipment['customer_phone'], $message);

    $_SESSION['flash'] = ['type' => 'success', 'text' => $waResult['ok'] ? 'تم تحديث الحالة وإرسال واتساب.' : 'تم تحديث الحالة (واتساب غير مُفعل أو فشل الإرسال).'];
    header('Location: ?page=dashboard');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'create-user') {
    requireRole(['admin']);

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? '');

    $allowed = ['admin', 'merchant', 'courier', 'customer'];
    if ($name === '' || $email === '' || $password === '' || !in_array($role, $allowed, true)) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'بيانات المستخدم غير مكتملة'];
        header('Location: ?page=dashboard');
        exit;
    }

    $stmt = db()->prepare('INSERT INTO users(name, email, password_hash, role, created_at) VALUES(?,?,?,?,?)');
    try {
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, date('Y-m-d H:i:s')]);
        $_SESSION['flash'] = ['type' => 'success', 'text' => 'تم إنشاء المستخدم بنجاح'];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'text' => 'تعذر إنشاء المستخدم (ربما البريد مستخدم)'];
    }

    header('Location: ?page=dashboard');
    exit;
}

function fetchUsersByRole(string $role): array
{
    $stmt = db()->prepare('SELECT id, name FROM users WHERE role = ? AND active = 1 ORDER BY id DESC');
    $stmt->execute([$role]);
    return $stmt->fetchAll();
}

function fetchShipmentsForUser(array $user): array
{
    $base = 'SELECT s.*, m.name AS merchant_name, c.name AS courier_name FROM shipments s
             LEFT JOIN users m ON m.id = s.merchant_id
             LEFT JOIN users c ON c.id = s.courier_id';

    if ($user['role'] === 'admin') {
        return db()->query($base . ' ORDER BY s.id DESC')->fetchAll();
    }

    $column = $user['role'] === 'merchant' ? 's.merchant_id' : 's.courier_id';
    $stmt = db()->prepare($base . " WHERE {$column} = ? ORDER BY s.id DESC");
    $stmt->execute([(int) $user['id']]);
    return $stmt->fetchAll();
}

function metricCards(array $shipments): array
{
    $cards = ['total' => count($shipments), 'delivered' => 0, 'failed' => 0, 'returned' => 0, 'cod' => 0.0];
    foreach ($shipments as $s) {
        if ($s['status'] === 'delivered') {
            $cards['delivered']++;
        }
        if ($s['status'] === 'failed') {
            $cards['failed']++;
        }
        if ($s['status'] === 'returned') {
            $cards['returned']++;
        }
        $cards['cod'] += (float) $s['cod_amount'];
    }

    return $cards;
}

if ($page === 'api-track') {
    header('Content-Type: application/json; charset=utf-8');
    $tracking = trim((string) ($_GET['tracking'] ?? ''));
    if ($tracking === '') {
        echo json_encode(['ok' => false, 'message' => 'tracking is required']);
        exit;
    }

    $stmt = db()->prepare('SELECT tracking_no, customer_name, city, status, updated_at FROM shipments WHERE tracking_no = ? LIMIT 1');
    $stmt->execute([$tracking]);
    $shipment = $stmt->fetch();

    if (!$shipment) {
        echo json_encode(['ok' => false, 'message' => 'not found']);
        exit;
    }

    echo json_encode(['ok' => true, 'data' => $shipment], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = currentUser();
if (!$user && $page !== 'login' && $page !== 'track') {
    header('Location: ?page=login');
    exit;
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SUPERX ERP</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <header class="hero">
    <div class="brand">
      <div class="logo">S</div>
      <div>
        <h1>SUPERX ERP</h1>
        <p>منصة شحن متكاملة: إدارة + تاجر + عميل + مندوب + API واتساب</p>
      </div>
    </div>
    <nav class="roles-nav">
      <?php if ($user): ?>
        <a class="nav-link" href="?page=dashboard">لوحة التحكم</a>
        <a class="nav-link" href="?page=track">تتبع الشحنة</a>
        <a class="nav-link" href="?page=logout">تسجيل الخروج</a>
      <?php else: ?>
        <a class="nav-link" href="?page=login">تسجيل الدخول</a>
        <a class="nav-link" href="?page=track">تتبع الشحنة</a>
      <?php endif; ?>
    </nav>
  </header>

  <main>
    <?php if ($flash): ?>
      <div class="card <?= $flash['type'] === 'success' ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash['text']) ?></div>
    <?php endif; ?>

    <?php if ($page === 'login'): ?>
      <section class="panel active">
        <h2>تسجيل الدخول</h2>
        <div class="card">
          <?php if ($error): ?><p class="err-text"><?= htmlspecialchars($error) ?></p><?php endif; ?>
          <form method="post" class="form-grid">
            <input type="email" name="email" required placeholder="البريد الإلكتروني">
            <input type="password" name="password" required placeholder="كلمة المرور">
            <button type="submit">دخول</button>
          </form>
          <p class="hint">حسابات تجريبية: admin@superx.local / admin123</p>
        </div>
      </section>

    <?php elseif ($page === 'track'): ?>
      <section class="panel active">
        <h2>واجهة العميل - تتبع الشحنة</h2>
        <div class="card tracking-card">
          <form class="track-form" onsubmit="return trackShipment(event)">
            <input id="trackingInput" required placeholder="SX1001">
            <button type="submit">تتبع</button>
          </form>
          <div id="trackingResult" class="tracking-result">أدخل رقم الشحنة لعرض الحالة.</div>
        </div>
      </section>

    <?php else: ?>
      <?php $dashboardUser = requireLogin(); ?>
      <?php $shipments = fetchShipmentsForUser($dashboardUser); ?>
      <?php $cards = metricCards($shipments); ?>

      <section class="panel active">
        <h2>لوحة التحكم - <?= htmlspecialchars($dashboardUser['name']) ?> (<?= htmlspecialchars($dashboardUser['role']) ?>)</h2>

        <div class="stats-grid">
          <article class="stat"><div class="label">إجمالي الشحنات</div><div class="value"><?= $cards['total'] ?></div></article>
          <article class="stat"><div class="label">تم التسليم</div><div class="value"><?= $cards['delivered'] ?></div></article>
          <article class="stat"><div class="label">فشل التسليم</div><div class="value"><?= $cards['failed'] ?></div></article>
          <article class="stat"><div class="label">مرتجع</div><div class="value"><?= $cards['returned'] ?></div></article>
          <article class="stat"><div class="label">إجمالي COD</div><div class="value"><?= number_format($cards['cod'], 2) ?></div></article>
        </div>

        <?php if (in_array($dashboardUser['role'], ['merchant', 'admin'], true)): ?>
          <div class="card">
            <h3>إنشاء شحنة جديدة</h3>
            <form method="post" action="?page=create-shipment" class="form-grid">
              <?php if ($dashboardUser['role'] === 'admin'): ?>
                <select name="merchant_id" required>
                  <option value="">اختر التاجر</option>
                  <?php foreach (fetchUsersByRole('merchant') as $merchant): ?>
                    <option value="<?= (int) $merchant['id'] ?>"><?= htmlspecialchars($merchant['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
              <select name="courier_id">
                <option value="">اختر المندوب (اختياري)</option>
                <?php foreach (fetchUsersByRole('courier') as $courier): ?>
                  <option value="<?= (int) $courier['id'] ?>"><?= htmlspecialchars($courier['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <input name="customer_name" required placeholder="اسم العميل">
              <input name="customer_phone" required placeholder="رقم العميل">
              <input name="city" required placeholder="المدينة">
              <input name="customer_address" required placeholder="العنوان">
              <input type="number" step="0.01" min="1" name="cod_amount" required placeholder="قيمة التحصيل">
              <input name="notes" placeholder="ملاحظات">
              <button type="submit">حفظ الشحنة</button>
            </form>
          </div>
        <?php endif; ?>

        <?php if ($dashboardUser['role'] === 'admin'): ?>
          <div class="card">
            <h3>إدارة المستخدمين والصلاحيات</h3>
            <form method="post" action="?page=create-user" class="form-grid">
              <input name="name" required placeholder="اسم المستخدم">
              <input type="email" name="email" required placeholder="email">
              <input type="password" name="password" required placeholder="password">
              <select name="role" required>
                <option value="admin">مدير</option>
                <option value="merchant">تاجر</option>
                <option value="courier">مندوب</option>
                <option value="customer">عميل</option>
              </select>
              <button type="submit">إضافة مستخدم</button>
            </form>
          </div>
        <?php endif; ?>

        <div class="card">
          <h3>الطلبات</h3>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>رقم التتبع</th>
                  <th>التاجر</th>
                  <th>المندوب</th>
                  <th>العميل</th>
                  <th>المدينة</th>
                  <th>COD</th>
                  <th>الحالة</th>
                  <th>آخر تحديث</th>
                  <?php if (in_array($dashboardUser['role'], ['courier', 'admin'], true)): ?><th>إجراء</th><?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($shipments as $s): ?>
                  <tr>
                    <td><?= htmlspecialchars($s['tracking_no']) ?></td>
                    <td><?= htmlspecialchars($s['merchant_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($s['courier_name'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($s['customer_name']) ?></td>
                    <td><?= htmlspecialchars($s['city']) ?></td>
                    <td><?= number_format((float) $s['cod_amount'], 2) ?></td>
                    <td><span class="badge badge-<?= htmlspecialchars($s['status']) ?>"><?= htmlspecialchars(statusLabel($s['status'])) ?></span></td>
                    <td><?= htmlspecialchars($s['updated_at']) ?></td>
                    <?php if (in_array($dashboardUser['role'], ['courier', 'admin'], true)): ?>
                      <td>
                        <form method="post" action="?page=update-status" class="inline-form">
                          <input type="hidden" name="shipment_id" value="<?= (int) $s['id'] ?>">
                          <select name="status" required>
                            <option value="in_transit">قيد التوصيل</option>
                            <option value="delivered">تم التسليم</option>
                            <option value="failed">فشل</option>
                            <option value="delayed">تأجيل</option>
                            <option value="returned">مرتجع</option>
                          </select>
                          <input name="comment" placeholder="ملاحظة">
                          <button type="submit">تحديث</button>
                        </form>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    <?php endif; ?>
  </main>

  <footer>
    <p>ERP SUPERX | API: <code>?page=api-track&tracking=SX1001</code></p>
  </footer>

  <script>
    async function trackShipment(event) {
      event.preventDefault();
      const tracking = document.getElementById('trackingInput').value.trim();
      const res = await fetch(`?page=api-track&tracking=${encodeURIComponent(tracking)}`);
      const payload = await res.json();
      const output = document.getElementById('trackingResult');

      if (!payload.ok) {
        output.innerHTML = 'لا توجد شحنة بهذا الرقم';
        return false;
      }

      const d = payload.data;
      output.innerHTML = `<strong>${d.tracking_no}</strong><br>العميل: ${d.customer_name}<br>المدينة: ${d.city}<br>الحالة: ${d.status}<br>آخر تحديث: ${d.updated_at}`;
      return false;
    }
  </script>
</body>
</html>
