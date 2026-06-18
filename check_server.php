<?php
/**
 * CloudLinux Headless PDF Converter Audit Tool
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Server PDF Diagnostics</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; max-width: 900px; margin: 2rem auto; padding: 0 1rem; color: #334155; }
        h1, h2 { color: #1e3a8a; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .status { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 9999px; font-size: 0.875rem; font-weight: 600; }
        .status-success { background: #dcfce7; color: #15803d; }
        .status-warning { background: #fef3c7; color: #b45309; }
        .status-danger { background: #fee2e2; color: #b91c1c; }
        pre { background: #f8fafc; border: 1px solid #e2e8f0; padding: 1rem; border-radius: 6px; overflow-x: auto; font-family: monospace; font-size: 0.875rem; }
        ol { padding-left: 1.5rem; }
        li { margin-bottom: 0.5rem; }
    </style>
</head>
<body>
    <h1>CloudLinux Server PDF Tool Audit</h1>
    <p>Use this tool to verify which CLI utilities are installed on your CloudLinux server for document conversions.</p>

    <!-- SECTION 1: SYSTEM ENVIRONMENT -->
    <div class="card">
        <h2>1. Server Environment Details</h2>
        <ul>
            <li><strong>OS:</strong> <?php echo php_uname(); ?></li>
            <li><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
            <li><strong>Server SAPI:</strong> <?php echo PHP_SAPI; ?></li>
            <li><strong>Execution User (UID):</strong> <?php echo exec('whoami') . " (UID: " . posix_getuid() . ")"; ?></li>
        </ul>
    </div>

    <!-- SECTION 2: COMMAND DISCOVERY -->
    <div class="card">
        <h2>2. Installed PDF Conversion Tools</h2>
        <p>Headless document conversions on Linux typically rely on LibreOffice or soffice commands. Below is the availability audit:</p>
        
        <?php
        $tools = [
            'libreoffice' => 'libreoffice --version 2>&1',
            'soffice' => 'soffice --version 2>&1',
            'python3' => 'python3 --version 2>&1',
            'pip3' => 'pip3 --version 2>&1',
            'pdftoppm' => 'pdftoppm -v 2>&1'
        ];
        
        echo '<ul>';
        foreach ($tools as $name => $cmd) {
            $path = exec("which $name 2>&1");
            $has_tool = (strpos($path, 'no ') === false && strpos($path, 'which:') === false && !empty($path));
            
            echo '<li style="margin-bottom:12px;">';
            echo "<strong>$name:</strong> ";
            if ($has_tool) {
                echo "<span class='status status-success'>Available ($path)</span><br>";
                $version = exec($cmd);
                echo "<pre style='margin:5px 0 0 0; padding:5px;'>Version info: " . htmlspecialchars($version) . "</pre>";
            } else {
                echo "<span class='status status-danger'>Not Found</span>";
            }
            echo '</li>';
        }
        echo '</ul>';
        ?>
    </div>

    <!-- SECTION 3: INSTALLATION INSTRUCTIONS FOR CLOUDLINUX -->
    <div class="card">
        <h2>3. Installation Instructions (For Server Admins)</h2>
        <p>If <code>libreoffice</code> is marked as **Not Found**, run the following commands on your CloudLinux terminal via SSH as root to install it:</p>
        
        <pre># 1. Update package list
sudo dnf update -y

# 2. Install headless LibreOffice Writer (handles word conversions)
sudo dnf install -y libreoffice-headless libreoffice-writer

# 3. Verify installation
libreoffice --version</pre>
        
        <p>Once installed, refresh this page to confirm that <code>libreoffice</code> status changes to **Available**.</p>
    </div>

    <p style="color: var(--text-muted); font-size: 0.85rem; text-align: center;">Note: Delete this <code>check_server.php</code> file from your server after diagnostics are complete.</p>
</body>
</html>
