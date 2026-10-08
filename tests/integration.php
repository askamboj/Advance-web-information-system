<?php
// Run only against your local development installation. Creates and cleans up test fixtures.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$base=$argv[1]??'http://127.0.0.1:8080';
if(!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost'],true))exit("Tests are restricted to a local development server.\n");
if(!is_dir(ROOT.'/tests/output'))mkdir(ROOT.'/tests/output',0700,true);
$run='test'.bin2hex(random_bytes(5));$password='Test only strong passphrase 2026!';$newPassword='A different test passphrase 2026!';$assertions=[];$users=[];$contactIds=[];
function expect(bool $ok,string $label): void {global $assertions;$assertions[]=['check'=>$label,'result'=>$ok?'PASS':'FAIL'];echo ($ok?'PASS ':'FAIL ').$label."\n";if(!$ok)throw new RuntimeException($label);}
class Browser {
    public string $token='';public string $cookie;
    public function __construct(public string $base,string $name){global $run;$this->cookie=ROOT.'/tests/output/'.$run.'-'.$name.'.cookies';}
    public function request(string $page='home',?array $post=null,array $args=[]):array{
        $h=curl_init($this->base.'/index.php?'.http_build_query(['page'=>$page]+$args));$headers=[];
        curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$this->cookie,CURLOPT_COOKIEFILE=>$this->cookie,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,CURLOPT_HEADERFUNCTION=>static function($h,$line)use(&$headers){$headers[]=$line;return strlen($line);}]);
        if($post!==null){$post+=['csrf'=>$this->token];curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,http_build_query($post));}
        $body=curl_exec($h);if($body===false)throw new RuntimeException(curl_error($h));$status=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_close($h);
        if(preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$m))$this->token=$m[1];
        return ['status'=>$status,'body'=>$body,'headers'=>implode('',$headers)];
    }
    public function login(string $email,string $password):void{$this->request('login');$r=$this->request('login',['action'=>'login','email'=>$email,'password'=>$password]);expect($r['status']===303,'Login succeeds for '.$email);$this->request('dashboard');}
    public function __destruct(){if(is_file($this->cookie))unlink($this->cookie);}
}
function fixture(string $name,string $role):array {global $run,$password,$users;$email=$run.'-'.$name.'@example.test';query('INSERT INTO students(name,email,password_hash,role) VALUES(?,?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]);$id=(int)db()->lastInsertId();$users[]=$id;return ['id'=>$id,'email'=>$email];}
$start=microtime(true);$success=false;
try {
    $guest=new Browser($base,'guest');
    foreach(['home','clubs','events','about','contact','privacy','login','register'] as $page){$r=$guest->request($page);expect($r['status']===200,'Public page: '.$page);expect(!str_contains($r['body'],'Warning:')&&!str_contains($r['body'],'Fatal error'),'No PHP error on '.$page);}
    expect(!str_contains($guest->request('login')['body'],'name="password_confirm"'),'Login is separate from registration');
    $r=$guest->request('register');expect(str_contains($r['body'],'name="password_confirm"'),'Registration has password confirmation');
    expect(str_contains($r['headers'],'Content-Security-Policy:')&&str_contains($r['headers'],'frame-ancestors'),'Security headers are sent');
    $fresh=new Browser($base,'cookie');$r=$fresh->request('login');expect(str_contains(strtolower($r['headers']),'httponly')&&str_contains(strtolower($r['headers']),'samesite=lax'),'Session cookie is HttpOnly and SameSite');
    expect($guest->request('dashboard')['status']===303,'Guests are redirected from dashboard');
    expect($guest->request('register',['action'=>'register','csrf'=>'bad'])['status']===403,'Forged CSRF is rejected');
    expect($guest->request('missing-page')['status']===404,'Unknown page returns 404');
    $guest->request('register');
    $email=$run.'-registered@example.test';
    query('INSERT INTO eligible_student_ids(student_number) VALUES(?)',[strtoupper($run)]);
    $register=['action'=>'register','name'=>'Integration Student','email'=>$email,'student_number'=>strtoupper($run),'password'=>$password,'password_confirm'=>$password,'consent'=>'1','role'=>'admin'];
    $registrationResponse=$guest->request('register',$register);
    if($registrationResponse['status']!==303)file_put_contents(ROOT.'/tests/output/registration-debug.html',$registrationResponse['body']);
    expect($registrationResponse['status']===303,'Student registration works');
    $record=one('SELECT * FROM students WHERE email=?',[$email]);$users[]=(int)$record['id'];
    expect($record['role']==='student','Registration ignores injected administrator role');
    expect($record['password_hash']!==$password&&password_verify($password,$record['password_hash']),'Password is hashed and verifies');
    expect($guest->request('register',$register)['status']===422,'Duplicate email rejected');
    $guest->request('login');expect($guest->request('login',['action'=>'login','email'=>"' OR 1=1 --",'password'=>'wrong'])['status']===422,'SQL injection cannot bypass login');
    $student=new Browser($base,'student');$student->login($email,$password);
    expect($student->request('club-form')['status']===403,'Student cannot open club creation');
    expect($student->request('admin')['status']===403,'Student cannot open administration');
    $org=fixture('Organiser One','organiser');$other=fixture('Organiser Two','organiser');$adm=fixture('Administrator','admin');$s2=fixture('Student Two','student');
    $owner=new Browser($base,'owner');$owner->login($org['email'],$password);$intruder=new Browser($base,'other');$intruder->login($other['email'],$password);$admin=new Browser($base,'admin');$admin->login($adm['email'],$password);
    $owner->request('club-form');
    $clubForm=['action'=>'save-club','id'=>'0','name'=>$run.' Community','category'=>'Technology','tagline'=>'Testing a real connected community','description'=>'A safe test club for integration and permission checks in the CampusConnect application.','location'=>'Test Lab','theme'=>'mint'];
    expect($owner->request('club-form',$clubForm)['status']===303,'Organiser creates club');$cid=(int)scalar('SELECT id FROM clubs WHERE name=?',[$clubForm['name']]);
    expect($cid>0,'Created club persisted in database');
    $clubForm['id']=(string)$cid;$clubForm['tagline']='Updated community description';expect($owner->request('club-form',$clubForm,['id'=>$cid])['status']===303,'Owner edits club');
    expect($intruder->request('members',null,['id'=>$cid])['status']===404,'Other organiser cannot view club members');
    expect($intruder->request('club-form',$clubForm,['id'=>$cid])['status']===404,'Other organiser cannot forge club update');
    $student->request('club',null,['id'=>$cid]);expect($student->request('club',['action'=>'join-club','club_id'=>$cid],['id'=>$cid])['status']===303,'Student joins club');
    $student->request('club',['action'=>'join-club','club_id'=>$cid],['id'=>$cid]);expect((int)scalar('SELECT COUNT(*) FROM memberships WHERE student_id=? AND club_id=?',[$record['id'],$cid])===1,'Duplicate membership creates no duplicate row');
    expect(str_contains($owner->request('members',null,['id'=>$cid])['body'],'Integration Student'),'Owner can see own member name');
    $student->request('club',['action'=>'leave-club','club_id'=>$cid],['id'=>$cid]);expect((int)scalar('SELECT COUNT(*) FROM memberships WHERE student_id=? AND club_id=?',[$record['id'],$cid])===0,'Student leaves club');
    $student->request('club',['action'=>'join-club','club_id'=>$cid],['id'=>$cid]);$owner->request('members',['action'=>'remove-member','club_id'=>$cid,'student_id'=>$record['id']],['id'=>$cid]);expect((int)scalar('SELECT COUNT(*) FROM memberships WHERE club_id=?',[$cid])===0,'Owner removes membership');
    $owner->request('event-form',null,['club'=>$cid]);$eventForm=['action'=>'save-event','id'=>'0','club_id'=>$cid,'title'=>'Integration event '.$run,'description'=>'A real test event that verifies database persistence, ownership and RSVP capacity.','starts_at'=>(new DateTimeImmutable('+3 days'))->format('Y-m-d\TH:i'),'ends_at'=>(new DateTimeImmutable('+3 days +2 hours'))->format('Y-m-d\TH:i'),'location'=>'Test Auditorium','capacity'=>'1'];
    expect($owner->request('event-form',$eventForm,['club'=>$cid])['status']===303,'Organiser creates event');$eid=(int)scalar('SELECT id FROM events WHERE title=?',[$eventForm['title']]);expect($eid>0,'Created event persisted');
    expect($intruder->request('event-form',null,['id'=>$eid])['status']===404,'Other organiser cannot edit event');
    expect($intruder->request('event-form',$eventForm,['club'=>$cid])['status']===404,'Other organiser cannot create event in foreign club');
    $student->request('event',null,['id'=>$eid]);expect($student->request('event',['action'=>'rsvp','event_id'=>$eid],['id'=>$eid])['status']===303,'Student RSVPs');
    expect($student->request('event',['action'=>'rsvp','event_id'=>$eid],['id'=>$eid])['status']===422,'Duplicate RSVP rejected');
    $second=new Browser($base,'second');$second->login($s2['email'],$password);expect($second->request('event',['action'=>'rsvp','event_id'=>$eid],['id'=>$eid])['status']===422,'Full event rejects extra RSVP');
    expect((int)scalar('SELECT COUNT(*) FROM rsvps WHERE event_id=?',[$eid])===1,'Capacity is respected');
    expect($owner->request('attendees',null,['id'=>$eid])['status']===200,'Owner can see event attendee list');
    expect($intruder->request('attendees',null,['id'=>$eid])['status']===404,'Foreign attendee list denied');
    $ics=$guest->request('calendar',null,['id'=>$eid]);expect($ics['status']===200&&str_contains($ics['headers'],'text/calendar')&&str_contains($ics['body'],'BEGIN:VEVENT'),'Calendar download is a valid event response');
    expect($student->request('event',['action'=>'cancel-rsvp','event_id'=>$eid],['id'=>$eid])['status']===303,'Student cancels RSVP');
    expect($second->request('event',['action'=>'rsvp','event_id'=>$eid],['id'=>$eid])['status']===303,'Released place can be reserved');
    $eventForm['id']=(string)$eid;$eventForm['capacity']='2';$eventForm['title']='Updated event '.$run;expect($owner->request('event-form',$eventForm,['id'=>$eid])['status']===303,'Owner edits event');
    $student->request('profile');$xss='<script>alert(1)</script>';expect($student->request('profile',['action'=>'profile','name'=>$xss,'bio'=>'Test bio'])['status']===303,'Profile update persists plain text');
    $profile=$student->request('profile');expect(!str_contains($profile['body'],$xss)&&str_contains($profile['body'],'&lt;script&gt;'),'Stored profile HTML is escaped');
    $student->request('profile',['action'=>'profile','name'=>'Integration Student','bio'=>'Test bio']);
    $student->request('contact');$contact=['action'=>'contact','name'=>'Integration Student','email'=>$email,'subject'=>$run.' enquiry','message'=>'Testing a stored enquiry <script>alert(1)</script> safely.','consent'=>'1','website'=>''];
    expect($student->request('contact',$contact)['status']===303,'Contact enquiry stored');$mid=(int)scalar('SELECT id FROM contact_messages WHERE subject=?',[$contact['subject']]);$contactIds[]=$mid;
    $inbox=$admin->request('admin');expect(str_contains($inbox['body'],$run.' enquiry')&&!str_contains($inbox['body'],$contact['message']),'Admin sees safely escaped enquiry');
    expect($admin->request('admin',['action'=>'resolve-contact','id'=>$mid])['status']===303,'Admin resolves enquiry');
    expect(scalar('SELECT status FROM contact_messages WHERE id=?',[$mid])==='resolved','Resolved status persists');
    expect($admin->request('admin',['action'=>'delete-contact','id'=>$mid])['status']===303,'Admin deletes enquiry');
    $student->request('dashboard');expect($student->request('dashboard',['action'=>'request-organiser'])['status']===303,'Student requests organiser access');
    $admin->request('admin');expect($admin->request('admin',['action'=>'organiser-decision','student_id'=>$record['id'],'decision'=>'approve'])['status']===303,'Admin approves organiser request');
    expect($student->request('dashboard')['status']===303,'Role change invalidates old session');$student->login($email,$password);expect($student->request('club-form')['status']===200,'Approved organiser can open creation page');
    $anotherSession=new Browser($base,'another');$anotherSession->login($email,$password);
    $student->request('profile');expect($student->request('profile',['action'=>'password','current_password'=>$password,'new_password'=>$newPassword,'password_confirm'=>$newPassword])['status']===303,'Password change works');
    expect($anotherSession->request('dashboard')['status']===303,'Password change invalidates other sessions');$student->request('profile');
    $student->request('contact',$contact);$linked=(int)scalar('SELECT COUNT(*) FROM contact_messages WHERE student_id=?',[$record['id']]);expect($linked===1,'Authenticated enquiry linked to account');
    expect($student->request('profile',['action'=>'delete-account','current_password'=>$newPassword,'confirmation'=>'DELETE'])['status']===303,'Account deletion works');
    expect(!one('SELECT id FROM students WHERE id=?',[$record['id']])&&(int)scalar('SELECT COUNT(*) FROM contact_messages WHERE student_id=?',[$record['id']])===0,'Deletion removes account and linked enquiries');
    $owner->request('event-form',null,['id'=>$eid]);expect($owner->request('event-form',['action'=>'delete-event','id'=>$eid,'confirmation'=>'DELETE'],['id'=>$eid])['status']===303,'Owner deletes event');expect((int)scalar('SELECT COUNT(*) FROM rsvps WHERE event_id=?',[$eid])===0,'Event deletion cascades to RSVPs');
    $owner->request('club-form',null,['id'=>$cid]);expect($owner->request('club-form',['action'=>'delete-club','id'=>$cid,'confirmation'=>'DELETE'],['id'=>$cid])['status']===303,'Owner deletes club');
    $owner->request('dashboard');expect($owner->request('home',['action'=>'logout'])['status']===303,'Logout works');expect($owner->request('dashboard')['status']===303,'Logged-out session is denied');
    // Dedicated nonexistent account exercises throttling without locking a real demo account.
    $guest->request('login');for($i=0;$i<9;$i++)$limited=$guest->request('login',['action'=>'login','email'=>$run.'-rate@example.test','password'=>'incorrect']);expect($limited['status']===429,'Repeated login attempts are rate-limited');
    $success=true;
} catch(Throwable $ex) {echo 'FAILED: '.$ex->getMessage()."\n";}
finally {
    foreach($users as $id)query('DELETE FROM students WHERE id=?',[$id]);
    query('DELETE FROM eligible_student_ids WHERE student_number=?',[strtoupper($run)]);
    foreach($contactIds as $id)query('DELETE FROM contact_messages WHERE id=?',[$id]);
    // Remove only buckets used by this run plus localhost counters generated by this local suite.
    $keys=['login-ip:127.0.0.1','register:127.0.0.1','contact:127.0.0.1',"login-account:' OR 1=1 --",'login-account:'.strtolower("' OR 1=1 --")];
    foreach(['registered','Organiser One','Organiser Two','Administrator','Student Two','rate'] as $name)$keys[]='login-account:'.strtolower($run.'-'.$name.'@example.test');
    foreach($users as $id){$keys[]='password:'.$id;$keys[]='delete:'.$id;}
    foreach($keys as $key)query('DELETE FROM rate_limits WHERE bucket=?',[hash_hmac('sha256',$key,$config['app_key'])]);
    $report=['date'=>date('c'),'target'=>$base,'php'=>PHP_VERSION,'database'=>scalar('SELECT VERSION()'),'duration_seconds'=>round(microtime(true)-$start,2),'passed'=>$success,'assertions'=>$assertions];
    file_put_contents(ROOT.'/tests/output/integration-results.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
echo count($assertions).' assertions; '.($success?'all passed.':'failure detected.')."\n";exit($success?0:1);
