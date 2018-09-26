<?php
//+-------------------------------------------------------------
//| 
//+-------------------------------------------------------------
//| Author Liu LianSen <liansen@d3zz.com> 
//+-------------------------------------------------------------
//| Date 2018-09-25
//+-------------------------------------------------------------
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/src/SocketException.php';
require dirname(__DIR__).'/src/Logger.php';

$logger = \mgologer\Logger::newInstance(__DIR__);
$start = microtime(1);
$logger->push('error','测试error1');
$logger->push('warn','测试warn1');
$logger->push('error',new Exception("测试错误2"));
$logger->push('log',['name' => 'aaa','age' => '18']);
$logger->push('vc_phone',['phone' => '13530431800','msg'=> '【测试】您本次验证为：123456']);
$logger->flush();
var_dump(microtime(1) - $start);