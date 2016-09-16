<?php
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Sabre\Xml\Service;

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
            'DeviceID' => 'e755280409405004',
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
            'DeviceID' => 'e755280409405004',
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