<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/accounts.php';
require __DIR__ . '/app/community_accounts.php';
require __DIR__ . '/app/student_registry.php';
require __DIR__ . '/app/clubs.php';
require __DIR__ . '/app/events.php';
require __DIR__ . '/app/community.php';
require __DIR__ . '/app/help.php';
$page = get('page', 'home');
$error = null;
try {
    db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], input('csrf'))) fail(403, 'Please refresh this page', 'Your security token expired or is invalid. No changes were made.');
        $requestLimit = input('action') === 'import-student-ids' ? 2*1024*1024+65536 : 20000;
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $requestLimit) fail(413, 'Request too large', 'Please shorten your submission or upload a spreadsheet under 2 MB.');
        try {
            $a = input('action');
            if (!handle_student_registry($a) && !handle_community_accounts($a) && !handle_accounts($a) && !handle_clubs($a) && !handle_events($a) && !handle_community($a)) fail(400, 'Unknown action', 'Please use the forms provided on this website.');
        } catch (UserError $ex) { $error = $ex->getMessage(); if (http_response_code() !== 429) http_response_code(422); }
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') { header('Allow: GET, HEAD, POST'); fail(405, 'Method not allowed', 'This request method is not supported.'); }
    if ($page === 'calendar') calendar_download();
    $pages = ['home'=>'home_page','about'=>'about_page','contact'=>'contact_page','privacy'=>'privacy_page','login'=>'login_page','register'=>'register_page','dashboard'=>'dashboard_page','profile'=>'profile_page','admin'=>'admin_page','clubs'=>'clubs_page','club'=>'club_page','club-form'=>'club_form_page','members'=>'members_page','events'=>'events_page','event'=>'event_page','event-form'=>'event_form_page','attendees'=>'attendees_page'];
    $pages += ['community-register'=>'community_register_page','community-login'=>'community_login_page','community-leaders'=>'community_leaders_page','help'=>'help_page'];
    if (!isset($pages[$page])) fail(404, 'A little off campus?', 'That page does not exist. Let’s get you back to the good stuff.');
    $pages[$page]();
} catch (Throwable $ex) {
    if (db_transaction_active()) db()->rollBack();
    error_log(get_class($ex) . ': ' . $ex->getMessage());
    if (!headers_sent()) http_response_code(503);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>CampusConnect · Setup</title><link rel="stylesheet" href="assets/style.css"><main class="container"><section class="panel empty"><div class="eyebrow">CampusConnect</div><h1>We’ll be right back.</h1><p>The service is temporarily unavailable. If this is a new installation, follow the setup steps in README.md and start your database service.</p><a class="btn" href="index.php">Try again</a></section></main></html>';
}
function db_transaction_active(): bool { try { return db()->inTransaction(); } catch (Throwable) { return false; } }
