<?php
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
$currentPage = basename($_SERVER['PHP_SELF']);

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    header('Location: my_orders.php');
    exit();
}

$order = null;
$error = '';

try {
    $stmt = $db->prepare("
        SELECT o.*, p.name AS package_name
        FROM orders o
        JOIN packages p ON p.id = o.package_id
        WHERE o.id = ? AND o.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch();
} catch (Exception $e) {
    $error = "Gagal memuat instruksi pembayaran: " . $e->getMessage();
}

if (!$order) {
    header('Location: my_orders.php');
    exit();
}

$instructionsHtml = generatePaymentInstructions($order, (string)$order['payment_method']);
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instruksi Pembayaran - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../include/css/dashboard.css">
    <style>
        :root {
            --surface: rgba(255, 255, 255, 0.84);
            --text-strong: #182235;
            --text-soft: #5f6f87;
            --brand-blue: #1554c8;
            --brand-blue-dark: #0f3d91;
            --brand-cyan: #0c8ddf;
            --shadow-soft: 0 26px 60px rgba(19, 33, 68, 0.12);
        }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255, 197, 111, 0.2), transparent 22%),
                radial-gradient(circle at top right, rgba(12, 141, 223, 0.12), transparent 24%),
                linear-gradient(135deg, #f6f0e5 0%, #edf4ff 48%, #f9fbff 100%);
            color: var(--text-strong);
        }
        .dashboard-layout { display:grid; grid-template-columns:280px 1fr; min-height:100vh; align-items:start; }
        .main-content { min-width:0; max-height:none; overflow:visible; padding:1.5rem; }
        .content-shell { max-width:1080px; margin:0 auto; }
        .page-header {
            position: relative;
            overflow: hidden;
            margin-bottom: 1.5rem;
            padding: 1.55rem;
            border-radius: 28px;
            color: #fff;
            background: linear-gradient(145deg, #0e377d 0%, #1554c8 54%, #0c8ddf 100%);
            box-shadow: 0 34px 70px rgba(15, 61, 145, 0.22);
        }
        .page-header::before {
            content: '';
            position: absolute;
            width: 240px;
            height: 240px;
            right: -70px;
            top: -60px;
            border-radius: 999px;
            background: rgba(255,255,255,0.16);
        }
        .page-header h1, .page-title { margin: 0 0 0.45rem; font-size: clamp(1.8rem, 3vw, 2.6rem); font-weight: 800; line-height: 1.02; letter-spacing: -0.05em; }
        .page-subtitle { margin: 0; color: rgba(243,248,255,0.86); line-height: 1.7; }
        .action-button { display:inline-flex; align-items:center; gap:8px; padding:12px 16px; border-radius:16px; border:1px solid transparent; background:linear-gradient(135deg, var(--brand-blue-dark) 0%, var(--brand-blue) 58%, var(--brand-cyan) 100%); color:#fff; text-decoration:none; font-weight:700; cursor:pointer; box-shadow: 0 14px 30px rgba(21,84,200,0.22); }
        .action-button:hover { transform: translateY(-2px); box-shadow: 0 18px 34px rgba(21,84,200,0.28); }
        .action-button.outline { background:rgba(255,255,255,0.96); color:var(--text-strong); border-color:#dbe6f2; box-shadow:none; }
        .action-button.outline:hover { background:#fff; box-shadow:0 10px 18px rgba(31,45,69,0.08); }
        .dashboard-card { background:var(--surface); border:1px solid rgba(255,255,255,0.72); border-radius:26px; padding:22px; box-shadow: var(--shadow-soft); backdrop-filter: blur(18px); }
        .card-header { margin-bottom:16px; }
        .card-title { margin:0; color: var(--text-strong); font-size: 1.08rem; font-weight: 800; }
        .card-body p { color: var(--text-soft); }
        .payment-instructions { margin-top:12px; }
        .bank-info { background:#f8fbff; border:1px solid #e1eaf5; border-radius:16px; padding:14px; margin-bottom:12px; }
        .alert { padding: 14px 16px; border-radius: 16px; margin-bottom: 1rem; border: 1px solid transparent; }
        .alert-danger { background:#fff1f1; color:#b42318; border-color:#f6c7c7; }
        @media (max-width:992px) {
            .dashboard-layout { grid-template-columns:1fr; }
            .main-content { padding:1rem; }
        }

        @media (max-width:768px) {
            .main-content { padding:0.85rem; }
            .content-shell { margin-top: 0 !important; }
            .page-header { padding: 1.25rem; border-radius: 22px; }
            .dashboard-card { padding: 1.25rem; border-radius: 22px; }
            .page-title { line-height: 1.1; }
            .alert { display: flex; align-items: flex-start; gap: 0.75rem; }
        }

        @media (max-width:480px) {
            .main-content { padding:0.75rem; }
            .page-header,
            .dashboard-card,
            .bank-info { border-radius: 18px; }
            .page-header,
            .dashboard-card { padding: 1rem; }
            .page-title { font-size: 1.45rem; }
            .page-subtitle { font-size: 0.9rem; }
            .card-title { font-size: 1rem; }
            .card-body p { font-size: 0.9rem; line-height: 1.6; }
            .action-button { width: 100%; justify-content: center; padding: 0.9rem 1rem; border-radius: 14px; }
        }
    </style>
</head>
<body>
<div class="dashboard-layout">
    <?php include __DIR__ . '/sidebar_partial.php'; ?>
    <main class="main-content">
        <div class="content-shell" style="margin-top:24px;">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-info-circle"></i> Instruksi Pembayaran</h1>
                <p class="page-subtitle">
                    Order: <?php echo htmlspecialchars($order['order_number']); ?> -
                    <?php echo htmlspecialchars($order['package_name']); ?>
                </p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="dashboard-card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-file-invoice-dollar"></i> Detail Pembayaran</h3>
                </div>
                <div class="card-body">
                    <p style="margin-bottom:12px;">
                        Metode: <strong><?php echo strtoupper(htmlspecialchars($order['payment_method'])); ?></strong><br>
                        Total Bayar: <strong>Rp <?php echo number_format((float)$order['amount'], 0, ',', '.'); ?></strong>
                    </p>
                    <div>
                        <?php echo $instructionsHtml; ?>
                    </div>
                    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                        <?php if ($order['payment_method'] === 'transfer' && $order['payment_status'] === 'pending'): ?>
                            <a class="action-button" href="upload_payment.php?order_id=<?php echo (int)$orderId; ?>">
                                <i class="fas fa-upload"></i> Upload Bukti Pembayaran
                            </a>
                        <?php endif; ?>
                        <a class="action-button outline" href="order_detail.php?id=<?php echo (int)$orderId; ?>">
                            <i class="fas fa-arrow-left"></i> Kembali ke Detail Pesanan
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php include __DIR__ . '/user_dropdown_partial.php'; ?>
</body>
</html>
