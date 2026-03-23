<?php
// admin/qris_settings.php - Redesain Monochrome Minimalist
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$currentUser = getCurrentUser();

// Initialize variables
$error = '';
$success = '';
$banks = [];

// Get existing bank accounts
try {
    $stmt = $db->query("
        SELECT * FROM system_settings 
        WHERE category = 'bank_account' 
        ORDER BY created_at DESC
    ");
    $banks = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Gagal memuat data rekening: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db->beginTransaction();
        
        if ($action === 'add_bank') {
            $bankData = [
                'bank_name' => $_POST['bank_name'] ?? '',
                'account_number' => $_POST['account_number'] ?? '',
                'account_name' => $_POST['account_name'] ?? '',
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'notes' => $_POST['notes'] ?? ''
            ];
            
            $settingKey = 'bank_' . time() . '_' . rand(100, 999);
            $settingValue = json_encode($bankData);
            
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value, setting_type, category, description)
                VALUES (?, ?, 'json', 'bank_account', ?)
            ");
            $stmt->execute([$settingKey, $settingValue, "Bank: {$bankData['bank_name']}"]);
            
            $success = "Rekening bank berhasil ditambahkan.";
            
        } elseif ($action === 'update_bank') {
            $bankId = $_POST['bank_id'] ?? '';
            $bankData = [
                'bank_name' => $_POST['bank_name'] ?? '',
                'account_number' => $_POST['account_number'] ?? '',
                'account_name' => $_POST['account_name'] ?? '',
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'notes' => $_POST['notes'] ?? ''
            ];
            
            $settingValue = json_encode($bankData);
            
            $stmt = $db->prepare("
                UPDATE system_settings 
                SET setting_value = ?, 
                    description = ?,
                    updated_at = NOW()
                WHERE setting_key = ?
            ");
            $stmt->execute([$settingValue, "Bank: {$bankData['bank_name']}", $bankId]);
            
            $success = "Rekening bank berhasil diperbarui.";
            
        } elseif ($action === 'delete_bank') {
            $bankId = $_POST['bank_id'] ?? '';
            
            $stmt = $db->prepare("DELETE FROM system_settings WHERE setting_key = ?");
            $stmt->execute([$bankId]);
            
            $success = "Rekening bank berhasil dihapus.";
        }
        
        $db->commit();
        
        // Refresh banks list
        $stmt = $db->query("
            SELECT * FROM system_settings 
            WHERE category = 'bank_account' 
            ORDER BY created_at DESC
        ");
        $banks = $stmt->fetchAll();
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Gagal memproses data: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pengaturan Rekening Bank - <?php echo htmlspecialchars(APP_NAME); ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #F8F9FA;
            --bg-hover: #F3F4F6;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #f0f0f0;
            --border-focus: #111827;
            
            --success-bg: #f0fdf4;
            --success-text: #166534;
            --warning-bg: #fffbeb;
            --warning-text: #92400e;
            --danger-bg: #fef2f2;
            --danger-text: #991b1b;
            --info-bg: #eff6ff;
            --info-text: #1e40af;
        }

        [data-theme="dark"] {
            --bg-primary: #1F2937;
            --bg-secondary: #111827;
            --bg-hover: #2D3748;
            --text-primary: #F8F9FA;
            --text-secondary: #9CA3AF;
            --text-muted: #6B7280;
            --border-color: #374151;
            --border-focus: #F8F9FA;
            
            --success-bg: rgba(22, 101, 52, 0.2);
            --success-text: #86efac;
            --warning-bg: rgba(146, 64, 14, 0.2);
            --warning-text: #fcd34d;
            --danger-bg: rgba(153, 27, 27, 0.2);
            --danger-text: #fca5a5;
            --info-bg: rgba(30, 64, 175, 0.2);
            --info-text: #93c5fd;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.5;
        }

        /* Main Content */
        .admin-main {
            margin-left: 280px;
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
        }

        @media (max-width: 992px) {
            .admin-main {
                margin-left: 0;
                padding: 1.5rem;
            }
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }

        .page-subtitle {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .page-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Inter', sans-serif;
        }

        .btn-primary {
            background-color: var(--text-primary);
            color: var(--bg-primary);
            border-color: var(--text-primary);
        }

        .btn-primary:hover {
            background-color: var(--bg-primary);
            color: var(--text-primary);
        }

        .btn-success {
            background-color: var(--success-bg);
            color: var(--success-text);
            border-color: var(--success-text);
        }

        .btn-success:hover {
            background-color: var(--success-text);
            color: white;
        }

        .btn-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-text);
        }

        .btn-danger:hover {
            background-color: var(--danger-text);
            color: white;
        }

        .btn-secondary {
            background-color: transparent;
            color: var(--text-primary);
            border-color: var(--border-color);
        }

        .btn-secondary:hover {
            background-color: var(--bg-hover);
            border-color: var(--text-primary);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        /* Alerts */
        .alert {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
        }

        .alert-success {
            background-color: var(--success-bg);
            color: var(--success-text);
            border-color: var(--success-text);
        }

        .alert-danger {
            background-color: var(--danger-bg);
            color: var(--danger-text);
            border-color: var(--danger-text);
        }

        .alert-close {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: currentColor;
            opacity: 0.5;
        }

        .alert-close:hover {
            opacity: 1;
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Card */
        .card {
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .card-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title i {
            color: var(--text-secondary);
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Form */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }

        .form-label.required::after {
            content: ' *';
            color: var(--danger-text);
        }

        .form-control,
        .form-textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-primary);
            font-size: 0.85rem;
            font-family: 'Inter', sans-serif;
        }

        .form-control:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--text-primary);
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .checkbox-label input {
            width: 16px;
            height: 16px;
            accent-color: var(--text-primary);
        }

        .checkbox-text {
            font-size: 0.85rem;
            color: var(--text-primary);
        }

        .form-actions {
            display: flex;
            gap: 0.75rem;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        /* Bank List */
        .bank-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .bank-item {
            background-color: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.2s ease;
        }

        .bank-item:hover {
            border-color: var(--text-primary);
        }

        .bank-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .bank-name {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .bank-name i {
            color: var(--text-secondary);
        }

        .bank-status {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.65rem;
            font-weight: 600;
        }

        .status-active {
            background-color: var(--success-bg);
            color: var(--success-text);
        }

        .status-inactive {
            background-color: var(--danger-bg);
            color: var(--danger-text);
        }

        .bank-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 1rem 0;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.1rem;
        }

        .detail-label {
            font-size: 0.6rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-size: 0.85rem;
            color: var(--text-primary);
            font-weight: 500;
        }

        .bank-notes {
            background-color: var(--bg-primary);
            border: 1px dashed var(--border-color);
            border-radius: 6px;
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }

        .bank-notes i {
            margin-right: 0.25rem;
            color: var(--text-muted);
        }

        .bank-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h4 {
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .admin-main {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.45rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-actions {
                width: 100%;
            }

            .page-actions .btn {
                width: 100%;
                justify-content: center;
            }

            .card-header,
            .card-body,
            .stat-card {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn {
                width: 100%;
            }

            .bank-details {
                grid-template-columns: 1fr;
            }

            .bank-actions {
                flex-direction: column;
            }

            .bank-actions .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .admin-main {
                padding: 0.875rem;
            }

            .page-title {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <!-- Include Navbar & Sidebar -->
    <?php include 'includes/navbar.php'; ?>
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="admin-main">
        <div class="container">
            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        <i class="fas fa-university"></i>
                        Pengaturan Rekening Bank
                    </h1>
                    <p class="page-subtitle">Kelola rekening bank untuk pembayaran transfer manual</p>
                </div>
                <div class="page-actions">
                    <a href="payment_verification.php" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i> Verifikasi
                    </a>
                </div>
            </div>
            
            <!-- Messages -->
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                    <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>
            
            <!-- Content Grid -->
            <div class="content-grid">
                <!-- Add/Edit Bank Form -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title" id="formTitle">
                            <i class="fas fa-plus-circle"></i>
                            Tambah Rekening
                        </h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="bankForm">
                            <input type="hidden" name="action" id="formAction" value="add_bank">
                            <input type="hidden" name="bank_id" id="bankId" value="">
                            
                            <div class="form-group">
                                <div class="form-label required">Nama Bank</div>
                                <input type="text" name="bank_name" id="bankName" class="form-control" required
                                       placeholder="Contoh: BCA, Mandiri, BRI">
                            </div>
                            
                            <div class="form-group">
                                <div class="form-label required">Nomor Rekening</div>
                                <input type="text" name="account_number" id="accountNumber" class="form-control" required
                                       placeholder="Masukkan nomor rekening">
                            </div>
                            
                            <div class="form-group">
                                <div class="form-label required">Atas Nama</div>
                                <input type="text" name="account_name" id="accountName" class="form-control" required
                                       placeholder="Nama sesuai buku tabungan">
                            </div>
                            
                            <div class="form-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_active" id="isActive" value="1" checked>
                                    <span class="checkbox-text">Aktif</span>
                                </label>
                            </div>
                            
                            <div class="form-group">
                                <div class="form-label">Catatan</div>
                                <textarea name="notes" id="notes" class="form-textarea" rows="3"
                                          placeholder="Contoh: Transfer maksimal 15:00 WIB"></textarea>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" id="submitBtn" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Tambah
                                </button>
                                <button type="button" id="cancelBtn" class="btn btn-secondary" 
                                        onclick="resetForm()" style="display: none;">
                                    <i class="fas fa-times"></i> Batal
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Banks List -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-list"></i>
                            Daftar Rekening
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php if (empty($banks)): ?>
                            <div class="empty-state">
                                <i class="fas fa-university"></i>
                                <h4>Belum Ada Rekening</h4>
                                <p>Tambahkan rekening bank pertama Anda melalui form di samping.</p>
                            </div>
                        <?php else: ?>
                            <div class="bank-list">
                                <?php foreach ($banks as $bank): 
                                    $bankData = json_decode($bank['setting_value'], true);
                                ?>
                                <div class="bank-item">
                                    <div class="bank-header">
                                        <div class="bank-name">
                                            <i class="fas fa-university"></i>
                                            <?php echo htmlspecialchars($bankData['bank_name']); ?>
                                        </div>
                                        <span class="bank-status <?php echo $bankData['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <i class="fas fa-<?php echo $bankData['is_active'] ? 'check-circle' : 'ban'; ?>"></i>
                                            <?php echo $bankData['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="bank-details">
                                        <div class="detail-item">
                                            <span class="detail-label">No. Rekening</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($bankData['account_number']); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <span class="detail-label">Atas Nama</span>
                                            <span class="detail-value"><?php echo htmlspecialchars($bankData['account_name']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($bankData['notes'])): ?>
                                    <div class="bank-notes">
                                        <i class="fas fa-sticky-note"></i>
                                        <?php echo htmlspecialchars($bankData['notes']); ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="bank-actions">
                                        <button type="button" class="btn btn-primary btn-sm" 
                                                onclick="editBank('<?php echo $bank['setting_key']; ?>', <?php echo htmlspecialchars(json_encode($bankData)); ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_bank">
                                            <input type="hidden" name="bank_id" value="<?php echo $bank['setting_key']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" 
                                                    onclick="return confirm('Hapus rekening <?php echo htmlspecialchars($bankData['bank_name']); ?>?')">
                                                <i class="fas fa-trash"></i> Hapus
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <script>
        function editBank(bankId, bankData) {
            document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Rekening';
            document.getElementById('formAction').value = 'update_bank';
            document.getElementById('bankId').value = bankId;
            document.getElementById('bankName').value = bankData.bank_name || '';
            document.getElementById('accountNumber').value = bankData.account_number || '';
            document.getElementById('accountName').value = bankData.account_name || '';
            document.getElementById('isActive').checked = bankData.is_active == 1;
            document.getElementById('notes').value = bankData.notes || '';
            
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save"></i> Simpan';
            document.getElementById('cancelBtn').style.display = 'inline-block';
            
            document.querySelector('.card').scrollIntoView({ behavior: 'smooth' });
        }
        
        function resetForm() {
            document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Tambah Rekening';
            document.getElementById('formAction').value = 'add_bank';
            document.getElementById('bankId').value = '';
            document.getElementById('bankForm').reset();
            document.getElementById('submitBtn').innerHTML = '<i class="fas fa-plus"></i> Tambah';
            document.getElementById('cancelBtn').style.display = 'none';
        }
        
        document.getElementById('bankForm').addEventListener('submit', function(e) {
            const accountNumber = document.getElementById('accountNumber').value;
            if (!/^\d+$/.test(accountNumber)) {
                e.preventDefault();
                alert('Nomor rekening harus berupa angka.');
            }
        });
        
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>
</html>
