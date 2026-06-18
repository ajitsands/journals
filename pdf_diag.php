<?php
/**
 * PDF Font Diagnostic — inspect server PDFs to see what font issues exist.
 * Run at: https://rjpes.in/pdf_diag.php
 * DELETE after use.
 */
header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font-size:13px;font-family:monospace;background:#111;color:#0f0;padding:20px;">';
echo "RJPES PDF Font Diagnostic\n";
echo "==========================\n\n";

$uploads = __DIR__ . '/uploads';
$py_exe  = 'python3';

$py_script = <<<'PYEOF'
import sys, os, fitz

sys.stdout.reconfigure(encoding='utf-8', errors='replace')

uploads_dir = sys.argv[1]
pdfs = sorted([f for f in os.listdir(uploads_dir) if f.startswith('manuscript_') and f.endswith('.pdf')])

for fname in pdfs:
    path = os.path.join(uploads_dir, fname)
    try:
        doc = fitz.open(path)
        total = len(doc)
        print(f"\n{'='*60}")
        print(f"FILE: {fname}  ({os.path.getsize(path)//1024} KB, {total} pages)")
        
        # Check first 3 body pages
        for pi in range(1, min(4, total)):
            page = doc[pi]
            fonts = page.get_fonts()
            images = page.get_images()
            print(f"  Page {pi+1}: {len(fonts)} fonts, {len(images)} images")
            for f in fonts:
                # f = (xref, ext, type, basefont, name, encoding, referencer)
                xref   = f[0]
                ftype  = f[2]  # TrueType, Type0, Type1, Type3, etc.
                base   = f[3]  # font base name
                enc    = f[5] if len(f) > 5 else '?'
                print(f"    [{ftype}] {base} | enc={enc}")
                
                # Check if font is embedded
                try:
                    font_obj = doc.xref_object(xref, compressed=False)
                    embedded = 'FontFile' in font_obj or 'FontFile2' in font_obj or 'FontFile3' in font_obj
                    print(f"      embedded={embedded}")
                except:
                    print(f"      embedded=?")
        
        # Render page 2 as PNG to check visual output
        if total > 1:
            pix = doc[1].get_pixmap(dpi=72)
            out_img = os.path.join(uploads_dir, fname.replace('.pdf', '_diag_p2.png'))
            pix.save(out_img)
            print(f"  Rendered page 2 -> {os.path.basename(out_img)}")
        
        doc.close()
    except Exception as e:
        print(f"\nERROR on {fname}: {e}")

print("\n\nDone.")
PYEOF;

$py_path = sys_get_temp_dir() . '/rjpes_diag_' . time() . '.py';
file_put_contents($py_path, $py_script);

$cmd = "$py_exe " . escapeshellarg($py_path) . " " . escapeshellarg($uploads) . " 2>&1";
echo htmlspecialchars(shell_exec($cmd));
@unlink($py_path);

// Show rendered images
echo "\n\n--- Rendered Page 2 Previews ---\n";
echo '</pre>';

$imgs = glob($uploads . '/*_diag_p2.png');
foreach ($imgs as $img) {
    $fname = basename($img);
    $pdf_name = str_replace('_diag_p2.png', '.pdf', $fname);
    $url = '/uploads/' . $fname;
    echo "<div style='margin:20px;display:inline-block;vertical-align:top;text-align:center;'>";
    echo "<b style='font-size:11px;display:block;margin-bottom:4px;'>$pdf_name</b>";
    echo "<img src='$url' style='max-width:350px;border:2px solid #555;' />";
    echo "</div>";
    // Clean up temp image after showing
    @unlink($img);
}
?>
