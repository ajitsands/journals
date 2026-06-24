<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/auth.php';
$current_page = basename($_SERVER['PHP_SELF']);
$logged_user = get_logged_in_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . " - RJPES" : "Research Journal on Physical Education and Sports (RJPES)"; ?></title>
    <meta name="description" content="Official Portal of Research Journal on Physical Education and Sports (RJPES). Peer-reviewed journal following UGC guidelines. ISSN 0975-4687.">
    <?php
    // Calculate path prefix to support root, subdirectories, and port mapping robustly
    $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $depth = substr_count($request_path, '/') - 1;
    // Fallback if running on root level
    if ($depth < 0) $depth = 0;
    $path_prefix = str_repeat('../', $depth);
    ?>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/styles.css">
    <!-- jQuery DataTables CSS (for all logged-in users) -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <!-- Ladda UI Button Spinners CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/ladda@1.0.6/dist/ladda-themeless.min.css">
</head>
<body>
    <header>
        <div class="header-container">
            <a href="<?php echo $path_prefix; ?>index.php" class="logo-section">
                <!-- Academic Seal Icon -->
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#d4af37" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                    <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    <path d="M12 6v10"></path>
                    <path d="M8 10h8"></path>
                </svg>
                <div>
                    <h1 class="journal-title-main">RJPES</h1>
                    <span class="journal-subtitle">Research Journal on Physical Education and Sports</span>
                </div>
            </a>
            <nav>
                <a href="<?php echo $path_prefix; ?>index.php" class="<?php echo ($current_page == 'index.php') ? 'active' : ''; ?>">Home</a>
                <a href="<?php echo $path_prefix; ?>journals.php" class="<?php echo ($current_page == 'journals.php') ? 'active' : ''; ?>">Archive</a>
                
                <?php if (is_logged_in() && $logged_user): ?>
                    <?php 
                    // Redirect to correct dashboard based on role
                    $dash_link = $path_prefix . "index.php";
                    if ($logged_user['role'] == 'admin') {
                        $dash_link = $path_prefix . "admin/dashboard.php";
                    } elseif ($logged_user['role'] == 'reviewer') {
                        $dash_link = $path_prefix . "reviewer/dashboard.php";
                    } elseif ($logged_user['role'] == 'author') {
                        $dash_link = $path_prefix . "author/dashboard.php";
                    }
                    ?>
                    <a href="<?php echo $dash_link; ?>" class="nav-btn">Dashboard (<?php echo sanitize($logged_user['fullname']); ?>)</a>
                    
                    <!-- Activities Dropdown in the Header -->
                    <div class="activities-dropdown">
                        <button class="activities-btn">
                            Activities 
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-left: 3px; vertical-align: middle;"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="dropdown-menu-content">
                            <?php if ($logged_user['role'] == 'admin'): ?>
                                <a href="<?php echo $path_prefix; ?>admin/dashboard.php" class="<?php echo ($current_page == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? 'active' : ''; ?>">Manage Submissions</a>
                                <a href="<?php echo $path_prefix; ?>admin/compile.php" class="<?php echo ($current_page == 'compile.php') ? 'active' : ''; ?>">Book Compilations</a>
                                <a href="<?php echo $path_prefix; ?>admin/authors.php" class="<?php echo ($current_page == 'authors.php') ? 'active' : ''; ?>">Manage Authors</a>
                                <a href="<?php echo $path_prefix; ?>admin/verifiers.php" class="<?php echo ($current_page == 'verifiers.php') ? 'active' : ''; ?>">Manage Verifiers</a>
                                <a href="<?php echo $path_prefix; ?>admin/admins.php" class="<?php echo ($current_page == 'admins.php') ? 'active' : ''; ?>">Manage Admins</a>
                                <a href="<?php echo $path_prefix; ?>admin/wallets.php" class="<?php echo ($current_page == 'wallets.php') ? 'active' : ''; ?>">Manage Wallets</a>
                                <a href="<?php echo $path_prefix; ?>admin/invoices.php" class="<?php echo ($current_page == 'invoices.php') ? 'active' : ''; ?>">Month-Wise Invoices</a>
                            <?php elseif ($logged_user['role'] == 'author'): ?>
                                <a href="<?php echo $path_prefix; ?>author/dashboard.php" class="<?php echo ($current_page == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], '/author/') !== false) ? 'active' : ''; ?>">My Submissions</a>
                                <a href="<?php echo $path_prefix; ?>author/submit.php" class="<?php echo ($current_page == 'submit.php') ? 'active' : ''; ?>">Submit New Paper</a>
                            <?php elseif ($logged_user['role'] == 'reviewer'): ?>
                                <a href="<?php echo $path_prefix; ?>reviewer/dashboard.php" class="<?php echo ($current_page == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], '/reviewer/') !== false) ? 'active' : ''; ?>">My Assignments</a>
                            <?php endif; ?>
                            <a href="<?php echo $path_prefix; ?>journals.php" class="<?php echo ($current_page == 'journals.php') ? 'active' : ''; ?>">Browse Archive</a>
                        </div>
                    </div>
                    
                    <a href="<?php echo $path_prefix; ?>logout.php" style="color: #fda4af;">Logout</a>
                <?php else: ?>
                    <a href="<?php echo $path_prefix; ?>login.php" class="<?php echo ($current_page == 'login.php') ? 'active' : ''; ?>">Login</a>
                    <a href="<?php echo $path_prefix; ?>register.php" class="nav-btn <?php echo ($current_page == 'register.php') ? 'active' : ''; ?>">Register</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>
