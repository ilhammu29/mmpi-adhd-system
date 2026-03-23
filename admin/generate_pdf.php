<?php
// admin/generate_pdf.php - Redesain Laporan dengan Border Tabel yang Rapi
require_once '../includes/config.php';
requireAdmin();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo "ID hasil tes tidak valid";
    exit;
}

$stmt = $db->prepare("
    SELECT tr.*, u.full_name, u.gender, u.date_of_birth, p.name AS package_name, ts.session_code
    FROM test_results tr
    JOIN users u ON u.id = tr.user_id
    JOIN packages p ON p.id = tr.package_id
    LEFT JOIN test_sessions ts ON ts.id = tr.test_session_id
    WHERE tr.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$r = $stmt->fetch();
if (!$r) {
    http_response_code(404);
    echo "Hasil tes tidak ditemukan";
    exit;
}

$validity = !empty($r['validity_scores']) ? json_decode($r['validity_scores'], true) : [];
$basic = !empty($r['basic_scales']) ? json_decode($r['basic_scales'], true) : [];
$supp = !empty($r['supplementary_scales']) ? json_decode($r['supplementary_scales'], true) : [];
$adhd = !empty($r['adhd_scores']) ? json_decode($r['adhd_scores'], true) : [];
if (!is_array($validity)) $validity = [];
if (!is_array($basic)) $basic = [];
if (!is_array($supp)) $supp = [];
if (!is_array($adhd)) $adhd = [];

$mmpiInterp = [];
$interpJson = $r['mmpi_interpretation'] ?? '';
if (!empty($interpJson)) {
    $decoded = json_decode($interpJson, true);
    if (is_array($decoded)) {
        $mmpiInterp = $decoded;
    } else {
        $mmpiInterp['clinical'] = $interpJson;
    }
}

$adhdInterpretationText = trim((string)($r['adhd_interpretation'] ?? ($adhd['interpretation'] ?? '')));
$overallInterpretation = trim((string)($r['overall_interpretation'] ?? ''));
$generalRecommendations = trim((string)($r['recommendations'] ?? ''));

function pdfValue($value) {
    if (is_array($value)) {
        if (array_key_exists('t', $value)) {
            return (string)($value['t'] ?? '-');
        }
        if (array_key_exists('raw', $value)) {
            return (string)($value['raw'] ?? '-');
        }
        return '-';
    }

    if ($value === null || $value === '') {
        return '-';
    }

    return (string)$value;
}

function pdfScaleRow($row, $label) {
    if (!is_array($row)) {
        $row = [];
    }

    return [
        'label' => $label,
        'raw' => pdfValue($row['raw'] ?? null),
        't' => pdfValue($row['t'] ?? null),
        'interpretation' => trim((string)($row['interpretation'] ?? '-'))
    ];
}

function pdfTextToLines($text) {
    $text = trim((string)$text);
    if ($text === '') {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $clean[] = $line;
        }
    }
    return $clean;
}

function pdfDateIndo($datetime) {
    if (empty($datetime)) {
        return '-';
    }

    $timestamp = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
    if (!$timestamp) {
        return '-';
    }

    $months = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];

    $day = date('d', $timestamp);
    $month = $months[(int)date('n', $timestamp)] ?? date('m', $timestamp);
    $year = date('Y', $timestamp);

    return $day . ' ' . $month . ' ' . $year;
}

// Format validity rows
$validityRows = [];
foreach (['L', 'F', 'K', 'VRIN', 'TRIN'] as $scale) {
    $entry = $validity[$scale] ?? null;
    $validityRows[] = [
        'label' => $scale,
        'value' => is_array($entry) ? pdfValue($entry['t'] ?? $entry['raw'] ?? null) : pdfValue($entry),
        'raw' => is_array($entry) ? pdfValue($entry['raw'] ?? null) : '-',
        't' => is_array($entry) ? pdfValue($entry['t'] ?? null) : '-'
    ];
}

// Format basic scale rows
$basicRows = [];
foreach ([
    'Hs' => 'Hypochondriasis (Hipokondriasis)',
    'D' => 'Depression (Depresi)',
    'Hy' => 'Hysteria (Histeria)',
    'Pd' => 'Psychopathic Deviate (Psikopati)',
    'Mf' => 'Masculinity-Femininity (Maskulinitas-Feminitas)',
    'Pa' => 'Paranoia (Paranoia)',
    'Pt' => 'Psychasthenia (Psikastenia)',
    'Sc' => 'Schizophrenia (Skizofrenia)',
    'Ma' => 'Hypomania (Hipomania)',
    'Si' => 'Social Introversion (Introversi Sosial)'
] as $scale => $label) {
    $basicRows[] = pdfScaleRow($basic[$scale] ?? [], $scale . ' - ' . $label);
}

// Format supplementary rows
$suppRows = [];
foreach (['A','R','Es','MAC-R','AGGR','PSYC','DISC','NEGE','INTR','RCd','RC1','RC2','RC3','RC4','RC6','RC7','RC8','RC9'] as $scale) {
    $suppRows[] = pdfScaleRow($supp[$scale] ?? [], $scale);
}

// Format recommendations
$recommendationRows = [];
if (!empty($mmpiInterp['recommendations']) && is_array($mmpiInterp['recommendations'])) {
    foreach ($mmpiInterp['recommendations'] as $rec) {
        $rec = trim((string)$rec);
        if ($rec !== '') {
            $recommendationRows[] = $rec;
        }
    }
}
foreach (pdfTextToLines($generalRecommendations) as $line) {
    $recommendationRows[] = $line;
}
$recommendationRows = array_values(array_unique($recommendationRows));

$printDate = pdfDateIndo(time());
$resultDate = !empty($r['created_at']) ? date('d/m/Y H:i', strtotime($r['created_at'])) : '-';
$age = '';
if (!empty($r['date_of_birth'])) {
    $dob = new DateTime($r['date_of_birth']);
    $now = new DateTime();
    $age = $dob->diff($now)->y . ' tahun';
}

$reportDirAbs = realpath(__DIR__ . '/../assets/uploads');
if ($reportDirAbs === false) {
    $reportDirAbs = __DIR__ . '/../assets/uploads';
}
$reportDirAbs .= '/reports';
if (!is_dir($reportDirAbs)) {
    @mkdir($reportDirAbs, 0775, true);
}

$resultCode = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($r['result_code'] ?? ('RES' . $id)));
$pdfFilename = 'report_' . $resultCode . '.pdf';
$pdfAbs = $reportDirAbs . '/' . $pdfFilename;
$pdfRelPath = 'assets/uploads/reports/' . $pdfFilename;
$htmlFilename = 'report_' . $resultCode . '.html';
$htmlAbs = $reportDirAbs . '/' . $htmlFilename;
$htmlRelPath = 'assets/uploads/reports/' . $htmlFilename;

ob_start();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Hasil Tes Psikologi - <?php echo htmlspecialchars($resultCode); ?></title>
    <style>
        /* Reset & Base Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        @page {
            size: A4;
            margin: 2cm 1.5cm;
        }

        body {
            font-family: 'Arial', sans-serif;
            line-height: 1.5;
            color: #1e293b;
            background: #ffffff;
            font-size: 11pt;
        }

        /* Header Styles */
        .report-header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 3px solid #0f172a;
        }

        .report-title {
            font-size: 22pt;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }

        .report-subtitle {
            font-size: 12pt;
            color: #475569;
            font-weight: 400;
        }

        .report-code {
            font-size: 11pt;
            color: #64748b;
            margin-top: 8px;
            font-family: 'Courier New', monospace;
        }

        /* Section Styles */
        .section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }

        .section-title {
            font-size: 14pt;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
            padding-bottom: 5px;
            border-bottom: 2px solid #cbd5e1;
        }

        .section-subtitle {
            font-size: 12pt;
            font-weight: 600;
            color: #334155;
            margin: 15px 0 10px;
        }

        /* Table Styles - Enhanced with Borders */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            border: 2px solid #0f172a;
            table-layout: fixed;
        }

        .data-table th {
            background-color: #f1f5f9;
            font-weight: 700;
            padding: 10px 8px;
            border: 1px solid #94a3b8;
            color: #0f172a;
            font-size: 11pt;
            text-align: left;
        }

        .data-table td {
            padding: 8px;
            border: 1px solid #cbd5e1;
            vertical-align: top;
        }

        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .data-table tr:hover {
            background-color: #f1f5f9;
        }

        /* Meta Table Styles */
        .meta-table th {
            width: 20%;
            background-color: #e2e8f0;
        }

        .meta-table td {
            width: 30%;
        }

        /* Numeric Cell Alignment */
        .num {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        /* Interpretation Text */
        .interpretation-text {
            font-size: 10.5pt;
            line-height: 1.6;
            color: #1e293b;
            padding: 10px;
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
        }

        .interpretation-text p {
            margin-bottom: 8px;
        }

        .interpretation-text ul {
            margin-left: 20px;
            margin-bottom: 8px;
        }

        /* Notes Lines */
        .notes-lines {
            line-height: 1.6;
        }

        .notes-lines div {
            padding: 2px 0;
        }

        .notes-lines div + div {
            border-top: 1px dashed #e2e8f0;
            margin-top: 4px;
            padding-top: 4px;
        }

        /* Recommendation Items */
        .recommendation-item {
            padding: 6px 0;
            border-bottom: 1px dotted #cbd5e1;
        }

        .recommendation-item:last-child {
            border-bottom: none;
        }

        .recommendation-number {
            display: inline-block;
            width: 24px;
            height: 24px;
            background-color: #0f172a;
            color: white;
            text-align: center;
            line-height: 24px;
            border-radius: 50%;
            font-size: 10pt;
            font-weight: 700;
            margin-right: 8px;
        }

        /* Muted Text */
        .muted {
            color: #64748b;
            font-style: italic;
        }

        /* Signature Section */
        .signature-section {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #0f172a;
        }

        .signature-box {
            width: 50%;
            margin-left: auto;
            text-align: center;
        }

        .signature-date {
            font-weight: 600;
            margin-bottom: 20px;
        }

        .signature-label {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .signature-line {
            margin: 15px 0 5px;
            font-size: 16pt;
            letter-spacing: 4px;
        }

        .signature-name {
            font-weight: 600;
            margin-top: 5px;
        }

        /* Footer Note */
        .footer-note {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #cbd5e1;
            font-size: 9pt;
            color: #64748b;
            text-align: center;
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 9pt;
            font-weight: 600;
            background-color: #e2e8f0;
            color: #1e293b;
        }

        .badge-success {
            background-color: #dcfce7;
            color: #166534;
        }

        .badge-warning {
            background-color: #fef9c3;
            color: #854d0e;
        }

        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }

        /* Page Break */
        .page-break {
            page-break-before: always;
        }

        /* ADHD Specific */
        .adhd-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .adhd-item {
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
        }

        .adhd-label {
            font-weight: 600;
            color: #475569;
            margin-bottom: 4px;
        }

        .adhd-value {
            font-size: 14pt;
            font-weight: 700;
            color: #0f172a;
        }

        /* Print Styles */
        @media print {
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .data-table {
                border: 2px solid #000;
            }
            
            .data-table th {
                background-color: #f0f0f0 !important;
            }
            
            .data-table td {
                border: 1px solid #000;
            }
            
            .badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="report-header">
        <h1 class="report-title">LAPORAN HASIL TES PSIKOLOGI</h1>
        <div class="report-subtitle">Sistem Asesmen Psikologi Terintegrasi</div>
        <div class="report-code">Kode Laporan: <?php echo htmlspecialchars($resultCode); ?></div>
    </div>

    <!-- Informasi Dasar -->
    <div class="section">
        <h2 class="section-title">A. INFORMASI DASAR</h2>
        <table class="data-table meta-table">
            <tbody>
                <tr>
                    <th>Nama Lengkap</th>
                    <td colspan="3"><strong><?php echo htmlspecialchars($r['full_name'] ?? '-'); ?></strong></td>
                </tr>
                <tr>
                    <th>Jenis Kelamin</th>
                    <td><?php echo htmlspecialchars($r['gender'] ?? '-'); ?></td>
                    <th>Usia</th>
                    <td><?php echo htmlspecialchars($age ?: '-'); ?></td>
                </tr>
                <tr>
                    <th>Paket Tes</th>
                    <td><?php echo htmlspecialchars($r['package_name'] ?? '-'); ?></td>
                    <th>Kode Sesi</th>
                    <td><?php echo htmlspecialchars($r['session_code'] ?? '-'); ?></td>
                </tr>
                <tr>
                    <th>Tanggal Hasil</th>
                    <td><?php echo htmlspecialchars($resultDate); ?></td>
                    <th>Tanggal Cetak</th>
                    <td><?php echo htmlspecialchars($printDate); ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Skala Validitas -->
    <div class="section">
        <h2 class="section-title">B. SKALA VALIDITAS</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th width="25%">Skala</th>
                    <th width="25%" class="num">Raw Score</th>
                    <th width="25%" class="num">T-Score</th>
                    <th width="25%" class="num">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($validityRows as $row): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                        <td class="num"><?php echo htmlspecialchars($row['raw']); ?></td>
                        <td class="num"><?php echo htmlspecialchars($row['t']); ?></td>
                        <td class="num">
                            <?php 
                            $tVal = floatval($row['t']);
                            if ($tVal >= 70) {
                                echo '<span class="badge badge-warning">Tinggi</span>';
                            } elseif ($tVal <= 40) {
                                echo '<span class="badge badge-danger">Rendah</span>';
                            } else {
                                echo '<span class="badge">Normal</span>';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Skala Dasar MMPI -->
    <div class="section">
        <h2 class="section-title">C. SKALA DASAR MMPI</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th width="40%">Skala</th>
                    <th width="15%" class="num">Raw</th>
                    <th width="15%" class="num">T-Score</th>
                    <th width="30%">Interpretasi Klinis</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($basicRows as $row): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                        <td class="num"><?php echo htmlspecialchars($row['raw']); ?></td>
                        <td class="num"><?php echo htmlspecialchars($row['t']); ?></td>
                        <td><?php echo htmlspecialchars($row['interpretation']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Skala Tambahan -->
    <?php if (!empty($suppRows)): ?>
    <div class="section">
        <h2 class="section-title">D. SKALA TAMBAHAN (SUPPLEMENTARY)</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th width="25%">Skala</th>
                    <th width="15%" class="num">Raw</th>
                    <th width="15%" class="num">T-Score</th>
                    <th width="45%">Interpretasi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($suppRows as $row): ?>
                    <?php if ($row['raw'] !== '-' || $row['t'] !== '-'): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                        <td class="num"><?php echo htmlspecialchars($row['raw']); ?></td>
                        <td class="num"><?php echo htmlspecialchars($row['t']); ?></td>
                        <td><?php echo htmlspecialchars($row['interpretation']); ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Screening ADHD -->
    <?php if (!empty($adhd) && isset($adhd['total'])): ?>
    <div class="section">
        <h2 class="section-title">E. SCREENING ADHD</h2>
        <table class="data-table">
            <tbody>
                <tr>
                    <th width="30%">Total Skor</th>
                    <td width="20%" class="num"><strong><?php echo htmlspecialchars(pdfValue($adhd['total'] ?? '-')); ?></strong></td>
                    <th width="20%">Severity</th>
                    <td width="30%">
                        <?php 
                        $severity = pdfValue($adhd['severity'] ?? '-');
                        $badgeClass = 'badge';
                        if ($severity == 'Ringan') $badgeClass = 'badge-success';
                        elseif ($severity == 'Sedang') $badgeClass = 'badge-warning';
                        elseif ($severity == 'Berat') $badgeClass = 'badge-danger';
                        ?>
                        <span class="<?php echo $badgeClass; ?>"><?php echo htmlspecialchars($severity); ?></span>
                    </td>
                </tr>
                <tr>
                    <th>Diagnosis</th>
                    <td colspan="3"><?php echo htmlspecialchars(pdfValue($adhd['diagnosis'] ?? '-')); ?></td>
                </tr>
                <tr>
                    <th>Inattention</th>
                    <td class="num"><?php echo htmlspecialchars(pdfValue($adhd['inattention'] ?? '-')); ?></td>
                    <th>Hyperactivity</th>
                    <td class="num"><?php echo htmlspecialchars(pdfValue($adhd['hyperactivity'] ?? '-')); ?></td>
                </tr>
                <tr>
                    <th>Impulsivity</th>
                    <td class="num"><?php echo htmlspecialchars(pdfValue($adhd['impulsivity'] ?? '-')); ?></td>
                    <th>Kategori</th>
                    <td><?php echo htmlspecialchars(pdfValue($adhd['category'] ?? '-')); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Interpretasi Hasil -->
    <div class="section">
        <h2 class="section-title">F. INTERPRETASI HASIL</h2>
        
        <!-- Pola Validitas -->
        <h3 class="section-subtitle">1. Pola Validitas</h3>
        <div class="interpretation-text">
            <?php $validityLines = pdfTextToLines($mmpiInterp['validity'] ?? ''); ?>
            <?php if (!empty($validityLines)): ?>
                <?php foreach ($validityLines as $line): ?>
                    <p><?php echo htmlspecialchars($line); ?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted">Tidak ada interpretasi validitas yang tersedia.</p>
            <?php endif; ?>
        </div>

        <!-- Profil Klinis -->
        <h3 class="section-subtitle">2. Profil Klinis</h3>
        <div class="interpretation-text">
            <?php $profileLines = pdfTextToLines($mmpiInterp['profile'] ?? ''); ?>
            <?php if (!empty($profileLines)): ?>
                <?php foreach ($profileLines as $line): ?>
                    <p><?php echo htmlspecialchars($line); ?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted">Tidak ada interpretasi profil klinis yang tersedia.</p>
            <?php endif; ?>
        </div>

        <!-- Skala Dasar -->
        <h3 class="section-subtitle">3. Analisis Skala Dasar</h3>
        <div class="interpretation-text">
            <?php $clinicalLines = pdfTextToLines($mmpiInterp['clinical'] ?? ''); ?>
            <?php if (!empty($clinicalLines)): ?>
                <?php foreach ($clinicalLines as $line): ?>
                    <p><?php echo htmlspecialchars($line); ?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted">Tidak ada analisis skala dasar yang tersedia.</p>
            <?php endif; ?>
        </div>

        <!-- Interpretasi ADHD -->
        <?php if (!empty($adhdInterpretationText)): ?>
        <h3 class="section-subtitle">4. Interpretasi ADHD</h3>
        <div class="interpretation-text">
            <?php $adhdLines = pdfTextToLines($adhdInterpretationText); ?>
            <?php if (!empty($adhdLines)): ?>
                <?php foreach ($adhdLines as $line): ?>
                    <p><?php echo htmlspecialchars($line); ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Kesimpulan Umum -->
        <h3 class="section-subtitle">5. Kesimpulan Umum</h3>
        <div class="interpretation-text">
            <?php $overallLines = pdfTextToLines($overallInterpretation); ?>
            <?php if (!empty($overallLines)): ?>
                <?php foreach ($overallLines as $line): ?>
                    <p><?php echo htmlspecialchars($line); ?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted">Tidak ada kesimpulan umum yang tersedia.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rekomendasi -->
    <div class="section">
        <h2 class="section-title">G. REKOMENDASI</h2>
        <div class="interpretation-text">
            <?php if (!empty($recommendationRows)): ?>
                <?php foreach ($recommendationRows as $index => $rec): ?>
                    <div class="recommendation-item">
                        <span class="recommendation-number"><?php echo $index + 1; ?></span>
                        <?php echo htmlspecialchars($rec); ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted">Tidak ada rekomendasi yang tersedia.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tanda Tangan -->
    <div class="signature-section">
        <div class="signature-box">
            <div class="signature-date">Singkawang, <?php echo htmlspecialchars($printDate); ?></div>
            <div class="signature-label">Psikolog / Pemeriksa</div>
            <div class="signature-line">_________________________</div>
            <div class="signature-name">( <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrator'); ?> )</div>
            <div style="margin-top: 5px; font-size: 9pt; color: #64748B;">SIP: 12345/PSI/2024</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer-note">
        <p>Dokumen ini dihasilkan secara otomatis oleh sistem asesmen psikologi terintegrasi.</p>
        <p>Laporan ini bersifat rahasia dan hanya untuk kepentingan profesional.</p>
        <p>Dicetak pada: <?php echo date('d/m/Y H:i:s'); ?> WIB</p>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

$generatedPath = '';
$generatedAbs = '';

// 1) Try true PDF generation via LibreOffice headless.
$tmpHtml = tempnam(sys_get_temp_dir(), 'mmpi_report_');
if ($tmpHtml !== false) {
    $tmpHtmlFile = $tmpHtml . '.html';
    @rename($tmpHtml, $tmpHtmlFile);
    file_put_contents($tmpHtmlFile, $html);

    $cmd = 'export HOME=/tmp && libreoffice --headless --convert-to pdf --outdir ' .
        escapeshellarg($reportDirAbs) . ' ' . escapeshellarg($tmpHtmlFile) . ' 2>&1';
    $out = [];
    $rc = 1;
    @exec($cmd, $out, $rc);

    $convertedPdf = $reportDirAbs . '/' . pathinfo($tmpHtmlFile, PATHINFO_FILENAME) . '.pdf';
    if ($rc === 0 && is_file($convertedPdf)) {
        @rename($convertedPdf, $pdfAbs);
        if (is_file($pdfAbs)) {
            $generatedPath = $pdfRelPath;
            $generatedAbs = $pdfAbs;
        }
    }

    @unlink($tmpHtmlFile);
}

// 2) Fallback: store printable HTML when PDF conversion is not available.
if ($generatedPath === '') {
    file_put_contents($htmlAbs, $html);
    $generatedPath = $htmlRelPath;
    $generatedAbs = $htmlAbs;
}

$up = $db->prepare("UPDATE test_results SET pdf_file_path = ?, pdf_generated_at = NOW(), updated_at = NOW() WHERE id = ?");
$up->execute([$generatedPath, $id]);

$ext = strtolower(pathinfo($generatedAbs, PATHINFO_EXTENSION));
logActivity((int)($_SESSION['user_id'] ?? 0), 'pdf_generated', "Generated report {$ext} for result {$resultCode} (ID: {$id}) at {$generatedPath}");

if (file_exists($generatedAbs)) {
    $mime = ($ext === 'pdf') ? 'application/pdf' : 'text/html';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . basename($generatedAbs) . '"');
    header('Content-Length: ' . filesize($generatedAbs));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Clear output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    readfile($generatedAbs);
    exit;
} else {
    http_response_code(404);
    echo "Dokumen gagal digenerate atau tidak ditemukan di server.";
    exit;
}
?>