<?php
const PUBLIC_PAGE = true;
require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    sign_out();
}
redirect('login.php');
