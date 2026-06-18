<?php
/**
 * RJPES Server Font Installer
 * Copies standard Windows fonts from git repository to ~/.fonts/ and runs fc-cache
 *
 * Visit: https://rjpes.in/setup_fonts.php
 * DELETE after running!
 */

header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

echo "RJPES Server Font Installer\n";
echo "============================\n\n";

$home_dir = '/home/rjpes';
$fonts_dir = $home_dir . '/.fonts';
$source_dir = __DIR__ . '/assets/fonts';

// 1. Check if source folder exists
if (!file_exists($source_dir)) {
    echo "ERROR: Source fonts folder does not exist at: $source_dir\n";
    echo "Make sure assets/fonts is pushed to git and pulled to server.\n";
    exit(1);
}

// 2. Create ~/.fonts/ if not exists
if (!file_exists($fonts_dir)) {
    if (mkdir($fonts_dir, 0755, true)) {
        echo "Created fonts directory: $fonts_dir\n";
    } else {
        echo "ERROR: Failed to create fonts directory: $fonts_dir\n";
        exit(1);
    }
} else {
    echo "Fonts directory already exists: $fonts_dir\n";
}

// 3. Copy fonts
echo "\nCopying fonts...\n";
$copied = 0;
$skipped = 0;
$files = glob($source_dir . '/*.{ttf,TTC,TTF,ttc}', GLOB_BRACE);
foreach ($files as $file) {
    $filename = basename($file);
    $dest = $fonts_dir . '/' . $filename;
    
    if (copy($file, $dest)) {
        echo "  Copied: $filename\n";
        $copied++;
    } else {
        echo "  FAILED to copy: $filename\n";
    }
}
echo "\nTotal copied: $copied files (skipped: $skipped)\n";

// 4. Run fc-cache
echo "\nRebuilding font cache (fc-cache)...\n";
$output = shell_exec("fc-cache -f -v " . escapeshellarg($fonts_dir) . " 2>&1");
echo $output . "\n";

// 5. Verify installed fonts
echo "\nVerifying installed fonts (fc-list)...\n";
$font_list = shell_exec('fc-list : family | sort -u | head -n 100 2>&1');
echo $font_list . "\n";

echo "=====================================\n";
echo "Font installation completed. Please DELETE setup_fonts.php from the server now.\n";
?>
