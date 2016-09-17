<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Sabre\Xml\Service;

$redisClient = new Predis\Client([
    'scheme' => 'tcp',
    'host'   => '127.0.0.1',
    'port'   => 6379,
]);

$cookie = new CookieJar();

//获取二维码code
$qrUrl = 'https://login.wx.qq.com/jslogin?appid=wx782c26e4c19acffb';
$request = new Client();
$qrRes = $request->request('GET', $qrUrl, [
    'cookies' => $cookie,
])->getBody()->getContents();
$qrRegex = "/window.QRLogin.code = 200; window.QRLogin.uuid = \"(?<qrcode>.*?)\"/";
preg_match($qrRegex, $qrRes, $qrCode);
$qrCode = $qrCode['qrcode'];

//获取二维码
$qrUrl = 'https://login.weixin.qq.com/qrcode/'.$qrCode;
//echo $qr;
$qr = imagecreatefromjpeg($qrUrl);
imagejpeg($qr, 'qr.jpg');

exec('open qr.jpg');

//登录获得ticket
$loginUrl = 'https://login.wx.qq.com/cgi-bin/mmwebwx-bin/login?loginicon=true&uuid='.$qrCode;
do {
    $loginRes = $request->request('GET', $loginUrl, [
        'cookies' => $cookie,
    ])->getBody()->getContents();
    $loginRegx = "/window.code=(?<code>[0-9]+);/";
    preg_match($loginRegx, $loginRes, $loginCode);
    $loginCode = $loginCode['code'];
} while ('200' != $loginCode);
$loginRegex = "/ticket=(?<ticket>.*?)&uuid.*scan=(?<loginScan>[0-9]+)/";
preg_match($loginRegex, $loginRes, $loginTicket);
$loginScan = $loginTicket['loginScan'];
$loginTicket = $loginTicket['ticket'];

//得到skey、sid、uin、pass_ticket
$loginInfoUrl = 'https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxnewloginpage';
$loginInfo = $request->request('GET', $loginInfoUrl, [
    'query' => [
        'ticket' => $loginTicket,
        'uuid' => $qrCode,
        'lang' => 'zh-CN',
        'scan' => $loginScan,
        'fun' => 'new',
        'version' => 'v2',
    ],
    'cookies' => $cookie,
])->getBody()->getContents();

$xmlService = new Service();
$loginInfo = $xmlService->parse($loginInfo);
$skey = $loginInfo[2]['value'];
$sid = $loginInfo[3]['value'];
$uin = $loginInfo[4]['value'];
$passTicket = $loginInfo[5]['value'];

//前十个消息info
$initUrl = 'https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxinit';
$init = $request->request('POST', $initUrl, [
    'query' => [
        'r' => '-856007561',
        'lang' => 'zh_CN',
        'pass_ticket' => $passTicket,
    ],
    'body' => json_encode([
        'BaseRequest' => [
            'Uin' => $uin,
            'Sid' => $sid,
            'Skey' => $skey,
            'DeviceID' => 'e755280409405005',
        ],
    ]),
    'headers' => [
        'Origin' => 'https://wx.qq.com',
        'Referer' => 'https://wx.qq.com/?&lang=zh_CN',
        'Host' => 'wx.qq.com',
        'Content-Type' => 'application/json;charset=UTF-8',
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/53.0.2785.101 Safari/537.36',
    ],
    'cookies' => $cookie,
])->getBody()->getContents();
$init = json_decode($init);
$syncKey = $init->SyncKey;
$userName = $init->User->UserName;

//第一次请求消息
$firstMsgUrl = 'https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxsync';
$firstMsg = $request->request('POST', $firstMsgUrl, [
    'query' => [
        'sid' => $sid,
        'skey' => $skey,
        'lang' => 'zh_CN',
        'pass_ticket' => $passTicket,
    ],
    'body' => json_encode([
        'BaseRequest' => [
            'Uin' => $uin,
            'Sid' => $sid,
            'Skey' => $skey,
            'DeviceID' => 'e755280409405005',
        ],
        'SyncKey' => $syncKey,
        'rr' => '-863360261',
    ]),
    'headers' => [
        'Origin' => 'https://wx.qq.com',
        'Referer' => 'https://wx.qq.com/?&lang=zh_CN',
        'Host' => 'wx.qq.com',
        'Content-Type' => 'application/json;charset=UTF-8',
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/53.0.2785.101 Safari/537.36',
    ],
    'cookies' => $cookie,
])->getBody()->getContents();
$syncKey = json_decode($firstMsg)->SyncKey;
$syncKeyJs = '';
foreach ($syncKey->List as $value) {
    $syncKeyJs .= $value->Key.'_'.$value->Val.'|';
}
$syncKeyJs = substr($syncKeyJs, 0, -1);

//查询是否有消息
while (true) {
    do {
        $syncCheckUrl = 'https://webpush.wx.qq.com/cgi-bin/mmwebwx-bin/synccheck';
        $syncCheck = $request->request('GET', $syncCheckUrl, [
            'query' => [
                'r' => '1474037221939',
                'skey' => $skey,
                'sid' => $sid,
                'uin' => $uin,
                'deviceid' => 'e755280409405005',
                'synckey' => $syncKeyJs,
                '_' => '1474037129389',
            ],
            'headers' => [
                'Referer' => 'https://wx.qq.com/?&lang=zh_CN',
                'Host' => 'webpush.wx.qq.com',
                'Connection' => 'keep-alive',
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/53.0.2785.101 Safari/537.36',
            ],
            'cookies' => $cookie,
        ])->getBody()->getContents();
        $syncCheckRegex = "/window.synccheck={retcode:\"(?<retcode>[0-9]+)\",selector:\"(?<selector>[0-9]+)\"}/";
        preg_match($syncCheckRegex, $syncCheck, $syncCheckRes);
        $retcode = $syncCheckRes['retcode'];
        $selector = $syncCheckRes['selector'];
//        echo $selector;
    } while ('2' != $selector);
    $messageUrl = 'https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxsync';
    $message = $request->request('POST', $messageUrl, [
        'query' => [
            'sid' => $sid,
            'skey' => $skey,
            'lang' => 'zh_CN',
            'pass_ticket' => $passTicket,
        ],
        'body' => json_encode([
            'BaseRequest' => [
                'Uin' => $uin,
                'Sid' => $sid,
                'Skey' => $skey,
                'DeviceID' => 'e755280409405005',
            ],
            'SyncKey' => $syncKey,
            'rr' => '-863360261',
        ]),
        'headers' => [
            'Origin' => 'https://wx.qq.com',
            'Referer' => 'https://wx.qq.com/?&lang=zh_CN',
            'Host' => 'wx.qq.com',
            'Content-Type' => 'application/json;charset=UTF-8',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/53.0.2785.101 Safari/537.36',
        ],
        'cookies' => $cookie,
    ])->getBody()->getContents();
    $message = json_decode($message);
    foreach ($message->AddMsgList as $value) {
        echo $value->Content."\n".$value->FromUserName."\n".$value->MsgId."\n".$value->MsgType;
        if ('1' == $value->MsgType) {
//            $userInfoUrl = 'https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxbatchgetcontact';
//            $userInfo = $request->request('POST', $userInfoUrl, [
//                'query' => [
//                    'type' => 'ex',
//                    'r' => 1474086349602,
//                    'lang' => 'zh_CN',
//                    'pass_ticket' => $passTicket,
//                ],
//                'body' => json_encode([
//                    'BaseRequest' => [
//                        'Uin' => $uin,
//                        'Sid' => $sid,
//                        'Skey' => $skey,
//                        'DeviceID' => 'e755280409405004',
//                    ],
//                    'Count' => 1,
//                    'List' => [
//                        [
//                            'UserName' => $value->FromUserName,
//                            'EncryChatRoomId' => '',
//                        ],
//                    ],
//                ]),
//                'headers' => [
//                    'Origin' => 'https://wx.qq.com',
//                    'Referer' => 'https://wx.qq.com/?&lang=zh_CN',
//                    'Host' => 'wx.qq.com',
//                    'Content-Type' => 'application/json;charset=UTF-8',
//                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/53.0.2785.101 Safari/537.36',
//                ],
//                'cookies' => $cookie,
//            ])->getBody()->getContents();
//            $userInfo = json_decode($userInfo);
//            if (!$userInfo->BaseResponse->Ret) {
//                foreach ($userInfo->ContactList as $userValue) {
//                    if (!$userValue->MemberCount) {
//                        $redisClient->set($userValue->UserName, $userValue->NickName);
//                    } else {
//                        foreach ($userValue->MemberList as $grpUserValue) {
//                            echo $grpUserValue->UserName;
//                            $redisClient->set($grpUserValue->UserName, $grpUserValue->NickName);
//                        }
//                    }
//                }
//            }
            $msgReplaceRegx = "/@[0-9a-z]+:<br\/>/";
            $msgReplace = preg_replace($msgReplaceRegx, '', $value->Content);
            $redisClient->lpush($value->MsgId, $msgReplace);
            $redisClient->expire($value->MsgId, 180);
        } elseif ('10002' == $value->MsgType) {
            $oldMsgIdRegex = "/&lt;msgid&gt;(?<oldMsgId>[0-9]+?)&lt/";
            preg_match($oldMsgIdRegex, $value->Content, $oldMsgId);
            $oldMsgId = $oldMsgId['oldMsgId'];
            $oldUserRegex = "/!\[CDATA\[\"(?<oldUser>.*?)\"/";
            preg_match($oldUserRegex, $value->Content, $oldUser);
            $oldUser = $oldUser['oldUser'];
            $oldMsg = $redisClient->rpop($oldMsgId);
            $msg = $oldUser.'撤回了一条消息，消息内容为：'.$oldMsg;
            echo $msg;
            $microtime = explode(' ', microtime());
            $time = $microtime[1].substr($microtime[0], 2, 7);
            echo $time;
            $sendUrl = 'https://wx.qq.com/cgi-bin/mmwebwx-bin/webwxsendmsg';
            echo json_encode([
                'BaseRequest' => [
                    'Uin' => $uin,
                    'Sid' => $sid,
                    'Skey' => $skey,
                    'DeviceID' => 'e755280409405005',
                ],
                'Msg' => [
                    'ClientMsgId' => $time,
                    'Content' => $msg,
                    'FromUsetName' => $userName,
                    'LocalID' => $time,
                    'ToUserName' => $value->FromUserName,
                    'Type' => 1,
                ],
                'Scene' => 0,
            ], JSON_UNESCAPED_UNICODE);
            $send = $request->request('POST', $sendUrl, [
                'query' => [
                    'lang' => 'zh_CN',
                    'pass_ticket' => $passTicket,
                ],
                'body' => json_encode([
                    'BaseRequest' => [
                        'Uin' => $uin,
                        'Sid' => $sid,
                        'Skey' => $skey,
                        'DeviceID' => 'e755280409405005',
                    ],
                    'Msg' => [
                        'ClientMsgId' => $time,
                        'Content' => $msg,
                        'FromUserName' => $userName,
                        'LocalID' => $time,
                        'ToUserName' => $value->FromUserName,
                        'Type' => 1,
                    ],
                    'Scene' => 0,
                ], JSON_UNESCAPED_UNICODE),
                'headers' => [
                    'Origin' => 'https://wx.qq.com',
                    'Referer' => 'https://wx.qq.com/?&lang=zh_CN',
                    'Host' => 'wx.qq.com',
                    'Content-Type' => 'application/json;charset=UTF-8',
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/53.0.2785.101 Safari/537.36',
                ],
                'cookies' => $cookie,
            ])->getBody()->getContents();
            echo $send;
        }
    }
    $syncKey = $message->SyncKey;
}


//{"BaseRequest":{"Uin":"2737148611","Sid":"NisbEeVUktUMhoSw","Skey":"@crypt_ba27dc80_d5f0d76d54a789deca06c9f2283f3d98","DeviceID":"e755280409405004"},"Count":1,"List":[{"UserName":"@91cd385db2028c7dda5e34e7b6357204","ChatRoomId":""}]}
//{"BaseRequest":{"Uin":"2737148611","Sid":"Fl8wsZ9thxFXku/r","Skey":"@crypt_ba27dc80_c4a11b741bea3c3dace6885b344434c9","DeviceID":"e491227323447136"},"Count":5,"List":[{"UserName":"@b37b1c29b52bbd75fa221e4f3e2c1ad9","EncryChatRoomId":""},{"UserName":"@2de075665d9d99e35cfaf72009c884d1","EncryChatRoomId":""},{"UserName":"@89b7788be14eba8e6bb1be3999a980da","EncryChatRoomId":""},{"UserName":"@8448e848d1cd02233df0e328b6578aec","EncryChatRoomId":""}]}



//
//{"BaseRequest":{"Uin":"1597063860","Sid":"lil4SHR28Aka6O7A","Skey":"@crypt_ba63df52_8382618d0e89f318eaa96874d59167c9","DeviceID":"e755280409405005"},"Msg":{"ClientMsgId":"14740965978502350","Content":"","FromUsetName":"@661e93d933ea8bd5f2dd51eeba5f2fa1","LocalId":"14740965978502350","ToUserName":"@1628abc316e9ec6252280ee2c2179cd0f405102c81fbfd27e67c08bc28f1e906","Type":1},"Scene":0}
//{"BaseRequest":{"Uin":"1597063860","Sid":"6jI5eLqre8MLsZxV","Skey":"@crypt_ba63df52_690787da1321fa983404ef93b22f5ef2","DeviceID":"e990682328512769"},"Msg":{"Content":"","FromUsetName":"@2976d80d00476d0dcde885b32b99c99f","ToUserName":"@ae017fa97ff5d21eb7a0fa0e639bafd1aabeb56b9631c647996ac1a4d5565ea7","LocalID":"14740974326760949","ClientMsgId":"14740974326760949","Type":1},"Scene":0}