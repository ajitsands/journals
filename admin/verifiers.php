<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$message = "";
$message_type = "";

// Handle Add New Verifier Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_verifier'])) {
    $fullname = sanitize($_POST['fullname']);
    $username = sanitize($_POST['username']);
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];
    $subject_domain = sanitize($_POST['subject_domain']);
    $phone = sanitize($_POST['phone'] ?? '');
    
    if (empty($fullname) || empty($username) || empty($email) || empty($password) || empty($subject_domain)) {
        $message = "Please fill in all required fields.";
        $message_type = "danger";
    } else {
        // Register using the auth helper, forcing role as 'reviewer'
        $result = register_user($username, $email, $password, $fullname, 'reviewer', $subject_domain, $phone);
        $message = $result['message'];
        $message_type = $result['success'] ? "success" : "danger";
    }
}

// Handle Block / Unblock Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_block'])) {
    $user_id = intval($_POST['user_id']);
    $new_status = intval($_POST['status']);
    
    if ($user_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_blocked = ? WHERE id = ? AND role = 'reviewer'");
            $stmt->execute([$new_status, $user_id]);
            $message = $new_status ? "Verifier account blocked successfully." : "Verifier account unblocked successfully.";
            $message_type = "success";
        } catch (PDOException $e) {
            $message = "Failed to update account status: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Handle Password Reset Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $user_id = intval($_POST['user_id']);
    $new_password = $_POST['new_password'];
    
    if ($user_id > 0 && !empty($new_password)) {
        if (strlen($new_password) < 6) {
            $message = "Password must be at least 6 characters long.";
            $message_type = "danger";
        } else {
            try {
                $hashed = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'reviewer'");
                $stmt->execute([$hashed, $user_id]);
                $message = "Verifier password reset successfully.";
                $message_type = "success";
            } catch (PDOException $e) {
                $message = "Failed to reset password: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// Handle Update Verifier Info Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_verifier_info'])) {
    $user_id = intval($_POST['user_id']);
    $fullname = sanitize($_POST['fullname']);
    $email = sanitize($_POST['email']);
    $subject_domain = sanitize($_POST['subject_domain']);
    $phone = sanitize($_POST['phone'] ?? '');
    
    if ($user_id > 0 && !empty($fullname) && !empty($email) && !empty($subject_domain)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Invalid email address format.";
            $message_type = "danger";
        } else {
            try {
                // Check if email already exists for another user
                $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $chk->execute([$email, $user_id]);
                if ($chk->fetch()) {
                    $message = "Email address is already in use by another account.";
                    $message_type = "danger";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET fullname = ?, email = ?, subject_domain = ?, phone = ? WHERE id = ? AND role = 'reviewer'");
                    $stmt->execute([$fullname, $email, $subject_domain, $phone, $user_id]);
                    $message = "Verifier profile updated successfully.";
                    $message_type = "success";
                }
            } catch (PDOException $e) {
                $message = "Failed to update profile: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    } else {
        $message = "All fields (Full Name, Email, Subject Domain) are required.";
        $message_type = "danger";
    }
}

// Fetch all verifiers
try {
    $stmt = $pdo->prepare("SELECT id, username, email, fullname, subject_domain, phone, is_blocked, created_at FROM users WHERE role = 'reviewer' ORDER BY created_at DESC");
    $stmt->execute();
    $verifiers = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database query failed: " . $e->getMessage());
}

// Auto-open Add modal if there was a form error on add_verifier
$open_add_modal = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_verifier']) && $message_type === 'danger');

$page_title = "Manage Verifiers";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dashboard-container">
    <!-- Main Content -->
    <main class="main-content" style="width: 100%;">
        <!-- Page Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 15px;">
            <div>
                <h2 style="font-family: var(--font-heading); color: var(--primary-color);">Manage Verifiers</h2>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Register new reviewers and manage active evaluators in the system</p>
            </div>
            <!-- Add Verifier Button -->
            <button onclick="openAddVerifierModal()" class="btn btn-dark" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="8.5" cy="7" r="4"/>
                    <line x1="20" y1="8" x2="20" y2="14"/>
                    <line x1="23" y1="11" x2="17" y2="11"/>
                </svg>
                Add New Verifier
            </button>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php if ($message_type == 'success'): ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php endif; ?>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <!-- Full-width Verifiers Table Card -->
        <div class="card" style="padding: 1.5rem;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                Active Verifiers / Reviewers
            </h3>
            
            <?php if (empty($verifiers)): ?>
                <div style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1rem; opacity: 0.7;">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                    </svg>
                    <p style="font-weight: 500;">No verifiers have been registered yet.</p>
                    <p style="font-size: 0.85rem; margin-top: 5px;">Click the <strong>"Add New Verifier"</strong> button above to register the first verifier.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table datatable">
                        <thead>
                            <tr>
                                <th>Verifier Name</th>
                                <th>Email / Username</th>
                                <th>Subject Domain</th>
                                <th>Registered Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($verifiers as $v): ?>
                                <tr>
                                    <td style="font-weight: 600; color: var(--primary-color);">
                                        <?php echo sanitize($v['fullname']); ?>
                                    </td>
                                    <td>
                                        <div style="font-weight: 500;"><?php echo sanitize($v['email']); ?></div>
                                        <?php if (!empty($v['phone'])): ?>
                                            <div style="font-size: 0.8rem; color: var(--text-color); margin-top: 2px;">📞 <?php echo sanitize($v['phone']); ?></div>
                                        <?php endif; ?>
                                        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">User: <?php echo sanitize($v['username']); ?></div>
                                    </td>
                                    <td>
                                        <span style="font-size: 0.85rem; background-color: #f1f5f9; padding: 4px 8px; border-radius: 4px; display: inline-block;">
                                            <?php echo sanitize($v['subject_domain']); ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 0.85rem; color: var(--text-muted);">
                                        <?php echo date('d M Y', strtotime($v['created_at'])); ?>
                                    </td>
                                    <td>
                                        <?php if ($v['is_blocked']): ?>
                                            <span class="badge badge-rejected" style="font-size: 0.72rem;">Blocked</span>
                                        <?php else: ?>
                                            <span class="badge badge-ready_for_publish" style="font-size: 0.72rem;">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                            <!-- Block/Unblock Form -->
                                            <form action="verifiers.php" method="POST" style="margin: 0;">
                                                <input type="hidden" name="toggle_block" value="1">
                                                <input type="hidden" name="user_id" value="<?php echo $v['id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo $v['is_blocked'] ? '0' : '1'; ?>">
                                                <?php if ($v['is_blocked']): ?>
                                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.75rem; background-color: var(--success-color); color: white;">
                                                        Unblock
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.75rem; background-color: var(--danger-color); color: white;">
                                                        Block
                                                    </button>
                                                <?php endif; ?>
                                            </form>

                                            <!-- Edit Info Trigger -->
                                            <button onclick="openEditModal(<?php echo $v['id']; ?>, '<?php echo addslashes(sanitize($v['fullname'])); ?>', '<?php echo addslashes(sanitize($v['email'])); ?>', '<?php echo addslashes(sanitize($v['subject_domain'])); ?>', '<?php echo addslashes(sanitize($v['phone'] ?? '')); ?>')" class="btn btn-primary" style="padding: 4px 8px; font-size: 0.75rem; background-color: var(--accent-color); color: var(--primary-dark); border: none;">
                                                Edit Info
                                            </button>

                                            <!-- Reset Password Trigger -->
                                            <button onclick="openResetModal(<?php echo $v['id']; ?>, '<?php echo addslashes(sanitize($v['fullname'])); ?>')" class="btn btn-secondary" style="padding: 4px 8px; font-size: 0.75rem; border: 1px solid var(--border-color); color: var(--text-color);">
                                                Reset Password
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- ===== MODAL: Add New Verifier ===== -->
<div id="addVerifierModal" class="modal-overlay" style="display: none; align-items: flex-start; padding-top: 40px;">
    <div class="modal-content" style="max-width: 540px; width: 95%;">
        <!-- Modal Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 2px solid var(--accent-color); padding-bottom: 12px;">
            <div>
                <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.25rem; margin: 0;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 6px; margin-bottom: 2px;">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="8.5" cy="7" r="4"/>
                        <line x1="20" y1="8" x2="20" y2="14"/>
                        <line x1="23" y1="11" x2="17" y2="11"/>
                    </svg>
                    Add New Verifier Account
                </h3>
                <p style="font-size: 0.8rem; color: var(--text-muted); margin: 4px 0 0 28px;">This account will be registered as a Reviewer / Verifier role</p>
            </div>
            <button onclick="closeAddVerifierModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); line-height: 1;">&times;</button>
        </div>
        
        <!-- Add Verifier Form -->
        <form action="verifiers.php" method="POST" id="addVerifierForm">
            <input type="hidden" name="add_verifier" value="1">
            
            <div class="form-group">
                <label for="v_fullname">Full Name <span style="font-size: 0.75rem; color: var(--text-muted);">(e.g. Dr. Jack Smith)</span></label>
                <input type="text" name="fullname" id="v_fullname" class="form-control" placeholder="Enter academic name with title" required>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                <div class="form-group">
                    <label for="v_username">Username</label>
                    <input type="text" name="username" id="v_username" class="form-control" placeholder="Unique login username" required>
                </div>
                <div class="form-group">
                    <label for="v_email">Email Address</label>
                    <input type="email" name="email" id="v_email" class="form-control" placeholder="official@institution.edu" required>
                </div>
            </div>

            <div class="form-group">
                <label for="v_phone">Mobile Number <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">(Optional)</span></label>
                <input type="text" name="phone" id="v_phone" class="form-control" placeholder="e.g. +919876543210">
            </div>

            <div class="form-group">
                <label for="v_password">Password</label>
                <div style="position: relative;">
                    <input type="password" name="password" id="v_password" class="form-control" placeholder="Minimum 6 characters" required minlength="6" style="padding-right: 42px;">
                    <button type="button" onclick="togglePasswordVisibility()" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted);" title="Show/Hide password">
                        <svg id="eyeIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="v_subject_domain">Subject Domain Specialty</label>
                <select name="subject_domain" id="v_subject_domain" class="form-control" required>
                    <option value="">Select Specialty...</option>
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

            <!-- Info note -->
            <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; font-size: 0.8rem; color: #1e40af; margin-bottom: 1.5rem;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 4px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                The verifier will be able to log in immediately using the credentials above. Please share the login details securely.
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeAddVerifierModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 10px 20px;">
                    Cancel
                </button>
                <button type="submit" class="btn btn-dark" style="padding: 10px 24px; display: inline-flex; align-items: center; gap: 8px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    Create Verifier Account
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ===== MODAL: Reset Password ===== -->
<div id="resetModal" class="modal-overlay" style="display: none;">
    <div class="modal-content">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.2rem;">Reset Password</h3>
            <button onclick="closeResetModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="verifiers.php" method="POST">
            <input type="hidden" name="reset_password" value="1">
            <input type="hidden" name="user_id" id="resetUserId">
            
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Set a new password for verifier <strong id="resetUserName"></strong>.
            </p>
            
            <div class="form-group">
                <label for="new_password">New Password</label>
                <input type="password" name="new_password" id="new_password" class="form-control" placeholder="Enter at least 6 characters" required minlength="6">
            </div>
            
            <div style="margin-top: 1.5rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeResetModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 16px;">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== MODAL: Edit Verifier Info ===== -->
<div id="editModal" class="modal-overlay" style="display: none;">
    <div class="modal-content" style="max-width: 500px; width: 95%;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
            <h3 style="font-family: var(--font-heading); color: var(--primary-color); font-size: 1.2rem;">Edit Verifier Info</h3>
            <button onclick="closeEditModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        
        <form action="verifiers.php" method="POST">
            <input type="hidden" name="update_verifier_info" value="1">
            <input type="hidden" name="user_id" id="editUserId">
            
            <div class="form-group">
                <label for="edit_fullname">Full Name</label>
                <input type="text" name="fullname" id="edit_fullname" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label for="edit_email">Email Address</label>
                <input type="email" name="email" id="edit_email" class="form-control" required>
            </div>

            <div class="form-group">
                <label for="edit_phone">Mobile Number <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: normal;">(Optional)</span></label>
                <input type="text" name="phone" id="edit_phone" class="form-control" placeholder="e.g. +919876543210">
            </div>

            <div class="form-group">
                <label for="edit_subject_domain">Subject Domain Specialty</label>
                <select name="subject_domain" id="edit_subject_domain" class="form-control" required>
                    <option value="">Select Specialty...</option>
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
            
            <div style="margin-top: 1.5rem; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeEditModal()" class="btn btn-secondary" style="border: 1px solid var(--border-color); color: var(--primary-color); padding: 8px 16px;">Cancel</button>
                <button type="submit" class="btn btn-dark" style="padding: 8px 16px;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Add Verifier Modal ──────────────────────────────────────────
function openAddVerifierModal() {
    document.getElementById('addVerifierModal').style.display = 'flex';
}
function closeAddVerifierModal() {
    document.getElementById('addVerifierModal').style.display = 'none';
    document.getElementById('addVerifierForm').reset();
}
document.getElementById('addVerifierModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddVerifierModal();
});

// ── Password visibility toggle ──────────────────────────────────
function togglePasswordVisibility() {
    var inp = document.getElementById('v_password');
    var icon = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        inp.type = 'password';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}

// Auto-open modal on page load if there was a validation error on add_verifier
<?php if ($open_add_modal): ?>
window.addEventListener('DOMContentLoaded', function() { openAddVerifierModal(); });
<?php endif; ?>

// ── Reset Password Modal ────────────────────────────────────────
function openResetModal(userId, userName) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUserName').textContent = userName;
    document.getElementById('resetModal').style.display = 'flex';
}
function closeResetModal() {
    document.getElementById('resetModal').style.display = 'none';
    document.getElementById('new_password').value = '';
}

// ── Edit Info Modal ─────────────────────────────────────────────
function openEditModal(userId, userName, userEmail, userDomain, userPhone) {
    document.getElementById('editUserId').value = userId;
    document.getElementById('edit_fullname').value = userName;
    document.getElementById('edit_email').value = userEmail;
    document.getElementById('edit_subject_domain').value = userDomain;
    document.getElementById('edit_phone').value = userPhone;
    document.getElementById('editModal').style.display = 'flex';
}
function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.getElementById('editUserId').value = '';
    document.getElementById('edit_fullname').value = '';
    document.getElementById('edit_email').value = '';
    document.getElementById('edit_subject_domain').value = '';
    document.getElementById('edit_phone').value = '';
}

// Backdrop modal click close
document.getElementById('resetModal').addEventListener('click', function(e) {
    if (e.target === this) closeResetModal();
});
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
