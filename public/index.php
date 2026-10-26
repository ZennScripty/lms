<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;

redirect(Auth::user() ? 'dashboard.php' : 'login.php');
