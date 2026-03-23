<?php
// includes/graph_functions.php

// Generate graph data for basic scales
function generateBasicScalesGraphData($basicScales) {
    if (empty($basicScales)) {
        return [
            'labels' => [],
            'datasets' => []
        ];
    }
    
    $labels = [];
    $data = [];
    $colors = [];
    $borderColors = [];
    
    $scaleOrder = ['Hs', 'D', 'Hy', 'Pd', 'Mf', 'Pa', 'Pt', 'Sc', 'Ma', 'Si'];
    
    foreach ($scaleOrder as $scale) {
        if (isset($basicScales[$scale]) && is_array($basicScales[$scale])) {
            $score = $basicScales[$scale];
            $tScore = $score['t'] ?? 50;
            
            $labels[] = $scale;
            $data[] = $tScore;
            
            // Set color based on T-score
            if ($tScore >= 70) {
                $colors[] = 'rgba(231, 76, 60, 0.7)';      // Red for clinical
                $borderColors[] = 'rgba(231, 76, 60, 1)';
            } elseif ($tScore >= 60) {
                $colors[] = 'rgba(230, 126, 34, 0.7)';     // Orange for elevated
                $borderColors[] = 'rgba(230, 126, 34, 1)';
            } elseif ($tScore >= 40) {
                $colors[] = 'rgba(39, 174, 96, 0.7)';      // Green for normal
                $borderColors[] = 'rgba(39, 174, 96, 1)';
            } else {
                $colors[] = 'rgba(52, 152, 219, 0.7)';     // Blue for low
                $borderColors[] = 'rgba(52, 152, 219, 1)';
            }
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [[
            'label' => 'T-Score',
            'data' => $data,
            'backgroundColor' => $colors,
            'borderColor' => $borderColors,
            'borderWidth' => 2,
            'pointRadius' => 6,
            'pointHoverRadius' => 8,
            'tension' => 0
        ]]
    ];
}

// Generate graph data for validity scales
function generateValidityScalesGraphData($validityScores) {
    $labels = ['L', 'F', 'K'];
    $data = [];
    $colors = [];
    $borderColors = [];
    
    foreach ($labels as $scale) {
        $rawScore = $validityScores[$scale] ?? 0;
        $tScore = calculateValidityTScore($scale, $rawScore);
        
        $data[] = $tScore;
        
        // Set color based on T-score
        if ($tScore >= 70) {
            $colors[] = 'rgba(231, 76, 60, 0.7)';
            $borderColors[] = 'rgba(231, 76, 60, 1)';
        } elseif ($tScore >= 60) {
            $colors[] = 'rgba(230, 126, 34, 0.7)';
            $borderColors[] = 'rgba(230, 126, 34, 1)';
        } else {
            $colors[] = 'rgba(52, 152, 219, 0.7)';
            $borderColors[] = 'rgba(52, 152, 219, 1)';
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [[
            'label' => 'T-Score',
            'data' => $data,
            'backgroundColor' => $colors,
            'borderColor' => $borderColors,
            'borderWidth' => 2
        ]]
    ];
}

// Calculate T-score for validity scales
function calculateValidityTScore($scale, $rawScore) {
    $norms = [
        'L' => ['mean' => 5, 'sd' => 2],
        'F' => ['mean' => 6, 'sd' => 3],
        'K' => ['mean' => 13, 'sd' => 4]
    ];
    
    if (isset($norms[$scale])) {
        $mean = $norms[$scale]['mean'];
        $sd = $norms[$scale]['sd'];
        
        $z = ($rawScore - $mean) / $sd;
        $t = 50 + ($z * 10);
        
        return max(30, min(120, round($t)));
    }
    
    return 50;
}

// Generate graph data for clinical subscales
function generateClinicalSubscalesGraphData($harrisScales) {
    if (empty($harrisScales)) {
        return [
            'labels' => [],
            'datasets' => []
        ];
    }
    
    $labels = [];
    $data = [];
    $colors = [];
    
    // Group by main scale for better visualization
    $groupedData = [];
    foreach ($harrisScales as $subscale => $scoreData) {
        if (is_array($scoreData)) {
            $mainScale = substr($subscale, 0, 2);
            $tScore = $scoreData['t'] ?? 50;
            
            if (!isset($groupedData[$mainScale])) {
                $groupedData[$mainScale] = [];
            }
            
            $groupedData[$mainScale][] = [
                'subscale' => $subscale,
                'tScore' => $tScore
            ];
        }
    }
    
    // Prepare data for grouped bar chart
    $datasets = [];
    $colorPalette = [
        'D' => 'rgba(52, 152, 219, 0.7)',    // Blue
        'Hy' => 'rgba(155, 89, 182, 0.7)',   // Purple
        'Pd' => 'rgba(231, 76, 60, 0.7)',    // Red
        'Pa' => 'rgba(230, 126, 34, 0.7)',   // Orange
        'Sc' => 'rgba(39, 174, 96, 0.7)',    // Green
        'Ma' => 'rgba(241, 196, 15, 0.7)',   // Yellow
        'Si' => 'rgba(149, 165, 166, 0.7)'   // Gray
    ];
    
    $subscaleLabels = [];
    foreach ($groupedData as $mainScale => $subscales) {
        foreach ($subscales as $item) {
            $subscaleLabels[] = $item['subscale'];
        }
    }
    
    // Create one dataset for each main scale
    foreach ($groupedData as $mainScale => $subscales) {
        $scaleData = array_fill(0, count($subscaleLabels), null);
        
        foreach ($subscales as $index => $item) {
            $labelIndex = array_search($item['subscale'], $subscaleLabels);
            if ($labelIndex !== false) {
                $scaleData[$labelIndex] = $item['tScore'];
            }
        }
        
        $datasets[] = [
            'label' => $mainScale,
            'data' => $scaleData,
            'backgroundColor' => $colorPalette[$mainScale] ?? 'rgba(149, 165, 166, 0.7)',
            'borderColor' => str_replace('0.7', '1', $colorPalette[$mainScale] ?? 'rgba(149, 165, 166, 1)'),
            'borderWidth' => 1
        ];
    }
    
    return [
        'labels' => $subscaleLabels,
        'datasets' => $datasets
    ];
}

// Generate graph data for content scales
function generateContentScalesGraphData($contentScales) {
    if (empty($contentScales)) {
        return [
            'labels' => [],
            'datasets' => []
        ];
    }
    
    $labels = [];
    $data = [];
    $colors = [];
    $borderColors = [];
    
    // Content scale order
    $contentScaleOrder = ['ANX', 'FRS', 'OBS', 'DEP', 'HEA', 'BIZ', 'ANG', 'CYN', 'ASP', 'TPA', 'LSE', 'SOD', 'FAM', 'WRK', 'TRT'];
    
    foreach ($contentScaleOrder as $scale) {
        if (isset($contentScales[$scale]) && is_array($contentScales[$scale])) {
            $score = $contentScales[$scale];
            $tScore = $score['t'] ?? 50;
            
            $labels[] = $scale;
            $data[] = $tScore;
            
            // Set color based on T-score
            if ($tScore >= 65) {
                $colors[] = 'rgba(231, 76, 60, 0.7)';
                $borderColors[] = 'rgba(231, 76, 60, 1)';
            } elseif ($tScore >= 60) {
                $colors[] = 'rgba(230, 126, 34, 0.7)';
                $borderColors[] = 'rgba(230, 126, 34, 1)';
            } elseif ($tScore >= 40) {
                $colors[] = 'rgba(39, 174, 96, 0.7)';
                $borderColors[] = 'rgba(39, 174, 96, 1)';
            } else {
                $colors[] = 'rgba(52, 152, 219, 0.7)';
                $borderColors[] = 'rgba(52, 152, 219, 1)';
            }
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [[
            'label' => 'T-Score',
            'data' => $data,
            'backgroundColor' => $colors,
            'borderColor' => $borderColors,
            'borderWidth' => 2,
            'pointRadius' => 5,
            'pointHoverRadius' => 7
        ]]
    ];
}

// Generate graph data for supplementary scales
function generateSupplementaryScalesGraphData($supplementaryScales) {
    if (empty($supplementaryScales)) {
        return [
            'labels' => [],
            'datasets' => []
        ];
    }
    
    $labels = [];
    $data = [];
    $colors = [];
    $borderColors = [];
    
    // Common supplementary scales
    $supplementaryScaleOrder = ['A', 'R', 'Es', 'Do', 'Re', 'Mt', 'PK', 'MDS', 'Ho', 'O-H', 'MAC-R', 'AAS', 'APS', 'GM', 'GF'];
    
    foreach ($supplementaryScaleOrder as $scale) {
        if (isset($supplementaryScales[$scale]) && is_array($supplementaryScales[$scale])) {
            $score = $supplementaryScales[$scale];
            $tScore = $score['t'] ?? 50;
            
            $labels[] = $scale;
            $data[] = $tScore;
            
            // Set color based on T-score
            if ($tScore >= 65) {
                $colors[] = 'rgba(231, 76, 60, 0.7)';
                $borderColors[] = 'rgba(231, 76, 60, 1)';
            } elseif ($tScore >= 60) {
                $colors[] = 'rgba(230, 126, 34, 0.7)';
                $borderColors[] = 'rgba(230, 126, 34, 1)';
            } elseif ($tScore >= 40) {
                $colors[] = 'rgba(39, 174, 96, 0.7)';
                $borderColors[] = 'rgba(39, 174, 96, 1)';
            } else {
                $colors[] = 'rgba(52, 152, 219, 0.7)';
                $borderColors[] = 'rgba(52, 152, 219, 1)';
            }
        }
    }
    
    return [
        'labels' => $labels,
        'datasets' => [[
            'label' => 'T-Score',
            'data' => $data,
            'backgroundColor' => $colors,
            'borderColor' => $borderColors,
            'borderWidth' => 2,
            'pointRadius' => 5,
            'pointHoverRadius' => 7
        ]]
    ];
}

// Generate JavaScript for Chart.js graphs
function generateGraphJS($graphData, $canvasId, $graphType = 'bar', $options = []) {
    if (empty($graphData['labels'])) {
        return "console.log('No data for graph: {$canvasId}');";
    }
    
    $js = "
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('{$canvasId}');
        if (!ctx) {
            console.error('Canvas not found: {$canvasId}');
            return;
        }
        
        // Destroy existing chart
        if (Chart.getChart('{$canvasId}')) {
            Chart.getChart('{$canvasId}').destroy();
        }
        
        const data = " . json_encode($graphData) . ";
        
        const config = {
            type: '{$graphType}',
            data: data,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: " . ($graphType === 'line' ? 'true' : 'false') . ",
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw + ' T';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 30,
                        max: 120,
                        ticks: {
                            stepSize: 10,
                            callback: function(value) {
                                if (value === 30 || value === 50 || value === 70 || value === 100 || value === 120) {
                                    return value;
                                }
                                return '';
                            }
                        },
                        grid: {
                            color: '#e0e0e0'
                        },
                        title: {
                            display: true,
                            text: 'T-Score'
                        }
                    }
                }
            }
        };
        
        // Apply custom options
        " . (!empty($options) ? "Object.assign(config.options, " . json_encode($options) . ");" : "") . "
        
        new Chart(ctx, config);
    });
    </script>
    ";
    
    return $js;
}
?>