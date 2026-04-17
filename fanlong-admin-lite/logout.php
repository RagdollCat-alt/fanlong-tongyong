<?php
require_once 'config.php';
if (isset($_SESSION['admin_id'])) {
    logAction('admins', 'logout', $_SESSION['admin_id']);
}
session_destroy();
header('Location: login.php');
exit();
