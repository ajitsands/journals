<?php
/**
 * RJPES All-in-One: Git Pull + Fix All PDFs
 * Visit: https://rjpes.in/fix_all.php
 * DELETE after use.
 */
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300); // 5 minutes max

$root    = __DIR__;
$uploads = $root . '/uploads';
$py_exe  = 'python3';

echo "RJPES: Git Pull + Reprocess All PDFs\n";
echo "======================================\n\n";

// STEP 1: Git pull latest code
echo "STEP 1: Git Pull\n";
echo "----------------\n";
chdir($root);
$pull = shell_exec('git reset --hard 2>&1 && git pull origin main 2>&1');
echo trim($pull) . "\n\n";

// STEP 2: Check fitz
echo "STEP 2: Check PyMuPDF\n";
echo "---------------------\n";
$check = shell_exec("$py_exe -c \"import fitz; print('OK: fitz ' + fitz.__version__)\" 2>&1");
echo trim($check) . "\n\n";
if (strpos($check, 'OK:') === false) {
    echo "ERROR: PyMuPDF not available. Cannot fix PDFs.\n";
    exit(1);
}

// STEP 3: Reprocess ALL manuscripts - flatten all body pages as standard JPEG
echo "STEP 3: Reprocessing All Manuscripts\n";
echo "-------------------------------------\n";

$py_script = <<<'PYEOF'
import sys, os, fitz

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

def flatten_pdf(in_path, out_path):
    """Re-render ALL body pages as 150 DPI grayscale JPEG.
    Fixes JPEG2000 (LibreOffice), CID fonts (MS Word), and DroidSansFallback issues."""
    src = fitz.open(in_path)
    out = fitz.open()
    out.insert_pdf(src, from_page=0, to_page=0)   # cover: keep as-is
    for i in range(1, len(src)):
        page  = src[i]
        mat   = fitz.Matrix(150 / 72, 150 / 72)
        pix   = page.get_pixmap(matrix=mat, colorspace=fitz.csGRAY, alpha=False)
        jpg   = pix.tobytes('jpeg', jpg_quality=85)
        np    = out.new_page(width=page.rect.width, height=page.rect.height)
        np.insert_image(np.rect, stream=jpg)
    src.close()
    out.save(out_path, garbage=4, deflate=True, clean=True)
    out.close()

uploads_dir = sys.argv[1]
pdfs = sorted([f for f in os.listdir(uploads_dir)
               if f.startswith('manuscript_') and f.endswith('.pdf')])

print(f"Found {len(pdfs)} PDFs\n")
ok = 0
err = 0
for fname in pdfs:
    path = os.path.join(uploads_dir, fname)
    tmp  = path + '.tmp.pdf'
    try:
        before = os.path.getsize(path) // 1024
        flatten_pdf(path, tmp)
        os.replace(tmp, path)
        after = os.path.getsize(path) // 1024
        print(f"OK  {fname}  ({before}KB -> {after}KB)")
        ok += 1
    except Exception as e:
        print(f"ERR {fname}: {e}")
        err += 1
        if os.path.exists(tmp): os.remove(tmp)

print(f"\nResult: {ok} fixed, {err} errors")
PYEOF;

$py_path = sys_get_temp_dir() . '/rjpes_fixall_' . time() . '.py';
file_put_contents($py_path, $py_script);

$cmd  = "$py_exe " . escapeshellarg($py_path) . " " . escapeshellarg($uploads) . " 2>&1";
$proc = popen($cmd, 'r');
if ($proc) {
    while (!feof($proc)) {
        $line = fgets($proc, 512);
        if ($line !== false) {
            echo $line;
            if (ob_get_level() > 0) ob_flush();
            flush();
        }
    }
    pclose($proc);
}
@unlink($py_path);

echo "\n======================================\n";
echo "DONE. Please DELETE fix_all.php now!\n";
?>
