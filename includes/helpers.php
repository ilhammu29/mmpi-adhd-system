<?php
// ../includes/helpers.php

// Helper function to get score category
function getScoreCategory($tScore) {
    if ($tScore >= 70) return 'elevated';
    if ($tScore >= 60) return 'high_normal';
    if ($tScore >= 40) return 'average';
    if ($tScore >= 30) return 'low_normal';
    return 'low';
}

// Helper function to get category color
function getCategoryColor($category) {
    $colors = [
        'elevated' => '#e74c3c',
        'high_normal' => '#f39c12',
        'average' => '#2ecc71',
        'low_normal' => '#3498db',
        'low' => '#9b59b6'
    ];
    return $colors[$category] ?? '#95a5a6';
}

// Helper function to get category label
function getCategoryLabel($category) {
    $labels = [
        'elevated' => 'Elevated',
        'high_normal' => 'High Normal',
        'average' => 'Average',
        'low_normal' => 'Low Normal',
        'low' => 'Low'
    ];
    return $labels[$category] ?? 'Unknown';
}

// Helper function to get scale description
function getScaleDescription($scale) {
    $descriptions = [
        // Basic Scales
        'L' => 'Lie Scale (Skala Kebohongan)',
        'F' => 'Infrequency Scale (Skala Ketidaknormalan)',
        'K' => 'Defensiveness Scale (Skala Pertahanan)',
        'Hs' => 'Hypochondriasis (Kekhawatiran Kesehatan)',
        'D' => 'Depression (Depresi)',
        'Hy' => 'Hysteria (Histeria)',
        'Pd' => 'Psychopathic Deviate (Deviasi Psikopatik)',
        'Mf' => 'Masculinity-Femininity (Maskulinitas-Feminitas)',
        'Pa' => 'Paranoia (Paranoid)',
        'Pt' => 'Psychasthenia (Psikastenia)',
        'Sc' => 'Schizophrenia (Skizofrenia)',
        'Ma' => 'Hypomania (Hipomania)',
        'Si' => 'Social Introversion (Introversi Sosial)',
        
        // Content Scales
        'ANX' => 'Anxiety (Kecemasan)',
        'FRS' => 'Fears (Ketakutan)',
        'OBS' => 'Obsessiveness (Obsesif)',
        'DEP' => 'Depression (Depresi)',
        'HEA' => 'Health Concerns (Kekhawatiran Kesehatan)',
        'BIZ' => 'Bizarre Mentation (Pemikiran Aneh)',
        'ANG' => 'Anger (Kemarahan)',
        'CYN' => 'Cynicism (Sinisme)',
        'ASP' => 'Antisocial Practices (Praktik Antisosial)',
        'TPA' => 'Type A (Tipe A)',
        'LSE' => 'Low Self-Esteem (Harga Diri Rendah)',
        'SOD' => 'Social Discomfort (Ketidaknyamanan Sosial)',
        'FAM' => 'Family Problems (Masalah Keluarga)',
        'WRK' => 'Work Interference (Gangguan Kerja)',
        'TRT' => 'Negative Treatment Indicators (Indikator Negatif Perawatan)',
        
        // Supplementary Scales
        'A' => 'Anxiety',
        'R' => 'Repression',
        'Es' => 'Ego Strength',
        'Do' => 'Dominance',
        'Re' => 'Responsibility',
        'Mt' => 'College Maladjustment',
        'PK' => 'Post-traumatic Stress Disorder',
        'MDS' => 'Marital Distress Scale',
        'Ho' => 'Hostility',
        'OH' => 'Overcontrolled Hostility',
        'MAC-R' => 'MacAndrew Alcoholism Scale-Revised',
        'AAS' => 'Addiction Acknowledgment Scale',
        'APS' => 'Addiction Potential Scale',
        
        // Harris-Lingoes Subscales
        'D1' => 'Subjective Depression',
        'D2' => 'Psychomotor Retardation',
        'D3' => 'Physical Malfunctioning',
        'D4' => 'Mental Dullness',
        'D5' => 'Brooding',
        'Hy1' => 'Denial of Social Anxiety',
        'Hy2' => 'Need for Affection',
        'Hy3' => 'Lassitude-Malaise',
        'Hy4' => 'Somatic Complaints',
        'Hy5' => 'Inhibition of Aggression',
        'Pd1' => 'Familial Discord',
        'Pd2' => 'Authority Problems',
        'Pd3' => 'Social Imperturbability',
        'Pd4' => 'Social Alienation',
        'Pd5' => 'Self-Alienation',
        'Pa1' => 'Persecutory Ideas',
        'Pa2' => 'Poignancy',
        'Pa3' => 'Naivete',
        'Sc1' => 'Social Alienation',
        'Sc2' => 'Emotional Alienation',
        'Sc3' => 'Lack of Ego Mastery, Cognitive',
        'Sc4' => 'Lack of Ego Mastery, Conative',
        'Sc5' => 'Lack of Ego Mastery, Defective Inhibition',
        'Sc6' => 'Bizarre Sensory Experiences',
        'Ma1' => 'Amorality',
        'Ma2' => 'Psychomotor Acceleration',
        'Ma3' => 'Imperturbability',
        'Ma4' => 'Ego Inflation',
        'Si1' => 'Shyness/Self-Consciousness',
        'Si2' => 'Social Avoidance',
        'Si3' => 'Alienation-Self and Others',
    ];
    return $descriptions[$scale] ?? $scale;
}

// Helper function to calculate age from date of birth
function calculateAge($dateOfBirth) {
    if (!$dateOfBirth || $dateOfBirth == '0000-00-00' || $dateOfBirth == '0000-00-00 00:00:00') return '-';
    
    try {
        $birthDate = new DateTime($dateOfBirth);
        $today = new DateTime();
        $age = $today->diff($birthDate);
        return $age->y;
    } catch (Exception $e) {
        return '-';
    }
}

// Helper function to format duration
function formatDuration($start, $end) {
    if (!$start || !$end || $start == '0000-00-00 00:00:00' || $end == '0000-00-00 00:00:00') return '-';
    
    try {
        $startTime = strtotime($start);
        $endTime = strtotime($end);
        if ($startTime === false || $endTime === false) return '-';
        
        $duration = $endTime - $startTime;
        if ($duration < 0) return '-';
        
        $hours = floor($duration / 3600);
        $minutes = floor(($duration % 3600) / 60);
        
        if ($hours > 0) {
            return $hours . ' jam ' . $minutes . ' menit';
        }
        return $minutes . ' menit';
    } catch (Exception $e) {
        return '-';
    }
}

// Check if function exists before declaring
if (!function_exists('getScoreCategory')) {
    function getScoreCategory($tScore) {
        // ... (sama seperti di atas)
    }
}

if (!function_exists('getCategoryColor')) {
    function getCategoryColor($category) {
        // ... (sama seperti di atas)
    }
}

// ... (lakukan untuk semua fungsi)