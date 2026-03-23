<?php
// client/test_complete.php
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];

$sessionId = $_GET['session_id'] ?? 0;
$resultCode = $_GET['result_code'] ?? '';

if (!$sessionId || !$resultCode) {
    header("Location: dashboard.php");
    exit;
}

// Cek apakah hasil sudah diizinkan admin
$stmt = $db->prepare("
    SELECT tr.*, p.name as package_name, 
           tr.result_unlocked, tr.unlocked_at, tr.unlocked_by,
           u.full_name as unlocked_by_name
    FROM test_results tr
    JOIN packages p ON tr.package_id = p.id
    LEFT JOIN users u ON tr.unlocked_by = u.id
    WHERE tr.result_code = ?
    AND tr.user_id = ?
    LIMIT 1
");
$stmt->execute([$resultCode, $userId]);
$result = $stmt->fetch();

if (!$result) {
    header("Location: dashboard.php");
    exit;
}

// Log view attempt
logActivity($userId, 'result_view_attempt', "Tried to view result: $resultCode");

// Cek jika sudah di-unlock
$isUnlocked = $result['result_unlocked'] == 1;
$unlockedAt = $result['unlocked_at'];
$unlockedByName = $result['unlocked_by_name'] ?? 'Admin';

// Cek jika ada notifikasi dari admin
$stmt = $db->prepare("
    SELECT * FROM notifications 
    WHERE user_id = ? 
    AND reference_type = 'test_result'
    AND reference_id = ?
    AND is_read = 0
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([$userId, $result['id']]);
$notification = $stmt->fetch();

// Mark notification as read
if ($notification) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->execute([$notification['id']]);
}

// Jika hasil sudah diunlock, redirect ke view result
if ($isUnlocked) {
    header("Location: view_result.php?id=" . $result['id']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menunggu Persetujuan - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../include/css/dashboard.css">
    <style>
        :root {
            --text-strong: #182235;
            --text-soft: #5f6f87;
            --brand-blue: #1554c8;
            --brand-blue-dark: #0f3d91;
            --brand-cyan: #0c8ddf;
            --brand-warm: #f0a34a;
        }
        .waiting-container {
            min-height: 100vh;
            font-family: 'Plus Jakarta Sans', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255, 197, 111, 0.2), transparent 22%),
                radial-gradient(circle at top right, rgba(12, 141, 223, 0.12), transparent 24%),
                linear-gradient(135deg, #f6f0e5 0%, #edf4ff 48%, #f9fbff 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .waiting-card {
            background: rgba(255,255,255,0.9);
            border-radius: 28px;
            padding: 3rem;
            max-width: 600px;
            width: 100%;
            text-align: center;
            box-shadow: 0 26px 60px rgba(19, 33, 68, 0.12);
            border: 1px solid rgba(255,255,255,0.78);
            backdrop-filter: blur(18px);
        }
        
        .waiting-icon {
            font-size: 4rem;
            color: var(--brand-blue);
            margin-bottom: 1.5rem;
        }
        
        .waiting-badge {
            background: linear-gradient(135deg, var(--brand-blue-dark) 0%, var(--brand-blue) 58%, var(--brand-cyan) 100%);
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 999px;
            font-size: 0.9rem;
            margin-bottom: 2rem;
            display: inline-block;
        }
        
        .test-info {
            background: #f8fbff;
            border-radius: 18px;
            padding: 1.5rem;
            margin: 2rem 0;
            text-align: left;
            border: 1px solid #e1eaf5;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #e7eef8;
        }
        
        .info-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .status-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
            justify-content: center;
        }
        
        .status-dot {
            width: 12px;
            height: 12px;
            background: var(--brand-warm);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.3; }
            100% { opacity: 1; }
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .notification-item {
            background: #eefaf1;
            border-left: 4px solid #2ecc71;
            padding: 1rem;
            border-radius: 14px;
            margin: 1.5rem 0;
            text-align: left;
        }

        @media (max-width: 768px) {
            .waiting-container {
                padding: 1rem;
                align-items: flex-start;
            }

            .waiting-card {
                padding: 2rem 1.5rem;
                border-radius: 22px;
            }

            .waiting-icon {
                font-size: 3.2rem;
                margin-bottom: 1rem;
            }

            .test-info {
                padding: 1.25rem;
                border-radius: 16px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .waiting-container {
                padding: 0.75rem;
            }

            .waiting-card {
                padding: 1.5rem 1rem;
                border-radius: 18px;
            }

            .waiting-badge {
                width: 100%;
                font-size: 0.82rem;
                padding: 0.55rem 1rem;
                margin-bottom: 1.25rem;
            }

            .test-info,
            .notification-item {
                padding: 1rem;
                border-radius: 14px;
            }

            .info-item {
                flex-direction: column;
                gap: 0.25rem;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="waiting-container">
        <div class="waiting-card">
            <div class="waiting-icon">
                <i class="fas fa-lock"></i>
            </div>
            
            <div class="waiting-badge">
                <i class="fas fa-clock"></i> MENUNGGU PERSETUJUAN
            </div>
            
            <h1 style="color: var(--text-strong); margin-bottom: 1rem; letter-spacing: -0.04em;">
                Hasil Tes Anda Sedang Diproses
            </h1>
            
            <p style="color: var(--text-soft); margin-bottom: 2rem;">
                Hasil tes Anda sedang ditinjau oleh psikolog/administrator. 
                Anda akan menerima notifikasi begitu hasil sudah tersedia untuk dilihat.
            </p>
            
            <?php if ($notification): ?>
            <div class="notification-item">
                <i class="fas fa-envelope text-success"></i>
                <strong>Pesan dari Admin:</strong><br>
                <?php echo htmlspecialchars($notification['message']); ?>
            </div>
            <?php endif; ?>
            
            <div class="test-info">
                <div class="info-item">
                    <span>Kode Hasil:</span>
                    <span style="font-weight: 700;"><?php echo htmlspecialchars($result['result_code']); ?></span>
                </div>
                <div class="info-item">
                    <span>Paket Tes:</span>
                    <span><?php echo htmlspecialchars($result['package_name']); ?></span>
                </div>
                <div class="info-item">
                    <span>Tanggal Tes:</span>
                    <span><?php echo date('d/m/Y H:i', strtotime($result['created_at'])); ?></span>
                </div>
                <div class="info-item">
                    <span>Status:</span>
                    <span style="color: #ff9800; font-weight: 600;">
                        <i class="fas fa-clock"></i> Dalam Review
                    </span>
                </div>
            </div>
            
            <div class="status-indicator">
                <div class="status-dot"></div>
                <span style="color: #ff9800;">Menunggu persetujuan admin</span>
            </div>
            
            <div style="margin-top: 2rem; padding: 1.5rem; background: #e3f2fd; border-radius: 12px;">
                <h4 style="color: #1976d2; margin-bottom: 0.5rem;">
                    <i class="fas fa-info-circle"></i> Informasi
                </h4>
                <p style="font-size: 0.9rem; color: #495057; margin: 0;">
                    Proses review biasanya memakan waktu 1-3 hari kerja. 
                    Anda akan menerima notifikasi melalui email dan dashboard ketika hasil sudah tersedia.
                </p>
            </div>
            
            <div class="action-buttons">
                        <a href="dashboard.php" class="btn btn-primary" style="border-radius: 16px; font-weight: 700;">
                    <i class="fas fa-tachometer-alt"></i> Kembali ke Dashboard
                </a>
                
                <a href="test_history.php" class="btn" style="background: rgba(255,255,255,0.96); color: var(--text-strong); border: 1px solid #dbe6f2; border-radius: 16px; font-weight: 700;">
                    <i class="fas fa-history"></i> Riwayat Tes
                </a>
            </div>
            
            <p style="font-size: 0.85rem; color: #adb5bd; margin-top: 2rem;">
                <i class="fas fa-shield-alt"></i> Keamanan & Privasi: 
                Hasil tes Anda dilindungi dan hanya dapat diakses setelah persetujuan resmi.
            </p>
        </div>
    </div>
</body>
</html>
