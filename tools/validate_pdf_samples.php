<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/scoring_functions.php';

$db = getDB();

// Expected values extracted from sample PDFs.
$samples = [
    'KRISMANDA_PUTRA_MALE' => [
        'gender' => 'male',
        'k_raw' => 24,
        'basic' => [
            'L' => ['raw' => 13, 't' => 92],
            'F' => ['raw' => 8, 't' => 61],
            'K' => ['raw' => 24, 't' => 68],
            'Hs' => ['raw' => 6, 't' => 33],
            'D' => ['raw' => 24, 't' => 62],
            'Hy' => ['raw' => 29, 't' => 69],
            'Pd' => ['raw' => 17, 't' => 38],
            'Mf' => ['raw' => 21, 't' => 40],
            'Pa' => ['raw' => 10, 't' => 49],
            'Pt' => ['raw' => 6, 't' => 30],
            'Sc' => ['raw' => 8, 't' => 30],
            'Ma' => ['raw' => 11, 't' => 33],
            'Si' => ['raw' => 25, 't' => 50],
        ],
    ],
    'CELINE_ANGELY_FEMALE' => [
        'gender' => 'female',
        'k_raw' => 24,
        'basic' => [
            'L' => ['raw' => 11, 't' => 86],
            'F' => ['raw' => 5, 't' => 81],
            'K' => ['raw' => 24, 't' => 70],
            'Hs' => ['raw' => 7, 't' => 33],
            'D' => ['raw' => 20, 't' => 49],
            'Hy' => ['raw' => 26, 't' => 58],
            'Pd' => ['raw' => 15, 't' => 36],
            'Mf' => ['raw' => 30, 't' => 65],
            'Pa' => ['raw' => 13, 't' => 59],
            'Pt' => ['raw' => 7, 't' => 30],
            'Sc' => ['raw' => 7, 't' => 30],
            'Ma' => ['raw' => 13, 't' => 37],
            'Si' => ['raw' => 20, 't' => 43],
        ],
    ],
];

function line($s = '') { echo $s . PHP_EOL; }
function applyKCorrectionRaw($scale, $raw, $kRaw) {
    if ($scale === 'Hs') return (int)round($raw + (0.5 * $kRaw));
    if ($scale === 'Pd') return (int)round($raw + (0.4 * $kRaw));
    if ($scale === 'Pt') return (int)round($raw + (1.0 * $kRaw));
    if ($scale === 'Sc') return (int)round($raw + (1.0 * $kRaw));
    if ($scale === 'Ma') return (int)round($raw + (0.2 * $kRaw));
    return (int)$raw;
}

$totalScaleCount = 0;
$totalAbsDiff = 0;
$totalExact = 0;
$allWithin5 = 0;

line('MMPI PDF Sample Validation');
line('Date: ' . date('Y-m-d H:i:s'));
line(str_repeat('-', 72));

foreach ($samples as $name => $sample) {
    $gender = $sample['gender'];
    $kRaw = (int)($sample['k_raw'] ?? 0);
    $sumAbs = 0;
    $cnt = 0;
    $exact = 0;
    $within5 = 0;

    line("Sample: $name ($gender)");
    line('Scale | Raw | CorrRaw | ExpectedT | ActualT | Diff');

    foreach ($sample['basic'] as $scale => $v) {
        $raw = (int)$v['raw'];
        $expectedT = (int)$v['t'];
        $corrRaw = applyKCorrectionRaw($scale, $raw, $kRaw);
        $actualT = (int)calculateTScoreForScale($scale, $corrRaw, $gender, $db);
        $diff = $actualT - $expectedT;
        $abs = abs($diff);

        if ($abs === 0) $exact++;
        if ($abs <= 5) $within5++;

        $sumAbs += $abs;
        $cnt++;

        line(sprintf('%-5s | %-3d | %-7d | %-9d | %-7d | %+d', $scale, $raw, $corrRaw, $expectedT, $actualT, $diff));
    }

    $mae = $cnt > 0 ? round($sumAbs / $cnt, 2) : 0;
    line("Summary: exact=$exact/$cnt, within5=$within5/$cnt, MAE=$mae");
    line(str_repeat('-', 72));

    $totalScaleCount += $cnt;
    $totalAbsDiff += $sumAbs;
    $totalExact += $exact;
    $allWithin5 += $within5;
}

$overallMae = $totalScaleCount > 0 ? round($totalAbsDiff / $totalScaleCount, 2) : 0;
line('OVERALL');
line("Exact match: $totalExact/$totalScaleCount");
line("Within ±5: $allWithin5/$totalScaleCount");
line("MAE: $overallMae");
