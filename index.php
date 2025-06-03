<?php
// /public/index.php
require_once __DIR__ . '/lib/Auth.php';

if (Auth::check()) {
    // Si ya está logueado, va al listado de leads
    header('Location: ./leads/list.php');
} else {
    // Si no, al login
    header('Location: ./auth/login.php');
}
exit;
