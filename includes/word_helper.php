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

/**
 * Convert DOCX/DOC to PDF using MS Word COM Automation via a temporary PowerShell script.
 * @param string $docx_path
 * @param string $pdf_path
 * @return bool
 */
function rjpes_convert_docx_to_pdf($docx_path, $pdf_path) {
    $docx_abs = realpath($docx_path);
    if (!$docx_abs) {
        return false;
    }
    
    // Ensure output directory exists
    $pdf_dir = dirname($pdf_path);
    if (!file_exists($pdf_dir)) {
        mkdir($pdf_dir, 0777, true);
    }
    
    // Realpath for target PDF path or directory
    $pdf_abs = $pdf_path;
    if (file_exists($pdf_path)) {
        $pdf_abs = realpath($pdf_path);
        @unlink($pdf_abs);
    } else {
        $dir_real = realpath($pdf_dir);
        if ($dir_real) {
            $pdf_abs = $dir_real . DIRECTORY_SEPARATOR . basename($pdf_path);
        }
    }
    
    // Write temporary PS1 script to avoid quoting issues
    $ps1_content = "\$word = New-Object -ComObject Word.Application\n";
    $ps1_content .= "\$word.Visible = \$false\n";
    $ps1_content .= "try {\n";
    $ps1_content .= "    \$doc = \$word.Documents.Open(\"" . addslashes($docx_abs) . "\")\n";
    $ps1_content .= "    \$doc.SaveAs(\"" . addslashes($pdf_abs) . "\", 17)\n";
    $ps1_content .= "    \$doc.Close()\n";
    $ps1_content .= "} catch {\n";
    $ps1_content .= "    Write-Error \$_.Exception.Message\n";
    $ps1_content .= "} finally {\n";
    $ps1_content .= "    \$word.Quit()\n";
    $ps1_content .= "}\n";
    
    $ps1_path = tempnam(sys_get_temp_dir(), 'rjpes_') . '.ps1';
    file_put_contents($ps1_path, $ps1_content);
    
    $cmd = "powershell -ExecutionPolicy Bypass -File " . escapeshellarg($ps1_path) . " 2>&1";
    $output = shell_exec($cmd);
    @unlink($ps1_path);
    
    if (!empty($output)) {
        error_log("PowerShell Word conversion output: " . trim($output));
    }
    
    return file_exists($pdf_abs) && filesize($pdf_abs) > 0;
}

/**
 * Merge cover PDF and body PDF using a temporary Python script and pypdf.
 * @param string $cover_pdf_path
 * @param string $body_pdf_path
 * @param string $output_pdf_path
 * @return bool
 */
function rjpes_pdf_merge($cover_pdf_path, $body_pdf_path, $output_pdf_path, $journal_number = '', $volume = '', $issue = '', $month_year = '') {
    $cover_abs = realpath($cover_pdf_path);
    $body_abs = realpath($body_pdf_path);
    if (!$cover_abs || !$body_abs) {
        return false;
    }
    
    // Ensure output directory exists
    $pdf_dir = dirname($output_pdf_path);
    if (!file_exists($pdf_dir)) {
        mkdir($pdf_dir, 0777, true);
    }
    
    $out_abs = $output_pdf_path;
    if (file_exists($output_pdf_path)) {
        $out_abs = realpath($output_pdf_path);
        @unlink($out_abs);
    } else {
        $dir_real = realpath($pdf_dir);
        if ($dir_real) {
            $out_abs = $dir_real . DIRECTORY_SEPARATOR . basename($output_pdf_path);
        }
    }
    
    // Create a unique temporary path for the initial merged file to avoid file lock issues in PyMuPDF
    $temp_merged_path = tempnam(sys_get_temp_dir(), 'rjpes_merge_') . '.pdf';
    
    $py_content = "import sys\n";
    $py_content .= "import fitz\n"; // PyMuPDF
    $py_content .= "from pypdf import PdfWriter\n";
    $py_content .= "try:\n";
    $py_content .= "    # 1. Merge PDFs using pypdf to a temp path\n";
    $py_content .= "    merger = PdfWriter()\n";
    $py_content .= "    merger.append(\"" . addslashes($cover_abs) . "\")\n";
    $py_content .= "    merger.append(\"" . addslashes($body_abs) . "\")\n";
    $py_content .= "    merger.write(\"" . addslashes($temp_merged_path) . "\")\n";
    $py_content .= "    merger.close()\n";
    $py_content .= "    \n";
    $py_content .= "    # 2. Add header, line, footer, and page numbers to body pages, saving to the final destination\n";
    $py_content .= "    doc = fitz.open(\"" . addslashes($temp_merged_path) . "\")\n";
    $py_content .= "    total_pages = len(doc)\n";
    $py_content .= "    for i in range(1, total_pages):\n";
    $py_content .= "        page = doc[i]\n";
    $py_content .= "        width = page.rect.width\n";
    $py_content .= "        height = page.rect.height\n";
    $py_content .= "        \n";
    $py_content .= "        # Header text\n";
    $py_content .= "        header_text = \"RJPES | Vol. " . addslashes($volume) . ", Issue " . addslashes($issue) . " (" . addslashes($month_year) . ") | Journal No: " . addslashes($journal_number) . "\"\n";
    $py_content .= "        page.insert_text((54, 50), header_text, fontsize=9, fontname=\"helv\", color=(0, 0, 0))\n";
    $py_content .= "        \n";
    $py_content .= "        # Header line separator\n";
    $py_content .= "        page.draw_line((54, 57), (width - 54, 57), color=(0, 0, 0), width=0.5)\n";
    $py_content .= "        \n";
    $py_content .= "        # Footer text\n";
    $py_content .= "        footer_text = \"RJPES Journal Portal | Official Publication of ACTPE, Calicut University\"\n";
    $py_content .= "        page.insert_text((54, height - 40), footer_text, fontsize=8, fontname=\"helv\", color=(0, 0, 0))\n";
    $py_content .= "        \n";
    $py_content .= "        # Page number\n";
    $py_content .= "        page_text = f\"Page {i + 1}\"\n";
    $py_content .= "        page.insert_text((width - 95, height - 40), page_text, fontsize=8, fontname=\"helv\", color=(0, 0, 0))\n";
    $py_content .= "        \n";
    $py_content .= "    doc.save(\"" . addslashes($out_abs) . "\")\n";
    $py_content .= "    doc.close()\n";
    $py_content .= "    print('success')\n";
    $py_content .= "except Exception as e:\n";
    $py_content .= "    print('error:', str(e))\n";
    
    $py_path = tempnam(sys_get_temp_dir(), 'rjpes_') . '.py';
    file_put_contents($py_path, $py_content);
    
    $cmd = "python " . escapeshellarg($py_path);
    $output = shell_exec($cmd);
    @unlink($py_path);
    @unlink($temp_merged_path);
    
    if (trim($output) !== 'success') {
        error_log("PDF merge failed: " . $output);
        return false;
    }
    
    return file_exists($out_abs) && filesize($out_abs) > 0;
}

/**
 * Extract all pages except page 1 from a PDF file using pypdf.
 * @param string $merged_pdf_path
 * @param string $output_body_path
 * @return bool
 */
function rjpes_pdf_extract_body($merged_pdf_path, $output_body_path) {
    $merged_abs = realpath($merged_pdf_path);
    if (!$merged_abs) {
        return false;
    }
    
    // Ensure output directory exists
    $pdf_dir = dirname($output_body_path);
    if (!file_exists($pdf_dir)) {
        mkdir($pdf_dir, 0777, true);
    }
    
    $out_abs = $output_body_path;
    if (file_exists($output_body_path)) {
        $out_abs = realpath($output_body_path);
        @unlink($out_abs);
    } else {
        $dir_real = realpath($pdf_dir);
        if ($dir_real) {
            $out_abs = $dir_real . DIRECTORY_SEPARATOR . basename($output_body_path);
        }
    }
    
    $py_content = "import sys\n";
    $py_content .= "from pypdf import PdfReader, PdfWriter\n";
    $py_content .= "try:\n";
    $py_content .= "    reader = PdfReader(\"" . addslashes($merged_abs) . "\")\n";
    $py_content .= "    writer = PdfWriter()\n";
    $py_content .= "    # Extract from page index 1 to end (pages 2+)\n";
    $py_content .= "    if len(reader.pages) > 1:\n";
    $py_content .= "        for i in range(1, len(reader.pages)):\n";
    $py_content .= "            writer.add_page(reader.pages[i])\n";
    $py_content .= "    else:\n";
    $py_content .= "        # Fallback to copy the single page if no other pages\n";
    $py_content .= "        writer.add_page(reader.pages[0])\n";
    $py_content .= "    with open(\"" . addslashes($out_abs) . "\", 'wb') as f:\n";
    $py_content .= "        writer.write(f)\n";
    $py_content .= "    print('success')\n";
    $py_content .= "except Exception as e:\n";
    $py_content .= "    print('error:', str(e))\n";
    
    $py_path = tempnam(sys_get_temp_dir(), 'rjpes_') . '.py';
    file_put_contents($py_path, $py_content);
    
    $cmd = "python " . escapeshellarg($py_path);
    shell_exec($cmd);
    @unlink($py_path);
    
    return file_exists($out_abs) && filesize($out_abs) > 0;
}

