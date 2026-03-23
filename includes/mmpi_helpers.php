<?php
// includes/mmpi_helpers.php
// Helper functions untuk MMPI scoring

/**
 * Get MMPI scale names with descriptions
 */
if (!function_exists('getMMPIScaleInfo')) {
    function getMMPIScaleInfo($scale) {
        $scales = [
            'L' => ['name' => 'Lie Scale', 'description' => 'Mengukur kecenderungan untuk menggambarkan diri secara positif'],
            'F' => ['name' => 'Infrequency Scale', 'description' => 'Mengukur respons tidak biasa atau acak'],
            'K' => ['name' => 'Defensiveness Scale', 'description' => 'Mengukur sikap defensif atau penolakan'],
            'Hs' => ['name' => 'Hypochondriasis', 'description' => 'Kekhawatiran berlebihan tentang kesehatan'],
            'D' => ['name' => 'Depression', 'description' => 'Gejala depresi dan kesedihan'],
            'Hy' => ['name' => 'Hysteria', 'description' => 'Kecenderungan somatisasi dan represi'],
            'Pd' => ['name' => 'Psychopathic Deviate', 'description' => 'Masalah sosial dan perilaku antisosial'],
            'Mf' => ['name' => 'Masculinity-Femininity', 'description' => 'Minat dan perilaku gender'],
            'Pa' => ['name' => 'Paranoia', 'description' => 'Kecurigaan dan pemikiran paranoid'],
            'Pt' => ['name' => 'Psychasthenia', 'description' => 'Kecemasan, obsesi, dan kompulsi'],
            'Sc' => ['name' => 'Schizophrenia', 'description' => 'Pemikiran tidak biasa dan isolasi sosial'],
            'Ma' => ['name' => 'Hypomania', 'description' => 'Tingkat energi dan aktivitas yang berlebihan'],
            'Si' => ['name' => 'Social Introversion', 'description' => 'Kecenderungan menarik diri dari sosial']
        ];
        
        return $scales[$scale] ?? ['name' => $scale, 'description' => ''];
    }
}



/**
 * Generate profile summary
 */


/**
 * Validate test profile
 */
if (!function_exists('validateProfile')) {
    function validateProfile($validityScores) {
        $warnings = [];
        
        $L = $validityScores['L'] ?? 0;
        $F = $validityScores['F'] ?? 0;
        $K = $validityScores['K'] ?? 0;
        
        // Check F scale
        if ($F > 25) {
            $warnings[] = "Skor F sangat tinggi (>25): Profil mungkin tidak valid.";
        } elseif ($F > 20) {
            $warnings[] = "Skor F tinggi (>20): Perhatikan kemungkinan distorsi.";
        }
        
        // Check L scale
        if ($L > 8) {
            $warnings[] = "Skor L tinggi (>8): Kecenderungan untuk terlihat baik (faking good).";
        }
        
        return implode("\n", $warnings);
    }
}

// --- PASTE KODE LOGIKA JB 2018 DI BAWAH SINI ---
// (Kode hitungIndeksJB2018 yang saya berikan sebelumnya)

if (!function_exists('hitungIndeksJB2018')) {
    function hitungIndeksJB2018($tScores) {
        // ... (Isi fungsi JB 2018 dari chat sebelumnya) ...
        // Helper ambil nilai T
        $getT = function($scale) use ($tScores) {
            return $tScores[$scale]['t'] ?? 50;
        };

        // 1. POTENSI KINERJA
        $potensiRaw = ($getT('K') + (120 - $getT('D')) + (120 - $getT('Pt'))) / 3;
        $potensi = min(10, max(0, $potensiRaw / 10));

        // 2. KEMAMPUAN ADAPTASI
        $adaptasiRaw = ((120 - $getT('Si')) + (120 - $getT('Sc'))) / 2;
        $adaptasi = min(10, max(0, $adaptasiRaw / 10));

        // 3. KENDALA PSIKOLOGIS
        $kendalaRaw = ($getT('Hs') + $getT('D') + $getT('Hy')) / 3;
        $kendala = min(10, max(0, $kendalaRaw / 10));

        // 4. INTEGRITAS MORAL
        $L_val = $getT('L');
        if ($L_val > 70) $L_val = 120 - $L_val; 
        
        $moralRaw = ($L_val + (120 - $getT('Pd'))) / 2;
        $moral = min(10, max(0, $moralRaw / 10));

        // Rata-rata Indeks
        $avg = ($potensi + $adaptasi + $moral + (10 - $kendala)) / 4;

        return [
            'potensi_kinerja' => number_format($potensi, 1),
            'adaptasi' => number_format($adaptasi, 1),
            'kendala_psikologis' => number_format($kendala, 1),
            'integritas_moral' => number_format($moral, 1),
            'average' => number_format($avg, 1),
            'kesimpulan' => ($avg >= 6.0) ? "DIREKOMENDASIKAN" : "TIDAK DIREKOMENDASIKAN"
        ];
    }
}
?>