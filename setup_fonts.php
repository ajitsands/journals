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

echo "RJPES Server Font Installer (v2)\n";
echo "=================================\n\n";

$home_dir = '/home/rjpes';
$fonts_dir = $home_dir . '/.fonts';
$cache_dir = $home_dir . '/.cache';
$fc_cache_dir = $cache_dir . '/fontconfig';
$source_dir = __DIR__ . '/assets/fonts';

// Set environment variables for Fontconfig
putenv("HOME=$home_dir");
putenv("XDG_CACHE_HOME=$cache_dir");
$_ENV['HOME'] = $home_dir;
$_ENV['XDG_CACHE_HOME'] = $cache_dir;

echo "Environment:\n";
echo "  HOME: " . getenv('HOME') . "\n";
echo "  XDG_CACHE_HOME: " . getenv('XDG_CACHE_HOME') . "\n\n";

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

// 3. Create ~/.cache/fontconfig if not exists
if (!file_exists($fc_cache_dir)) {
    if (mkdir($fc_cache_dir, 0755, true)) {
        echo "Created cache directory: $fc_cache_dir\n";
    } else {
        echo "ERROR: Failed to create cache directory: $fc_cache_dir\n";
        exit(1);
    }
} else {
    echo "Cache directory already exists: $fc_cache_dir\n";
}

// 4. Copy fonts
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

// 5. Run fc-cache with env vars
echo "\nRebuilding font cache (fc-cache)...\n";
$cmd = "export HOME=" . escapeshellarg($home_dir) . " && export XDG_CACHE_HOME=" . escapeshellarg($cache_dir) . " && fc-cache -f -v " . escapeshellarg($fonts_dir) . " 2>&1";
$output = shell_exec($cmd);
echo $output . "\n";

// 6. Verify installed fonts
echo "\nVerifying installed fonts (fc-list)...\n";
$cmd_list = "export HOME=" . escapeshellarg($home_dir) . " && export XDG_CACHE_HOME=" . escapeshellarg($cache_dir) . " && fc-list : family | sort -u | head -n 100 2>&1";
$font_list = shell_exec($cmd_list);
echo $font_list . "\n";

echo "=====================================\n";
echo "Font installation completed. Please DELETE setup_fonts.php from the server now.\n";
?>
