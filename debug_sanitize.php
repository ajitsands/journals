<?php
// v3 check git status on server
header('Content-Type: text/plain; charset=utf-8');
chdir(__DIR__);
echo shell_exec('git log --oneline -5 2>&1');
echo "\n\n";
echo shell_exec('git status 2>&1');
echo "\n\n";
echo shell_exec('cat includes/pdf_helper.php | head -100 2>&1');
