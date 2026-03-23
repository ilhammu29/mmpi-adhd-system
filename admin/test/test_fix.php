<?php
// test_fix.php
require_once 'includes/config.php';

// Test sanitize function
$testCases = [
    null,
    '',
    'Hello World',
    '  Spaces  ',
    123,
    0,
    true,
    false,
    ['test', null, 'array']
];

echo "<h2>Testing sanitize() function</h2>";
foreach ($testCases as $test) {
    $result = sanitize($test);
    echo "Input: " . var_export($test, true) . "<br>";
    echo "Output: " . var_export($result, true) . "<br><hr>";
}

// Test escape function
echo "<h2>Testing escape() function</h2>";
$htmlTest = '<script>alert("XSS")</script>';
echo "Original: " . $htmlTest . "<br>";
echo "Escaped: " . escape($htmlTest) . "<br>";

// Test null handling
echo "<h2>Testing null handling</h2>";
echo "sanitize(null): " . var_export(sanitize(null), true) . "<br>";
echo "escape(null): " . var_export(escape(null), true) . "<br>";