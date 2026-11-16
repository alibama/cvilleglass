<?php
// Serves residents' uploaded photos and CVs from data/uploads (outside the web root).
// File names are random, never reused, so browsers may cache them for good.
require __DIR__ . '/../../app/bootstrap.php';

$name = (string)($_GET['f'] ?? '');
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
if (!preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|pdf)$/', $name, $m) || !is_file($path = upload_dir() . '/' . $name)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: ' . $types[$m[1]]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');
if ($m[1] === 'pdf') {
    $st = db()->prepare('SELECT name FROM residents WHERE cv_file = ?');
    $st->execute([$name]);
    $who = (string)$st->fetchColumn() ?: 'Resident';
    $ascii = preg_replace('/[^A-Za-z0-9 ._-]/', '', function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $who) : $who) ?: 'Resident';
    header('Content-Disposition: inline; filename="' . $ascii . ' CV.pdf"; filename*=UTF-8\'\'' . rawurlencode($who . ' CV.pdf'));
}
readfile($path);
