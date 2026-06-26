<?php
/**
 * Temporary script to inspect PDF text after regeneration.
 */
header('Content-Type: text/plain; charset=utf-8');

$pdf_path = __DIR__ . '/uploads/manuscript_1782462758_6186.pdf';

if (!file_exists($pdf_path)) {
    die("PDF not found at $pdf_path");
}

// Let's run python to read the PDF text of page 1 (index 0)
$python_code = <<<PY
import fitz
import sys

doc = fitz.open("$pdf_path")
page = doc[0]
text = page.get_text()
print("Page 1 Text (After Fix):")
print("----------------------------------------")
print(text)
print("----------------------------------------")
doc.close()
PY;

$temp_py = tempnam(sys_get_temp_dir(), 'pdf_read');
file_put_contents($temp_py, $python_code);

$output = shell_exec("python3 " . escapeshellarg($temp_py) . " 2>&1");
echo $output;

@unlink($temp_py);
