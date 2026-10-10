<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
if(!is_file($root.'/config/local.php')){
    $config=require $root.'/config/example.php'; $config['app_key']=bin2hex(random_bytes(32));
    file_put_contents($root.'/config/local.php',"<?php\nreturn ".var_export($config,true).";\n");
}
require $root.'/app/bootstrap.php';
if(strlen($config['app_key']??'')<64 || str_starts_with($config['app_key'],'REPLACE')){
    $config['app_key']=bin2hex(random_bytes(32));
    file_put_contents($root.'/config/local.php',"<?php\nreturn ".var_export($config,true).";\n");
}
$options=getopt('', ['demo','admin-email:','admin-name:']);
try {
    if(!preg_match('/dbname=([a-zA-Z0-9_]+)/',$config['dsn'],$m))throw new RuntimeException('The DSN must contain a simple database name.');
    $serverDsn=preg_replace('/;?dbname=[a-zA-Z0-9_]+/','',$config['dsn']);
    $server=new PDO($serverDsn,$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE IF NOT EXISTS `'.$m[1].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $sql=file_get_contents($root.'/database/schema.sql');
    foreach(explode(';',$sql) as $statement)if(trim($statement)!=='')db()->exec($statement);
    if((int)scalar('SELECT COUNT(*) FROM students')>0){echo "Schema checked. Existing data preserved; no accounts or demo records added.\n";exit;}
    $password=bin2hex(random_bytes(10)).'!Cc';
    $hash=password_hash($password,PASSWORD_DEFAULT);
    $adminEmail=$options['admin-email']??'admin@campusconnect.test';
    if(!filter_var($adminEmail,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid admin email.');
    $create=function(string $name,string $email,string $role='student')use($hash):int{query('INSERT INTO students(name,email,password_hash,role) VALUES(?,?,?,?)',[$name,$email,$hash,$role]);return (int)db()->lastInsertId();};
    db()->beginTransaction();
    $adminId=$create($options['admin-name']??'Campus Administrator',$adminEmail,'admin');
    $accounts=[['Administrator',$adminEmail]];
    if(isset($options['demo'])){
        $orgs=[];
        foreach([['Alex Jordan','alex@campusconnect.test'],['Maya Lin','maya@campusconnect.test'],['Sam Kumar','sam@campusconnect.test']] as [$name,$email]){ $orgs[]=$create($name,$email,'organiser'); $accounts[]=['Organiser',$email]; }
        $students=[];
        foreach(['Jamie Lee','Taylor Brooks','Riley Chen','Morgan Patel','Casey Wilson','Avery Singh','Jordan Kim','Charlie Evans','Parker Ali','Harper Davis','Quinn Thomas','Sky Rivera'] as $i=>$name){$email=$i===0?'student@campusconnect.test':'student'.($i+1).'@campusconnect.test';$students[]=$create($name,$email);if($i===0)$accounts[]=['Student',$email];}
        $clubs=[
            ['The Creative Collective','Creative','Make things. Make friends. Make a little mess.','A welcoming space for curious minds and creative hands. We explore illustration, photography, crafts and everything in between. You do not need a portfolio or expensive tools — just bring your curiosity. We meet weekly for relaxed creative sessions and share what we are learning.','Design Studio, Building B','peach',0],
            ['Code & Coffee','Technology','Good code, better company. All skill levels welcome.','We are a community of people who enjoy building things with technology. From your first line of code to your next side project, there is room for you here. Join our friendly coding sessions, practical workshops and collaborative challenges. Bring a laptop if you have one; questions are always welcome.','Innovation Lab, Building C','mint',1],
            ['The Outdoor Club','Sport & wellbeing','A breath of fresh air. A whole new perspective.','Step away from the screen and explore the world around campus. We organise easy local walks, weekend adventures and relaxed outdoor catch-ups. Beginners are welcome and every activity includes clear information about the route and what to bring.','Student Union courtyard','lavender',2],
            ['Global Table','Culture','Different backgrounds. One shared table.','Food, stories and friendships across cultures. Global Table brings students together to learn about one another through conversation, cooking and informal cultural exchange. Everyone is welcome, whether you are new to campus or have been here for years.','Community Kitchen','yellow',0],
            ['The Study Circle','Academic','Big questions are better with good company.','A supportive community for focused study and shared learning. We run quiet study sessions, swap revision strategies and celebrate progress. Bring your current assignment or reading and work alongside students who understand the journey. We encourage original work and respectful collaboration.','Library Collaboration Room','blue',1],
            ['Campus Kindness','Community','Small acts. A campus-sized difference.','Make campus a little kinder through small, practical acts of service. We organise community projects, sustainability catch-ups and opportunities to help one another. Join a friendly group that values reliable participation, inclusivity and a willingness to learn.','Student Services Lounge','rose',2],
        ];
        $ids=[];
        foreach($clubs as [$name,$cat,$tag,$desc,$loc,$theme,$owner]){query('INSERT INTO clubs(name,category,tagline,description,location,theme,owner_id) VALUES(?,?,?,?,?,?,?)',[$name,$cat,$tag,$desc,$loc,$theme,$orgs[$owner]]);$ids[]=(int)db()->lastInsertId();}
        foreach($ids as $i=>$cid){foreach($students as $j=>$sid){if(($j+$i)%3!==0)query('INSERT INTO memberships(student_id,club_id) VALUES(?,?)',[$sid,$cid]);}}
        $eventData=[
            [0,'Make a little mess: art afternoon','An afternoon of low-pressure creativity, collage and good conversation. We provide paper, basic paints and a few prompts to get you started. Come alone or bring a friend; no experience needed. Wear something you do not mind getting a little colourful.',2,'Design Studio, Building B',30],
            [1,'Build, brew & brainstorm','Bring your laptop and a project idea, or come along to learn your first lines of code. We will pair up, share tips and build something small together. Tea and coffee are available in the common area; bring your reusable cup.',4,'Innovation Lab, Building C',40],
            [2,'Golden hour campus walk','A relaxed walk through campus and the nearby gardens. Meet at the student union courtyard with comfortable shoes and water. The route is mostly flat and takes about an hour. We will finish with time to catch up and enjoy the sunset.',6,'Student Union courtyard',25],
            [3,'Around the world in one table','Share a story, discover a new perspective and meet students from across the world. This is a conversation gathering with light refreshments, not a cooking class. Let the organiser know about access or dietary needs in advance.',8,'Community Kitchen',35],
            [4,'Study together, stress a little less','Bring your books, notes and a task you would like to finish. We will use short focused study blocks with breaks to stretch and share progress. This is a supportive space for independent study, not a place to exchange assessed answers.',9,'Library Collaboration Room',24],
            [5,'Little acts, big impact','Join us for a campus clean-up and a conversation about everyday sustainability. Gloves and collection bags are provided. Wear closed shoes, bring water and meet the group at the student services lounge.',11,'Student Services Lounge',40],
            [0,'Through your lens: photo walk','See familiar places differently with a relaxed photography walk. A phone camera is all you need. We will explore light, framing and respectful photography before sharing favourite shots with the group.',14,'Main Library steps',22],
            [1,'Your first website workshop','Build a small webpage with HTML and CSS in a friendly beginner session. Bring a laptop with a text editor installed. We will cover the basics together and leave plenty of time for questions.',16,'Innovation Lab, Building C',30],
            [2,'Fresh air Friday','Reset after a busy week with a gentle outdoor catch-up. Join us for a short walk and a relaxed conversation in the gardens. Bring a picnic blanket if you have one and dress for the weather.',18,'Campus Gardens',30],
        ];
        foreach($eventData as $i=>[$club,$title,$desc,$days,$loc,$cap]){
            $start=(new DateTimeImmutable('today +'.$days.' days'))->setTime(16,0)->setTimezone(new DateTimeZone('UTC'));$end=$start->modify('+2 hours');
            query('INSERT INTO events(club_id,title,description,starts_at,ends_at,location,capacity) VALUES(?,?,?,?,?,?,?)',[$ids[$club],$title,$desc,$start->format('Y-m-d H:i:s'),$end->format('Y-m-d H:i:s'),$loc,$cap]);$eid=(int)db()->lastInsertId();
            foreach(array_slice($students,0,3+($i%5)) as $sid)query('INSERT INTO rsvps(student_id,event_id) VALUES(?,?)',[$sid,$eid]);
        }
    }
    db()->commit();
    $text="CampusConnect LOCAL installation accounts\nGenerated: ".date('c')."\n\n";
    foreach($accounts as [$role,$email])$text.="$role: $email\n";
    $text.="\nInitial password for these generated accounts: $password\n\nChange passwords after first login. Never commit or publicly share this file.\nDemo records are fictional and are not verified campus organisations.\n";
    file_put_contents($root.'/var/demo-accounts.txt',$text);
    echo "Installation complete. Credentials are in var/demo-accounts.txt (private, ignored by Git).\n";
    echo isset($options['demo'])?"Loaded 6 fictional clubs, 9 future events and sample memberships/RSVPs.\n":"Created the administrator account. No demonstration records loaded.\n";
} catch(Throwable $ex){try{if(db()->inTransaction())db()->rollBack();}catch(Throwable){}fwrite(STDERR,'Installation failed: '.$ex->getMessage()."\n");exit(1);}
