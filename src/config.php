<?php
/**
 * 连接配置
 */
$conf = [
    'host'  => '127.0.0.1',
    'port'  => 8707,
    'db'    => '',
    'user'  => '',
    'password' => '',
    'connect_sec' => 1,
    'send_sec' => 3,
    'recv_sec' => 3,
];

return loadLocalConf($conf);