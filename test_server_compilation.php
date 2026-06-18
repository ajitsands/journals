<?php
/**
 * RJPES Server PDF Compilation Verification Tool
 * Runs the conversion and merge pipeline on a sample file to check if fonts are embedded correctly.
 *
 * Visit: https://rjpes.in/test_server_compilation.php
 * DELETE after use.
 */

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

echo "RJPES PDF Compilation Test\n";
echo "============================\n\n";

require_once __DIR__ . '/includes/word_helper.php';
require_once __DIR__ . '/includes/pdf_helper.php';

$upload_dir = __DIR__ . '/uploads';
$docx_path = __DIR__ . '/sampledoc/Sreeji NS (1).docx';

if (!file_exists($docx_path)) {
    echo "ERROR: Sample docx not found at: $docx_path\n";
    exit(1);
}

// 1. Generate Cover
echo "Step 1: Generating dummy cover...\n";
$dummy_data = [
    'journal_number' => 'RJPES-2026-TEST',
    'title' => 'Test Manuscript Title for Font Verification',
    'abstract' => 'This is a test abstract to verify that the PDF generation is working correctly on the server with the installed fonts.',
    'subject_domain' => 'Computer Science / Engineering',
    'volume' => '20',
    'issue' => '1',
    'published_at' => date('Y-m-d H:i:s'),
    'author_photo' => '',
    'authors' => [
        ['name' => 'John Doe', 'designation' => 'Professor', 'department' => 'CSE', 'institution' => 'RJPES University', 'email' => 'john@example.com']
    ]
];

$pdf_generator = new RJPES_PDF();
$cover_bytes = $pdf_generator->generate($dummy_data);
$cover_path = $upload_dir . '/test_cover_temp.pdf';
file_put_contents($cover_path, $cover_bytes);
echo "  Cover generated at: $cover_path (" . filesize($cover_path) . " bytes)\n\n";

// 2. Convert DOCX to PDF
echo "Step 2: Converting DOCX to PDF...\n";
$body_path = $upload_dir . '/test_body_temp.pdf';
if (file_exists($body_path)) {
    @unlink($body_path);
}

// Ensure correct environment variables are set for php script execution too
putenv('HOME=/home/rjpes');
putenv('XDG_CACHE_HOME=/home/rjpes/.cache');

$conv_success = rjpes_convert_docx_to_pdf($docx_path, $body_path);
if ($conv_success && file_exists($body_path)) {
    echo "  SUCCESS: DOCX converted to PDF at: $body_path (" . filesize($body_path) . " bytes)\n\n";
} else {
    echo "  ERROR: Conversion failed. Check php logs.\n\n";
    @unlink($cover_path);
    exit(1);
}

// 3. Merge PDFs
echo "Step 3: Merging Cover and Body...\n";
$final_path = $upload_dir . '/test_final_merged.pdf';
if (file_exists($final_path)) {
    @unlink($final_path);
}

$merge_success = rjpes_pdf_merge($cover_path, $body_path, $final_path, 'RJPES-2026-TEST', '20', '1', 'June 2026');

// Clean up temp cover and body
@unlink($cover_path);
@unlink($body_path);

if ($merge_success && file_exists($final_path)) {
    echo "  SUCCESS: Final merged PDF generated at: $final_path (" . filesize($final_path) . " bytes)\n\n";
} else {
    echo "  ERROR: Merging failed. Check php logs.\n\n";
    exit(1);
}

// 4. Run PyMuPDF inspection on the final PDF
echo "Step 4: Inspecting generated PDF using fitz...\n";
$py_script = <<<'PYEOF'
import sys, os, fitz
final_path = sys.argv[1]

try:
    doc = fitz.open(final_path)
    print(f"Merged PDF Pages: {len(doc)}")
    
    # Check page 2 (first page of manuscript)
    if len(doc) > 1:
        page = doc[1]
        fonts = page.get_fonts()
        print(f"Page 2 Fonts: {len(fonts)}")
        for f in fonts:
            xref = f[0]
            ftype = f[2]
            base = f[3]
            enc = f[5] if len(f) > 5 else '?'
            
            # Check if embedded
            try:
                font_obj = doc.xref_object(xref, compressed=False)
                embedded = 'FontFile' in font_obj or 'FontFile2' in font_obj or 'FontFile3' in font_obj
            except:
                embedded = False
                
            print(f"  - [{ftype}] {base} | encoding={enc} | embedded={embedded}")
            
    doc.close()
except Exception as e:
    print(f"Error inspecting PDF: {e}")
PYEOF;

$py_path = sys_get_temp_dir() . '/test_inspect_' . time() . '.py';
file_put_contents($py_path, $py_script);

$py_exe = (DIRECTORY_SEPARATOR === '\\') ? 'python' : 'python3';
$cmd = "export HOME=/home/rjpes && export XDG_CACHE_HOME=/home/rjpes/.cache && $py_exe " . escapeshellarg($py_path) . " " . escapeshellarg($final_path) . " 2>&1";
$inspect_output = shell_exec($cmd);
@unlink($py_path);

echo $inspect_output . "\n";
echo "============================\n";
echo "Done testing. Test file is available at: https://rjpes.in/uploads/test_final_merged.pdf\n";
?>
