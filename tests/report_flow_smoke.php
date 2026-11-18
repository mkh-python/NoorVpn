<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'config.php';
require 'report_setup.php';
$textbotlang = require 'lang/fa.php';
$adminrulecheck = ['rule'=>'administrator'];
$from_id = '123456789'; $user = ['step'=>'home','lang'=>'fa','Processing_value'=>''];
$backadmin = '{}'; $setting_panel = '{}'; $sent = []; $calls = []; $blocked = false; $forum = false; $nextTopic = 100;
$pdo->exec('UPDATE setting SET Channel_Report=NULL,report_channel_link=NULL');
$setting = $pdo->query('SELECT * FROM setting LIMIT 1')->fetch();
function savedata($type,$field,$value) { global $user; $user['Processing_value'] = json_encode([$field=>$value]); }
function step($step,$id) { global $user; $user['step']=$step; }
function sendmessage($id,$text,$keyboard,$mode) { global $sent; $sent[]=$text; }
function clearSelectCache($table) {}
function telegram($method,$data) {
    global $calls,$blocked,$forum,$nextTopic;
    $calls[]=$method;
    if ($method==='getChat') { return $blocked ? ['ok'=>false] : ['ok'=>true,'result'=>['id'=>-1001234567890,'type'=>($forum?'supergroup':'channel'),'is_forum'=>$forum]]; }
    if ($method==='getChatMember') { return ['ok'=>true,'result'=>['status'=>'administrator','can_post_messages'=>true,'can_manage_topics'=>true]]; }
    if ($method==='createForumTopic') { return ['ok'=>true,'result'=>['message_thread_id'=>++$nextTopic]]; }
    if ($method==='deleteForumTopic') { return ['ok'=>true]; }
    throw new RuntimeException('Unexpected API mutation: '.$method);
}
$source = file_get_contents('admin.php');
$start = strpos($source, '} elseif ($text == $textbotlang[\'keyboard\'][\'botReports\']');
$end = strpos($source, '} elseif ($text == $textbotlang[\'keyboard\'][\'shopSettings\']', $start);
if ($start===false || $end===false) { throw new RuntimeException('Report router not found'); }
$router = 'if (false) { '.substr($source,$start,$end-$start).'}';
function dispatchReport($input) {
    global $router,$textbotlang,$adminrulecheck,$from_id,$user,$backadmin,$setting_panel,$setting,$pdo,$APIKEY,$usernamebot;
    $text=$input;
    eval($router);
}
function expectStep($expected) { global $user; if ($user['step']!==$expected) { throw new RuntimeException('Wrong conversation step'); } }
dispatchReport($textbotlang['keyboard']['botReports']); expectStep('report_channel_link');
dispatchReport('not a link'); expectStep('report_channel_link');
dispatchReport('https://t.me/+private_fixture'); expectStep('report_channel_id');
dispatchReport('invalid-id'); expectStep('report_channel_id');
$blocked=true; dispatchReport('-1001234567890'); expectStep('report_channel_id');
if ($pdo->query('SELECT Channel_Report FROM setting')->fetchColumn()!==null) { throw new RuntimeException('Failed destination was persisted'); }
$blocked=false; dispatchReport('-1001234567890'); expectStep('home');
if ($pdo->query('SELECT Channel_Report FROM setting')->fetchColumn()!=='-1001234567890') { throw new RuntimeException('Channel not saved'); }
if (in_array('sendMessage',$calls,true) || in_array('createForumTopic',$calls,true)) { throw new RuntimeException('Channel setup attempted forum/test-message mutation'); }
$pdo->exec("UPDATE setting SET Channel_Report='-1001111111111'");
$pdo->exec("ALTER TABLE setting ADD CONSTRAINT noorvpn_flow_failure CHECK (Channel_Report <> '-1001234567890')");
try {
    $forum=true;
    dispatchReport($textbotlang['keyboard']['botReports']);
    dispatchReport('https://t.me/+private_fixture');
    dispatchReport('-1001234567890');
    expectStep('report_channel_id');
    if (count(array_filter($calls,fn($m)=>$m==='deleteForumTopic'))!==10) { throw new RuntimeException('New forum topics were not removed after save failure'); }
    if ($pdo->query('SELECT Channel_Report FROM setting')->fetchColumn()!=='-1001111111111') { throw new RuntimeException('Old report destination changed after failure'); }
} finally { $pdo->exec('ALTER TABLE setting DROP CHECK noorvpn_flow_failure'); }
$user['step']='addchannelid'; $user['Processing_value']='';
dispatchReport('-1001234567890'); expectStep('report_channel_link');
echo "Actual report router: link-first, numeric-ID second, retries, private channel and legacy-step recovery OK\n";
