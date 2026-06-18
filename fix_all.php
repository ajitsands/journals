<?php
/**
 * RJPES All-in-One: Git Pull + Flatten All Manuscript PDFs as JPEG Images.
 * Since original DOCX files are deleted after upload, we re-render existing
 * PDF body pages as standard JPEG images that every browser can display.
 *
 * Visit: https://rjpes.in/fix_all.php
 * DELETE after use.
 */
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(600);

$root = __DIR__;

echo "RJPES: Git Pull + Flatten All Manuscripts\n";
echo "===========================================\n\n";

// STEP 1: Git pull
echo "STEP 1: Git Pull\n";
echo "----------------\n";
chdir($root);
echo shell_exec('git reset --hard 2>&1') . "\n";
echo shell_exec('git pull origin main 2>&1') . "\n\n";

// STEP 2: Check fitz
echo "STEP 2: Check PyMuPDF\n";
echo "---------------------\n";
$py_exe = 'python3';
$check = shell_exec("$py_exe -c \"import fitz; print(fitz.__version__)\" 2>&1");
echo "fitz version: " . trim($check) . "\n\n";

// STEP 3: Flatten ALL pages (including cover) as standard JPEG images
echo "STEP 3: Flatten All Manuscript PDFs\n";
echo "------------------------------------\n";

$py_script = <<<'PYEOF'
import sys, os, fitz

uploads_dir = sys.argv[1]
pdfs = sorted([f for f in os.listdir(uploads_dir)
               if f.startswith('manuscript_') and f.endswith('.pdf')])

print(f"Found {len(pdfs)} manuscripts\n")

ok = 0
err = 0
for fname in pdfs:
    path = os.path.join(uploads_dir, fname)
    tmp  = path + '.tmp.pdf'
    try:
        before = os.path.getsize(path) // 1024
        src = fitz.open(path)
        out = fitz.open()
        
        for i in range(len(src)):
            page = src[i]
            w = page.rect.width
            h = page.rect.height
            # Render EVERY page as 200 DPI RGB JPEG
            mat = fitz.Matrix(200 / 72, 200 / 72)
            pix = page.get_pixmap(matrix=mat, alpha=False)
            jpg = pix.tobytes('jpeg', jpg_quality=90)
            np = out.new_page(width=w, height=h)
            np.insert_image(np.rect, stream=jpg)
        
        src.close()
        out.save(tmp, garbage=4, deflate=True, clean=True)
        out.close()
        
        os.replace(tmp, path)
        after = os.path.getsize(path) // 1024
        print(f"OK  {fname}  ({before}KB -> {after}KB)")
        ok += 1
    except Exception as e:
        print(f"ERR {fname}: {e}")
        err += 1
        if os.path.exists(tmp):
            os.remove(tmp)

print(f"\nResult: {ok} fixed, {err} errors")
PYEOF;

$py_path = sys_get_temp_dir() . '/rjpes_flatten_' . time() . '.py';
file_put_contents($py_path, $py_script);
$uploads = $root . '/uploads';

$cmd = "$py_exe " . escapeshellarg($py_path) . " " . escapeshellarg($uploads) . " 2>&1";
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

echo "\n===========================================\n";
echo "DONE. New submissions will use standard fonts.\n";
echo "Existing PDFs flattened as JPEG images.\n";
echo "DELETE fix_all.php from server now!\n";
?>
