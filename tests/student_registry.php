<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
require ROOT.'/app/student_registry.php';
$base = $argv[1]??'http://127.0.0.1:8087';
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1','localhost'], true)) exit("Local development targets only.\n");
$run = 'REG'.strtoupper(bin2hex(random_bytes(5)));
$password = 'Registry test passphrase 2026!';
$accounts = []; $files = []; $checks = 0; $ok = false;
function expect_registry(bool $condition, string $label): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($label);
    echo "PASS $label\n";
}
class RegistryBrowser {
    private $curl; private string $token = '';
    public function __construct(private string $base) {
        $this->curl=curl_init();
        curl_setopt_array($this->curl,[CURLOPT_COOKIEFILE=>'',CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20]);
    }
    public function request(string $page, ?array $post=null, bool $multipart=false): array {
        curl_setopt($this->curl,CURLOPT_URL,$this->base.'/index.php?page='.urlencode($page));
        if ($post===null) curl_setopt($this->curl,CURLOPT_HTTPGET,true);
        else {
            $post+=['csrf'=>$this->token];
            curl_setopt($this->curl,CURLOPT_POSTFIELDS,$multipart?$post:http_build_query($post));
        }
        $body=curl_exec($this->curl);
        if ($body===false) throw new RuntimeException(curl_error($this->curl));
        if(preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$match)) $this->token=$match[1];
        return ['status'=>curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE),'body'=>$body];
    }
}
function registry_fixture_file(string $extension, string $contents): string {
    global $files,$run;
    $path=ROOT.'/tests/output/'.$run.'-'.count($files).'.'.$extension;
    file_put_contents($path,$contents); $files[]=$path; return $path;
}
function registry_xlsx_fixture(string $id, bool $formula=false): string {
    global $files,$run;
    $path=ROOT.'/tests/output/'.$run.'-'.count($files).'.zip'; $files[]=$path;
    $zip=new PharData($path,0,null,Phar::ZIP);
    $zip['[Content_Types].xml']='<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/></Types>';
    $zip['_rels/.rels']='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $zip['xl/workbook.xml']='<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $zip['xl/_rels/workbook.xml.rels']='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
    $zip['xl/sharedStrings.xml']='<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Student ID</t></si><si><t>'.$id.'</t></si></sst>';
    $zip['xl/worksheets/sheet1.xml']='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="B1" t="s"><v>0</v></c></row><row r="2"><c r="B2" t="s">'.($formula?'<f>1+1</f>':'').'<v>1</v></c></row><row r="3"><c r="B3" t="inlineStr"><is><t>'.$id.'-INLINE</t></is></c></row></sheetData></worksheet>';
    unset($zip); return $path;
}
try {
    if(!is_dir(ROOT.'/tests/output')) mkdir(ROOT.'/tests/output',0700,true);
    query("INSERT INTO students(name,email,password_hash,role) VALUES(?,?,?,'admin')",['Registry Admin',$run.'@example.test',password_hash($password,PASSWORD_DEFAULT)]);
    $accounts[]=(int)db()->lastInsertId();
    $admin=new RegistryBrowser($base); $admin->request('login');
    expect_registry($admin->request('login',['action'=>'login','email'=>$run.'@example.test','password'=>$password])['status']===303,'Administrator login');
    expect_registry(str_contains($admin->request('admin')['body'],'Upload an Excel list'),'Admin dashboard contains registry controls');
    expect_registry($admin->request('admin',['action'=>'add-student-ids','student_ids'=>$run.'-MANUAL','csrf'=>'bad'])['status']===403,'Registry changes require CSRF');
    expect_registry($admin->request('admin',['action'=>'add-student-ids','student_ids'=>strtolower($run)."-MANUAL\n".$run.'-MANUAL'])['status']===303,'Manual entry normalizes and deduplicates IDs');
    expect_registry((int)scalar('SELECT COUNT(*) FROM eligible_student_ids WHERE student_number=?',[$run.'-MANUAL'])===1,'Manual duplicate creates only one record');
    expect_registry($admin->request('admin',['action'=>'add-student-ids','student_ids'=>$run.'-MANUAL'])['status']===303,'Repeated import is safe');
    $csv=registry_fixture_file('csv',"student_id\n0$run-CSV\n");
    expect_registry($admin->request('admin',['action'=>'import-student-ids','student_file'=>new CURLFile($csv,'text/csv','students.csv')],true)['status']===303,'CSV upload imports IDs');
    expect_registry((bool)one('SELECT student_number FROM eligible_student_ids WHERE student_number=?',['0'.$run.'-CSV']),'CSV leading zero preserved');
    $bulk="student_id\n";
    for($i=0;$i<1000;$i++) $bulk.=$run.'-BULK'.str_pad((string)$i,4,'0',STR_PAD_LEFT)."\n";
    $bulkPath=registry_fixture_file('csv',$bulk);
    expect_registry(strlen($bulk)>20000 && $admin->request('admin',['action'=>'import-student-ids','student_file'=>new CURLFile($bulkPath,'text/csv','bulk.csv')],true)['status']===303,'Valid upload larger than the ordinary form limit succeeds');
    expect_registry((int)scalar('SELECT COUNT(*) FROM eligible_student_ids WHERE student_number LIKE ?',[$run.'-BULK%'])===1000,'All 1,000 bulk IDs imported');
    $xlsx=registry_xlsx_fixture('00'.$run);
    expect_registry($admin->request('admin',['action'=>'import-student-ids','student_file'=>new CURLFile($xlsx,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','students.xlsx')],true)['status']===303,'Real XLSX upload imports shared and inline strings');
    expect_registry((bool)one('SELECT student_number FROM eligible_student_ids WHERE student_number=?',['00'.$run]),'XLSX leading zeros preserved');
    expect_registry((bool)one('SELECT student_number FROM eligible_student_ids WHERE student_number=?',['00'.$run.'-INLINE']),'XLSX named header in second column recognized');
    $bad=registry_fixture_file('csv',"student_id\n$run-ROLLBACK\ninvalid id!\n");
    expect_registry($admin->request('admin',['action'=>'import-student-ids','student_file'=>new CURLFile($bad,'text/csv','bad.csv')],true)['status']===422,'Invalid CSV rejected');
    expect_registry(!one('SELECT student_number FROM eligible_student_ids WHERE student_number=?',[$run.'-ROLLBACK']),'Invalid batch imports nothing');
    $formula=registry_xlsx_fixture($run.'-FORMULA',true);
    expect_registry($admin->request('admin',['action'=>'import-student-ids','student_file'=>new CURLFile($formula,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','formula.xlsx')],true)['status']===422,'Formula spreadsheet rejected');
    $fake=registry_fixture_file('xlsx','not a zip workbook');
    expect_registry($admin->request('admin',['action'=>'import-student-ids','student_file'=>new CURLFile($fake,'application/octet-stream','fake.xlsx')],true)['status']===422,'Malformed spreadsheet rejected');
    $big=registry_fixture_file('csv',str_repeat('x',2100000));
    expect_registry(in_array($admin->request('admin',['action'=>'import-student-ids','student_file'=>new CURLFile($big,'text/csv','big.csv')],true)['status'],[413,422],true),'Oversized upload rejected');
    $guest=new RegistryBrowser($base); $guest->request('register');
    expect_registry(str_contains($guest->request('register')['body'],'name="student_number"'),'Personal form requires student ID');
    expect_registry($guest->request('admin',['action'=>'add-student-ids','student_ids'=>$run.'-FORGED'])['status']===303,'Guest cannot change registry');
    $form=['action'=>'register','name'=>'Verified Student','email'=>$run.'-student@example.test','password'=>$password,'password_confirm'=>$password,'consent'=>'1'];
    expect_registry($guest->request('register',$form)['status']===422,'Missing student ID rejected');
    expect_registry($guest->request('register',$form+['student_number'=>$run.'-MISSING'])['status']===422,'Unknown student ID rejected');
    expect_registry(!one('SELECT id FROM students WHERE email=?',[$form['email']]),'Unapproved registration creates no account');
    $form['student_number']=strtolower($run).'-manual';
    expect_registry($guest->request('register',$form)['status']===303,'Approved ID allows personal registration');
    $student=(int)scalar('SELECT id FROM students WHERE email=?',[$form['email']]); $accounts[]=$student;
    expect_registry((int)scalar('SELECT account_id FROM student_id_claims WHERE student_number=?',[$run.'-MANUAL'])===$student,'Approved ID linked to personal account');
    $reuse=$form; $reuse['email']=$run.'-duplicate@example.test';
    expect_registry($guest->request('register',$reuse)['status']===422,'One ID cannot register a second personal account');
    $guest->request('login');
    expect_registry($guest->request('login',['action'=>'login','email'=>$form['email'],'password'=>$password])['status']===303,'Verified student can log in');
    $guest->request('profile');
    expect_registry($guest->request('admin',['action'=>'import-student-ids','student_file'=>new CURLFile($csv,'text/csv','students.csv')],true)['status']===403,'Student cannot upload approved IDs');
    $community=new RegistryBrowser($base); $community->request('community-register');
    $request=['action'=>'register-community','name'=>'Pending Community','email'=>$run.'-community@example.test','student_number'=>$run.'-MISSING','password'=>$password,'password_confirm'=>$password,'consent'=>'1'];
    for($i=1;$i<=4;$i++){ $request['leader_name_'.$i]='Leader '.$i; $request['leader_email_'.$i]=$run.'-'.$i.'@example.test'; }
    expect_registry($community->request('community-register',$request)['status']===422,'Community requires an approved registering leader ID');
    $request['student_number']=$run.'-MANUAL';
    expect_registry($community->request('community-register',$request)['status']===303,'Registering leader may use their personal ID for a community request');
    $cid=(int)scalar('SELECT id FROM students WHERE email=?',[$request['email']]); $accounts[]=$cid;
    expect_registry($guest->request('admin',['action'=>'community-decision','student_id'=>$cid,'decision'=>'approved'])['status']===403,'Student cannot approve community requests');
    $admin->request('admin');
    expect_registry($admin->request('admin',['action'=>'community-decision','student_id'=>$cid,'decision'=>'rejected'])['status']===303,'Administrator can reject community request');
    $community->request('community-login');
    $rejected=$community->request('community-login',['action'=>'community-login','email'=>$request['email'],'password'=>$password]);
    expect_registry($rejected['status']===422 && str_contains($rejected['body'],'rejected'),'Rejected community cannot log in');
    expect_registry($admin->request('admin',['action'=>'community-decision','student_id'=>$cid,'decision'=>'approved'])['status']===422,'Already reviewed request cannot be replayed');
    $ok=true;
} catch(Throwable $ex) { echo 'FAIL '.$ex->getMessage()."\n"; }
finally {
    foreach(array_reverse($accounts) as $id) query('DELETE FROM students WHERE id=?',[$id]);
    query('DELETE FROM eligible_student_ids WHERE student_number LIKE ? OR student_number LIKE ? OR student_number LIKE ?',[$run.'%','0'.$run.'%','00'.$run.'%']);
    $keys=['register:127.0.0.1','login-ip:127.0.0.1','login-account:'.strtolower($run).'@example.test','login-account:'.strtolower($run).'-student@example.test','login-account:'.strtolower($run).'-community@example.test'];
    foreach($keys as $key) query('DELETE FROM rate_limits WHERE bucket=?',[hash_hmac('sha256',$key,$config['app_key'])]);
    foreach($files as $file) if(is_file($file)) unlink($file);
}
echo "$checks checks; ".($ok?'all passed.':'failure detected.')."\n";
exit($ok?0:1);
