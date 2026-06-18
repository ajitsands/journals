<?php
/**
     * Microsoft Word COM Automation Diagnostic & Troubleshooting Tool for Windows Server
 */

// Basic security: only allow admin role, local access, or restrict if desired
// (For troubleshooting we keep it accessible, but recommend deleting after use)

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Word COM Diagnostics</title>
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
    <h1>Microsoft Word COM Automation Diagnostic Tool</h1>
    <p>Use this tool to diagnose and fix Word-to-PDF conversion issues on your Windows Server.</p>

    <!-- SECTION 1: SYSTEM ENVIRONMENT -->
    <div class="card">
        <h2>1. System Environment Details</h2>
        <ul>
            <li><strong>OS:</strong> <?php echo php_uname(); ?></li>
            <li><strong>PHP Version:</strong> <?php echo PHP_VERSION; ?></li>
            <li><strong>Server API (SAPI):</strong> <?php echo PHP_SAPI; ?></li>
            <li><strong>PHP Execution User:</strong> <?php echo get_current_user() . " (Identity: " . (getenv('USERNAME') ?: 'Unknown') . ")"; ?></li>
        </ul>
    </div>

    <!-- SECTION 2: DESKTOP FOLDERS DIAGNOSTIC & AUTO-FIX -->
    <div class="card">
        <h2>2. Microsoft Word Profile Desktop Folders</h2>
        <p>Microsoft Word COM automation requires a <code>Desktop</code> folder to exist inside the system profile directories so it can initialize. If missing, Word will throw exceptions.</p>
        
        <?php
        $system32_desktop = 'C:\Windows\System32\config\systemprofile\Desktop';
        $syswow64_desktop = 'C:\Windows\SysWOW64\config\systemprofile\Desktop';
        
        // Attempt Auto-Fix
        $fix_output = [];
        if (!is_dir($system32_desktop)) {
            if (@mkdir($system32_desktop, 0777, true)) {
                $fix_output[] = "Successfully created missing directory: $system32_desktop";
            } else {
                $fix_output[] = "Failed to create directory: $system32_desktop (Access Denied)";
            }
        }
        if (!is_dir($syswow64_desktop)) {
            if (@mkdir($syswow64_desktop, 0777, true)) {
                $fix_output[] = "Successfully created missing directory: $syswow64_desktop";
            } else {
                $fix_output[] = "Failed to create directory: $syswow64_desktop (Access Denied)";
            }
        }
        ?>

        <ul>
            <li>
                <strong>System32 Desktop:</strong> <code><?php echo $system32_desktop; ?></code> - 
                <?php if (is_dir($system32_desktop)): ?>
                    <span class="status status-success">Exists</span>
                <?php else: ?>
                    <span class="status status-danger">Missing</span>
                <?php endif; ?>
            </li>
            <li style="margin-top: 10px;">
                <strong>SysWOW64 Desktop:</strong> <code><?php echo $syswow64_desktop; ?></code> - 
                <?php if (is_dir($syswow64_desktop)): ?>
                    <span class="status status-success">Exists</span>
                <?php else: ?>
                    <span class="status status-danger">Missing</span>
                <?php endif; ?>
            </li>
        </ul>

        <?php if (!empty($fix_output)): ?>
            <h3>Auto-Fix Results:</h3>
            <pre><?php echo implode("\n", $fix_output); ?></pre>
        <?php endif; ?>
    </div>

    <!-- SECTION 3: DIRECT COM ACTIVATION TEST -->
    <div class="card">
        <h2>3. Direct PHP COM Object Test</h2>
        <p>Testing direct activation of Word COM in PHP using <code>new COM("Word.Application")</code>:</p>
        
        <?php
        if (!class_exists('COM')) {
            echo '<p><span class="status status-danger">Failed</span> PHP COM extension is not enabled. Enable <code>extension=com_dotnet</code> in your <code>php.ini</code>.</p>';
        } else {
            try {
                $word = new COM("Word.Application");
                $word->Visible = false;
                echo '<p><span class="status status-success">Success</span> Word COM Object created successfully!</p>';
                $word->Quit();
            } catch (Exception $e) {
                echo '<p><span class="status status-danger">Failed</span> Error activating Word COM object:</p>';
                echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
            }
        }
        ?>
    </div>

    <!-- SECTION 4: POWERSHELL COM ACTIVATION TEST -->
    <div class="card">
        <h2>4. PowerShell COM Object Test</h2>
        <p>Testing if PowerShell executing under the web server identity can successfully launch Word COM:</p>
        
        <?php
        $ps_cmd = 'powershell -ExecutionPolicy Bypass -Command "$word = New-Object -ComObject Word.Application; if ($word) { echo \'Success: Word Application Instance Created.\'; $word.Quit() } else { echo \'Failed.\' }" 2>&1';
        $ps_output = shell_exec($ps_cmd);
        
        if (stripos($ps_output, 'Success') !== false) {
            echo '<p><span class="status status-success">Success</span> PowerShell test completed successfully!</p>';
            echo '<pre>' . htmlspecialchars(trim($ps_output)) . '</pre>';
        } else {
            echo '<p><span class="status status-danger">Failed</span> PowerShell COM activation failed. Output details:</p>';
            echo '<pre>' . htmlspecialchars(trim($ps_output)) . '</pre>';
        }
        ?>
    </div>

    <!-- SECTION 5: STEP-BY-STEP DCOM CONFIG INSTRUCTIONS -->
    <div class="card">
        <h2>5. DCOM Configuration Troubleshooting Steps</h2>
        <p>If the tests above fail with permission or access denied errors, configure the Windows DCOM permissions for Microsoft Word:</p>
        <ol>
            <li>Press <strong>Win + R</strong>, type <code>dcomcnfg</code>, and press Enter to open <strong>Component Services</strong>.</li>
            <li>Navigate to <strong>Component Services</strong> &rarr; <strong>Computers</strong> &rarr; <strong>My Computer</strong> &rarr; <strong>DCOM Config</strong>.</li>
            <li>Scroll down to find <strong>Microsoft Word 97 - 2003 Document</strong> (or <strong>Microsoft Word Document</strong>).</li>
            <li>Right-click on it and select <strong>Properties</strong>.</li>
            <li>Go to the <strong>Identity</strong> tab:
                <ul>
                    <li>Change it from <em>The launching user</em> to <strong>The interactive user</strong> (this forces Word to run in a session that has a UI context).</li>
                    <li>Alternatively, select <strong>This user</strong> and specify local Administrator credentials.</li>
                </ul>
            </li>
            <li>Go to the <strong>Security</strong> tab:
                <ul>
                    <li>Under <em>Launch and Activation Permissions</em>, select <strong>Customize</strong> and click <strong>Edit</strong>.</li>
                    <li>Add your IIS Web Application Pool Identity (e.g. <code>IIS_IUSRS</code>, <code>IUSR</code>, or <code>Network Service</code>) and grant them <strong>Local Launch</strong> and <strong>Local Activation</strong> permissions.</li>
                </ul>
            </li>
            <li>Click <strong>Apply</strong> and <strong>OK</strong>, and restart your web server (IIS / Apache).</li>
        </ol>
    </div>

    <p style="color: var(--text-muted); font-size: 0.85rem; text-align: center;">Note: For security, delete this <code>diagnose_word.php</code> file from your server after diagnosing.</p>
</body>
</html>
