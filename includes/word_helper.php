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
 * Convert DOCX to PDF by reading DOCX XML and re-typesetting with PyMuPDF (fitz).
 * Uses ONLY standard PDF Type1 fonts (Times-Roman, Times-Bold, Helvetica) which
 * are supported by EVERY PDF viewer (Chrome, Firefox, Safari, Adobe Reader) without
 * any font embedding. This permanently eliminates all font rendering issues.
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

    // Python script: read DOCX XML → re-typeset → PDF using standard fonts only
    $py = <<<'PYEOF'
import sys, zipfile, re
import xml.etree.ElementTree as ET
import fitz

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

DOCX = sys.argv[1]
PDF  = sys.argv[2]

WNS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'

def tag(el):
    return el.tag.split('}')[1] if '}' in el.tag else el.tag

def w(name): return f'{{{WNS}}}{name}'

# ── Read DOCX XML ────────────────────────────────────────────────────
try:
    with zipfile.ZipFile(DOCX) as z:
        with z.open('word/document.xml') as f:
            root = ET.fromstring(f.read())
except Exception as e:
    print(f'error reading docx: {e}')
    sys.exit(1)

# ── Extract paragraphs with run-level bold/italic ────────────────────
paragraphs = []
body = root.find(w('body'))
if body is None:
    body = root

for para in body.findall('.//' + w('p')):
    pPr   = para.find(w('pPr'))
    style = ''
    align = 'left'
    if pPr is not None:
        ps = pPr.find(w('pStyle'))
        if ps is not None:
            style = (ps.get(w('val')) or '').lower()
        jc = pPr.find(w('jc'))
        if jc is not None:
            align = (jc.get(w('val')) or 'left').lower()

    runs = []
    for r in para.findall('.//' + w('r')):
        txt = ''.join((t.text or '') for t in r.findall(w('t')))
        if not txt:
            continue
        rPr   = r.find(w('rPr'))
        bold  = rPr is not None and rPr.find(w('b'))  is not None
        ital  = rPr is not None and rPr.find(w('i'))  is not None
        runs.append((txt, bold, ital))

    full = ''.join(r[0] for r in runs).strip()
    if full:
        paragraphs.append({'text': full, 'runs': runs, 'style': style, 'align': align})

# ── PDF layout constants (A4) ────────────────────────────────────────
PW, PH   = 595, 842
ML, MR   = 72, 72
MT, MB   = 80, 72
TW       = PW - ML - MR   # usable text width

# Standard PDF Type1 fonts — built into every viewer, never need embedding
F_NORMAL = 'tiro'    # Times-Roman
F_BOLD   = 'tibo'    # Times-Bold
F_ITALIC = 'tiit'    # Times-Italic
F_BI     = 'tibi'    # Times-BoldItalic
BASE     = 11.0

def pick_font(bold, ital):
    if bold and ital: return F_BI
    if bold:          return F_BOLD
    if ital:          return F_ITALIC
    return F_NORMAL

def para_fontsize(style):
    if style in ('heading1','1'): return 15.0
    if style in ('heading2','2'): return 13.0
    if style in ('heading3','3'): return 12.0
    return BASE

def measure(text, fontname, fontsize):
    """Return width of text using fitz font metrics."""
    try:
        fo = fitz.Font(fontname=fontname)
        return fo.text_length(text, fontsize)
    except Exception:
        return len(text) * fontsize * 0.55   # rough fallback

def wrap_text(text, fontname, fontsize, max_width):
    """Word-wrap text and return list of lines."""
    words  = text.split()
    lines  = []
    cur    = ''
    for word in words:
        candidate = (cur + ' ' + word).strip() if cur else word
        if measure(candidate, fontname, fontsize) <= max_width:
            cur = candidate
        else:
            if cur:
                lines.append(cur)
            cur = word
    if cur:
        lines.append(cur)
    return lines or ['']

# ── Build PDF ────────────────────────────────────────────────────────
doc  = fitz.open()
page = doc.new_page(width=PW, height=PH)
y    = float(MT)

def ensure_space(needed):
    global page, y
    if y + needed > PH - MB:
        page = doc.new_page(width=PW, height=PH)
        y    = float(MT)

for para in paragraphs:
    style    = para['style']
    align    = para['align']
    runs     = para['runs']
    fontsize = para_fontsize(style)
    lh       = fontsize * 1.5    # line height

    # Determine dominant font for the paragraph (used for wrapping)
    has_bold = any(r[1] for r in runs)
    has_ital = any(r[2] for r in runs)
    dom_font = pick_font(has_bold, has_ital)

    is_heading = style.startswith('heading') or style in ('title',)
    if is_heading:
        ensure_space(lh * 2)
        y += lh * 0.4

    # Wrap full text using dominant font
    full_text = para['text']
    lines     = wrap_text(full_text, dom_font, fontsize, TW)

    for li, line in enumerate(lines):
        ensure_space(lh)
        tw_line = measure(line, dom_font, fontsize)
        if align in ('center',):
            x = ML + max(0, (TW - tw_line) / 2.0)
        elif align in ('right',):
            x = ML + max(0, TW - tw_line)
        else:
            x = float(ML)

        page.insert_text(
            (x, y + fontsize),
            line,
            fontsize=fontsize,
            fontname=dom_font,
            color=(0, 0, 0)
        )
        y += lh

    y += fontsize * 0.5   # paragraph gap

doc.save(PDF, garbage=4, deflate=True, clean=True)
doc.close()
print('success')
PYEOF;

    $py_path = tempnam(sys_get_temp_dir(), 'rjpes_docx2pdf_') . '.py';
    file_put_contents($py_path, $py);

    $py_exe = (DIRECTORY_SEPARATOR === '\\') ? 'python' : 'python3';
    $cmd    = "$py_exe " . escapeshellarg($py_path)
            . " " . escapeshellarg($docx_abs)
            . " " . escapeshellarg($pdf_abs)
            . " 2>&1";
    $output = shell_exec($cmd);
    @unlink($py_path);

    if (strpos($output, 'success') === false) {
        error_log("DOCX→PDF conversion failed: " . trim($output));
        return false;
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
    $py_content .= "        header_text = \"RJPES | Vol. " . addslashes($volume) . ", Issue " . addslashes($issue) . " (" . addslashes($month_year) . ") | Journal No: " . addslashes($journal_number) . "\"\n";
    $py_content .= "        page.insert_text((54, 50), header_text, fontsize=9, fontname=\"helv\", color=(0, 0, 0))\n";
    $py_content .= "        page.draw_line((54, 57), (width - 54, 57), color=(0, 0, 0), width=0.5)\n";
    $py_content .= "        footer_text = \"RJPES Journal Portal | Official Publication of ACTPE, Calicut University\"\n";
    $py_content .= "        page.insert_text((54, height - 40), footer_text, fontsize=8, fontname=\"helv\", color=(0, 0, 0))\n";
    $py_content .= "        page_text = f\"Page {i + 1}\"\n";
    $py_content .= "        page.insert_text((width - 95, height - 40), page_text, fontsize=8, fontname=\"helv\", color=(0, 0, 0))\n";
    $py_content .= "    \n";
    $py_content .= "    # 3. Save — body already uses standard Type1 fonts (Times/Helvetica),\n";
    $py_content .= "    #    no flattening needed. These fonts render in every browser.\n";
    $py_content .= "    doc.save(\"" . addslashes($out_abs) . "\", garbage=4, deflate=True, clean=True)\n";
    $py_content .= "    doc.close()\n";
    $py_content .= "    print('success')\n";
    $py_content .= "except Exception as e:\n";
    $py_content .= "    print('error:', str(e))\n";
    
    $py_path = tempnam(sys_get_temp_dir(), 'rjpes_') . '.py';
    file_put_contents($py_path, $py_content);
    
    $py_exe = (DIRECTORY_SEPARATOR === '\\') ? 'python' : 'python3';
    $cmd = "$py_exe " . escapeshellarg($py_path) . " 2>&1";
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
    $cmd = "$py_exe " . escapeshellarg($py_path) . " 2>&1";
    $output = shell_exec($cmd);
    @unlink($py_path);
    
    if (trim($output) !== 'success') {
        error_log("PDF extract body failed: " . trim($output));
    }
    
    return file_exists($out_abs) && filesize($out_abs) > 0;
}

