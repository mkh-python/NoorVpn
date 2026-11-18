import json
from pathlib import Path
import subprocess
import unittest
ROOT=Path(__file__).resolve().parents[1]
class ReportSetupTests(unittest.TestCase):
    def php(self,code):
        p=subprocess.run(['php','-r','require "report_setup.php";'+code],cwd=ROOT,capture_output=True,text=True)
        self.assertEqual(p.returncode,0,p.stderr);return json.loads(p.stdout)
    def test_public_private_link_validation(self):
        r=self.php('echo json_encode([noorVpnReportLink("https://t.me/example_channel"),noorVpnReportLink("https://t.me/+AbCd_123"),noorVpnReportLink("https://t.me/joinchat/AbCd_123"),noorVpnReportLink("https://evil.example/channel"),noorVpnReportLink("https://t.me/c/123/45")]);')
        self.assertEqual(r[0]['username'],'@example_channel');self.assertEqual(r[1]['kind'],'private');self.assertEqual(r[2]['kind'],'private');self.assertIsNone(r[3]);self.assertIsNone(r[4])
    def fixture(self,link='https://t.me/+AbCd_123',chat_type='channel',status='administrator',post=True,forum=False,username='example_channel'):
        code='''$calls=[];$api=function($method,$data) use (&$calls){$calls[]=$method;
        if($method==="getChat" && $data["chat_id"]==="@another_channel")return ["ok"=>true,"result"=>["id"=>-1009999999999]];
        if($method==="getChat")return ["ok"=>true,"result"=>["id"=>-1001234567890,"type"=>TYPE,"username"=>USERNAME,"is_forum"=>FORUM]];
        if($method==="getChatMember")return ["ok"=>true,"result"=>["status"=>STATUS,"can_post_messages"=>POST,"can_manage_topics"=>true]];
        if($method==="createForumTopic")return ["ok"=>true,"result"=>["message_thread_id"=>count($calls)+10]];
        return ["ok"=>true];};$r=noorVpnPrepareReportDestination(LINK,"-1001234567890","8550185144",$api,["buyreport"=>"Buy","backupfile"=>"Backup"]);echo json_encode(["result"=>$r,"calls"=>$calls]);'''
        for k,v in {'TYPE':chat_type,'USERNAME':username,'FORUM':forum,'STATUS':status,'POST':post,'LINK':link}.items():code=code.replace(k,json.dumps(v))
        return self.php(code)
    def test_private_channel_does_not_need_forum_or_create_topics(self):
        r=self.fixture();self.assertTrue(r['result']['ok']);self.assertEqual(r['result']['topics'],{'buyreport':0,'backupfile':0});self.assertNotIn('createForumTopic',r['calls']);self.assertNotIn('sendMessage',r['calls'])
    def test_normal_group_supported(self):self.assertTrue(self.fixture(chat_type='supergroup')['result']['ok'])
    def test_missing_admin_or_post_right_rejected(self):
        self.assertEqual(self.fixture(status='member')['result']['error'],'not_admin')
        self.assertEqual(self.fixture(post=False)['result']['error'],'cannot_post')
    def test_forum_preserves_topic_support(self):
        r=self.fixture(chat_type='supergroup',forum=True);self.assertTrue(r['result']['ok']);self.assertEqual(r['calls'].count('createForumTopic'),2)
    def test_public_link_must_match_numeric_destination(self):self.assertEqual(self.fixture(link='https://t.me/another_channel')['result']['error'],'link_mismatch')
    def test_private_chat_not_found_does_not_try_to_join(self):
        r=self.php('$calls=[];$api=function($method,$args) use (&$calls){$calls[]=$method;return ["ok"=>false,"description"=>"Bad Request: chat not found"];};$r=noorVpnPrepareReportDestination("https://t.me/+AbCd_123","-1001234567890","8550185144",$api,["buyreport"=>"Buy"]);echo json_encode([$r,$calls]);')
        self.assertEqual(r[0]['error'],'chat_not_found');self.assertEqual(r[1],['getChat'])
    def test_invalid_numeric_id_is_rejected_before_api(self):
        r=self.php('$r=noorVpnPrepareReportDestination("https://t.me/example_channel","username","1",function(){throw new Exception("must not call API");},[]);echo json_encode($r);')
        self.assertEqual(r['error'],'invalid_id')
    def test_partial_forum_failure_cleans_up_only_new_topics(self):
        r=self.php('$calls=[];$n=0;$api=function($m,$d)use(&$calls,&$n){$calls[]=[$m,$d];if($m==="getChat")return ["ok"=>true,"result"=>["id"=>-1001234567890,"type"=>"supergroup","is_forum"=>true]];if($m==="getChatMember")return ["ok"=>true,"result"=>["status"=>"administrator","can_manage_topics"=>true]];if($m==="createForumTopic")return ++$n===1?["ok"=>true,"result"=>["message_thread_id"=>99]]:["ok"=>false];return ["ok"=>true];};$r=noorVpnPrepareReportDestination("https://t.me/+AbCd_123","-1001234567890","1",$api,["old"=>"Old","new"=>"New","fail"=>"Fail"],["old"=>7]);echo json_encode([$r,$calls]);')
        self.assertEqual(r[0]['error'],'topic_failed')
        deleted=[v[1]['message_thread_id'] for v in r[1] if v[0]=='deleteForumTopic']
        self.assertEqual(deleted,[99]);self.assertNotIn(7,deleted)
    def test_private_user_chat_is_rejected(self):self.assertEqual(self.fixture(chat_type='private')['result']['error'],'invalid_type')
    def test_forum_cleanup_never_deletes_reused_topics(self):
        r=self.php('$calls=[];noorVpnDiscardReportTopics(["chat_id"=>"-1001234567890","topics"=>["old"=>7,"new"=>99],"created_topics"=>[99]],function($m,$d)use(&$calls){$calls[]=[$m,$d];return ["ok"=>true];});echo json_encode($calls);')
        self.assertEqual(r,[['deleteForumTopic',{'chat_id':'-1001234567890','message_thread_id':99}]])
if __name__=='__main__':unittest.main()
