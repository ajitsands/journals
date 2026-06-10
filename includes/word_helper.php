<?php
/**
 * Helper utilities for extracting text from .doc and .docx files
 */

/**
 * Extract text from a DOCX file
 * @param string $file_path
 * @return string|false
 */
function rjpes_read_docx($file_path) {
    if (!file_exists($file_path)) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($file_path) === true) {
        $xml_content = '';
        if (($index = $zip->locateName('word/document.xml')) !== false) {
            $xml_content = $zip->getFromIndex($index);
        }
        $zip->close();

        if ($xml_content) {
            $dom = new DOMDocument();
            // Suppress warnings on HTML-like tags or namespace declarations
            if (@$dom->loadXML($xml_content)) {
                $ns_uri = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
                $paragraphs = $dom->getElementsByTagNameNS($ns_uri, 'p');
                $text_content = '';

                foreach ($paragraphs as $p) {
                    $p_text = '';
                    $t_elements = $p->getElementsByTagNameNS($ns_uri, 't');
                    foreach ($t_elements as $t) {
                        $p_text .= $t->nodeValue;
                    }
                    if (trim($p_text) !== '') {
                        $text_content .= trim($p_text) . "\n\n";
                    }
                }
                return trim($text_content);
            }
        }
    }
    return false;
}

/**
 * Extract text from a binary DOC file (fallback)
 * @param string $file_path
 * @return string|false
 */
function rjpes_read_doc($file_path) {
    if (!file_exists($file_path)) {
        return false;
    }

    if (($fh = fopen($file_path, 'r')) !== false) {
        // Read file contents
        $data = fread($fh, filesize($file_path));
        fclose($fh);

        // Filter out non-printable ASCII/unicode control characters to extract raw text
        $cleaned = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $char = $data[$i];
            $ord = ord($char);
            if ($ord == 13 || $ord == 10) {
                $cleaned .= "\n";
            } elseif ($ord >= 32 && $ord <= 126) {
                $cleaned .= $char;
            }
        }

        // Normalize spacing and newlines
        $cleaned = preg_replace("/\r\n?/", "\n", $cleaned);
        $cleaned = preg_replace("/\n\n+/", "\n\n", $cleaned);
        return trim($cleaned);
    }
    return false;
}
