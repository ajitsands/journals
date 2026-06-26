<?php
/**
 * Temporary script to test PDF title encoding.
 */
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

$journal_id = 38;

function local_sanitize_utf8_for_pdf($text) {
    if (empty($text)) {
        return '';
    }
    $replacements = [
        "\xe2\x80\x98" => "'", // U+2018 left single quote
        "\xe2\x80\x99" => "'", // U+2019 right single quote (curly apostrophe)
        "\xe2\x80\x9a" => "'",
        "\xe2\x80\x9b" => "'",
        "\xe2\x80\x9c" => '"', // U+201C left double quote
        "\xe2\x80\x9d" => '"', // U+201D right double quote
        "\xe2\x80\x9e" => '"',
        "\xe2\x80\x9f" => '"',
        "\xe2\x80\x93" => '-', // U+2013 en-dash
        "\xe2\x80\x94" => '-', // U+2014 em-dash
        "\xe2\x80\xa6" => '...', // U+2026 horizontal ellipsis
        "\xc2\xa0" => ' ',
    ];
    
    echo "Before strtr replacements (length: " . strlen($text) . "):\n";
    for ($i = 0; $i < strlen($text); $i++) {
        $c = $text[$i];
        if (ord($c) > 127) {
            echo "  [$i] character: " . var_export($c, true) . " (code: " . ord($c) . ")\n";
        }
    }
    
    $text = strtr($text, $replacements);
    
    echo "After strtr replacements (length: " . strlen($text) . "):\n";
    for ($i = 0; $i < strlen($text); $i++) {
        $c = $text[$i];
        if (ord($c) > 127) {
            echo "  [$i] character: " . var_export($c, true) . " (code: " . ord($c) . ")\n";
        }
    }
    
    if (mb_check_encoding($text, 'UTF-8')) {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
        if ($converted !== false) {
            return $converted;
        }
        $converted_mb = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        if ($converted_mb !== false) {
            return $converted_mb;
        }
    }
    return $text;
}

try {
    $stmt = $pdo->prepare("SELECT j.*, u.fullname AS author_name, u.email AS author_email FROM journals j JOIN users u ON j.author_id = u.id WHERE j.id = ?");
    $stmt->execute([$journal_id]);
    $journal = $stmt->fetch();

    if (!$journal) {
        die("Journal not found.");
    }

    $title = html_entity_decode($journal['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    echo "Raw Title in PHP: " . $title . "\n";
    echo "Bytes of Title (over 127):\n";
    for ($i = 0; $i < strlen($title); $i++) {
        $c = $title[$i];
        if (ord($c) > 127) {
            echo "  [$i] character: " . var_export($c, true) . " (code: " . ord($c) . ")\n";
        }
    }

    $clean_title = local_sanitize_utf8_for_pdf($title);
    echo "\nClean Title in PHP: " . $clean_title . "\n";
    echo "Bytes of Clean Title (over 127):\n";
    for ($i = 0; $i < strlen($clean_title); $i++) {
        $c = $clean_title[$i];
        if (ord($c) > 127) {
            echo "  [$i] character: " . var_export($c, true) . " (code: " . ord($c) . ")\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
