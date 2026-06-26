<?php
/**
 * Debug script: test sanitize_utf8_for_pdf on the server
 * DELETE after use.
 */
header('Content-Type: text/plain; charset=utf-8');

// Reproduce the problematic title exactly as it would be in the DB
$title_from_db = "THE EFFECTS OF COMBINED COMPLEX AND  CLOSED KINETIC CHAIN TRAINING VERSUS TRADITIONAL CONDITIONING ON COLLEGE STUDENTS\xe2\x80\x99 FITNESS PROFILES";

echo "=== TITLE FROM DB (hex of last 20 chars) ===\n";
$tail = substr($title_from_db, -30);
echo "String: " . $tail . "\n";
echo "Hex: " . bin2hex($tail) . "\n\n";

// Test strtr replacement
$replacements = [
    "\xe2\x80\x98" => "'",
    "\xe2\x80\x99" => "'",   // U+2019 right single quotation mark
    "\xe2\x80\x9c" => '"',
    "\xe2\x80\x9d" => '"',
    "\xe2\x80\x93" => '-',
    "\xe2\x80\x94" => '-',
    "\xe2\x80\xa6" => '...',
    "\xc2\xa0"     => ' ',
];
$after_strtr = strtr($title_from_db, $replacements);
$tail2 = substr($after_strtr, -30);
echo "=== AFTER STRTR (hex of last 30 chars) ===\n";
echo "String: " . $tail2 . "\n";
echo "Hex: " . bin2hex($tail2) . "\n\n";

// Test iconv CP1252//TRANSLIT
$after_iconv = @iconv('UTF-8', 'CP1252//TRANSLIT', $after_strtr);
echo "=== AFTER ICONV CP1252//TRANSLIT ===\n";
if ($after_iconv === false) {
    echo "iconv returned FALSE\n";
} else {
    $tail3 = substr($after_iconv, -30);
    echo "String: " . $tail3 . "\n";
    echo "Hex: " . bin2hex($tail3) . "\n";
}
echo "\n";

// Test iconv with original (skipping strtr)
$after_iconv_orig = @iconv('UTF-8', 'CP1252//TRANSLIT', $title_from_db);
echo "=== AFTER ICONV CP1252//TRANSLIT (without strtr) ===\n";
if ($after_iconv_orig === false) {
    echo "iconv returned FALSE\n";
} else {
    $tail4 = substr($after_iconv_orig, -30);
    echo "String: " . $tail4 . "\n";
    echo "Hex: " . bin2hex($tail4) . "\n";
}
echo "\n";

// Check what byte the iconv produces for U+2019
echo "=== WHAT DOES ICONV PRODUCE FOR U+2019? ===\n";
$test = "\xe2\x80\x99";
echo "Input hex: " . bin2hex($test) . "\n";
$out = @iconv('UTF-8', 'CP1252//TRANSLIT', $test);
if ($out === false) {
    echo "TRANSLIT: FALSE\n";
} else {
    echo "TRANSLIT output hex: " . bin2hex($out) . " (decimal: " . ord($out) . ")\n";
}
$out2 = @iconv('UTF-8', 'CP1252//IGNORE', $test);
if ($out2 === false) {
    echo "IGNORE: FALSE\n";
} else {
    echo "IGNORE output: '" . $out2 . "' hex: " . bin2hex($out2) . " (length: " . strlen($out2) . ")\n";
}
