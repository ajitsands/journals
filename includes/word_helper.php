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
 * Convert DOCX/DOC to PDF using MS Word (Windows) or LibreOffice (Linux).
 * These converters preserve tables, images, graphs, and all formatting.
 * Font rendering issues are fixed later during the merge step (flatten to JPEG).
 *
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
    
    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows: MS Word COM Automation (preserves all formatting)
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
    } else {
        // Linux: LibreOffice headless (preserves tables, images, graphs)
        $out_dir = dirname($pdf_abs);
        
        $cmd = "export HOME=/home/rjpes && export XDG_CACHE_HOME=/home/rjpes/.cache && libreoffice --headless --convert-to pdf --outdir " . escapeshellarg($out_dir) . " " . escapeshellarg($docx_abs) . " 2>&1";
        $output = shell_exec($cmd);
        
        $in_filename = pathinfo($docx_abs, PATHINFO_FILENAME);
        $generated_pdf = $out_dir . DIRECTORY_SEPARATOR . $in_filename . '.pdf';
        
        if (!file_exists($generated_pdf)) {
            // Try soffice fallback
            $cmd = "export HOME=/home/rjpes && export XDG_CACHE_HOME=/home/rjpes/.cache && soffice --headless --convert-to pdf --outdir " . escapeshellarg($out_dir) . " " . escapeshellarg($docx_abs) . " 2>&1";
            $output = shell_exec($cmd);
        }
        
        if (file_exists($generated_pdf)) {
            if (realpath($generated_pdf) !== realpath($pdf_abs)) {
                @rename($generated_pdf, $pdf_abs);
            }
        } else {
            error_log("Linux PDF conversion failed. Command: $cmd, Output: " . trim($output));
        }
    }
    
    return file_exists($pdf_abs) && filesize($pdf_abs) > 0;
}


/**
 * Merge cover PDF and body PDF using PyMuPDF (fitz) only.
 * Using fitz's insert_pdf() instead of pypdf preserves all embedded fonts intact.
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
    
    $py_content = "import sys\n";
    $py_content .= "import fitz\n";
    $py_content .= "\n";
    $py_content .= "try:\n";
    $py_content .= "    # 1. Merge cover + body PDFs\n";
    $py_content .= "    doc = fitz.open(\"" . addslashes($cover_abs) . "\")\n";
    $py_content .= "    body = fitz.open(\"" . addslashes($body_abs) . "\")\n";
    $py_content .= "    doc.insert_pdf(body)\n";
    $py_content .= "    body.close()\n";
    $py_content .= "    \n";
    $py_content .= "    # 2. Add RJPES header, separator line, footer, and page numbers to body pages\n";
    $py_content .= "    total_pages = len(doc)\n";
    $py_content .= "    for i in range(1, total_pages):\n";
    $py_content .= "        page = doc[i]\n";
    $py_content .= "        width = page.rect.width\n";
    $py_content .= "        height = page.rect.height\n";
    $py_content .= "        # Clean up existing RJPES headers/footers if present to avoid overlap\n";
    $py_content .= "        has_hdr = False\n";
    $py_content .= "        for inst in page.search_for(\"RJPES\"):\n";
    $py_content .= "            if inst.y0 < 100:\n";
    $py_content .= "                has_hdr = True\n";
    $py_content .= "                break\n";
    $py_content .= "        if has_hdr:\n";
    $py_content .= "            page.add_redact_annot(fitz.Rect(0, 30, width, 65))\n";
    $py_content .= "            page.add_redact_annot(fitz.Rect(0, height - 55, width, height))\n";
    $py_content .= "            page.apply_redactions()\n";
    $py_content .= "        \n";
    $py_content .= "        header_text = \"RJPES | Vol. " . addslashes($volume) . ", Issue " . addslashes($issue) . " (" . addslashes($month_year) . ") | Journal No: " . addslashes($journal_number) . "\"\n";
    $py_content .= "        page.insert_text((54, 50), header_text, fontsize=9, fontname=\"helv\", color=(0, 0, 0))\n";
    $py_content .= "        page.draw_line((54, 57), (width - 54, 57), color=(0, 0, 0), width=0.5)\n";
    $py_content .= "        footer_text = \"RESEARCH JOURNAL ON PHYSICAL EDUCATION AND SPORTS (RJPES)\"\n";
    $py_content .= "        page.insert_text((54, height - 40), footer_text, fontsize=8, fontname=\"helv\", color=(0, 0, 0))\n";
    $py_content .= "    \n";
    $py_content .= "    # 3. Save the merged PDF (vector format)\n";
    $py_content .= "    doc.save(\"" . addslashes($out_abs) . "\", garbage=4, deflate=True, clean=True)\n";
    $py_content .= "    doc.close()\n";
    $py_content .= "    print('success')\n";
    $py_content .= "except Exception as e:\n";
    $py_content .= "    print('error:', str(e))\n";
    
    $py_path = tempnam(sys_get_temp_dir(), 'rjpes_') . '.py';
    file_put_contents($py_path, $py_content);
    
    $py_exe = (DIRECTORY_SEPARATOR === '\\') ? 'python' : 'python3';
    $cmd_prefix = (DIRECTORY_SEPARATOR === '\\') ? '' : 'export HOME=/home/rjpes && export XDG_CACHE_HOME=/home/rjpes/.cache && ';
    $cmd = $cmd_prefix . "$py_exe " . escapeshellarg($py_path) . " 2>&1";
    $output = shell_exec($cmd);
    @unlink($py_path);
    
    if (strpos($output, 'success') === false) {
        error_log("PDF merge failed: " . trim($output));
        return false;
    }
    
    return file_exists($out_abs) && filesize($out_abs) > 0;
}

/**
 * Extract all pages except page 1 from a PDF file using PyMuPDF (fitz).
 * Using fitz's insert_pdf() instead of pypdf preserves all embedded fonts intact.
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
    $py_content .= "import fitz\n"; // PyMuPDF only — preserves embedded fonts
    $py_content .= "try:\n";
    $py_content .= "    doc = fitz.open(\"" . addslashes($merged_abs) . "\")\n";
    $py_content .= "    out_doc = fitz.open()\n";
    $py_content .= "    # Extract from page index 1 to end (pages 2+), skipping the cover\n";
    $py_content .= "    if len(doc) > 1:\n";
    $py_content .= "        out_doc.insert_pdf(doc, from_page=1, to_page=len(doc)-1)\n";
    $py_content .= "    else:\n";
    $py_content .= "        # Fallback: copy the single page if no body pages exist\n";
    $py_content .= "        out_doc.insert_pdf(doc, from_page=0, to_page=0)\n";
    $py_content .= "    out_doc.save(\"" . addslashes($out_abs) . "\")\n";
    $py_content .= "    out_doc.close()\n";
    $py_content .= "    doc.close()\n";
    $py_content .= "    print('success')\n";
    $py_content .= "except Exception as e:\n";
    $py_content .= "    print('error:', str(e))\n";
    
    $py_path = tempnam(sys_get_temp_dir(), 'rjpes_') . '.py';
    file_put_contents($py_path, $py_content);
    
    $py_exe = (DIRECTORY_SEPARATOR === '\\') ? 'python' : 'python3';
    $cmd_prefix = (DIRECTORY_SEPARATOR === '\\') ? '' : 'export HOME=/home/rjpes && export XDG_CACHE_HOME=/home/rjpes/.cache && ';
    $cmd = $cmd_prefix . "$py_exe " . escapeshellarg($py_path) . " 2>&1";
    $output = shell_exec($cmd);
    @unlink($py_path);
    
    if (trim($output) !== 'success') {
        error_log("PDF extract body failed: " . trim($output));
    }
    
    return file_exists($out_abs) && filesize($out_abs) > 0;
}

/**
 * Regenerate and re-merge a journal's PDF using its current database metadata
 * (volume, issue, and published_at date).
 * This is useful if the admin changes the publication details or if they were 
 * originally submitted under a different set of edition settings.
 */
function rjpes_regenerate_journal_pdf($journal_id) {
    global $pdo;
    require_once __DIR__ . '/auth.php';
    
    // Fetch journal details
    $stmt = $pdo->prepare("SELECT j.*, u.fullname AS author_name, u.email AS author_email FROM journals j JOIN users u ON j.author_id = u.id WHERE j.id = ?");
    $stmt->execute([$journal_id]);
    $journal = $stmt->fetch();
    if (!$journal) return false;
    
    $manuscript_file = $journal['manuscript_file'];
    if (empty($manuscript_file)) return false;
    
    $pdf_path = __DIR__ . '/../' . $manuscript_file;
    if (!file_exists($pdf_path)) return false;
    
    // We also need the authors list
    $auth_stmt = $pdo->prepare("SELECT * FROM journal_authors WHERE journal_id = ? ORDER BY order_num ASC");
    $auth_stmt->execute([$journal_id]);
    $authors = $auth_stmt->fetchAll();
    
    // If authors list is empty, default to primary author
    if (empty($authors)) {
        $authors = [[
            'name' => $journal['author_name'] ?? '',
            'photo_path' => $journal['author_photo'] ?? null,
            'order_num' => 1
        ]];
    }
    
    // Prepare data for the PDF cover page generator
    $journal_pdf_data = [
        'id' => $journal['id'],
        'title' => $journal['title'],
        'author_name' => $journal['author_name'] ?? '',
        'subject_domain' => $journal['subject_domain'],
        'journal_number' => $journal['journal_number'],
        'abstract' => $journal['abstract'],
        'content' => '', // MUST BE EMPTY for cover page only
        'volume' => !empty($journal['volume']) ? $journal['volume'] : '20',
        'issue' => !empty($journal['issue']) ? $journal['issue'] : '1',
        'published_at' => !empty($journal['published_at']) ? $journal['published_at'] : date('Y-m-d H:i:s'),
        'author_photo' => $journal['author_photo'] ?? null,
        'authors' => $authors
    ];
    
    require_once __DIR__ . '/pdf_helper.php';
    
    // 1. Generate new Cover Page PDF
    $pdf_generator = new RJPES_PDF();
    $cover_bytes = $pdf_generator->generate($journal_pdf_data);
    
    $upload_dir = __DIR__ . '/../uploads/';
    $cover_temp = $upload_dir . 'cover_temp_regen_' . time() . '_' . rand(1000, 9999) . '.pdf';
    file_put_contents($cover_temp, $cover_bytes);
    
    // 2. Extract Body PDF from the existing merged PDF
    $body_temp = $upload_dir . 'body_temp_regen_' . time() . '_' . rand(1000, 9999) . '.pdf';
    $ext_success = rjpes_pdf_extract_body($pdf_path, $body_temp);
    
    if (!$ext_success) {
        @unlink($cover_temp);
        return false;
    }
    
    // Determine the edition month/year to draw in the running header/footer
    $pub_time = !empty($journal['published_at']) ? strtotime($journal['published_at']) : time();
    $edition_month_year = date('F Y', $pub_time);
    
    // 3. Merge the new cover and the extracted body
    $final_temp = $upload_dir . 'final_temp_regen_' . time() . '_' . rand(1000, 9999) . '.pdf';
    $merge_success = rjpes_pdf_merge(
        $cover_temp, 
        $body_temp, 
        $final_temp, 
        $journal['journal_number'], 
        $journal_pdf_data['volume'], 
        $journal_pdf_data['issue'], 
        $edition_month_year
    );
    
    // Clean up temp files
    @unlink($cover_temp);
    @unlink($body_temp);
    
    if ($merge_success && file_exists($final_temp) && filesize($final_temp) > 0) {
        // Overwrite the original PDF file with the new regenerated one
        @unlink($pdf_path);
        $ok = copy($final_temp, $pdf_path);
        @unlink($final_temp);
        return $ok;
    }
    
    if (file_exists($final_temp)) {
        @unlink($final_temp);
    }
    return false;
}
