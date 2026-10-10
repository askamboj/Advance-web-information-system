<?php
// Community accounts share one login; leader contacts are not separate credentials.
function community_account(int $id): ?array {
    return one("SELECT c.student_id,COALESCE(a.status,'approved') status FROM community_accounts c LEFT JOIN community_applications a ON a.student_id=c.student_id WHERE c.student_id=?", [$id]);
}
function community_leaders_from_form(): array {
    $leaders = [];
    for ($i = 1; $i <= 8; $i++) {
        $name = input('leader_name_'.$i);
        $email = strtolower(input('leader_email_'.$i));
        if ($name === '' && $email === '') continue;
        valid(mb_strlen($name) >= 2 && mb_strlen($name) <= 80, 'Each leader needs a name between 2 and 80 characters.');
        valid(strlen($email) <= 190 && (bool)filter_var($email, FILTER_VALIDATE_EMAIL), 'Each leader needs a valid email address.');
        valid(!isset($leaders[$email]), 'Each leader must have a different email address.');
        $leaders[$email] = ['name'=>$name, 'email'=>$email];
    }
    valid(count($leaders) >= 4, 'A community account must have at least four leaders with different email addresses.');
    return array_values($leaders);
}
function save_community_leaders(int $id, array $leaders): void {
    query('DELETE FROM community_leaders WHERE student_id=?', [$id]);
    foreach ($leaders as $leader) query('INSERT INTO community_leaders(student_id,name,email) VALUES(?,?,?)', [$id,$leader['name'],$leader['email']]);
}
function handle_community_accounts(string $a): bool {
    if ($a === 'register-community') {
        valid(!user(), 'Log out before registering a new community account.');
        rate('register:'.($_SERVER['REMOTE_ADDR']??''), 8, 900);
        $name = field('name', 2, 80);
        $email = strtolower(field('email', 5, 190));
        valid((bool)filter_var($email, FILTER_VALIDATE_EMAIL), 'Enter a valid shared community email address.');
        $password = password_input(); check_password($password);
        valid($password === password_input('password_confirm'), 'Your passwords do not match.');
        valid(input('consent') === '1', 'Confirm that the leaders agreed to this registration and the privacy notice.');
        $leaders = community_leaders_from_form();
        db()->beginTransaction();
        try {
            $studentNumber = eligible_student_id(input('student_number'), true);
            query("INSERT INTO students(name,email,password_hash,role) VALUES(?,?,?,'student')", [$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $id = (int)db()->lastInsertId();
            query('INSERT INTO community_accounts(student_id) VALUES(?)', [$id]);
            query('INSERT INTO community_applications(student_id,registering_student_number) VALUES(?,?)', [$id,$studentNumber]);
            save_community_leaders($id, $leaders);
            audit('community.created', $id);
            db()->commit();
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            if ($ex instanceof PDOException && $ex->getCode() === '23000') throw new UserError('An account already uses this email. Use a different community email or log in.');
            throw $ex;
        }
        flash('Community request submitted. Your registering leader ID matched the list. An administrator must approve the request before you can log in.');
        go('community-login');
    }
    if ($a === 'save-community-leaders') {
        $u = require_user();
        if (!community_account((int)$u['id'])) fail(403, 'Community account required', 'Only a community account can manage its leaders.');
        $leaders = community_leaders_from_form();
        $record = one('SELECT password_hash FROM students WHERE id=?', [$u['id']]);
        rate('leaders:'.$u['id'], 8, 900);
        valid(password_verify(password_input('current_password'), $record['password_hash']), 'Enter the current shared password to update leaders.');
        db()->beginTransaction();
        try {
            // Serialize updates so concurrent submissions cannot mix leader lists.
            one('SELECT student_id FROM community_accounts WHERE student_id=? FOR UPDATE', [$u['id']]);
            save_community_leaders((int)$u['id'], $leaders);
            audit('community.leaders_updated', (int)$u['id']);
            db()->commit();
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            throw $ex;
        }
        flash('Your community leaders have been updated.'); go('community-leaders');
    }
    if ($a === 'community-decision') {
        $u = admin(); $id = (int)input('student_id'); $decision = input('decision');
        valid(in_array($decision, ['approved','rejected'], true), 'Choose approve or reject.');
        db()->beginTransaction();
        try {
            $application = one('SELECT * FROM community_applications WHERE student_id=? FOR UPDATE', [$id]);
            valid($application !== null && $application['status'] === 'pending', 'This community request is no longer awaiting review.');
            if ($decision === 'approved') eligible_student_id($application['registering_student_number']);
            query('UPDATE community_applications SET status=?,reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE student_id=?', [$decision,$u['id'],$id]);
            query('UPDATE students SET role=?,session_version=session_version+1 WHERE id=?', [$decision==='approved'?'organiser':'student',$id]);
            audit('community.'.$decision, (int)$u['id']); db()->commit();
        } catch (Throwable $ex) { if (db()->inTransaction()) db()->rollBack(); throw $ex; }
        flash($decision==='approved'?'Community approved. Its leaders can now use the shared login.':'Community request rejected. Login remains disabled.'); go('admin');
    }
    return false;
}
function account_choices(string $active): void { ?>
    <nav class="tabs" aria-label="Account type">
        <a class="<?=$active==='personal'?'active':''?>" href="<?=e(url('register'))?>">Personal account</a>
        <a class="<?=$active==='community'?'active':''?>" href="<?=e(url('community-register'))?>">Community account</a>
    </nav>
<?php }
function community_leader_fields(array $leaders = []): void { ?>
    <h2>Your leadership team</h2><p>Add at least four leaders with different email addresses. You can list up to eight. Their contact details are private to this shared account.</p>
    <div class="leader-grid">
    <?php for ($i=1; $i<=8; $i++): $leader=$leaders[$i-1]??[]; ?>
        <fieldset class="leader-card"><legend>Leader <?=$i?><?=$i>4?' (optional)':''?></legend>
        <?php text_field('Full name','leader_name_'.$i,'text',$leader['name']??'','minlength="2" maxlength="80"'.($i<=4?' required':''));
        text_field('Email address','leader_email_'.$i,'email',$leader['email']??'','maxlength="190"'.($i<=4?' required':'')); ?>
        </fieldset>
    <?php endfor; ?>
    </div>
<?php }
function community_register_page(): void {
    if (user()) go('dashboard');
    render_header('Create a community account');
    page_heading('BUILD SOMETHING TOGETHER','Request a community account.','One shared login for at least four leaders, activated after administrator approval.');
    account_choices('community'); ?>
    <section class="panel"><form method="post" action="<?=e(url('community-register'))?>">
    <?=action('register-community')?>
    <div class="settings-grid"><div>
    <?php text_field('Community name','name','text','','required minlength="2" maxlength="80" autocomplete="organization"');
    text_field('Shared community email','email','email','','required maxlength="190" autocomplete="username"');
    text_field('Registering leader student ID','student_number','text','','required maxlength="40" autocomplete="off"'); ?>
    <p>List yourself as Leader 1. Only the registering leader ID is checked against the administrator list. The other leaders do not need to provide student IDs.</p>
    </div><div>
    <?php text_field('Shared password (12-72 bytes)','password','password','','required minlength="12" maxlength="72" autocomplete="new-password"');
    text_field('Confirm shared password','password_confirm','password','','required minlength="12" maxlength="72" autocomplete="new-password"'); ?>
    </div></div>
    <?php community_leader_fields(); ?>
    <label class="checkbox"><input type="checkbox" name="consent" value="1" required <?=input('consent')==='1'?'checked':''?>><span>All listed leaders agree to this registration, sharing this account, and the <a href="<?=e(url('privacy'))?>">privacy notice</a>.</span></label>
    <button class="btn">Submit for approval <?=icon('arrow')?></button>
    </form><p class="auth-switch">Already registered? <a href="<?=e(url('community-login'))?>">Community login</a></p></section>
<?php render_footer(); }
function community_login_page(): void {
    if (user()) go('dashboard');
    render_header('Community login'); ?>
    <section class="auth-layout"><?php auth_visual('Lead together.','Use your shared community account to manage clubs, events and your leadership team.'); ?>
    <div class="auth-form"><div class="eyebrow">COMMUNITY SPACE</div><h1>Community login.</h1><p>After administrator approval, all leaders use the same community email and password.</p>
    <form method="post" action="<?=e(url('community-login'))?>"><?=action('community-login')?>
    <?php text_field('Community email','email','email','','required maxlength="190" autocomplete="username"');
    text_field('Shared password','password','password','','required maxlength="72" autocomplete="current-password"'); ?>
    <button class="btn full">Log in to your community <?=icon('arrow')?></button></form>
    <p><a href="<?=e(url('community-register'))?>">Create a community account</a></p>
    <p><a href="<?=e(url('login'))?>">Personal account login</a></p></div></section>
<?php render_footer(); }
function community_approval_admin_section(): void {
    admin();
    $applications = rows("SELECT a.*,s.name,s.email FROM community_applications a JOIN students s ON s.id=a.student_id WHERE a.status='pending' ORDER BY s.created_at,a.student_id LIMIT 50"); ?>
    <section class="section"><div class="section-title"><h2>Community account requests</h2><span class="pill"><?=e((string)scalar("SELECT COUNT(*) FROM community_applications WHERE status='pending'"))?> pending</span></div>
    <p>Review the registering leader ID and leadership team before activating the shared account. Showing the first 50 pending requests.</p>
    <?php if (!$applications): ?><p>No community requests awaiting approval.</p><?php endif; ?>
    <?php foreach ($applications as $application): ?><article class="panel enquiry"><h3><?=e($application['name'])?></h3><p><?=e($application['email'])?> &middot; Registering student ID: <strong><?=e($application['registering_student_number'])?></strong></p>
    <ul><?php foreach (rows('SELECT name,email FROM community_leaders WHERE student_id=? ORDER BY id', [$application['student_id']]) as $leader): ?><li><?=e($leader['name'])?> &middot; <?=e($leader['email'])?></li><?php endforeach; ?></ul>
    <form method="post" action="<?=e(url('admin'))?>" class="row-actions"><?=action('community-decision')?><input type="hidden" name="student_id" value="<?=(int)$application['student_id']?>"><button class="btn btn-small" name="decision" value="approved">Approve community</button><button class="btn btn-small btn-outline" name="decision" value="rejected">Reject</button></form></article><?php endforeach; ?></section>
<?php }
function community_leaders_page(): void {
    $u = require_user();
    if (!community_account((int)$u['id'])) fail(403, 'Community account required', 'Log in with a community account to manage leaders.');
    $leaders = rows('SELECT name,email FROM community_leaders WHERE student_id=? ORDER BY id', [$u['id']]);
    render_header('Community leaders');
    page_heading('SHARED COMMUNITY ACCOUNT', $u['name'], 'Keep your leadership team up to date. At least four leaders must remain on the account.');
    dashboard_nav('community-leaders'); ?>
    <section class="panel"><form method="post" action="<?=e(url('community-leaders'))?>"><?=action('save-community-leaders')?>
    <?php community_leader_fields($leaders);
    text_field('Current shared password','current_password','password','','required maxlength="72" autocomplete="current-password"'); ?>
    <button class="btn">Save leaders <?=icon('check')?></button></form></section>
<?php render_footer(); }
