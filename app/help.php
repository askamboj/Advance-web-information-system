<?php
function help_page(): void {
    render_header('Help & getting started');
    page_heading('A LITTLE GUIDANCE','Your campus, made simple.','Choose the right account, find your community and get answers when you need them.'); ?>
    <div class="settings-grid">
        <section class="panel prose"><div class="eyebrow">FOR INDIVIDUAL STUDENTS</div><h2>Start with your student ID.</h2>
            <ol><li>Choose a personal account and enter your student ID, name, email and password.</li><li>Your ID must match the list added by the campus administrator. One ID can register one personal account.</li><li>Once your ID matches and registration succeeds, log in straight away. Personal accounts do not need separate administrator approval.</li></ol>
            <a class="btn" href="<?=e(url('register'))?>">Create a personal account <?=icon('arrow')?></a>
        </section>
        <section class="panel prose"><div class="eyebrow">FOR COMMUNITY LEADERS</div><h2>Lead something together.</h2>
            <ol><li>Choose a community account and provide a shared email and password.</li><li>Enter the registering leader's student ID and list at least four leaders with different email addresses. Only the registering leader needs to provide an approved student ID.</li><li>Submit your request. An administrator must approve it before the shared community login becomes available.</li></ol>
            <a class="btn" href="<?=e(url('community-register'))?>">Request a community account <?=icon('arrow')?></a>
        </section>
    </div>
    <section class="section"><div class="section-title"><h2>Make yourself at home.</h2></div><div class="settings-grid">
        <article class="panel"><h3>Find your people</h3><p>Browse clubs by interest, open a club page and join while logged in. Your memberships appear on your dashboard.</p><a class="text-link" href="<?=e(url('clubs'))?>">Discover clubs <?=icon('arrow')?></a></article>
        <article class="panel"><h3>Make a plan</h3><p>Explore upcoming events, open the details and RSVP when a place is available. You can cancel your RSVP or download a calendar entry from the event page.</p><a class="text-link" href="<?=e(url('events'))?>">Explore events <?=icon('arrow')?></a></article>
    </div></section>
    <section class="panel prose"><h2>Common questions</h2>
        <details><summary>My student ID is not accepted. What should I do?</summary><p>Check the ID carefully, including any leading zeros or hyphens. The administrator must add it to the approved list before you can register. Contact the campus team if it is still missing.</p></details>
        <details><summary>My ID already has an account. Can I register again?</summary><p>Each student ID can be linked to one personal account. Use the <a href="<?=e(url('login'))?>">personal login page</a> for your existing account. Contact the campus team if you do not recognise the account; do not use somebody else's student ID.</p></details>
        <details><summary>Why can my community not log in yet?</summary><p>New community requests remain pending until an administrator reviews them. Rejected requests also remain unable to log in. Use the <a href="<?=e(url('community-login'))?>">community login page</a>, and contact the campus team if you need help with a request.</p></details>
        <details><summary>Do all community leaders need their own login?</summary><p>No. Leaders use one shared community email and password. Anyone using that account has the same access to its clubs, events, leader list and account settings.</p></details>
        <details><summary>Can we change our community leaders?</summary><p>After approval, open Community leaders from the dashboard. Confirm the shared password to save changes. Keep at least four leaders with different email addresses on the account.</p></details>
        <details><summary>How do I update my profile or password?</summary><p>Log in, open My campus and choose Profile &amp; security. Password changes require the current password and sign out other sessions.</p></details>
        <details><summary>Will a contact enquiry send an email?</summary><p>Enquiries are saved in the administrator inbox. Keep the reference number shown after submitting. This website does not send automatic enquiry emails.</p></details>
    </section>
    <section class="section panel"><h2>Still need a hand?</h2><p>Tell the campus team which step is causing trouble. Never include your password in an enquiry.</p><div class="row-actions"><a class="btn" href="<?=e(url('contact'))?>">Contact the campus team <?=icon('mail')?></a><a class="btn btn-outline" href="<?=e(url('privacy'))?>">Privacy &amp; your data</a></div></section>
<?php render_footer(); }
