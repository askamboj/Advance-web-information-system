<?php
// Run against a local development installation after scripts/install.php.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
$base = $argv[1] ?? 'http://127.0.0.1:8087';
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1','localhost'], true)) exit("Local development targets only.\n");
$run = 'community'.bin2hex(random_bytes(5));
$password = 'Community test passphrase 2026!';
$email = $run.'@example.test';
$ids = []; $checks = 0; $ok = false;
function check(bool $condition, string $label): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}
class CommunityTestBrowser {
    private $curl;
    private string $csrf = '';
    public function __construct(private string $base) {
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15]);
    }
    public function request(string $page, ?array $post = null): array {
        curl_setopt($this->curl, CURLOPT_URL, $this->base.'/index.php?page='.urlencode($page));
        if ($post !== null) {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($post + ['csrf'=>$this->csrf]));
        } else curl_setopt($this->curl, CURLOPT_HTTPGET, true);
        $body = curl_exec($this->curl);
        if ($body === false) throw new RuntimeException(curl_error($this->curl));
        if (preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $match)) $this->csrf=$match[1];
        return ['status'=>curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE),'body'=>$body];
    }
}
try {
    query('INSERT INTO eligible_student_ids(student_number) VALUES(?)',[strtoupper($run)]);
    query("INSERT INTO students(name,email,password_hash,role) VALUES(?,?,?,'admin')",['Review Admin',$run.'-admin@example.test',password_hash($password,PASSWORD_DEFAULT)]);
    $ids[]=(int)db()->lastInsertId();
    $browser = new CommunityTestBrowser($base);
    foreach (['register','community-register','community-login'] as $page) check($browser->request($page)['status']===200, "$page opens");
    check(str_contains($browser->request('register')['body'],'Community account'), 'Registration offers both account types');
    check($browser->request('community-leaders')['status']===303, 'Guests cannot see leader contacts');
    $form = ['action'=>'register-community','name'=>'Community Test','email'=>$email,'student_number'=>strtoupper($run),'password'=>$password,'password_confirm'=>$password,'consent'=>'1'];
    for ($i=1;$i<=4;$i++) { $form['leader_name_'.$i]='Leader '.$i; $form['leader_email_'.$i]=$run.'-leader'.$i.'@example.test'; }
    check($browser->request('community-register',$form+['csrf'=>'invalid'])['status']===403, 'Community registration requires CSRF');
    $short=$form; unset($short['leader_name_4'],$short['leader_email_4']);
    check($browser->request('community-register',$short)['status']===422, 'Three leaders rejected');
    check(!one('SELECT id FROM students WHERE email=?',[$email]), 'Invalid registration leaves no account');
    $duplicate=$form; $duplicate['leader_email_4']=strtoupper($duplicate['leader_email_1']);
    check($browser->request('community-register',$duplicate)['status']===422, 'Duplicate leader emails rejected regardless of case');
    $invalid=$form; $invalid['leader_email_4']='not-an-email';
    check($browser->request('community-register',$invalid)['status']===422, 'Invalid leader email rejected');
    check($browser->request('community-register',$form)['status']===303, 'Four leaders create a shared community account');
    $id=(int)scalar('SELECT id FROM students WHERE email=?',[$email]); $ids[]=$id;
    check((int)scalar('SELECT COUNT(*) FROM community_leaders WHERE student_id=?',[$id])===4, 'Four leaders persisted');
    check(scalar('SELECT role FROM students WHERE id=?',[$id])==='student', 'Pending community has no organiser access');
    check($browser->request('community-register',$form)['status']===422, 'Duplicate shared account email rejected');
    check((int)scalar('SELECT COUNT(*) FROM community_leaders WHERE student_id=?',[$id])===4, 'Duplicate submission preserves existing leaders');
    $browser->request('login');
    check($browser->request('login',['action'=>'login','email'=>$email,'password'=>$password])['status']===422, 'Personal login rejects community account');
    $browser->request('community-login');
    check($browser->request('community-login',['action'=>'community-login','email'=>$email,'password'=>'wrong'])['status']===422, 'Wrong shared password rejected');
    check($browser->request('community-login',['action'=>'community-login','email'=>$email,'password'=>$password])['status']===422, 'Pending community cannot log in');
    $reviewer=new CommunityTestBrowser($base); $reviewer->request('login');
    check($reviewer->request('login',['action'=>'login','email'=>$run.'-admin@example.test','password'=>$password])['status']===303, 'Administrator can log in to review requests');
    check(str_contains($reviewer->request('admin')['body'],strtoupper($run)), 'Administrator sees registering leader ID');
    check($reviewer->request('admin',['action'=>'community-decision','student_id'=>$id,'decision'=>'approved'])['status']===303, 'Administrator approves community');
    check(scalar('SELECT role FROM students WHERE id=?',[$id])==='organiser', 'Approved community receives organiser access');
    check($browser->request('community-login',['action'=>'community-login','email'=>$email,'password'=>$password])['status']===303, 'Shared community login succeeds');
    check(str_contains($browser->request('dashboard')['body'],'Community leaders'), 'Community dashboard links to leader management');
    check(str_contains($browser->request('community-leaders')['body'],$form['leader_email_1']), 'Shared account can view leaders');
    $update=$short; $update['action']='save-community-leaders'; $update['current_password']=$password;
    check($browser->request('community-leaders',$update)['status']===422, 'Leader updates cannot reduce team below four');
    $update=$form; $update['action']='save-community-leaders'; $update['current_password']='wrong';
    check($browser->request('community-leaders',$update)['status']===422, 'Leader changes require shared password');
    $update['current_password']=$password; $update['leader_name_5']='Fifth Leader'; $update['leader_email_5']=$run.'-leader5@example.test';
    check($browser->request('community-leaders',$update)['status']===303, 'A fifth leader can be added');
    check((int)scalar('SELECT COUNT(*) FROM community_leaders WHERE student_id=?',[$id])===5, 'Fifth leader persisted');
    $browser->request('club-form');
    $club=['action'=>'save-club','id'=>'0','name'=>$run,'category'=>'Technology','tagline'=>'Community managed club','description'=>'This test club is managed through the new shared community account.','location'=>'Campus','theme'=>'mint'];
    check($browser->request('club-form',$club)['status']===303, 'Community can create a club');
    $cid=(int)scalar('SELECT id FROM clubs WHERE owner_id=?',[$id]);
    $browser->request('event-form');
    $event=['action'=>'save-event','id'=>'0','club_id'=>$cid,'title'=>'Community event','description'=>'An event created by the shared community account for testing.','starts_at'=>(new DateTimeImmutable('+3 days'))->format('Y-m-d\TH:i'),'ends_at'=>(new DateTimeImmutable('+3 days +2 hours'))->format('Y-m-d\TH:i'),'location'=>'Campus','capacity'=>'20'];
    check($browser->request('event-form',$event)['status']===303, 'Community can create an event');
    $personalEmail=$run.'-personal@example.test';
    query('INSERT INTO students(name,email,password_hash) VALUES(?,?,?)',['Personal Test',$personalEmail,password_hash($password,PASSWORD_DEFAULT)]);
    $ids[]=(int)db()->lastInsertId();
    $personal=new CommunityTestBrowser($base); $personal->request('community-login');
    check($personal->request('community-login',['action'=>'community-login','email'=>$personalEmail,'password'=>$password])['status']===422, 'Community login rejects a personal account');
    $personal->request('login');
    check($personal->request('login',['action'=>'login','email'=>$personalEmail,'password'=>$password])['status']===303, 'Personal login still works');
    check($personal->request('community-leaders')['status']===403, 'Personal account cannot read community leaders');
    check($personal->request('community-leaders',$update)['status']===403, 'Personal account cannot update community leaders');
    $browser->request('profile');
    check($browser->request('profile',['action'=>'delete-account','current_password'=>$password,'confirmation'=>'DELETE'])['status']===303, 'Community account deletion works');
    check((int)scalar('SELECT COUNT(*) FROM community_leaders WHERE student_id=?',[$id])===0, 'Deletion removes private leader contacts');
    check((int)scalar('SELECT COUNT(*) FROM clubs WHERE owner_id=?',[$id])===0, 'Deletion removes community clubs');
    $ok=true;
} catch (Throwable $ex) { echo 'FAIL '.$ex->getMessage()."\n"; }
finally {
    foreach ($ids as $id) query('DELETE FROM students WHERE id=?',[$id]);
    query('DELETE FROM eligible_student_ids WHERE student_number=?',[strtoupper($run)]);
    $keys=['register:127.0.0.1','login-ip:127.0.0.1','login-account:'.$email,'login-account:'.$run.'-personal@example.test','login-account:'.$run.'-admin@example.test'];
    foreach ($ids as $id) { $keys[]='leaders:'.$id; $keys[]='delete:'.$id; }
    foreach ($keys as $key) query('DELETE FROM rate_limits WHERE bucket=?',[hash_hmac('sha256',$key,$config['app_key'])]);
}
echo "$checks checks; ".($ok?'all passed.':'failure detected.')."\n";
exit($ok?0:1);
