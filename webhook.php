<?php
/**
 * GitHub Webhook Auto-Deployment Script
 * Automatically triggers 'git pull' on push events with HMAC-SHA256 signature verification.
 */

// ── CONFIGURATION ──────────────────────────────────────────────────────────
// Define the secret token set on GitHub webhook settings
define('WEBHOOK_SECRET', 'MySuperSecretToken123!#');

// The branch to track and pull
define('DEPLOY_BRANCH', 'main');

// The root path of the local git repository
define('REPO_DIR', __DIR__);

// Log file destination for auditing deployments
define('LOG_FILE', REPO_DIR . '/uploads/deploy_log.txt');
// ───────────────────────────────────────────────────────────────────────────

// Set correct response header
header('Content-Type: text/plain; charset=utf-8');

// 1. Verify Request Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Error: Method Not Allowed. Use POST pushes from GitHub.");
}

// 2. Read Request Payload
$payload = file_get_contents('php://input');
if (empty($payload)) {
    http_response_code(400);
    die("Error: Empty payload.");
}

// 3. Verify HMAC-SHA256 Signature
$github_signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (empty($github_signature)) {
    http_response_code(400);
    die("Error: X-Hub-Signature-256 header missing.");
}

$expected_signature = 'sha256=' . hash_hmac('sha256', $payload, WEBHOOK_SECRET);

if (!hash_equals($expected_signature, $github_signature)) {
    http_response_code(403);
    // Log security failure (masked signature comparison for safety)
    log_deployment("Security Warning: Invalid webhook signature. Deployment rejected.");
    die("Error: Invalid signature.");
}

// 4. Verify Branch matches DEPLOY_BRANCH
$data = json_decode($payload, true);
if (isset($data['ref'])) {
    $expected_ref = 'refs/heads/' . DEPLOY_BRANCH;
    if ($data['ref'] !== $expected_ref) {
        log_deployment("Push event to '" . $data['ref'] . "' ignored. Setup is tracking '" . DEPLOY_BRANCH . "'.");
        echo "Push ignored: Ref '" . $data['ref'] . "' does not match tracked branch '" . DEPLOY_BRANCH . "'.\n";
        exit;
    }
}

// 5. Execute Auto-Deployment Pull
log_deployment("Start deployment triggered by push event...");

// Ensure target directory exists and change active path to it
if (!is_dir(REPO_DIR)) {
    log_deployment("Deployment Error: REPO_DIR does not exist.");
    http_response_code(500);
    die("Error: Deployment directory path not found.");
}

chdir(REPO_DIR);

// Execute git pull with force reset to discard server-side overrides
$output = shell_exec("git reset --hard 2>&1 && git pull origin " . escapeshellarg(DEPLOY_BRANCH) . " 2>&1");

// 6. Log output and respond
$log_msg = "Git Pull Output:\n" . trim($output);
log_deployment($log_msg);

echo "Deployment Successful.\n";
echo "Git Output:\n" . $output . "\n";


// Helper: Write audit logs
function log_deployment($message)
{
    $timestamp = date('[Y-m-d H:i:s]');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown IP';
    $log_entry = "{$timestamp} [IP: {$ip}] {$message}\n" . str_repeat('-', 80) . "\n";

    // Ensure parent folder exists (e.g. uploads folder)
    $dir = dirname(LOG_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    @file_put_contents(LOG_FILE, $log_entry, FILE_APPEND);
}
