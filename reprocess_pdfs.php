<?php
/**
 * Server PDF Reprocessor — flattens ALL body pages as standard JPEG images.
 * Fixes Chrome/Firefox rendering for:
 *   - MS Word PDFs with Identity-H CID fonts (Chrome PDFium cannot render)
 *   - LibreOffice PDFs with JPEG2000 embedded content (Chrome cannot decode)
 *   - DroidSansFallback / missing-encoding fonts (render as boxes)
 *
 * Run at: https://rjpes.in/reprocess_pdfs.php
 * DELETE this file after running for security.
 */
header('Content-Type: text/plain; charset=utf-8');

$root    = __DIR__;
$uploads = $root . '/uploads';

echo "RJPES PDF Reprocessor (v2 — Flatten All Body Pages)\n";
echo "=====================================================\n\n";

// 1. Check Python + fitz availability
$py_exe = 'python3';
$check  = shell_exec("$py_exe -c \"import fitz; print('fitz-ok-' + fitz.__version__)\" 2>&1");
if (strpos($check, 'fitz-ok-') !== false) {
    echo "✔ PyMuPDF (fitz) available: " . trim($check) . "\n\n";
} else {
    echo "✘ PyMuPDF not available: " . trim($check) . "\n";
    echo "Install with:  pip3 install pymupdf\n";
    exit(1);
}

// 2. Python script — flatten ALL body pages as standard JPEG (no CID check needed)
$py_script = <<<'PYEOF'
import sys, os, fitz

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

def reprocess_pdf(in_path, out_path):
    """
    Flatten all body pages (pages 2+) as 150 DPI grayscale JPEG images.
    Cover page (page 1) is kept as-is (Helvetica Type1 = universally supported).
    This fixes Chrome/Firefox rendering for JPEG2000, CID fonts, and missing encodings.
    """
    src = fitz.open(in_path)
    out = fitz.open()
    
    # Cover page: always keep as vector (no encoding issues)
    out.insert_pdf(src, from_page=0, to_page=0)
    
    for i in range(1, len(src)):
        page = src[i]
        w = page.rect.width
        h = page.rect.height
        # Re-render as standard JPEG — universally supported by all PDF viewers
        mat = fitz.Matrix(150 / 72, 150 / 72)
        pix = page.get_pixmap(matrix=mat, colorspace=fitz.csGRAY, alpha=False)
        jpg = pix.tobytes('jpeg', jpg_quality=85)
        new_page = out.new_page(width=w, height=h)
        new_page.insert_image(new_page.rect, stream=jpg)
    
    src.close()
    out.save(out_path, garbage=4, deflate=True, clean=True)
    out.close()

uploads_dir = sys.argv[1]
pdfs = sorted([f for f in os.listdir(uploads_dir) if f.startswith('manuscript_') and f.endswith('.pdf')])
print(f"Found {len(pdfs)} manuscript PDFs to reprocess\n")

fixed  = 0
errors = 0
for fname in pdfs:
    in_path  = os.path.join(uploads_dir, fname)
    tmp_path = in_path + '.tmp.pdf'
    try:
        in_size = os.path.getsize(in_path) // 1024
        reprocess_pdf(in_path, tmp_path)
        os.replace(tmp_path, in_path)
        out_size = os.path.getsize(in_path) // 1024
        print(f"OK: {fname}  ({in_size} KB -> {out_size} KB)")
        fixed += 1
    except Exception as e:
        print(f"ERROR: {fname}: {e}")
        errors += 1
        if os.path.exists(tmp_path):
            os.remove(tmp_path)

print(f"\nDone: {fixed} reprocessed, {errors} errors")
PYEOF;

$py_path = sys_get_temp_dir() . '/rjpes_reprocess2_' . time() . '.py';
file_put_contents($py_path, $py_script);

echo "Reprocessing manuscripts in: $uploads\n";
echo "----------------------------------------------\n";

$cmd  = "$py_exe " . escapeshellarg($py_path) . " " . escapeshellarg($uploads) . " 2>&1";
$proc = popen($cmd, 'r');
if ($proc) {
    while (!feof($proc)) {
        $line = fgets($proc, 256);
        if ($line !== false) {
            echo $line;
            if (ob_get_level() > 0) ob_flush();
            flush();
        }
    }
    pclose($proc);
} else {
    echo "Error: Could not run Python script.\n";
}

@unlink($py_path);

echo "\n=====================================================\n";
echo "✔ Done. Please DELETE this file from the server now.\n";
?>
