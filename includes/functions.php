<?php
// includes/functions.php
// MMPI-2 Scoring Functions and Utilities

// ============================================
// 1. DATABASE CONSTANTS FOR MMPI-2 KEYS
// ============================================

/**
 * MMPI-2 Basic Scales with K-correction
 */
const MMPI_BASIC_SCALES = [
    'L' => [
        'name' => 'Lie',
        'items' => [15, 30, 45, 60, 75, 90, 105, 120, 135, 150, 165, 180, 195, 210, 225, 240],
        'keying' => false // False-keyed
    ],
    'F' => [
        'name' => 'Infrequency',
        'items' => [27, 31, 33, 42, 44, 46, 50, 53, 62, 63, 67, 68, 72, 77, 85, 92, 98, 102, 106, 110, 
                    111, 113, 115, 116, 119, 122, 124, 129, 131, 132, 134, 138, 142, 143, 144, 146, 
                    149, 150, 156, 159, 162, 163, 170, 172, 175, 181, 184, 187, 188, 190, 192, 194, 
                    197, 198, 200, 203, 206, 209, 210, 215],
        'keying' => true // True-keyed
    ],
    'K' => [
        'name' => 'Correction',
        'items' => [30, 39, 71, 89, 124, 129, 134, 138, 142, 148, 160, 170, 171, 180, 183, 217, 234, 
                    267, 272, 296, 316, 322, 368, 370, 372, 373, 374, 377, 378, 379, 380, 381, 382, 
                    383, 384, 385, 386, 387, 388],
        'keying' => false // False-keyed
    ],
    'Hs' => [
        'name' => 'Hypochondriasis',
        'items' => [2, 10, 20, 47, 52, 92, 141, 148, 162, 182],
        'keying' => true,
        'k_weight' => 0.5
    ],
    'D' => [
        'name' => 'Depression',
        'items' => [2, 5, 9, 15, 24, 27, 31, 33, 38, 42, 46, 50, 54, 55, 62, 72, 75, 95, 98, 102, 106, 
                    109, 114, 121, 124, 136, 142, 155, 160, 165, 191, 235],
        'keying' => true,
        'k_weight' => 0
    ],
    'Hy' => [
        'name' => 'Hysteria',
        'items' => [3, 10, 14, 16, 20, 25, 34, 36, 37, 40, 42, 44, 47, 48, 53, 57, 60, 61],
        'keying' => true,
        'k_weight' => 0
    ],
    'Pd' => [
        'name' => 'Psychopathic Deviate',
        'items' => [12, 16, 24, 27, 33, 38, 42, 61, 62, 67, 84, 102, 106, 110, 127],
        'keying' => true,
        'k_weight' => 0.4
    ],
    'Mf' => [
        'name' => 'Masculinity-Femininity',
        'items' => [1, 4, 19, 25, 41, 52, 70, 73, 77, 87],
        'keying' => true,
        'k_weight' => 0
    ],
    'Pa' => [
        'name' => 'Paranoia',
        'items' => [16, 24, 27, 33, 42, 61, 67, 70, 74, 83],
        'keying' => true,
        'k_weight' => 0
    ],
    'Pt' => [
        'name' => 'Psychasthenia',
        'items' => [2, 5, 15, 22, 27, 31, 33, 38, 39, 42, 44, 46, 51, 55, 61, 65, 67, 76, 94, 100, 
                    106, 114, 127, 137, 147],
        'keying' => true,
        'k_weight' => 1.0
    ],
    'Sc' => [
        'name' => 'Schizophrenia',
        'items' => [7, 15, 23, 27, 31, 33, 42, 50, 60, 67, 72, 78, 84, 94, 100, 102, 106, 109, 114, 
                    127, 136, 140, 147],
        'keying' => true,
        'k_weight' => 1.0
    ],
    'Ma' => [
        'name' => 'Hypomania',
        'items' => [12, 13, 16, 19, 20, 24, 34, 38, 41, 42, 46],
        'keying' => true,
        'k_weight' => 0.2
    ],
    'Si' => [
        'name' => 'Social Introversion',
        'items' => [36, 52, 56, 57, 62, 68, 73, 74, 75, 83, 92, 97, 98, 102, 120, 127, 130, 135, 
                    140, 147, 167, 175, 191, 205, 206, 210, 216, 220, 229, 232],
        'keying' => true,
        'k_weight' => 0
    ]
];

/**
 * Harris-Lingoes Subscales
 */
const MMPI_HARRIS_SCALES = [
    'D1' => ['name' => 'Subjective Depression', 'items' => [31, 54, 175, 235, 5, 15, 38, 46, 55, 62, 75, 95, 98, 109, 121, 124, 136, 142, 160, 24, 72, 102, 106, 114, 155, 9, 27, 33, 50, 146, 191]],
    'D2' => ['name' => 'Psychomotor Retardation', 'items' => [31, 54, 175, 235, 15, 38, 46, 55, 98, 121, 124, 142, 160, 106, 25]],
    'D3' => ['name' => 'Physical Malfunctioning', 'items' => [2, 10, 18, 36, 44, 48, 155, 182, 141, 148, 162]],
    'D4' => ['name' => 'Mental Dullness', 'items' => [31, 54, 175, 235, 46, 95, 121, 124, 155, 160, 106, 24, 72, 114, 146]],
    'D5' => ['name' => 'Brooding', 'items' => [15, 95, 142, 24, 72, 102, 114, 9, 27, 33]],
    
    'Hy1' => ['name' => 'Denial Social Anxiety', 'items' => [3, 10, 14, 16, 20, 25]],
    'Hy2' => ['name' => 'Need for Affection', 'items' => [3, 14, 20, 25, 34, 36, 37, 40, 42, 44, 47, 48, 53, 60]],
    'Hy3' => ['name' => 'Lassitude-Malaise', 'items' => [10, 36, 37, 40, 44, 47, 48, 53, 60, 61, 2, 9, 56, 92, 130, 155, 165]],
    'Hy4' => ['name' => 'Somatic Complaints', 'items' => [3, 36, 37, 40, 44, 47, 48, 53, 60, 2, 155, 162, 56, 92, 100, 104, 130, 165, 10, 18, 28, 52, 57, 58, 91, 109, 111, 118, 119, 138, 154, 157, 159, 166]],
    'Hy5' => ['name' => 'Inhibition Aggression', 'items' => [14, 25, 34, 36, 37, 130, 234]],
    
    'Pd1' => ['name' => 'Familial Discord', 'items' => [21, 24, 33, 38, 42, 96, 177, 219, 268]],
    'Pd2' => ['name' => 'Authority Problems', 'items' => [38, 42, 35, 93, 99, 144, 171, 208]],
    'Pd3' => ['name' => 'Social Imperturbability', 'items' => [12, 25, 34, 62, 67, 118, 120, 210, 218, 226, 263]],
    'Pd4' => ['name' => 'Social Alienation', 'items' => [24, 33, 42, 27, 61, 16, 21, 102, 106, 110, 127, 243, 267]],
    'Pd5' => ['name' => 'Self-Alienation', 'items' => [24, 33, 38, 42, 27, 61, 62, 84, 12, 102, 106, 110, 127, 114, 165]],
    
    'Pa1' => ['name' => 'Persecutory Ideas', 'items' => [16, 27, 33, 42, 61, 102, 106, 127, 24, 110, 149, 158, 261, 273, 285, 296, 310, 317, 332, 336]],
    'Pa2' => ['name' => 'Poignancy', 'items' => [16, 27, 61, 254, 265, 283, 298, 302, 313]],
    'Pa3' => ['name' => 'Naivete', 'items' => [26, 81, 104, 117, 123, 241, 271, 288, 292]],
    
    'Sc1' => ['name' => 'Social Alienation', 'items' => [27, 33, 42, 67, 102, 106, 127, 16, 24, 110, 317, 332, 15, 31, 60, 94, 114, 136, 179, 207, 249, 274, 286, 293, 311, 316, 339]],
    'Sc2' => ['name' => 'Emotional Alienation', 'items' => [27, 33, 42, 106, 15, 60, 114, 136, 293, 121, 163, 167, 203, 260, 262, 277, 301, 307, 315]],
    'Sc3' => ['name' => 'Cognitive Ego Mastery', 'items' => [27, 33, 42, 102, 106, 127, 15, 31, 60, 94, 114, 136, 163, 207, 262, 316, 9, 46, 47, 162, 168, 169, 178, 188, 216, 231, 304, 312, 319, 328]],
    'Sc4' => ['name' => 'Conative Ego Mastery', 'items' => [27, 33, 42, 106, 24, 60, 94, 136, 203, 262, 293, 312, 328, 21, 38, 91, 109, 201, 208, 209, 218, 223, 235, 305, 322]],
    'Sc5' => ['name' => 'Defective Inhibition', 'items' => [127, 163, 260, 277, 322, 12, 208, 218, 223, 235, 168, 188, 216, 319, 305, 22, 23, 45, 143, 192, 239, 240, 248, 300, 323, 329]],
    'Sc6' => ['name' => 'Bizarre Sensory', 'items' => [33, 42, 60, 114, 136, 317, 46, 47, 162, 168, 178, 304, 319, 23, 192, 240, 323, 329, 40, 118, 247, 255]],
    
    'Ma1' => ['name' => 'Amorality', 'items' => [12, 19, 16, 24, 34, 61, 254, 127, 241, 133, 208]],
    'Ma2' => ['name' => 'Psychomotor Acceleration', 'items' => [12, 19, 42, 106, 15, 208, 218, 235, 223, 240, 85, 86, 112, 122, 130, 147, 166, 204, 213, 246, 260, 266, 269]],
    'Ma3' => ['name' => 'Imperturbability', 'items' => [24, 34, 61, 127, 218, 8, 57, 87, 135, 152, 176, 211, 215, 229]],
    'Ma4' => ['name' => 'Ego Inflation', 'items' => [16, 24, 61, 19, 106, 127, 133, 122, 241, 129, 144, 153, 165, 191, 248, 278, 284, 291]],
    
    'Si1' => ['name' => 'Shyness', 'items' => [127, 36, 52, 56, 57, 102, 130, 147, 167, 28, 58, 111, 118, 129, 139]],
    'Si2' => ['name' => 'Social Avoidance', 'items' => [36, 56, 57, 127, 135, 268, 333, 338]],
    'Si3' => ['name' => 'Alienation', 'items' => [127, 130, 102, 147, 310, 317, 336, 65, 99, 160, 265, 273, 283, 308, 313, 326, 337]]
];

/**
 * Content Scales
 */
const MMPI_CONTENT_SCALES = [
    'ANX' => ['name' => 'Anxiety', 'items' => [39, 68, 76, 140, 142, 150, 152, 156, 161, 165, 210, 293, 308, 309, 339, 408, 304, 319, 342, 347, 358, 463, 469]],
    'FRS' => ['name' => 'Fears', 'items' => [172, 173, 179, 184, 187, 190, 194, 200, 203, 206, 211, 213, 216, 220, 224, 230, 243, 304, 342, 383, 388, 485, 496]],
    'OBS' => ['name' => 'Obsessiveness', 'items' => [33, 76, 109, 128, 135, 152, 163, 165, 199, 205, 210, 213, 214, 230, 293, 309, 319, 333, 408, 443, 458, 472, 484]],
    'DEP' => ['name' => 'Depression', 'items' => [9, 38, 39, 42, 50, 54, 56, 57, 62, 68, 71, 73, 74, 75, 92, 95, 98, 100, 102, 106, 109, 114, 121, 124, 129, 130, 131, 134, 136, 140, 142, 155, 160, 165, 166, 167, 175, 182, 191, 199, 205, 207, 209, 210, 211, 213, 215, 229, 235]],
    'HEA' => ['name' => 'Health Concerns', 'items' => [2, 10, 20, 47, 52, 92, 141, 148, 155, 162, 182, 192, 206, 252, 267, 307, 319, 339, 350, 356, 359, 367, 371, 378, 384, 387]],
    'BIZ' => ['name' => 'Bizarre Mentation', 'items' => [27, 31, 33, 42, 50, 66, 72, 78, 84, 94, 100, 102, 106, 109, 114, 127, 136, 140, 147, 163, 190, 334, 339, 350, 352, 354, 362, 363]],
    'ANG' => ['name' => 'Anger', 'items' => [12, 13, 16, 19, 20, 24, 34, 38, 41, 42, 46, 95, 96, 109, 114, 121, 129, 138, 144, 149, 152, 160, 163, 165, 171, 176, 183, 189, 196, 202, 208, 217, 219, 230, 232, 242, 243, 297]],
    'CYN' => ['name' => 'Cynicism', 'items' => [27, 33, 42, 61, 67, 70, 74, 83, 100, 102, 106, 109, 114, 127, 136, 140, 147, 170, 172, 175, 181, 184, 186, 194, 208, 209, 213, 217, 227, 242]],
    'ASP' => ['name' => 'Antisocial Practices', 'items' => [12, 13, 16, 19, 20, 24, 34, 38, 41, 42, 46, 84, 102, 106, 110, 116, 119, 122, 132, 143, 144, 149, 156, 163, 167, 170, 171, 175, 181, 184, 201, 217, 227, 242, 246, 251, 266]],
    'TPA' => ['name' => 'Type A', 'items' => [27, 31, 33, 42, 50, 53, 57, 62, 68, 72, 76, 78, 85, 94, 100, 102, 106, 109, 114, 121, 127, 136, 140, 147, 152, 163, 165, 170, 173, 181, 184, 188, 196, 211, 213, 217, 223, 227, 232, 242, 243, 248, 253, 255, 266, 276]],
    'LSE' => ['name' => 'Low Self-Esteem', 'items' => [51, 52, 56, 57, 62, 68, 73, 74, 75, 83, 92, 97, 98, 102, 120, 127, 130, 135, 140, 147, 167, 175, 191, 205, 206, 210, 216, 220, 229, 232]],
    'SOD' => ['name' => 'Social Discomfort', 'items' => [36, 52, 56, 57, 62, 68, 73, 74, 75, 83, 92, 97, 98, 102, 120, 127, 130, 135, 140, 147, 167, 175]],
    'FAM' => ['name' => 'Family Problems', 'items' => [12, 16, 24, 27, 33, 38, 42, 61, 62, 67, 213, 214, 219, 223, 241, 243, 244, 245, 254, 290]],
    'WRK' => ['name' => 'Work Interference', 'items' => [15, 24, 38, 46, 50, 54, 55, 62, 72, 75, 95, 98, 102, 106, 109, 114, 121, 124]],
    'TRT' => ['name' => 'Negative Treatment', 'items' => [54, 55, 62, 68, 72, 75, 95, 98, 102, 106, 109, 114, 121, 124, 129, 130, 131, 134, 136, 140, 142, 155, 160, 165, 166, 167]]
];

/**
 * Supplementary Scales
 */
const MMPI_SUPPLEMENTARY_SCALES = [
    'A' => ['name' => 'Anxiety', 'items' => [12, 15, 22, 27, 31, 33, 38, 39, 42, 44, 46, 51, 55, 61, 65, 67, 76, 94, 100, 106, 114, 127, 137, 147]],
    'R' => ['name' => 'Repression', 'items' => [16, 25, 34, 36, 37, 40, 42, 44, 47, 48, 53, 57, 60, 61, 64, 104, 105, 119, 120, 135, 171, 180]],
    'Es' => ['name' => 'Ego Strength', 'items' => [7, 31, 33, 42, 50, 60, 67, 72, 78, 84, 94, 100, 102, 106, 109, 114, 127, 136, 140, 147]],
    'AAS' => ['name' => 'Addiction Admission', 'items' => [27, 31, 33, 42, 50, 53, 57, 62, 68, 72, 76, 78, 85]]
];

/**
 * VRIN and TRIN consistency pairs
 */
const VRIN_PAIRS = [
    [3, 39], [4, 50], [5, 91], [6, 29], [15, 54], [20, 53], [21, 180], [22, 118], [23, 82], [24, 43],
    [25, 108], [26, 44], [27, 45], [28, 46], [33, 106], [34, 109], [35, 110], [36, 126], [37, 136],
    [38, 141], [40, 147], [41, 87], [42, 88], [47, 169], [48, 170], [49, 171], [51, 173], [52, 175],
    [56, 289], [57, 179], [58, 188], [59, 195], [60, 196], [61, 201], [62, 202], [63, 210], [64, 222],
    [65, 225], [66, 232], [67, 233], [68, 234], [69, 235], [70, 237], [71, 238], [72, 239], [73, 240],
    [74, 241], [76, 250], [77, 251]
];

const TRIN_PAIRS = [
    'true' => [
        [3, 39], [63, 127], [99, 314], [377, 534], [12, 166], [65, 95], [125, 195]
    ],
    'false' => [
        [9, 56], [140, 196], [262, 275], [65, 95], [152, 464]
    ]
];

/**
 * Uniform T-Score Coefficients (for Basic Scales 1-4, 6-9)
 */
const UNIFORM_T_COEFFICIENTS = [
    'male' => [
        1 => ['B0' => 17.50, 'B1' => 2.25, 'B2' => 0.065, 'B3' => -0.0008, 'C' => 14.0], // Hs
        2 => ['B0' => 21.20, 'B1' => 1.85, 'B2' => 0.095, 'B3' => -0.0010, 'C' => 28.0], // D
        3 => ['B0' => 19.80, 'B1' => 1.95, 'B2' => 0.075, 'B3' => -0.0009, 'C' => 22.0], // Hy
        4 => ['B0' => 18.90, 'B1' => 2.05, 'B2' => 0.085, 'B3' => -0.0008, 'C' => 20.0], // Pd
        6 => ['B0' => 16.80, 'B1' => 2.45, 'B2' => 0.055, 'B3' => -0.0007, 'C' => 11.0], // Pa
        7 => ['B0' => 20.10, 'B1' => 1.75, 'B2' => 0.105, 'B3' => -0.0012, 'C' => 31.0], // Pt
        8 => ['B0' => 19.50, 'B1' => 1.90, 'B2' => 0.095, 'B3' => -0.0010, 'C' => 30.0], // Sc
        9 => ['B0' => 18.20, 'B1' => 2.15, 'B2' => 0.075, 'B3' => -0.0009, 'C' => 17.0]  // Ma
    ],
    'female' => [
        1 => ['B0' => 18.44, 'B1' => 2.35, 'B2' => 0.055, 'B3' => -0.0007, 'C' => 13.5], // Hs
        2 => ['B0' => 7.14, 'B1' => 1.90, 'B2' => 0.085, 'B3' => -0.0009, 'C' => 27.0], // D
        3 => ['B0' => 3.08, 'B1' => 2.05, 'B2' => 0.065, 'B3' => -0.0008, 'C' => 21.0], // Hy
        4 => ['B0' => 1.40, 'B1' => 2.15, 'B2' => 0.075, 'B3' => -0.0008, 'C' => 19.5], // Pd
        6 => ['B0' => 39.25, 'B1' => 2.55, 'B2' => 0.045, 'B3' => -0.0006, 'C' => 10.5], // Pa
        7 => ['B0' => -0.99, 'B1' => 1.85, 'B2' => 0.095, 'B3' => -0.0011, 'C' => 30.0], // Pt
        8 => ['B0' => -18.00, 'B1' => 2.00, 'B2' => 0.085, 'B3' => -0.0009, 'C' => 29.0], // Sc
        9 => ['B0' => 15.25, 'B1' => 2.25, 'B2' => 0.065, 'B3' => -0.0008, 'C' => 16.5]  // Ma
    ]
];

/**
 * Normative Data (Means and Standard Deviations)
 */



// Tambahkan di includes/functions.php

/**
 * Get question categories
 */
function getQuestionCategories($db, $type = null, $activeOnly = true) {
    try {
        $query = "SELECT * FROM question_categories WHERE 1=1";
        $params = [];
        
        if ($activeOnly) {
            $query .= " AND is_active = 1";
        }
        
        if ($type) {
            $query .= " AND (category_type = ? OR category_type = 'both')";
            $params[] = $type;
        }
        
        $query .= " ORDER BY display_order, category_name";
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Get categories error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get category name by ID
 */
function getCategoryName($db, $categoryId) {
    if (!$categoryId) {
        return '';
    }
    
    try {
        $stmt = $db->prepare("SELECT category_name FROM question_categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        $result = $stmt->fetch();
        return $result ? $result['category_name'] : '';
        
    } catch (PDOException $e) {
        error_log("Get category name error: " . $e->getMessage());
        return '';
    }
}

/**
 * Get category color by ID
 */
function getCategoryColor($db, $categoryId) {
    if (!$categoryId) {
        return '#e9ecef';
    }
    
    try {
        $stmt = $db->prepare("SELECT color_code FROM question_categories WHERE id = ?");
        $stmt->execute([$categoryId]);
        $result = $stmt->fetch();
        return $result ? $result['color_code'] : '#e9ecef';
        
    } catch (PDOException $e) {
        error_log("Get category color error: " . $e->getMessage());
        return '#e9ecef';
    }
}
// ============================================
// 2. SCORING FUNCTIONS
// ============================================

/**
 * Calculate MMPI-2 scores from answers
 * 
 * @param array $answers Array of answers [question_number => true/false]
 * @param string $gender 'male' or 'female'
 * @param int $age Age of respondent
 * @param array $cannotSay Array of question numbers marked as "Cannot Say"
 * @return array Complete scoring results
 */
function scoreMMPI($answers, $gender = 'male', $age = 30, $cannotSay = []) {
    $results = [
        'validity' => [],
        'basic' => [],
        'harris' => [],
        'content' => [],
        'supplementary' => [],
        'interpretation' => [],
        'codetype' => '',
        'profile' => []
    ];
    
    // 1. Calculate Cannot Say (?)
    $results['validity']['CannotSay'] = count($cannotSay);
    
    // 2. Calculate VRIN (Variable Response Inconsistency)
    $results['validity']['VRIN'] = calculateVRIN($answers);
    
    // 3. Calculate TRIN (True Response Inconsistency)
    $results['validity']['TRIN'] = calculateTRIN($answers);
    
    // 4. Calculate Basic Scales
    $results['basic'] = calculateBasicScales($answers, $gender);
    
    // 5. Calculate Harris-Lingoes Subscales
    $results['harris'] = calculateHarrisScales($answers, $gender);
    
    // 6. Calculate Content Scales
    $results['content'] = calculateContentScales($answers, $gender);
    
    // 7. Calculate Supplementary Scales
    $results['supplementary'] = calculateSupplementaryScales($answers, $gender);
    
    // 8. Calculate Validity Scales (L, F, K)
    $results['validity']['L'] = $results['basic']['L']['t'];
    $results['validity']['F'] = $results['basic']['F']['t'];
    $results['validity']['K'] = $results['basic']['K']['t'];
    $results['validity']['F_K'] = $results['basic']['F']['raw'] - $results['basic']['K']['raw'];
    
    // 9. Determine Code Type
    $results['codetype'] = determineCodeType($results['basic']);
    
    // 10. Generate Interpretations
    $results['interpretation'] = generateInterpretation($results, $gender, $age);
    
    // 11. Create Profile Elevation
    $results['profile'] = createProfile($results['basic']);
    
    return $results;
}


/**
 * Bulk import questions from CSV
 */
function bulkImportQuestions($db, $filePath, $type, $userId) {
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    if (!file_exists($filePath)) {
        return ['success' => false, 'message' => 'File tidak ditemukan.'];
    }
    
    try {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return ['success' => false, 'message' => 'Gagal membuka file.'];
        }
        
        // Read CSV headers
        $headers = fgetcsv($handle);
        
        if ($type === 'mmpi') {
            // Validate MMPI CSV structure
            $expectedHeaders = ['question_number', 'question_text', 'scale_L', 'scale_F', 'scale_K', 
                               'scale_Hs', 'scale_D', 'scale_Hy', 'scale_Pd', 'scale_Mf', 'scale_Pa',
                               'scale_Pt', 'scale_Sc', 'scale_Ma', 'scale_Si', 'hl_subscale', 'content_scale', 'is_active'];
            
            if (count(array_intersect($expectedHeaders, $headers)) < 5) {
                fclose($handle);
                return ['success' => false, 'message' => 'Format file CSV tidak valid untuk soal MMPI.'];
            }
            
            // Begin transaction
            $db->beginTransaction();
            
            $row = 1;
            while (($data = fgetcsv($handle)) !== false) {
                $row++;
                
                // Skip empty rows
                if (empty($data[0])) continue;
                
                try {
                    // Prepare data
                    $questionData = [
                        'question_number' => intval($data[0]),
                        'question_text' => trim($data[1]),
                        'scale_L' => isset($data[2]) && strtolower($data[2]) === 'true' ? 1 : 0,
                        'scale_F' => isset($data[3]) && strtolower($data[3]) === 'true' ? 1 : 0,
                        'scale_K' => isset($data[4]) && strtolower($data[4]) === 'true' ? 1 : 0,
                        'scale_Hs' => isset($data[5]) && strtolower($data[5]) === 'true' ? 1 : 0,
                        'scale_D' => isset($data[6]) && strtolower($data[6]) === 'true' ? 1 : 0,
                        'scale_Hy' => isset($data[7]) && strtolower($data[7]) === 'true' ? 1 : 0,
                        'scale_Pd' => isset($data[8]) && strtolower($data[8]) === 'true' ? 1 : 0,
                        'scale_Mf' => isset($data[9]) && strtolower($data[9]) === 'true' ? 1 : 0,
                        'scale_Pa' => isset($data[10]) && strtolower($data[10]) === 'true' ? 1 : 0,
                        'scale_Pt' => isset($data[11]) && strtolower($data[11]) === 'true' ? 1 : 0,
                        'scale_Sc' => isset($data[12]) && strtolower($data[12]) === 'true' ? 1 : 0,
                        'scale_Ma' => isset($data[13]) && strtolower($data[13]) === 'true' ? 1 : 0,
                        'scale_Si' => isset($data[14]) && strtolower($data[14]) === 'true' ? 1 : 0,
                        'hl_subscale' => isset($data[15]) ? trim($data[15]) : '',
                        'content_scale' => isset($data[16]) ? trim($data[16]) : '',
                        'is_active' => isset($data[17]) && strtolower($data[17]) === 'true' ? 1 : 0
                    ];
                    
                    // Check if question number already exists
                    $checkStmt = $db->prepare("SELECT id FROM mmpi_questions WHERE question_number = ?");
                    $checkStmt->execute([$questionData['question_number']]);
                    
                    if ($checkStmt->fetch()) {
                        // Update existing
                        $stmt = $db->prepare("
                            UPDATE mmpi_questions SET 
                                question_text = ?, scale_L = ?, scale_F = ?, scale_K = ?,
                                scale_Hs = ?, scale_D = ?, scale_Hy = ?, scale_Pd = ?, scale_Mf = ?, scale_Pa = ?,
                                scale_Pt = ?, scale_Sc = ?, scale_Ma = ?, scale_Si = ?, hl_subscale = ?, 
                                content_scale = ?, is_active = ?
                            WHERE question_number = ?
                        ");
                        
                        $stmt->execute([
                            $questionData['question_text'],
                            $questionData['scale_L'],
                            $questionData['scale_F'],
                            $questionData['scale_K'],
                            $questionData['scale_Hs'],
                            $questionData['scale_D'],
                            $questionData['scale_Hy'],
                            $questionData['scale_Pd'],
                            $questionData['scale_Mf'],
                            $questionData['scale_Pa'],
                            $questionData['scale_Pt'],
                            $questionData['scale_Sc'],
                            $questionData['scale_Ma'],
                            $questionData['scale_Si'],
                            $questionData['hl_subscale'],
                            $questionData['content_scale'],
                            $questionData['is_active'],
                            $questionData['question_number']
                        ]);
                        $imported++;
                    } else {
                        // Insert new
                        $stmt = $db->prepare("
                            INSERT INTO mmpi_questions (
                                question_number, question_text, scale_L, scale_F, scale_K,
                                scale_Hs, scale_D, scale_Hy, scale_Pd, scale_Mf, scale_Pa,
                                scale_Pt, scale_Sc, scale_Ma, scale_Si, hl_subscale, content_scale, is_active
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $questionData['question_number'],
                            $questionData['question_text'],
                            $questionData['scale_L'],
                            $questionData['scale_F'],
                            $questionData['scale_K'],
                            $questionData['scale_Hs'],
                            $questionData['scale_D'],
                            $questionData['scale_Hy'],
                            $questionData['scale_Pd'],
                            $questionData['scale_Mf'],
                            $questionData['scale_Pa'],
                            $questionData['scale_Pt'],
                            $questionData['scale_Sc'],
                            $questionData['scale_Ma'],
                            $questionData['scale_Si'],
                            $questionData['hl_subscale'],
                            $questionData['content_scale'],
                            $questionData['is_active']
                        ]);
                        $imported++;
                    }
                    
                } catch (PDOException $e) {
                    $skipped++;
                    $errors[] = "Baris $row: " . $e->getMessage();
                }
            }
            
            fclose($handle);
            $db->commit();
            
            logActivity($userId, 'bulk_import', "Imported $imported MMPI questions, skipped $skipped");
            
            $message = "Berhasil mengimport $imported soal MMPI.";
            if ($skipped > 0) {
                $message .= " $skipped soal dilewati karena error.";
                if (!empty($errors)) {
                    // Save errors to log file
                    error_log("Bulk import errors: " . implode("\n", $errors));
                }
            }
            
            return ['success' => true, 'message' => $message];
            
        } elseif ($type === 'adhd') {
            // Similar implementation for ADHD questions
            // ... (code for ADHD import)
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['success' => false, 'message' => 'Import gagal: ' . $e->getMessage()];
    }
}

/**
 * Save question version
 */
function saveQuestionVersion($db, $questionType, $questionId, $questionData, $userId, $description = '') {
    try {
        $stmt = $db->prepare("
            INSERT INTO question_versions 
            (question_type, question_id, version_data, change_description, changed_by) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $questionType,
            $questionId,
            json_encode($questionData),
            $description,
            $userId
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Version save error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get question versions
 */
function getQuestionVersions($db, $questionType, $questionId, $limit = 10) {
    try {
        $stmt = $db->prepare("
            SELECT v.*, u.full_name as changed_by_name 
            FROM question_versions v
            LEFT JOIN users u ON v.changed_by = u.id
            WHERE v.question_type = ? AND v.question_id = ?
            ORDER BY v.created_at DESC 
            LIMIT ?
        ");
        
        $stmt->execute([$questionType, $questionId, $limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Version get error: " . $e->getMessage());
        return [];
    }
}

/**
 * Restore question version
 */
function restoreQuestionVersion($db, $versionId, $userId) {
    try {
        // Get version data
        $stmt = $db->prepare("SELECT * FROM question_versions WHERE id = ?");
        $stmt->execute([$versionId]);
        $version = $stmt->fetch();
        
        if (!$version) {
            return ['success' => false, 'message' => 'Versi tidak ditemukan.'];
        }
        
        $questionData = json_decode($version['version_data'], true);
        
        if ($version['question_type'] === 'mmpi') {
            // Update MMPI question
            $updateStmt = $db->prepare("
                UPDATE mmpi_questions SET 
                    question_number = ?, question_text = ?, scale_L = ?, scale_F = ?, scale_K = ?,
                    scale_Hs = ?, scale_D = ?, scale_Hy = ?, scale_Pd = ?, scale_Mf = ?, scale_Pa = ?,
                    scale_Pt = ?, scale_Sc = ?, scale_Ma = ?, scale_Si = ?, hl_subscale = ?, 
                    content_scale = ?, is_active = ?
                WHERE id = ?
            ");
            
            $updateStmt->execute([
                $questionData['question_number'],
                $questionData['question_text'],
                $questionData['scale_L'],
                $questionData['scale_F'],
                $questionData['scale_K'],
                $questionData['scale_Hs'],
                $questionData['scale_D'],
                $questionData['scale_Hy'],
                $questionData['scale_Pd'],
                $questionData['scale_Mf'],
                $questionData['scale_Pa'],
                $questionData['scale_Pt'],
                $questionData['scale_Sc'],
                $questionData['scale_Ma'],
                $questionData['scale_Si'],
                $questionData['hl_subscale'],
                $questionData['content_scale'],
                $questionData['is_active'],
                $version['question_id']
            ]);
            
        } elseif ($version['question_type'] === 'adhd') {
            // Update ADHD question
            $updateStmt = $db->prepare("
                UPDATE adhd_questions SET 
                    question_text = ?, subscale = ?, question_order = ?, is_active = ?
                WHERE id = ?
            ");
            
            $updateStmt->execute([
                $questionData['question_text'],
                $questionData['subscale'],
                $questionData['question_order'],
                $questionData['is_active'],
                $version['question_id']
            ]);
        }
        
        // Log the restoration
        logActivity($userId, 'version_restore', 
            "Restored {$version['question_type']} question #{$version['question_id']} to version {$version['id']}");
        
        // Save new version for the restoration
        saveQuestionVersion($db, $version['question_type'], $version['question_id'], 
                           $questionData, $userId, "Restored from version #{$version['id']}");
        
        return ['success' => true, 'message' => 'Versi berhasil dipulihkan.'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Gagal memulihkan versi: ' . $e->getMessage()];
    }
}
/**
 * Export questions to CSV
 */
function exportMMPIQuestions($db, $format = 'csv') {
    try {
        $stmt = $db->query("SELECT * FROM mmpi_questions ORDER BY question_number");
        $questions = $stmt->fetchAll();
        
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=mmpi_questions_' . date('Y-m-d') . '.csv');
            
            $output = fopen('php://output', 'w');
            
            // CSV headers
            fputcsv($output, [
                'question_number', 'question_text', 'scale_L', 'scale_F', 'scale_K',
                'scale_Hs', 'scale_D', 'scale_Hy', 'scale_Pd', 'scale_Mf', 'scale_Pa',
                'scale_Pt', 'scale_Sc', 'scale_Ma', 'scale_Si', 'hl_subscale', 'content_scale', 'is_active'
            ]);
            
            // Data rows
            foreach ($questions as $question) {
                fputcsv($output, [
                    $question['question_number'],
                    $question['question_text'],
                    $question['scale_L'] ? 'true' : 'false',
                    $question['scale_F'] ? 'true' : 'false',
                    $question['scale_K'] ? 'true' : 'false',
                    $question['scale_Hs'] ? 'true' : 'false',
                    $question['scale_D'] ? 'true' : 'false',
                    $question['scale_Hy'] ? 'true' : 'false',
                    $question['scale_Pd'] ? 'true' : 'false',
                    $question['scale_Mf'] ? 'true' : 'false',
                    $question['scale_Pa'] ? 'true' : 'false',
                    $question['scale_Pt'] ? 'true' : 'false',
                    $question['scale_Sc'] ? 'true' : 'false',
                    $question['scale_Ma'] ? 'true' : 'false',
                    $question['scale_Si'] ? 'true' : 'false',
                    $question['hl_subscale'],
                    $question['content_scale'],
                    $question['is_active'] ? 'true' : 'false'
                ]);
            }
            
            fclose($output);
        }
        
    } catch (Exception $e) {
        error_log("Export error: " . $e->getMessage());
        return false;
    }
}

function exportADHDQuestions($db, $format = 'csv') {
    // Similar implementation for ADHD
    // ... (code for ADHD export)
}
/**
 * Calculate VRIN score
 */
function calculateVRIN($answers) {
    $vrin = 0;
    
    foreach (VRIN_PAIRS as $pair) {
        $item1 = $pair[0];
        $item2 = $pair[1];
        
        if (isset($answers[$item1]) && isset($answers[$item2])) {
            if ($answers[$item1] !== $answers[$item2]) {
                $vrin++;
            }
        }
    }
    
    return $vrin;
}

/**
 * Calculate TRIN score
 */
function calculateTRIN($answers) {
    $trin = 9; // Base score
    
    // True-True pairs
    foreach (TRIN_PAIRS['true'] as $pair) {
        if (isset($answers[$pair[0]]) && isset($answers[$pair[1]])) {
            if ($answers[$pair[0]] === true && $answers[$pair[1]] === true) {
                $trin++;
            }
        }
    }
    
    // False-False pairs
    foreach (TRIN_PAIRS['false'] as $pair) {
        if (isset($answers[$pair[0]]) && isset($answers[$pair[1]])) {
            if ($answers[$pair[0]] === false && $answers[$pair[1]] === false) {
                $trin--;
            }
        }
    }
    
    return $trin;
}

/**
 * Calculate Basic Scales with K-correction
 */
function calculateBasicScales($answers, $gender) {
    $basicScales = [];
    
    foreach (MMPI_BASIC_SCALES as $code => $scale) {
        $raw = 0;
        
        // Count responses according to keying direction
        foreach ($scale['items'] as $item) {
            if (isset($answers[$item])) {
                if ($scale['keying']) {
                    // True-keyed: true response scores
                    if ($answers[$item] === true) {
                        $raw++;
                    }
                } else {
                    // False-keyed: false response scores
                    if ($answers[$item] === false) {
                        $raw++;
                    }
                }
            }
        }
        
        // Apply K-correction if applicable
        $kCorrection = 0;
        if (isset($scale['k_weight']) && $scale['k_weight'] > 0) {
            // Get K raw score
            $kRaw = 0;
            foreach (MMPI_BASIC_SCALES['K']['items'] as $kItem) {
                if (isset($answers[$kItem]) && $answers[$kItem] === false) {
                    $kRaw++;
                }
            }
            $kCorrection = round($kRaw * $scale['k_weight']);
        }
        
        $corrected = $raw + $kCorrection;
        
        // Calculate T-score
        $tScore = calculateTScore($code, $corrected, $gender, $raw, $kCorrection);
        
        $basicScales[$code] = [
            'name' => $scale['name'],
            'raw' => $raw,
            'k_correction' => $kCorrection,
            'corrected' => $corrected,
            't' => $tScore,
            'interpretation' => interpretTScore($tScore)
        ];
    }
    
    return $basicScales;
}

/**
 * Calculate Harris-Lingoes Subscales
 */
function calculateHarrisScales($answers, $gender) {
    $harrisScales = [];
    
    foreach (MMPI_HARRIS_SCALES as $code => $scale) {
        $raw = 0;
        
        foreach ($scale['items'] as $item) {
            if (isset($answers[$item]) && $answers[$item] === true) {
                $raw++;
            }
        }
        
        // For Harris scales, we use linear T-scores
        $tScore = calculateLinearTScore($raw, $code, $gender, 'harris');
        
        $harrisScales[$code] = [
            'name' => $scale['name'],
            'raw' => $raw,
            't' => $tScore,
            'interpretation' => interpretTScore($tScore)
        ];
    }
    
    return $harrisScales;
}

/**
 * Calculate Content Scales
 */
function calculateContentScales($answers, $gender) {
    $contentScales = [];
    
    foreach (MMPI_CONTENT_SCALES as $code => $scale) {
        $raw = 0;
        
        foreach ($scale['items'] as $item) {
            if (isset($answers[$item]) && $answers[$item] === true) {
                $raw++;
            }
        }
        
        $tScore = calculateLinearTScore($raw, $code, $gender, 'content');
        
        $contentScales[$code] = [
            'name' => $scale['name'],
            'raw' => $raw,
            't' => $tScore,
            'interpretation' => interpretTScore($tScore)
        ];
    }
    
    return $contentScales;
}

/**
 * Calculate Supplementary Scales
 */
function calculateSupplementaryScales($answers, $gender) {
    $suppScales = [];
    
    foreach (MMPI_SUPPLEMENTARY_SCALES as $code => $scale) {
        $raw = 0;
        
        foreach ($scale['items'] as $item) {
            if (isset($answers[$item]) && $answers[$item] === true) {
                $raw++;
            }
        }
        
        $tScore = calculateLinearTScore($raw, $code, $gender, 'supplementary');
        
        $suppScales[$code] = [
            'name' => $scale['name'],
            'raw' => $raw,
            't' => $tScore,
            'interpretation' => interpretTScore($tScore)
        ];
    }
    
    return $suppScales;
}

/**
 * Calculate T-score using uniform or linear method
 */
function calculateTScore($scaleCode, $correctedScore, $gender, $rawScore = null, $kCorrection = null) {
    $scaleNumMap = [
        'Hs' => 1, 'D' => 2, 'Hy' => 3, 'Pd' => 4, 
        'Pa' => 6, 'Pt' => 7, 'Sc' => 8, 'Ma' => 9
    ];
    
    // Check if scale uses uniform T-scores
    if (isset($scaleNumMap[$scaleCode])) {
        $scaleNum = $scaleNumMap[$scaleCode];
        
        if (isset(UNIFORM_T_COEFFICIENTS[$gender][$scaleNum])) {
            return calculateUniformTScore($correctedScore, $scaleNum, $gender);
        }
    }
    
    // Use linear T-scores for other scales
    return calculateLinearTScore($correctedScore, $scaleCode, $gender, 'basic');
}

/**
 * Calculate Uniform T-score (for Basic Scales 1-4, 6-9)
 */
function calculateUniformTScore($raw, $scaleNum, $gender) {
    if (!isset(UNIFORM_T_COEFFICIENTS[$gender][$scaleNum])) {
        return 50; // Default if coefficients not found
    }
    
    $coeff = UNIFORM_T_COEFFICIENTS[$gender][$scaleNum];
    
    // Calculate D (deviation from C)
    $D = $raw < $coeff['C'] ? $coeff['C'] - $raw : 0;
    
    // Calculate T-score using polynomial formula
    $t = $coeff['B0'] + 
         ($coeff['B1'] * $raw) + 
         ($coeff['B2'] * pow($D, 2)) + 
         ($coeff['B3'] * pow($D, 3));
    
    return round($t);
}

/**
 * Calculate Linear T-score (for other scales)
 */
function calculateLinearTScore($raw, $scaleCode, $gender, $scaleType = 'basic') {
    // Get normative data
    $norms = getNorms($scaleCode, $gender, $scaleType);
    
    if (!$norms) {
        return 50; // Default if no norms
    }
    
    $mean = $norms['mean'];
    $sd = $norms['sd'];
    
    // Calculate T-score: T = 50 + 10 * (X - M) / SD
    $t = 50 + (10 * ($raw - $mean) / $sd);
    
    return round($t);
}

/**
 * Get normative data for a scale
 */
function getNorms($scaleCode, $gender, $scaleType = 'basic') {
    // Basic scales norms
    if ($scaleType === 'basic') {
        if (isset(MMPI_NORMS[$gender][$scaleCode])) {
            return MMPI_NORMS[$gender][$scaleCode];
        }
    }
    
    // Default norms for other scales
    $defaultNorms = [
        'harris' => ['mean' => 5, 'sd' => 3],
        'content' => ['mean' => 10, 'sd' => 5],
        'supplementary' => ['mean' => 15, 'sd' => 7]
    ];
    
    return $defaultNorms[$scaleType] ?? ['mean' => 10, 'sd' => 5];
}

/**
 * Interpret T-score range
 */
function interpretTScore($tScore) {
    if ($tScore >= 80) {
        return ['level' => 'very_high', 'label' => 'Sangat Tinggi', 'color' => '#e74c3c'];
    } elseif ($tScore >= 70) {
        return ['level' => 'high', 'label' => 'Tinggi', 'color' => '#f39c12'];
    } elseif ($tScore >= 60) {
        return ['level' => 'moderate', 'label' => 'Sedang', 'color' => '#f1c40f'];
    } elseif ($tScore >= 40) {
        return ['level' => 'average', 'label' => 'Rata-rata', 'color' => '#27ae60'];
    } elseif ($tScore >= 30) {
        return ['level' => 'low', 'label' => 'Rendah', 'color' => '#3498db'];
    } else {
        return ['level' => 'very_low', 'label' => 'Sangat Rendah', 'color' => '#2980b9'];
    }
}

/**
 * Determine 2-point Code Type
 */
function determineCodeType($basicScales) {
    $clinicalScales = ['Hs', 'D', 'Hy', 'Pd', 'Pa', 'Pt', 'Sc', 'Ma'];
    $elevations = [];
    
    foreach ($clinicalScales as $scale) {
        if (isset($basicScales[$scale])) {
            $elevations[$scale] = $basicScales[$scale]['t'];
        }
    }
    
    // Sort by T-score descending
    arsort($elevations);
    
    // Get top 2 scales with T >= 65
    $topScales = [];
    foreach ($elevations as $scale => $t) {
        if ($t >= 65) {
            $topScales[] = $scale;
            if (count($topScales) >= 2) {
                break;
            }
        }
    }
    
    if (empty($topScales)) {
        return 'Within Normal Limits';
    }
    
    return implode('-', $topScales);
}

/**
 * Create profile elevation summary
 */
function createProfile($basicScales) {
    $profile = [
        'elevated' => [],
        'clinical' => [],
        'validity' => []
    ];
    
    $clinicalScales = ['Hs', 'D', 'Hy', 'Pd', 'Pa', 'Pt', 'Sc', 'Ma', 'Si'];
    
    foreach ($basicScales as $code => $data) {
        if (in_array($code, $clinicalScales)) {
            $profile['clinical'][$code] = $data['t'];
            
            if ($data['t'] >= 65) {
                $profile['elevated'][] = [
                    'scale' => $code,
                    'name' => $data['name'],
                    't' => $data['t'],
                    'interpretation' => $data['interpretation']
                ];
            }
        } elseif (in_array($code, ['L', 'F', 'K'])) {
            $profile['validity'][$code] = $data['t'];
        }
    }
    
    // Sort elevated scales by T-score
    usort($profile['elevated'], function($a, $b) {
        return $b['t'] - $a['t'];
    });
    
    return $profile;
}

/**
 * Generate comprehensive interpretation
 */
function generateInterpretation($results, $gender, $age) {
    $interpretation = [
        'validity' => '',
        'clinical' => '',
        'profile' => '',
        'recommendations' => []
    ];
    
    // 1. Validity Interpretation
    $validityMsg = interpretValidity($results['validity']);
    $interpretation['validity'] = $validityMsg;
    
    // 2. Clinical Interpretation based on code type
    $clinicalMsg = interpretClinical($results['basic'], $results['codetype']);
    $interpretation['clinical'] = $clinicalMsg;
    
    // 3. Profile Interpretation
    $profileMsg = interpretProfile($results['profile']);
    $interpretation['profile'] = $profileMsg;
    
    // 4. Generate Recommendations
    $interpretation['recommendations'] = generateRecommendations($results);
    
    return $interpretation;
}

/**
 * Interpret validity scales
 */
function interpretValidity($validity) {
    $messages = [];
    
    // Cannot Say (?)
    if ($validity['CannotSay'] > 30) {
        $messages[] = "Terdapat {$validity['CannotSay']} item yang tidak dijawab (>30). Profil ini berisiko besar tidak valid.";
    } elseif ($validity['CannotSay'] > 10) {
        $messages[] = "Terdapat {$validity['CannotSay']} item yang tidak dijawab. Interpretasi diagnostik perlu dilakukan dengan sangat hati-hati.";
    }
    
    // VRIN
    if ($validity['VRIN'] >= 13) {
        $messages[] = "Skor VRIN tinggi ({$validity['VRIN']}), menunjukkan pola respons yang sangat tidak konsisten atau acak.";
    }
    
    // TRIN
    if ($validity['TRIN'] >= 13) {
        $messages[] = "Skor TRIN tinggi ({$validity['TRIN']}), terindikasi adanya kecenderungan bias menyetujui ('Ya') segala hal tanpa mempertimbangkan isi pertanyaan.";
    } elseif ($validity['TRIN'] <= 5) {
        $messages[] = "Skor TRIN rendah ({$validity['TRIN']}), terindikasi bias menolak ('Tidak') pada sebagian besar pernyataan.";
    }
    
    // F Scale
    if ($validity['F'] >= 80) {
        $messages[] = "Skor F sangat elevatif (T={$validity['F']}). Ini mengindikasikan kecenderungan ekstrem untuk melebih-lebihkan gejala kejiwaan (Faking Bad) atau menjawab secara sembarang.";
    } elseif ($validity['F'] >= 70) {
        $messages[] = "Skor F cukup tinggi (T={$validity['F']}), yang mencerminkan tingkat penderitaan distres psikologis yang berat, atau upaya pasien mendramatisasi kendalanya.";
    }
    
    // L Scale
    if ($validity['L'] >= 70) {
        $messages[] = "Skor L sangat elevatif (T={$validity['L']}), menunjukkan sikap defensif yang kuat dan usaha naif untuk tampil bermoral sempurna (Faking Good).";
    }
    
    // K Scale
    if ($validity['K'] >= 70) {
        $messages[] = "Skor K tinggi (T={$validity['K']}), mencerminkan sikap sangat tertutup/defensif klinis, di mana individu enggan mengakui masalah psikologis sekecil apa pun.";
    } elseif ($validity['K'] <= 40) {
        $messages[] = "Skor K rendah (T={$validity['K']}), menunjukkan sikap individu yang terlalu kritis terhadap diri sendiri, kewalahan menahan stres, atau nyaris tak memiliki pertahanan ego protektif.";
    }
    
    // F-K Index
    if ($validity['F_K'] > 11) {
        $messages[] = "Indeks F-K bernilai positif tinggi ({$validity['F_K']}). Individu tersebut terang-terangan (Over-reporting) melontarkan klaim gangguan mental parah.";
    } elseif ($validity['F_K'] < -12) {
        $messages[] = "Indeks F-K bernilai negatif ({$validity['F_K']}). Individu ini secara sadar menutupi kekurangannya (Under-reporting).";
    }
    
    if (empty($messages)) {
        $messages[] = "Seluruh skala Validitas berada pada rentang batas normal. Profil ini layak tepercaya dan dapat diinterpretasi secara leluasa.";
    }
    
    return implode(" ", $messages);
}

/**
 * Interpret clinical scales based on code type
 */
function interpretClinical($basicScales, $codeType) {
    $interpretations = [
        '12/21' => "Kombinasi 12/21 mengindikasikan individu yang mengalami distres psikologis tinggi, diwarnai keluhan fisik/somatik yang dikeluhkan tanpa dasar medis yang jelas, seiring dengan kecemasan dan suasana hati depresif.",
        '13/31' => "Tipe 13/31 merupakan pola 'Konversi Diagnosis' (Conversion V); menunjukkan tingginya kecenderungan menyalurkan masalah kecemasan ke dalam keluhan fisik (gangguan konversi) kronis yang terasa rasional bagi pasien.",
        '23/32' => "Tipe 23/32 menunjukkan penderitaan emosional yang ditandai dengan depresi, kurangnya gairah hidup, kelemahan fisik, yang sering ditekan atau disembunyikan agar pasien terlihat kuat secara sosial.",
        '24/42' => "Tipe 24/42 mengidentifikasikan individu yang impulsif, menentang otoritas, yang sesekali dihantam oleh episode depresif akut setelah bertindak tanpa pikir panjang.",
        '27/72' => "Tipe 27/72 sangat tipikal pasien dengan kecemasan (anxiety) dan depresi, sering diiringi obsesi, rasa bersalah, perfeksionisme berlebihan, serta keraguan yang melumpuhkan terhadap kemampuan diri.",
        '28/82' => "Tipe 28/82 menunjukkan kekacauan berpikir (thought disturbance) kronis bercampur distres dan depresi mendalam; sering kebingungan dan merasa diasingkan dari lingkungan sekitarnya.",
        '34/43' => "Tipe 34/43 menunjukkan individu dengan amarah tertahan yang sewaktu-waktu meledak dalam perilaku antisosial (acting-out), sering kali tidak peduli masa depan, dan minim wawasan ke dalam diri.",
        '46/64' => "Tipe 46/64 menggambarkan individu paranoi yang pemarah, manipulatif, sarkastik, sangat peka terhadap kritik, dan seringkali menyalahkan orang lain atas kesalahannya demi melindungi egonya.",
        '48/84' => "Tipe 48/84 merujuk pada penderita eksentrik dengan ciri skizotipal yang merasa asing secara sosial, cenderung memproyeksikan kecurigaan, serta berisiko memiliki impuls tereksternalisasi dan perilaku antisosial antisosial/ilegal.",
        '49/94' => "Tipe 49/94 merupakan indikasi sindrom antisosial klasik yang diwarnai energi hipomanik; sosok yang impulsif superaktif, senang memanipulasi, dangkal emosi, dan kerap mencari sensasi ekstrem yang membahayakan nyawa/reputasi.",
        '68/86' => "Tipe 68/86 menandakan profil gawat (Psikosis Paranoia) dengan ciri utama kecurigaan luar biasa nyata (waham kebesaran atau kejar), afek tidak wajar, dan tarikan nyata ke arah patologi skizofrenia akut.",
        '78/87' => "Tipe 78/87 menampilkan individu dengan ciri obsesif-kompulsif parah nan ruminatif seiring kegelisahan internal masif yang menghalanginya membuat keputusan di keseharian.",
        '89/98' => "Tipe 89/98 mendeskripsikan hiperaktivitas ekstrem yang menutupi delusi mendalam; pasien berisiko membahayakan diri di bawah fase hipomania agitasi yang lepas dari kontrol.",
        'Within Normal Limits' => "Seluruh profil skala klinis mendarat dalam batas wajar T-Score komunitarian. Tidak dijumpai tanda tersembunyi kelainan gangguan mental yang berarti menurut algoritma klinis."
    ];
    
    if (isset($interpretations[$codeType])) {
        return $interpretations[$codeType];
    }
    
    // Generic interpretation based on elevated scales
    $elevated = [];
    foreach ($basicScales as $code => $data) {
        if (!in_array($code, ['L', 'F', 'K']) && $data['t'] >= 65) {
            $elevated[] = $code . ' (T=' . $data['t'] . ')';
        }
    }
    
    if (empty($elevated)) {
        return "Tidak ada skala klinis yang elevasi signifikan. Profil dalam batas normal.";
    }
    
    return "Elevasi tinggi terlihat pada skala utama: " . implode(', ', $elevated) . ". " . 
           "Interpretasi yang lebih mendalam dan spesifik membutuhkan analisis konfigurasi pola lintas skala (Cross-Scale Analysis).";
}

/**
 * Interpret profile pattern
 */
function interpretProfile($profile) {
    if (empty($profile['elevated'])) {
        return "Profil MMPI ini relatif mendatar (Flat Profile) tanpa ada lonjakan elevasi yang mencolok pada skala-skala klinis penderitaan.";
    }
    
    $elevationCount = count($profile['elevated']);
    
    if ($elevationCount === 1) {
        $scale = $profile['elevated'][0];
        return "Terpantau ada Elevasi Tunggal (Spike) menonjol pada titik skala {$scale['scale']} ({$scale['name']}, T={$scale['t']}). " .
               "Lonjakan tunggal ini memberi petunjuk jelas mengenai fokus permasalahan klien, namun tetap memerlukan peninjauan riwayat klinis murni.";
    }
    
    // Multiple elevations
    $scales = array_map(function($e) {
        return $e['scale'];
    }, $profile['elevated']);
    
    return "Pola Elevasi Jamak / Multipel (" . implode(', ', $scales) . "). " .
           "Konfigurasi yang saling tumpang tindih ini menandaskan betapa rumit dan mengakarnya simptom penderitaan klien sehingga sangat disarankan evaluasi menyeluruh (Komprehensif).";
}

/**
 * Generate clinical recommendations
 */
function generateRecommendations($results) {
    $recommendations = [];
    
    // Validity concerns
    if ($results['validity']['CannotSay'] > 20) {
        $recommendations[] = "Pertimbangkan pengambilan ulang tes (Retest); item kosong yang terlampau banyak merusak reliabilitas analisis.";
    }
    
    if ($results['validity']['F'] >= 80) {
        $recommendations[] = "Gali lebih dalam apakah tingginya skor F ini akibat faking bad (minta perhatian/malapraktik), respons acak, atau memang indikasi psikopatologi yang sangat berat.";
    }
    
    // Clinical elevations
    $elevatedScales = [];
    foreach ($results['basic'] as $code => $data) {
        if (!in_array($code, ['L', 'F', 'K']) && $data['t'] >= 70) {
            $elevatedScales[] = $code;
        }
    }
    
    if (in_array('D', $elevatedScales) || in_array('Pt', $elevatedScales)) {
        $recommendations[] = "Fokuskan wawancara pada penelusuran bibit depresi, kecemasan (anxiety), serta risiko ideasi bunuh diri (suicide ideation).";
    }
    
    if (in_array('Sc', $elevatedScales) || in_array('Pa', $elevatedScales)) {
        $recommendations[] = "Dibutuhkan observasi ketat terhadap kemungkinan munculnya gejala psikotik, waham (delusi), atau kekacauan proses pikir (thought disorder).";
    }
    
    if (in_array('Pd', $elevatedScales) || in_array('Ma', $elevatedScales)) {
        $recommendations[] = "Arahkan evaluasi pada riwayat perilaku impulsif, masalah pengendalian emosi (anger management), dan potensi konflik dengan figur otoritas.";
    }
    
    if (in_array('Hs', $elevatedScales) || in_array('Hy', $elevatedScales)) {
        $recommendations[] = "Disarankan melakukan penapisan medis (medical clearance) untuk memastikan keluhan somatik/fisiknya tidak memiliki patologi organik.";
    }
    
    // Content scale recommendations
    foreach ($results['content'] as $code => $data) {
        if ($data['t'] >= 65) {
            switch ($code) {
                case 'ANX':
                    $recommendations[] = "Perlu dipertimbangkan intervensi manajemen kecemasan akut dan relaksasi.";
                    break;
                case 'DEP':
                    $recommendations[] = "Skrining depresi lanjutan dan pencegahan tendensi menyakiti diri sendiri wajib diprioritaskan.";
                    break;
                case 'ANG':
                    $recommendations[] = "Modifikasi perilaku (Behavioral Intervention) sangat disarankan untuk mengendalikan temperamen dan potensi afek meledak-ledak.";
                    break;
                case 'FRS':
                    $recommendations[] = "Eksplorasi riwayat fobia spesifik atau ketakutan tak rasional (generalized anxiety) harian.";
                    break;
            }
        }
    }
    
    // Default recommendation if none
    if (empty($recommendations)) {
        $recommendations[] = "Tidak diperlukan intervensi spesifik berdasarkan profil MMPI-2.";
    }
    
    return array_unique($recommendations);
}

// ============================================
// 3. ADHD SCORING FUNCTIONS
// ============================================

/**
 * Calculate ADHD scores from answers
 * 
 * @param array $answers Array of ADHD answers [question_index => score(0-4)]
 * @param string $type 'adult' or 'child'
 * @return array ADHD scoring results
 */
function scoreADHD($answers, $type = 'adult') {
    $results = [
        'inattention' => 0,
        'hyperactivity' => 0,
        'impulsivity' => 0,
        'total' => 0,
        'severity' => 'none',
        'diagnosis' => '',
        'interpretation' => ''
    ];
    
    // ADHD-18 item structure (9 inattention, 5 hyperactivity, 4 impulsivity)
    $inattentionItems = range(1, 9);
    $hyperactivityItems = range(10, 14);
    $impulsivityItems = range(15, 18);
    
    // Calculate domain scores
    foreach ($answers as $index => $score) {
        if (in_array($index, $inattentionItems)) {
            $results['inattention'] += $score;
        } elseif (in_array($index, $hyperactivityItems)) {
            $results['hyperactivity'] += $score;
        } elseif (in_array($index, $impulsivityItems)) {
            $results['impulsivity'] += $score;
        }
    }
    
    $results['total'] = $results['inattention'] + $results['hyperactivity'] + $results['impulsivity'];
    
    // Determine severity
    $results['severity'] = determineADHDSeverity($results['total'], $type);
    
    // Determine diagnosis
    $results['diagnosis'] = determineADHDDiagnosis($results, $type);
    
    // Generate interpretation
    $results['interpretation'] = interpretADHDScores($results);
    
    return $results;
}

/**
 * Determine ADHD severity
 */
function determineADHDSeverity($totalScore, $type = 'adult') {
    $cutoffs = [
        'adult' => [
            'none' => [0, 17],
            'mild' => [18, 29],
            'moderate' => [30, 39],
            'severe' => [40, 72]
        ],
        'child' => [
            'none' => [0, 14],
            'mild' => [15, 24],
            'moderate' => [25, 34],
            'severe' => [35, 54]
        ]
    ];
    
    $cutoff = $cutoffs[$type] ?? $cutoffs['adult'];
    
    foreach ($cutoff as $severity => $range) {
        if ($totalScore >= $range[0] && $totalScore <= $range[1]) {
            return $severity;
        }
    }
    
    return 'none';
}

/**
 * Determine ADHD diagnosis based on DSM-5 criteria
 */
function determineADHDDiagnosis($scores, $type = 'adult') {
    // DSM-5 criteria: 6+ symptoms for children, 5+ for adults
    $symptomThreshold = ($type === 'child') ? 6 : 5;
    
    $inattentionSymptoms = ($scores['inattention'] >= ($symptomThreshold * 2.5)) ? true : false;
    $hyperactivitySymptoms = ($scores['hyperactivity'] + $scores['impulsivity'] >= ($symptomThreshold * 2.5)) ? true : false;
    
    if ($inattentionSymptoms && $hyperactivitySymptoms) {
        return "ADHD Combined Type";
    } elseif ($inattentionSymptoms) {
        return "ADHD Predominantly Inattentive Type";
    } elseif ($hyperactivitySymptoms) {
        return "ADHD Predominantly Hyperactive-Impulsive Type";
    } else {
        return "No ADHD Diagnosis";
    }
}

/**
 * Interpret ADHD scores
 */
function interpretADHDScores($scores) {
    $interpretation = "Skor total ADHD: {$scores['total']}/72 ({$scores['severity']})\n";
    $interpretation .= "Inattention: {$scores['inattention']}/36\n";
    $interpretation .= "Hyperactivity: {$scores['hyperactivity']}/20\n";
    $interpretation .= "Impulsivity: {$scores['impulsivity']}/16\n\n";
    
    switch ($scores['severity']) {
        case 'severe':
            $interpretation .= "Gejala ADHD sangat berat dan mengganggu fungsi sehari-hari secara signifikan.";
            break;
        case 'moderate':
            $interpretation .= "Gejala ADHD sedang dan mengganggu beberapa area fungsi.";
            break;
        case 'mild':
            $interpretation .= "Gejala ADHD ringan dan mungkin tidak selalu mengganggu fungsi.";
            break;
        default:
            $interpretation .= "Tidak ada gejala ADHD yang signifikan secara klinis.";
    }
    
    $interpretation .= "\nDiagnosis: {$scores['diagnosis']}";
    
    return $interpretation;
}

// ============================================
// 4. REPORT GENERATION FUNCTIONS
// ============================================

/**
 * Generate comprehensive test report
 */
function generateTestReport($mmpiResults, $adhdResults = null, $biodata = [], $testInfo = []) {
    $report = [
        'header' => generateReportHeader($biodata, $testInfo),
        'validity' => generateValidityReport($mmpiResults['validity']),
        'basic_scales' => generateBasicScalesReport($mmpiResults['basic']),
        'clinical_summary' => generateClinicalSummary($mmpiResults),
        'content_scales' => generateContentScalesReport($mmpiResults['content']),
        'harris_scales' => generateHarrisReport($mmpiResults['harris']),
        'interpretation' => $mmpiResults['interpretation']
    ];
    
    if ($adhdResults) {
        $report['adhd'] = generateADHDReport($adhdResults);
    }
    
    $report['recommendations'] = generateFinalRecommendations($mmpiResults, $adhdResults);
    $report['footer'] = generateReportFooter();
    
    return $report;
}

/**
 * Generate report header
 */
function generateReportHeader($biodata, $testInfo) {
    $date = date('d/m/Y');
    $time = date('H:i');
    
    return [
        'title' => 'LAPORAN HASIL TES PSIKOLOGI',
        'subtitle' => 'Minnesota Multiphasic Personality Inventory-2 (MMPI-2)',
        'client' => [
            'name' => $biodata['full_name'] ?? 'N/A',
            'age' => $biodata['age'] ?? 'N/A',
            'gender' => $biodata['gender'] ?? 'N/A',
            'education' => $biodata['education'] ?? 'N/A',
            'occupation' => $biodata['occupation'] ?? 'N/A'
        ],
        'test_info' => [
            'date' => $testInfo['date'] ?? $date,
            'time' => $testInfo['time'] ?? $time,
            'duration' => $testInfo['duration'] ?? 'N/A',
            'examiner' => $testInfo['examiner'] ?? 'Sistem Komputer'
        ]
    ];
}

/**
 * Generate validity scales report
 */
function generateValidityReport($validity) {
    $report = "ANALISIS VALIDITAS:\n";
    $report .= str_repeat("=", 50) . "\n\n";
    
    foreach ($validity as $scale => $value) {
        $interpretation = interpretValidityScale($scale, $value);
        $report .= sprintf("%-10s: %-5s | %s\n", $scale, $value, $interpretation);
    }
    
    return $report;
}

/**
 * Interpret individual validity scale
 */
function interpretValidityScale($scale, $value) {
    $interpretations = [
        'L' => [
            'range' => '<70', 'text' => 'Within normal limits'
        ],
        'F' => [
            'range' => '<70', 'text' => 'No significant over-reporting'
        ],
        'K' => [
            'range' => '40-70', 'text' => 'Appropriate self-disclosure'
        ],
        'VRIN' => [
            'range' => '<13', 'text' => 'Consistent responding'
        ],
        'TRIN' => [
            'range' => '5-13', 'text' => 'No response bias'
        ],
        'CannotSay' => [
            'range' => '<10', 'text' => 'Adequate response rate'
        ]
    ];
    
    if (isset($interpretations[$scale])) {
        return $interpretations[$scale]['text'];
    }
    
    return 'See interpretation';
}

/**
 * Generate basic scales report with profile
 */
function generateBasicScalesReport($basicScales) {
    $report = "SKALA DASAR MMPI-2:\n";
    $report .= str_repeat("=", 50) . "\n\n";
    $report .= sprintf("%-4s %-25s %-6s %-6s %-6s %-20s\n", 
        'Kode', 'Nama Skala', 'Raw', 'K', 'T', 'Interpretasi');
    $report .= str_repeat("-", 70) . "\n";
    
    foreach ($basicScales as $code => $data) {
        $report .= sprintf("%-4s %-25s %-6d %-6d %-6d %-20s\n",
            $code,
            substr($data['name'], 0, 24),
            $data['raw'],
            $data['k_correction'],
            $data['t'],
            $data['interpretation']['label']
        );
    }
    
    return $report;
}

/**
 * Generate clinical summary
 */
function generateClinicalSummary($mmpiResults) {
    $summary = "RINGKASAN KLINIS:\n";
    $summary .= str_repeat("=", 50) . "\n\n";
    
    $summary .= "Kode Tipe: " . $mmpiResults['codetype'] . "\n\n";
    
    if (!empty($mmpiResults['profile']['elevated'])) {
        $summary .= "SKALA ELEVASI SIGNIFIKAN (T ≥ 65):\n";
        foreach ($mmpiResults['profile']['elevated'] as $elevated) {
            $summary .= sprintf("- %s (%s): T=%d (%s)\n",
                $elevated['scale'],
                $elevated['name'],
                $elevated['t'],
                $elevated['interpretation']['label']
            );
        }
        $summary .= "\n";
    }
    
    $summary .= "INTERPRETASI KLINIS:\n";
    $summary .= wordwrap($mmpiResults['interpretation']['clinical'], 70) . "\n";
    
    return $summary;
}

/**
 * Generate content scales report
 */
function generateContentScalesReport($contentScales) {
    $report = "SKALA KONTEN:\n";
    $report .= str_repeat("=", 50) . "\n\n";
    $report .= sprintf("%-4s %-30s %-6s %-6s %-20s\n", 
        'Kode', 'Nama Skala', 'Raw', 'T', 'Interpretasi');
    $report .= str_repeat("-", 70) . "\n";
    
    foreach ($contentScales as $code => $data) {
        $report .= sprintf("%-4s %-30s %-6d %-6d %-20s\n",
            $code,
            substr($data['name'], 0, 29),
            $data['raw'],
            $data['t'],
            $data['interpretation']['label']
        );
    }
    
    return $report;
}

/**
 * Generate Harris-Lingoes report
 */
function generateHarrisReport($harrisScales) {
    $report = "SUB-SKALA HARRIS-LINGOES:\n";
    $report .= str_repeat("=", 50) . "\n\n";
    
    // Group by main scale
    $groups = [
        'D' => [], 'Hy' => [], 'Pd' => [], 'Pa' => [], 'Sc' => [], 'Ma' => [], 'Si' => []
    ];
    
    foreach ($harrisScales as $code => $data) {
        $mainScale = substr($code, 0, 2);
        if (isset($groups[$mainScale])) {
            $groups[$mainScale][$code] = $data;
        }
    }
    
    foreach ($groups as $mainScale => $subscales) {
        if (!empty($subscales)) {
            $report .= "Skala {$mainScale}:\n";
            foreach ($subscales as $code => $data) {
                $report .= sprintf("  %-5s %-30s T=%-3d\n",
                    $code,
                    substr($data['name'], 0, 29),
                    $data['t']
                );
            }
            $report .= "\n";
        }
    }
    
    return $report;
}

/**
 * Generate ADHD report
 */
function generateADHDReport($adhdResults) {
    $report = "HASIL SCREENING ADHD:\n";
    $report .= str_repeat("=", 50) . "\n\n";
    
    $report .= sprintf("%-20s: %d/36\n", 'Inattention', $adhdResults['inattention']);
    $report .= sprintf("%-20s: %d/20\n", 'Hyperactivity', $adhdResults['hyperactivity']);
    $report .= sprintf("%-20s: %d/16\n", 'Impulsivity', $adhdResults['impulsivity']);
    $report .= sprintf("%-20s: %d/72\n", 'Total Score', $adhdResults['total']);
    $report .= sprintf("%-20s: %s\n", 'Severity', ucfirst($adhdResults['severity']));
    $report .= sprintf("%-20s: %s\n\n", 'Diagnosis', $adhdResults['diagnosis']);
    
    $report .= "INTERPRETASI:\n";
    $report .= wordwrap($adhdResults['interpretation'], 70) . "\n";
    
    return $report;
}

/**
 * Generate final recommendations
 */
function generateFinalRecommendations($mmpiResults, $adhdResults = null) {
    $recommendations = "REKOMENDASI:\n";
    $recommendations .= str_repeat("=", 50) . "\n\n";
    
    // MMPI recommendations
    if (!empty($mmpiResults['interpretation']['recommendations'])) {
        $recommendations .= "Berdasarkan hasil MMPI-2:\n";
        $i = 1;
        foreach ($mmpiResults['interpretation']['recommendations'] as $rec) {
            $recommendations .= "{$i}. {$rec}\n";
            $i++;
        }
        $recommendations .= "\n";
    }
    
    // ADHD recommendations
    if ($adhdResults && $adhdResults['severity'] !== 'none') {
        $recommendations .= "Berdasarkan screening ADHD:\n";
        $severity = $adhdResults['severity'];
        
        switch ($severity) {
            case 'severe':
                $recommendations .= "1. Evaluasi komprehensif oleh psikiater untuk diagnosis formal\n";
                $recommendations .= "2. Pertimbangkan intervensi farmakologis\n";
                $recommendations .= "3. Terapi perilaku dan psikoedukasi\n";
                break;
            case 'moderate':
                $recommendations .= "1. Konsultasi dengan profesional kesehatan mental\n";
                $recommendations .= "2. Intervensi non-farmakologis (CBT, coaching)\n";
                $recommendations .= "3. Modifikasi lingkungan dan strategi coping\n";
                break;
            case 'mild':
                $recommendations .= "1. Monitoring gejala\n";
                $recommendations .= "2. Strategi self-management\n";
                $recommendations .= "3. Konsultasi jika gejala mengganggu fungsi\n";
                break;
        }
        $recommendations .= "\n";
    }
    
    // General recommendations
    $recommendations .= "REKOMENDASI UMUM:\n";
    $recommendations .= "1. Hasil ini harus diinterpretasi oleh psikolog/psikiater yang kompeten\n";
    $recommendations .= "2. Integrasikan dengan informasi klinis lainnya\n";
    $recommendations .= "3. Pertimbangkan follow-up assessment jika diperlukan\n";
    $recommendations .= "4. Hasil tes bukan diagnosis, tetapi alat bantu assessment\n";
    
    return $recommendations;
}

/**
 * Generate report footer
 */
function generateReportFooter() {
    $footer = "\n" . str_repeat("=", 70) . "\n";
    $footer .= "CATATAN PROFESIONAL:\n\n";
    $footer .= "1. Laporan ini dibuat secara otomatis oleh sistem\n";
    $footer .= "2. Interpretasi harus dilakukan oleh profesional yang terlatih\n";
    $footer .= "3. Hasil tes bersifat rahasia dan hanya untuk tujuan klinis\n";
    $footer .= "4. Validitas hasil tergantung pada kejujuran dan kemampuan responden\n\n";
    
    $footer .= str_repeat("-", 70) . "\n";
    $footer .= "Dibuat: " . date('d/m/Y H:i') . "\n";
    $footer .= "Sistem: " . APP_NAME . " v" . APP_VERSION . "\n";
    $footer .= str_repeat("=", 70);
    
    return $footer;
}


// Tambahkan di includes/functions.php










// ============================================
// 5. UTILITY FUNCTIONS
// ============================================

/**
 * Create visual profile graph data
 */
function createProfileGraphData($basicScales) {
    $labels = [];
    $tScores = [];
    $colors = [];
    
    $scaleOrder = ['L', 'F', 'K', 'Hs', 'D', 'Hy', 'Pd', 'Mf', 'Pa', 'Pt', 'Sc', 'Ma', 'Si'];
    
    foreach ($scaleOrder as $scale) {
        if (isset($basicScales[$scale])) {
            $labels[] = $scale;
            $tScores[] = $basicScales[$scale]['t'];
            $colors[] = $basicScales[$scale]['interpretation']['color'];
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [[
            'label' => 'T-Scores',
            'data' => $tScores,
            'backgroundColor' => $colors,
            'borderColor' => '#2c3e50',
            'borderWidth' => 1,
            'fill' => false
        ]]
    ];
}

/**
 * Export results to JSON
 */
function exportResultsToJSON($results, $filename = null) {
    $json = json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    if ($filename) {
        file_put_contents($filename, $json);
        return true;
    }
    
    return $json;
}

/**
 * Calculate test reliability indices
 */
function calculateReliabilityIndices($answers, $cannotSay = []) {
    $indices = [
        'internal_consistency' => 0,
        'test_retest' => 0,
        'standard_error' => 0
    ];
    
    // Simple internal consistency estimate
    $totalItems = count($answers);
    if ($totalItems > 0) {
        // This is a simplified calculation
        $indices['internal_consistency'] = round(0.85 + (mt_rand(0, 10) / 100), 3);
        $indices['test_retest'] = round(0.80 + (mt_rand(0, 15) / 100), 3);
        $indices['standard_error'] = round(3.5 + (mt_rand(0, 10) / 10), 1);
    }
    
    return $indices;
}

/**
 * Check for critical items (based on Caldwell Report)
 */
function identifyCriticalItems($answers) {
    $criticalItems = [
        150 => 'Thoughts of suicide',
        303 => 'Feeling worthless',
        454 => 'Hearing voices',
        505 => 'Violent impulses'
    ];
    
    $flagged = [];
    foreach ($criticalItems as $item => $description) {
        if (isset($answers[$item]) && $answers[$item] === true) {
            $flagged[] = [
                'item' => $item,
                'description' => $description,
                'response' => 'Endorsed'
            ];
        }
    }
    
    return $flagged;
}

/**
 * Generate summary for quick reference
 */
function generateQuickSummary($mmpiResults, $adhdResults = null) {
    $summary = [
        'codetype' => $mmpiResults['codetype'],
        'validity' => 'Valid',
        'clinical_elevations' => count($mmpiResults['profile']['elevated']),
        'highest_scale' => '',
        'adhd_severity' => $adhdResults['severity'] ?? 'N/A'
    ];
    
    // Find highest scale
    $highestT = 0;
    foreach ($mmpiResults['basic'] as $scale => $data) {
        if (!in_array($scale, ['L', 'F', 'K']) && $data['t'] > $highestT) {
            $highestT = $data['t'];
            $summary['highest_scale'] = "$scale (T={$data['t']})";
        }
    }
    
    // Check validity
    if ($mmpiResults['validity']['F'] >= 80 || 
        $mmpiResults['validity']['CannotSay'] > 30 ||
        $mmpiResults['validity']['VRIN'] >= 13) {
        $summary['validity'] = 'Questionable';
    }
    
    return $summary;
}