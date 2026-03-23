<?php
// admin/ajax_get_client_edit.php
require_once '../includes/config.php';
requireAdmin();

$db = getDB();

$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$client_id) {
    echo '<p style="color: #e74c3c;">ID klien tidak valid.</p>';
    exit();
}

try {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();
    
    if (!$client) {
        echo '<p style="color: #e74c3c;">Klien tidak ditemukan.</p>';
        exit();
    }
    
} catch (PDOException $e) {
    error_log("Client edit error: " . $e->getMessage());
    echo '<p style="color: #e74c3c;">Gagal memuat data klien.</p>';
    exit();
}
?>

<?php
$clientInitials = 'U';
if (!empty($client['full_name'])) {
    $parts = explode(' ', trim((string)$client['full_name']));
    $clientInitials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
$clientAvatarUrl = !empty($client['avatar']) ? BASE_URL . '/assets/uploads/avatars/' . rawurlencode(basename((string)$client['avatar'])) : '';
?>

<style>
    .edit-sheet {
        display: grid;
        gap: 1.25rem;
    }

    .edit-hero {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 1rem;
        align-items: center;
        padding: 1.2rem;
        border-radius: 22px;
        border: 1px solid var(--border-color);
        background:
            radial-gradient(circle at top right, rgba(17, 24, 39, 0.06), transparent 36%),
            var(--bg-secondary);
    }

    .edit-hero-avatar {
        width: 76px;
        height: 76px;
        border-radius: 22px;
        background-color: var(--text-primary);
        color: var(--bg-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: 700;
        overflow: hidden;
    }

    .edit-hero-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .edit-hero-name {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 0.3rem;
    }

    .edit-hero-meta {
        font-size: 0.85rem;
        color: var(--text-secondary);
        line-height: 1.65;
    }

    .edit-card {
        border: 1px solid var(--border-color);
        border-radius: 20px;
        overflow: hidden;
        background-color: var(--bg-primary);
    }

    .edit-card-head {
        padding: 1rem 1.2rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        align-items: center;
        gap: 0.65rem;
        font-size: 0.92rem;
        font-weight: 700;
        color: var(--text-primary);
    }

    .edit-card-head i {
        width: 30px;
        height: 30px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background-color: var(--bg-secondary);
        border: 1px solid var(--border-color);
        color: var(--text-primary);
    }

    .edit-card-body {
        padding: 1.2rem;
    }

    .edit-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }

    .edit-field {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
    }

    .edit-field.full {
        grid-column: 1 / -1;
    }

    .edit-label {
        font-size: 0.68rem;
        color: var(--text-secondary);
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 700;
    }

    .edit-label.required::after {
        content: ' *';
        color: #dc2626;
    }

    .edit-input,
    .edit-select,
    .edit-textarea {
        width: 100%;
        padding: 0.78rem 0.95rem;
        border-radius: 14px;
        border: 1px solid var(--border-color);
        background-color: var(--bg-secondary);
        color: var(--text-primary);
        font: inherit;
        transition: border-color 0.2s ease, background-color 0.2s ease;
    }

    .edit-input:focus,
    .edit-select:focus,
    .edit-textarea:focus {
        outline: none;
        border-color: var(--text-primary);
        background-color: var(--bg-primary);
    }

    .edit-textarea {
        min-height: 96px;
        resize: vertical;
    }

    .edit-help {
        font-size: 0.74rem;
        color: var(--text-secondary);
        line-height: 1.5;
    }

    .edit-status-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }

    .edit-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-top: 0.5rem;
    }

    .edit-message {
        display: none;
        padding: 0.9rem 1rem;
        border-radius: 14px;
        border: 1px solid transparent;
        font-size: 0.84rem;
    }

    .edit-message.show {
        display: block;
    }

    .edit-message.success {
        background-color: rgba(22, 101, 52, 0.12);
        border-color: rgba(22, 101, 52, 0.22);
        color: #166534;
    }

    .edit-message.error {
        background-color: rgba(153, 27, 27, 0.12);
        border-color: rgba(153, 27, 27, 0.22);
        color: #991b1b;
    }

    @media (max-width: 768px) {
        .edit-hero,
        .edit-grid,
        .edit-status-grid {
            grid-template-columns: 1fr;
        }

        .edit-actions .btn {
            width: 100%;
        }
    }
</style>

<form id="editClientForm" onsubmit="saveClientEdit(event, <?php echo $client['id']; ?>)">
    <input type="hidden" name="id" value="<?php echo $client['id']; ?>">
    <div class="edit-sheet">
        <section class="edit-hero">
            <div class="edit-hero-avatar">
                <?php if ($clientAvatarUrl): ?>
                    <img src="<?php echo htmlspecialchars($clientAvatarUrl); ?>" alt="Avatar klien">
                <?php else: ?>
                    <?php echo htmlspecialchars($clientInitials); ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="edit-hero-name"><?php echo htmlspecialchars($client['full_name']); ?></div>
                <div class="edit-hero-meta">
                    @<?php echo htmlspecialchars($client['username']); ?><br>
                    <?php echo htmlspecialchars($client['email']); ?><br>
                    Bergabung <?php echo formatDate($client['created_at']); ?>
                </div>
            </div>
        </section>

        <div id="editClientMessage" class="edit-message"></div>

        <section class="edit-card">
            <div class="edit-card-head">
                <i class="fas fa-id-card"></i>
                Informasi Dasar
            </div>
            <div class="edit-card-body">
                <div class="edit-grid">
                    <div class="edit-field full">
                        <label class="edit-label required">Nama Lengkap</label>
                        <input type="text" name="full_name" required class="edit-input" value="<?php echo htmlspecialchars($client['full_name']); ?>">
                    </div>

                    <div class="edit-field">
                        <label class="edit-label required">Username</label>
                        <input type="text" name="username" required class="edit-input" value="<?php echo htmlspecialchars($client['username']); ?>">
                    </div>

                    <div class="edit-field">
                        <label class="edit-label required">Email</label>
                        <input type="email" name="email" required class="edit-input" value="<?php echo htmlspecialchars($client['email']); ?>">
                    </div>

                    <div class="edit-field">
                        <label class="edit-label">No. Telepon</label>
                        <input type="text" name="phone" class="edit-input" value="<?php echo htmlspecialchars($client['phone'] ?? ''); ?>">
                    </div>

                    <div class="edit-field">
                        <label class="edit-label">Tanggal Lahir</label>
                        <input type="date" name="date_of_birth" class="edit-input" value="<?php echo $client['date_of_birth'] ?: ''; ?>">
                    </div>

                    <div class="edit-field">
                        <label class="edit-label">Jenis Kelamin</label>
                        <select name="gender" class="edit-select">
                            <option value="Laki-laki" <?php echo ($client['gender'] ?? '') === 'Laki-laki' ? 'selected' : ''; ?>>Laki-laki</option>
                            <option value="Perempuan" <?php echo ($client['gender'] ?? '') === 'Perempuan' ? 'selected' : ''; ?>>Perempuan</option>
                            <option value="Lainnya" <?php echo ($client['gender'] ?? '') === 'Lainnya' ? 'selected' : ''; ?>>Lainnya</option>
                        </select>
                    </div>

                    <div class="edit-field">
                        <label class="edit-label">Pendidikan</label>
                        <input type="text" name="education" class="edit-input" value="<?php echo htmlspecialchars($client['education'] ?? ''); ?>">
                    </div>

                    <div class="edit-field">
                        <label class="edit-label">Pekerjaan</label>
                        <input type="text" name="occupation" class="edit-input" value="<?php echo htmlspecialchars($client['occupation'] ?? ''); ?>">
                    </div>

                    <div class="edit-field full">
                        <label class="edit-label">Alamat</label>
                        <textarea name="address" class="edit-textarea"><?php echo htmlspecialchars($client['address'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </section>

        <section class="edit-card">
            <div class="edit-card-head">
                <i class="fas fa-shield-alt"></i>
                Akses & Keamanan
            </div>
            <div class="edit-card-body">
                <div class="edit-grid">
                    <div class="edit-field full">
                        <label class="edit-label">Password Baru</label>
                        <input type="password" name="password" class="edit-input" placeholder="Kosongkan jika tidak ingin mengubah password">
                        <div class="edit-help">Password minimal 6 karakter. Jika dikosongkan, password lama tetap dipakai.</div>
                    </div>
                </div>

                <div class="edit-status-grid" style="margin-top: 1rem;">
                    <div class="edit-field">
                        <label class="edit-label">Peran</label>
                        <select name="role" class="edit-select">
                            <option value="client" <?php echo $client['role'] === 'client' ? 'selected' : ''; ?>>Klien</option>
                            <option value="admin" <?php echo $client['role'] === 'admin' ? 'selected' : ''; ?>>Administrator</option>
                        </select>
                    </div>

                    <div class="edit-field">
                        <label class="edit-label">Status Akun</label>
                        <select name="is_active" class="edit-select">
                            <option value="1" <?php echo $client['is_active'] ? 'selected' : ''; ?>>Aktif</option>
                            <option value="0" <?php echo !$client['is_active'] ? 'selected' : ''; ?>>Nonaktif</option>
                        </select>
                    </div>
                </div>
            </div>
        </section>

        <div class="edit-actions">
            <button type="button" class="btn btn-secondary" onclick="closeModal('editClientModal')">Batal</button>
            <button type="submit" class="btn btn-primary" id="editClientSubmitBtn">
                <i class="fas fa-save"></i> Simpan Perubahan
            </button>
        </div>
    </div>
</form>

<script>
function saveClientEdit(event, clientId) {
    event.preventDefault();
    const formData = new FormData(event.target);
    const messageBox = document.getElementById('editClientMessage');
    const submitButton = document.getElementById('editClientSubmitBtn');

    if (messageBox) {
        messageBox.className = 'edit-message';
        messageBox.textContent = '';
    }
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
    }
    
    fetch('<?php echo BASE_URL; ?>/admin/ajax_save_client.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (messageBox) {
                messageBox.className = 'edit-message success show';
                messageBox.textContent = data.message || 'Data klien berhasil diperbarui.';
            }
            closeModal('editClientModal');
            setTimeout(() => location.reload(), 500);
        } else {
            if (messageBox) {
                messageBox.className = 'edit-message error show';
                messageBox.textContent = data.message || 'Gagal menyimpan perubahan.';
            }
        }
    })
    .catch(error => {
        if (messageBox) {
            messageBox.className = 'edit-message error show';
            messageBox.textContent = 'Gagal menyimpan perubahan.';
        }
        console.error(error);
    })
    .finally(() => {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.innerHTML = '<i class="fas fa-save"></i> Simpan Perubahan';
        }
    });
}
</script>
