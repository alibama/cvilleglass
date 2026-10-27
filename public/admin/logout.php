<?php
require __DIR__ . '/../../app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $_SESSION = [];
    session_destroy();
}
redirect('/admin/login.php');
