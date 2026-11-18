<?php
chdir(dirname(__DIR__));
$textbotlang = require 'lang/fa.php';
$from_id=1; $backadmin='retry-keyboard'; $optionX_ui_single='panel-keyboard';
$user=['step'=>'setinboundandprotocol','Processing_value'=>'fixture'];
$panel=['type'=>'x-ui_single','name_panel'=>'fixture']; $sent=[]; $writes=[]; $calls=[];
$response=['status'=>200,'body'=>json_encode(['success'=>false,'msg'=>'Obtain: record not found','obj'=>null])];
function select(...$args){global $panel;return $panel;}
function get_clinets($text,$panel){global $response,$calls;$calls[]=$text;return $response;}
function sendmessage($id,$text,$keyboard,$mode){global $sent;$sent[]=[$text,$keyboard];}
function step($value,$id){global $user;$user['step']=$value;}
function update(...$args){global $writes;$writes[]=$args;}
$source=file_get_contents('admin.php');
$start=strpos($source,'} elseif ($user[\'step\'] == "setinboundandprotocol") {');
$end=strpos($source,'} elseif ($text == $textbotlang[\'keyboard\'][\'renewalStatus\']',$start);
$router='if(false){'.substr($source,$start,$end-$start).'}';
function runInput($input){global $text,$router,$user,$panel,$from_id,$textbotlang,$backadmin,$optionX_ui_single;$text=$input;eval($router);}
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
runInput('missing-client');
check(count($sent)===1,'Failure should send one actionable message, not raw panel output');
check($sent[0][1]===$backadmin,'Failure keyboard must match pending input state');
check($user['step']==='setinboundandprotocol' && !$writes,'Missing client must allow retry without changes');
$sent=[]; $response=['status'=>200,'body'=>'not-json']; runInput('fixture');
check(count($sent)===1 && !$writes,'Malformed API response must not save');
$sent=[]; $response=['status'=>200,'body'=>json_encode(['success'=>true,'obj'=>['inboundIds'=>[]]])];runInput('fixture');
check(!$writes && $user['step']==='setinboundandprotocol','Empty inbound list must not save');
$response=['status'=>200,'body'=>json_encode(['success'=>true,'obj'=>['inboundIds'=>[2,5]]])];runInput('fixture');
check($user['step']==='home' && $writes[0][2]==='[2,5]','Retry with valid client must save inbound IDs and exit');
echo "Protocol wizard: missing client, invalid JSON, empty inbounds, successful retry OK\n";
