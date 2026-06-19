<?php
$page_title = "Register Portal";
require_once __DIR__ . '/includes/header.php';

$message = "";
$message_type = "";
$role = 'author';

if (isset($_GET['role']) && $_GET['role'] === 'reviewer') {
    $role = 'reviewer';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username']);
    $email = sanitize($_POST['email']);
    $password = $_POST['password']; // Don't sanitize passwords
    $fullname = sanitize($_POST['fullname']);
    $role = sanitize($_POST['role'] ?? 'author');
    $subject_domain = sanitize($_POST['subject_domain'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');

    $result = register_user($username, $email, $password, $fullname, $role, $subject_domain, $phone);
    
    $message = $result['message'];
    $message_type = $result['success'] ? "success" : "danger";
}
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
  <div class="ic" style="top:4%; left:3%; animation: floatA 7s ease-in-out infinite;">
    <svg width="95" height="95" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>
    </svg>
  </div>
  <!-- Top-right: Open Book -->
  <div class="ic" style="top:4%; right:4%; animation: floatB 8s ease-in-out infinite;">
    <svg width="105" height="105" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
    </svg>
  </div>
  <!-- Mid-left: Document -->
  <div class="ic" style="top:35%; left:1.5%; animation: floatC 9s ease-in-out infinite;">
    <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/>
    </svg>
  </div>
  <!-- Mid-right: Pen/Edit -->
  <div class="ic" style="top:38%; right:2%; animation: floatD 6.5s ease-in-out infinite;">
    <svg width="82" height="82" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
    </svg>
  </div>
  <!-- Bottom-left: Bar Chart -->
  <div class="ic" style="bottom:8%; left:4%; animation: floatE 8.5s ease-in-out infinite;">
    <svg width="88" height="88" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/>
    </svg>
  </div>
  <!-- Bottom-right: Award/Trophy -->
  <div class="ic" style="bottom:7%; right:3%; animation: floatA 7.5s ease-in-out infinite 1s;">
    <svg width="92" height="92" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/>
    </svg>
  </div>
  <!-- Upper-center-left: Search -->
  <div class="ic" style="top:18%; left:12%; animation: floatB 10s ease-in-out infinite 0.5s;">
    <svg width="65" height="65" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
    </svg>
  </div>
  <!-- Upper-center-right: Bookmark -->
  <div class="ic" style="top:16%; right:11%; animation: floatC 9.5s ease-in-out infinite 1.5s;">
    <svg width="62" height="62" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>
    </svg>
  </div>
  <!-- Lower-mid-left: Layers -->
  <div class="ic" style="bottom:26%; left:7%; animation: floatD 11s ease-in-out infinite 2s;">
    <svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>
    </svg>
  </div>
  <!-- Lower-mid-right: Clock -->
  <div class="ic" style="bottom:28%; right:7%; animation: floatE 8s ease-in-out infinite 0.8s;">
    <svg width="70" height="70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
    </svg>
  </div>
  <!-- Top centre-left: Star -->
  <div class="ic" style="top:2%; left:28%; animation: floatA 12s ease-in-out infinite 3s;">
    <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
    </svg>
  </div>
  <!-- Top centre-right: Globe -->
  <div class="ic" style="top:2%; right:26%; animation: floatB 11s ease-in-out infinite 2.5s;">
    <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
    </svg>
  </div>
  <!-- Mid-center-left: Users/People -->
  <div class="ic" style="top:60%; left:2%; animation: floatC 9s ease-in-out infinite 1s;">
    <svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
    </svg>
  </div>
  <!-- Mid-center-right: Check/Verified -->
  <div class="ic" style="top:62%; right:2%; animation: floatD 7s ease-in-out infinite 1.2s;">
    <svg width="70" height="70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
    </svg>
  </div>
</div>

<main style="width: 100%; max-width: 760px; margin: 4rem auto; padding: 0 1.5rem; box-sizing: border-box;">
    <div class="card" style="box-shadow: var(--shadow-lg); width: 100%;">
        <h2 class="card-title" style="text-align: center; margin-bottom: 2rem;">Create RJPES Account</h2>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php if ($message_type == 'success'): ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <?php else: ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php endif; ?>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <form action="register.php" method="POST" id="registerForm">
            <div class="form-group">
                <label for="fullname">Full Name (including titles like Dr./Prof.)</label>
                <input type="text" name="fullname" id="fullname" class="form-control" placeholder="e.g., Prof. Dr. John Doe" required>
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" class="form-control" placeholder="Choose a unique username" required>
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" name="email" id="email" class="form-control" placeholder="e.g., johndoe@calicut.edu" required>
            </div>

            <div class="form-group">
                <label for="phone">Mobile Number <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">(Optional)</span></label>
                <input type="text" name="phone" id="phone" class="form-control" placeholder="e.g., +919876543210">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" class="form-control" placeholder="Enter secure password" required minlength="6">
            </div>

            <div class="form-group">
                <label for="role">Register As</label>
                <input type="hidden" name="role" value="<?php echo htmlspecialchars($role); ?>">
                <div class="form-control" style="background: #f8fafc; color: var(--text-muted); cursor: not-allowed; display: flex; align-items: center; gap: 8px;">
                    <?php if ($role === 'reviewer'): ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        <strong>Reviewer</strong>&nbsp;<span style="font-size: 0.85rem;">(Verify / Peer Review Research Papers)</span>
                    <?php else: ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <strong>Author</strong>&nbsp;<span style="font-size: 0.85rem;">(Submit Research Papers)</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group" id="domainGroup">
                <label for="subject_domain">Primary Subject Domain</label>
                <select name="subject_domain" id="subject_domain" class="form-control" required>
                    <option value="">Select Domain...</option>
                    <option value="Physical Education">Physical Education</option>
                    <option value="Sports Science">Sports Science</option>
                    <option value="Sports and Society">Sports and Society</option>
                    <option value="Kinesiology and Biomechanics">Kinesiology and Biomechanics</option>
                    <option value="Exercise Physiology">Exercise Physiology</option>
                    <option value="Diet, Nutrition and Drugs">Diet, Nutrition and Drugs</option>
                    <option value="Health, Fitness, Yoga and Wellness">Health, Fitness, Yoga and Wellness</option>
                    <option value="Sports Equipment and Facilities">Sports Equipment and Facilities</option>
                    <option value="Sports Training and Competitions">Sports Training and Competitions</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem; padding: 14px;">Sign Up</button>
        </form>

        <p style="text-align: center; margin-top: 1.5rem; font-size: 0.9rem; color: var(--text-muted);">
            Already have an account? <a href="/login.php" style="font-weight: 600;">Log in here</a>
        </p>
    </div>
</main>

<script>
// No role change logic needed — registration is Author-only.
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
