<?php
// Use Apache (multiple workers), not PHP's single-worker development server.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$base=$argv[1]??'http://127.0.0.1:8081';
if(!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost'],true))exit("Local development targets only.\n");
$run='capacity'.bin2hex(random_bytes(5));$password=bin2hex(random_bytes(12));$ids=[];$handles=[];$results=[];$ok=false;
if(!is_dir(ROOT.'/tests/output'))mkdir(ROOT.'/tests/output',0700,true);
try {
    for($i=0;$i<5;$i++){
        query('INSERT INTO students(name,email,password_hash,role) VALUES(?,?,?,?)',[$run.' '.$i,$run.$i.'@example.test',password_hash($password,PASSWORD_DEFAULT),$i===0?'organiser':'student']);$ids[]=(int)db()->lastInsertId();
        $h=curl_init();curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_URL=>$base.'/index.php?page=login']);
        $body=curl_exec($h);preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$token);
        curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['action'=>'login','email'=>$run.$i.'@example.test','password'=>$password,'csrf'=>$token[1]??''])]);curl_exec($h);
        if(curl_getinfo($h,CURLINFO_RESPONSE_CODE)!==303)throw new RuntimeException('Could not authenticate concurrency fixture.');
        curl_setopt_array($h,[CURLOPT_HTTPGET=>true,CURLOPT_URL=>$base.'/index.php?page=dashboard']);$body=curl_exec($h);preg_match('/name="csrf" value="([a-f0-9]+)"/',$body,$token);$handles[]=[$h,$token[1]??''];
    }
    query('INSERT INTO clubs(name,category,tagline,description,location,theme,owner_id) VALUES(?,?,?,?,?,?,?)',[$run,'Technology','Capacity verification','Concurrent booking verification fixture.','Test venue','mint',$ids[0]]);$cid=(int)db()->lastInsertId();
    query('INSERT INTO events(club_id,title,description,starts_at,ends_at,location,capacity) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY),?,1)',[$cid,$run,'One spot; five concurrent students.','Test venue']);$eid=(int)db()->lastInsertId();
    $multi=curl_multi_init();
    foreach($handles as [$h,$token]){curl_setopt_array($h,[CURLOPT_URL=>$base.'/index.php?page=event&id='.$eid,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['action'=>'rsvp','event_id'=>$eid,'csrf'=>$token])]);curl_multi_add_handle($multi,$h);}
    do {$status=curl_multi_exec($multi,$active);if($active)curl_multi_select($multi,1.0);}while($active&&$status===CURLM_OK);
    foreach($handles as [$h]){$results[]=curl_getinfo($h,CURLINFO_RESPONSE_CODE);curl_multi_remove_handle($multi,$h);}
    curl_multi_close($multi);$count=(int)scalar('SELECT COUNT(*) FROM rsvps WHERE event_id=?',[$eid]);
    $ok=count(array_filter($results,fn($s)=>$s===303))===1&&count(array_filter($results,fn($s)=>$s===422))===4&&$count===1;
    echo ($ok?'PASS':'FAIL').': five simultaneous requests for one spot; statuses '.implode(', ',$results).'; stored RSVPs '.$count."\n";
}catch(Throwable $ex){echo 'FAIL: '.$ex->getMessage()."\n";}
finally {
    foreach($ids as $id)query('DELETE FROM students WHERE id=?',[$id]);
    foreach($handles as [$h])curl_close($h);
    foreach(array_merge(['login-ip:127.0.0.1'],array_map(fn($i)=>'login-account:'.$run.$i.'@example.test',range(0,4))) as $key)query('DELETE FROM rate_limits WHERE bucket=?',[hash_hmac('sha256',$key,$config['app_key'])]);
    file_put_contents(ROOT.'/tests/output/concurrency-results.json',json_encode(['date'=>date('c'),'target'=>$base,'passed'=>$ok,'statuses'=>$results],JSON_PRETTY_PRINT));
}
exit($ok?0:1);
