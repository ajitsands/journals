<?php
/**
 * Server PDF Reprocessor — fixes Chrome font rendering on all existing manuscripts.
 * Run once at: https://rjpes.in/reprocess_pdfs.php
 * DELETE this file after running for security.
 */
header('Content-Type: text/plain; charset=utf-8');

$root = __DIR__;
$uploads = $root . '/uploads';

echo "RJPES PDF Reprocessor\n";
echo "======================\n\n";

// 1. Check Python + fitz availability
$py_exe = 'python3';
$check = shell_exec("$py_exe -c \"import fitz; print('fitz-ok-' + fitz.__version__)\" 2>&1");
if (strpos($check, 'fitz-ok-') !== false) {
    echo "✔ PyMuPDF (fitz) available: " . trim($check) . "\n\n";
} else {
    echo "✘ PyMuPDF not found: " . trim($check) . "\n";
    echo "Trying to install fitz...\n";
    $install = shell_exec("pip3 install pymupdf 2>&1");
    echo $install . "\n";
    $check2 = shell_exec("$py_exe -c \"import fitz; print('fitz-ok-' . fitz.__version__)\" 2>&1");
    if (strpos($check2, 'fitz-ok-') === false) {
        echo "✘ Cannot proceed without PyMuPDF. Please install it on the server.\n";
        exit(1);
    }
    echo "✔ PyMuPDF installed successfully.\n\n";
}

// 2. Python script to flatten Identity-H CID font pages
$py_script = <<<'PYEOF'
import sys, os, fitz

def has_cid_fonts(page):
    for f in page.get_fonts():
        enc = f[5] if len(f) > 5 else ''
        if enc == 'Identity-H':
            return True
    return False

def reprocess_pdf(in_path, out_path):
    src = fitz.open(in_path)
    out = fitz.open()
    
    # Cover page: keep as-is (Helvetica, renders fine in all browsers)
    out.insert_pdf(src, from_page=0, to_page=0)
    
    for i in range(1, len(src)):
        page = src[i]
        w = page.rect.width
        h = page.rect.height
        if has_cid_fonts(page):
            # Render as grayscale JPEG 150 DPI — fixes Chrome/Firefox PDF viewer
            mat = fitz.Matrix(150 / 72, 150 / 72)
            pix = page.get_pixmap(matrix=mat, colorspace=fitz.csGRAY, alpha=False)
            jpg = pix.tobytes('jpeg', jpg_quality=85)
            new_page = out.new_page(width=w, height=h)
            new_page.insert_image(new_page.rect, stream=jpg)
        else:
            out.insert_pdf(src, from_page=i, to_page=i)
    
    src.close()
    out.save(out_path, garbage=4, deflate=True, clean=True)
    out.close()
    return True

uploads_dir = sys.argv[1]
pdfs = [f for f in os.listdir(uploads_dir) if f.startswith('manuscript_') and f.endswith('.pdf')]
print(f"Found {len(pdfs)} manuscript PDFs to check")

fixed = 0
skipped = 0
errors = 0
for fname in pdfs:
    in_path = os.path.join(uploads_dir, fname)
    tmp_path = in_path + '.tmp.pdf'
    try:
        # Check if already processed (no CID fonts = already image-flattened)
        src = fitz.open(in_path)
        has_cid = any(has_cid_fonts(src[i]) for i in range(1, min(3, len(src))))
        src.close()
        
        if not has_cid:
            print(f"SKIP (already fixed): {fname}")
            skipped += 1
            continue
        
        reprocess_pdf(in_path, tmp_path)
        os.replace(tmp_path, in_path)
        size_kb = os.path.getsize(in_path) // 1024
        print(f"FIXED: {fname} -> {size_kb} KB")
        fixed += 1
    except Exception as e:
        print(f"ERROR: {fname}: {e}")
        errors += 1
        if os.path.exists(tmp_path):
            os.remove(tmp_path)

print(f"\nDone: {fixed} fixed, {skipped} skipped, {errors} errors")
PYEOF;

$py_path = sys_get_temp_dir() . '/rjpes_reprocess_' . time() . '.py';
file_put_contents($py_path, $py_script);

echo "3. Reprocessing manuscripts in: $uploads\n";
echo "----------------------------------------------\n";

// Run with output buffering disabled for real-time output
$cmd = "$py_exe " . escapeshellarg($py_path) . " " . escapeshellarg($uploads) . " 2>&1";

$proc = popen($cmd, 'r');
if ($proc) {
    while (!feof($proc)) {
        $line = fgets($proc, 256);
        if ($line !== false) {
            echo $line;
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    }
    pclose($proc);
} else {
    echo "Error: Could not run Python script.\n";
}

@unlink($py_path);

echo "\n======================\n";
echo "✔ Reprocessing complete. You can DELETE this file now.\n";
?>
