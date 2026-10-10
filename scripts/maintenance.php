<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
query('DELETE FROM rate_limits WHERE expires_at<UTC_TIMESTAMP()');
query('DELETE FROM audit_log WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)');
$removed=0;
foreach(glob(ROOT.'/var/sessions/sess_*')?:[] as $path)if(is_file($path)&&filemtime($path)<time()-86400){unlink($path);$removed++;}
echo "Expired rate limits, old audit entries and $removed expired session files removed.\n";
