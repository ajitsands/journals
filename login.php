<?php
require_once __DIR__ . '/includes/auth.php';

// If already logged in, redirect
if (is_logged_in()) {
    $user = get_logged_in_user();
    if ($user) {
        if ($user['role'] == 'admin') {
            header("Location: /admin/dashboard.php");
        } elseif ($user['role'] == 'reviewer') {
            header("Location: /reviewer/dashboard.php");
        } else {
            header("Location: /author/dashboard.php");
        }
        exit;
    }
}

$message = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_or_username = sanitize($_POST['email_or_username']);
    $password = $_POST['password'];

    $result = login_user($email_or_username, $password);
    if ($result['success']) {
        // Redirect to dashboard
        $user = get_logged_in_user();
        if ($user['role'] == 'admin') {
            header("Location: /admin/dashboard.php");
        } elseif ($user['role'] == 'reviewer') {
            header("Location: /reviewer/dashboard.php");
        } else {
            header("Location: /author/dashboard.php");
        }
        exit;
    } else {
        $message = $result['message'];
        $message_type = "danger";
    }
}

$page_title = "Login Portal";
require_once __DIR__ . '/includes/header.php';
?>

<!-- Animated Academic Background Icons -->
<style>
.auth-bg {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    overflow: hidden;
}
.auth-bg .ic {
    position: absolute;
    opacity: 0.065;
    color: #0b2240;
    stroke: #0b2240;
}
.auth-bg .ic svg { display: block; }
@keyframes floatA { 0%,100%{transform:translateY(0) rotate(-4deg);} 50%{transform:translateY(-18px) rotate(4deg);} }
@keyframes floatB { 0%,100%{transform:translateY(0) rotate(6deg);} 50%{transform:translateY(-22px) rotate(-3deg);} }
@keyframes floatC { 0%,100%{transform:translateY(0) rotate(0deg);} 50%{transform:translateY(-12px) rotate(8deg);} }
@keyframes floatD { 0%,100%{transform:translateY(0) rotate(-8deg);} 50%{transform:translateY(-20px) rotate(2deg);} }
@keyframes floatE { 0%,100%{transform:translateY(0) rotate(3deg);} 50%{transform:translateY(-14px) rotate(-6deg);} }
main { position: relative; z-index: 1; }
</style>

<div class="auth-bg" aria-hidden="true">
  <!-- Top-left: Graduation Cap -->
  <div class="ic" style="top:6%; left:4%; animation: floatA 7s ease-in-out infinite;">
    <svg width="90" height="90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>
    </svg>
  </div>
  <!-- Top-right: Open Book -->
  <div class="ic" style="top:5%; right:5%; animation: floatB 8s ease-in-out infinite;">
    <svg width="100" height="100" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
    </svg>
  </div>
  <!-- Mid-left: Document -->
  <div class="ic" style="top:38%; left:2%; animation: floatC 9s ease-in-out infinite;">
    <svg width="75" height="75" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
    </svg>
  </div>
  <!-- Mid-right: Pen/Edit -->
  <div class="ic" style="top:40%; right:3%; animation: floatD 6.5s ease-in-out infinite;">
    <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
    </svg>
  </div>
  <!-- Bottom-left: Bar Chart -->
  <div class="ic" style="bottom:10%; left:5%; animation: floatE 8.5s ease-in-out infinite;">
    <svg width="85" height="85" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/>
    </svg>
  </div>
  <!-- Bottom-right: Award/Trophy -->
  <div class="ic" style="bottom:8%; right:4%; animation: floatA 7.5s ease-in-out infinite 1s;">
    <svg width="90" height="90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
    </svg>
  </div>
  <!-- Upper-center-left: Search/Magnifier -->
  <div class="ic" style="top:20%; left:14%; animation: floatB 10s ease-in-out infinite 0.5s;">
    <svg width="65" height="65" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
  </div>
  <!-- Upper-center-right: Bookmark -->
  <div class="ic" style="top:18%; right:13%; animation: floatC 9.5s ease-in-out infinite 1.5s;">
    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>
    </svg>
  </div>
  <!-- Lower-mid-left: Layers/Papers -->
  <div class="ic" style="bottom:28%; left:8%; animation: floatD 11s ease-in-out infinite 2s;">
    <svg width="70" height="70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>
    </svg>
  </div>
  <!-- Lower-mid-right: Clock -->
  <div class="ic" style="bottom:30%; right:8%; animation: floatE 8s ease-in-out infinite 0.8s;">
    <svg width="68" height="68" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
    </svg>
  </div>
  <!-- Far top-left small: Star -->
  <div class="ic" style="top:3%; left:30%; animation: floatA 12s ease-in-out infinite 3s;">
    <svg width="45" height="45" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
    </svg>
  </div>
  <!-- Far top-right small: Globe -->
  <div class="ic" style="top:3%; right:28%; animation: floatB 11s ease-in-out infinite 2.5s;">
    <svg width="50" height="50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
    </svg>
  </div>
</div>

<main class="container" style="max-width: 500px; margin-top: 5rem; margin-bottom: 5rem;">
    <div class="card" style="box-shadow: var(--shadow-lg);">
        <div style="text-align: center; margin-bottom: 2rem;">
            <!-- Academic Icon -->
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#0b2240" stroke-width="2" style="margin-bottom: 10px;">
                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
            </svg>
            <h2 class="card-title" style="border-bottom: none; padding-bottom: 0; margin-bottom: 5px;">Login to RJPES</h2>
            <p style="font-size: 0.85rem; color: var(--text-muted);">Access your submissions, reviews, or panel</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && $_GET['error'] == 'unauthorized'): ?>
            <div class="alert alert-danger">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <div>Access denied. You are not authorized to view that page.</div>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="email_or_username">Username or Email Address</label>
                <input type="text" name="email_or_username" id="email_or_username" class="form-control" placeholder="Enter username or email" required autofocus>
            </div>

            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                    <label for="password" style="margin-bottom: 0;">Password</label>
                </div>
                <input type="password" name="password" id="password" class="form-control" placeholder="Enter password" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem; padding: 14px;">Sign In</button>
        </form>

        <div style="text-align: center; margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem; font-size: 0.85rem; color: var(--text-muted);">
            <p>Don't have an account? <a href="/register.php" style="font-weight: 600; color: var(--primary-color);">Create author account</a></p>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
