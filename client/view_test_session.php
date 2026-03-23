<?php
require_once '../includes/config.php';
requireClient();

$db = getDB();
$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$sessionId = $_GET['id'] ?? 0;

$stmt = $db->prepare("
    SELECT ts.*, p.*, u.full_name
    FROM test_sessions ts
    JOIN packages p ON ts.package_id = p.id
    JOIN users u ON ts.user_id = u.id
    WHERE ts.id = ? AND ts.user_id = ?
");
$stmt->execute([$sessionId, $userId]);
$session = $stmt->fetch();

if (!$session) {
    die('Session tidak ditemukan.');
}

$mmpiAnswers = $session['mmpi_answers'] ? json_decode($session['mmpi_answers'], true) : [];
$adhdAnswers = $session['adhd_answers'] ? json_decode($session['adhd_answers'], true) : [];
if (!is_array($mmpiAnswers)) $mmpiAnswers = [];
if (!is_array($adhdAnswers)) $adhdAnswers = [];

$stmt = $db->prepare("SELECT question_number, question_text FROM mmpi_questions WHERE is_active = 1");
$stmt->execute();
$questions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = $db->prepare("SELECT id, question_text FROM adhd_questions WHERE is_active = 1");
$stmt->execute();
$adhdQuestions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$statusMap = [
    'not_started' => 'Belum Dimulai',
    'in_progress' => 'Sedang Dikerjakan',
    'completed' => 'Selesai'
];
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail Session - <?php echo APP_NAME; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-500: #1e88ff;
            --primary-700: #0052aa;
            --success-500: #16a34a;
            --surface: rgba(255, 255, 255, 0.84);
            --border: rgba(148, 163, 184, 0.16);
            --text: #0f172a;
            --muted: #64748b;
            --shadow: 0 24px 48px rgba(15, 23, 42, 0.08);
            --radius: 1.4rem;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(30, 136, 255, 0.18), transparent 24%),
                radial-gradient(circle at top right, rgba(245, 158, 11, 0.14), transparent 22%),
                linear-gradient(145deg, #f4f8fc 0%, #ecf3f8 56%, #e5edf4 100%);
        }

        .page-shell {
            max-width: 1320px;
            margin: 0 auto;
            padding: 2rem;
        }

        .hero {
            background: linear-gradient(135deg, rgba(255,255,255,0.86), rgba(255,255,255,0.72));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.86);
            box-shadow: var(--shadow);
            border-radius: 1.8rem;
            padding: 1.6rem 1.7rem;
            margin-bottom: 1.5rem;
        }

        .hero-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            background: rgba(0, 82, 170, 0.08);
            border: 1px solid rgba(0, 82, 170, 0.1);
            color: var(--primary-700);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .hero-head {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: flex-start;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .hero h1 {
            margin: 0 0 0.45rem;
            font-size: 2rem;
            line-height: 1.1;
            letter-spacing: -0.04em;
        }

        .hero p {
            margin: 0;
            color: var(--muted);
            line-height: 1.7;
        }

        .hero-actions {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            border: 0;
            border-radius: 999px;
            padding: 0.8rem 1.1rem;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-700), var(--primary-500));
            color: white;
            box-shadow: 0 14px 24px rgba(0, 82, 170, 0.16);
        }

        .btn-soft {
            background: rgba(255,255,255,0.92);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card,
        .section-card {
            background: var(--surface);
            backdrop-filter: blur(18px);
            border: 1px solid rgba(255,255,255,0.86);
            box-shadow: var(--shadow);
            border-radius: var(--radius);
        }

        .stat-card {
            padding: 1.15rem;
        }

        .stat-label {
            font-size: 0.76rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 800;
        }

        .stat-value {
            margin-top: 0.55rem;
            font-size: 1.18rem;
            font-weight: 800;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.7rem;
            border-radius: 999px;
            background: rgba(30,136,255,0.08);
            color: var(--primary-700);
            font-size: 0.78rem;
            font-weight: 700;
        }

        .section-card {
            padding: 1.35rem;
            margin-bottom: 1.4rem;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .section-head h2 {
            margin: 0;
            font-size: 1.08rem;
            letter-spacing: -0.02em;
        }

        .section-copy {
            color: var(--muted);
            font-size: 0.9rem;
            line-height: 1.7;
        }

        .table-wrap {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
        }

        .data-table th,
        .data-table td {
            padding: 0.85rem 0.9rem;
            text-align: left;
            border-bottom: 1px solid rgba(148, 163, 184, 0.14);
            vertical-align: top;
        }

        .data-table th {
            background: rgba(248, 250, 252, 0.92);
            color: var(--muted);
            font-size: 0.76rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 800;
        }

        .data-table tr:last-child td {
            border-bottom: 0;
        }

        .answer-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.36rem 0.62rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            background: rgba(248,250,252,0.95);
            border: 1px solid rgba(148,163,184,0.14);
        }

        .json-box {
            background: #0f172a;
            color: #dbeafe;
            border-radius: 1.1rem;
            padding: 1rem;
            overflow: auto;
            font-size: 0.84rem;
            line-height: 1.7;
            margin-top: 0.85rem;
            white-space: pre-wrap;
        }

        .empty-state {
            padding: 1rem;
            border-radius: 1rem;
            background: rgba(248,250,252,0.9);
            border: 1px dashed rgba(148,163,184,0.26);
            color: var(--muted);
        }

        @media (max-width: 1080px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .page-shell {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .hero h1 {
                font-size: 1.6rem;
            }

            .hero,
            .section-card,
            .stat-card {
                border-radius: 1.2rem;
            }

            .hero {
                padding: 1.2rem;
            }

            .hero-actions {
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .section-card {
                padding: 1rem;
            }

            .section-head {
                align-items: flex-start;
            }

            .data-table {
                min-width: 640px;
            }

            .json-box {
                font-size: 0.78rem;
                padding: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .page-shell {
                padding: 0.75rem;
            }

            .hero,
            .section-card,
            .stat-card,
            .empty-state {
                border-radius: 1rem;
            }

            .hero {
                padding: 1rem;
            }

            .hero-kicker,
            .status-pill {
                width: 100%;
                justify-content: center;
            }

            .hero h1 {
                font-size: 1.35rem;
            }

            .hero p,
            .section-copy,
            .empty-state {
                font-size: 0.85rem;
            }

            .stat-card {
                padding: 1rem;
            }

            .stat-value {
                font-size: 1rem;
                word-break: break-word;
            }

            .data-table th,
            .data-table td {
                padding: 0.75rem 0.7rem;
                font-size: 0.84rem;
            }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <section class="hero">
            <div class="hero-kicker">
                <i class="fas fa-magnifying-glass-chart"></i>
                Session Inspector
            </div>
            <div class="hero-head">
                <div>
                    <h1><?php echo htmlspecialchars($session['session_code']); ?></h1>
                    <p>Halaman ini dipakai untuk melihat detail sesi, jawaban tersimpan, dan data mentah sebelum atau sesudah hasil diproses.</p>
                </div>
                <div class="hero-actions">
                    <a href="view_result.php?session_id=<?php echo $sessionId; ?>" class="btn btn-primary">
                        <i class="fas fa-file-lines"></i>
                        Lihat Hasil
                    </a>
                    <a href="dashboard.php" class="btn btn-soft">
                        <i class="fas fa-arrow-left"></i>
                        Kembali
                    </a>
                </div>
            </div>
        </section>

        <section class="stats-grid">
            <article class="stat-card">
                <div class="stat-label">User</div>
                <div class="stat-value"><?php echo htmlspecialchars($session['full_name']); ?></div>
            </article>
            <article class="stat-card">
                <div class="stat-label">Paket</div>
                <div class="stat-value"><?php echo htmlspecialchars($session['name']); ?></div>
            </article>
            <article class="stat-card">
                <div class="stat-label">Status</div>
                <div class="stat-value">
                    <span class="status-pill">
                        <i class="fas fa-circle-dot"></i>
                        <?php echo htmlspecialchars($statusMap[$session['status']] ?? $session['status']); ?>
                    </span>
                </div>
            </article>
            <article class="stat-card">
                <div class="stat-label">Jawaban MMPI</div>
                <div class="stat-value"><?php echo count($mmpiAnswers); ?></div>
            </article>
            <article class="stat-card">
                <div class="stat-label">Jawaban ADHD</div>
                <div class="stat-value"><?php echo count($adhdAnswers); ?></div>
            </article>
        </section>

        <section class="section-card">
            <div class="section-head">
                <h2>Informasi Sesi</h2>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <tbody>
                        <tr>
                            <th>Session Code</th>
                            <td><?php echo htmlspecialchars($session['session_code']); ?></td>
                            <th>Result ID</th>
                            <td><?php echo $session['result_id'] ?: 'Belum ada'; ?></td>
                        </tr>
                        <tr>
                            <th>Dibuat</th>
                            <td><?php echo htmlspecialchars($session['created_at']); ?></td>
                            <th>Diupdate</th>
                            <td><?php echo htmlspecialchars($session['updated_at'] ?? '-'); ?></td>
                        </tr>
                        <tr>
                            <th>Current Page</th>
                            <td><?php echo (int) ($session['current_page'] ?? 0); ?></td>
                            <th>Sisa Waktu</th>
                            <td><?php echo isset($session['time_remaining']) ? (int) $session['time_remaining'] . ' detik' : '-'; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="section-card">
            <div class="section-head">
                <div>
                    <h2>Jawaban MMPI</h2>
                    <div class="section-copy"><?php echo count($mmpiAnswers); ?> jawaban tersimpan untuk skala MMPI.</div>
                </div>
            </div>
            <?php if (!empty($mmpiAnswers)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>No. Soal</th>
                            <th>Jawaban</th>
                            <th>Teks Soal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php ksort($mmpiAnswers); foreach ($mmpiAnswers as $qNum => $answer): ?>
                        <tr>
                            <td><?php echo (int) $qNum; ?></td>
                            <td>
                                <span class="answer-badge">
                                    <i class="fas fa-<?php echo (int) $answer === 1 ? 'check-circle' : 'times-circle'; ?>"></i>
                                    <?php echo (int) $answer; ?> (<?php echo (int) $answer === 1 ? 'Ya' : 'Tidak'; ?>)
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($questions[$qNum] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">Belum ada jawaban MMPI yang tersimpan pada sesi ini.</div>
            <?php endif; ?>
        </section>

        <section class="section-card">
            <div class="section-head">
                <div>
                    <h2>Jawaban ADHD</h2>
                    <div class="section-copy"><?php echo count($adhdAnswers); ?> jawaban tersimpan untuk modul ADHD.</div>
                </div>
            </div>
            <?php if (!empty($adhdAnswers)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID Soal</th>
                            <th>Jawaban</th>
                            <th>Teks Soal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        ksort($adhdAnswers);
                        foreach ($adhdAnswers as $qId => $answer):
                            $answerText = 'N/A';
                            if ((int) $answer === 0) $answerText = 'Tidak Pernah';
                            elseif ((int) $answer === 1) $answerText = 'Jarang';
                            elseif ((int) $answer === 2) $answerText = 'Kadang-kadang';
                            elseif ((int) $answer === 3) $answerText = 'Sering';
                            elseif ((int) $answer === 4) $answerText = 'Sangat Sering';
                        ?>
                        <tr>
                            <td><?php echo (int) $qId; ?></td>
                            <td>
                                <span class="answer-badge">
                                    <i class="fas fa-bars-staggered"></i>
                                    <?php echo (int) $answer; ?> (<?php echo $answerText; ?>)
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($adhdQuestions[$qId] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">Belum ada jawaban ADHD yang tersimpan pada sesi ini.</div>
            <?php endif; ?>
        </section>

        <section class="section-card">
            <div class="section-head">
                <div>
                    <h2>Raw JSON</h2>
                    <div class="section-copy">Data mentah ini berguna untuk validasi scoring atau inspeksi penyimpanan jawaban.</div>
                </div>
            </div>
            <div class="section-copy">MMPI Answers JSON</div>
            <pre class="json-box"><?php echo htmlspecialchars(json_encode($mmpiAnswers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
            <div class="section-copy" style="margin-top: 1rem;">ADHD Answers JSON</div>
            <pre class="json-box"><?php echo htmlspecialchars(json_encode($adhdAnswers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
        </section>
    </div>
</body>
</html>
