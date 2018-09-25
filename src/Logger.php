<?php
//+-------------------------------------------------------------
//| 
//+-------------------------------------------------------------
//| Author Liu LianSen <liansen@d3zz.com> 
//+-------------------------------------------------------------
//| Date 2018-09-25
//+-------------------------------------------------------------
namespace mgologer;

class Logger
{
    protected static $instance = null;

    /**
     * 获取日志实例
     * @return Logger
     */
    public static function newInstance()
    {
        if(is_null(static::$instance)){
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * 连接配置
     * @var array
     */
    protected $conf = [
        'host'  => '127.0.0.1',
        'port'  => 8707,
        'db'    => '',
        'user'  => '',
        'password' => '',
        'timeout' => 1
    ];

    /**
     * Logger constructor.
     */
    public function __construct()
    {
        $this->conf = include __DIR__. '/config.php';
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        if(!empty($this->logs)) {
            $this->flush();
        }
        socket_write($this->socket, pack("L",-1)); //发送一个特殊的结束标记包
        $this->closeConnection();
    }

    protected $socket = null;

    /**
     * 抛出错误异常
     * @throws \Exception
     */
    protected function socketException()
    {
        $errCode = socket_last_error();
        $errMsg  = socket_strerror($errCode);
        throw new SocketException("Can not create socket: [{$errCode}] {$errMsg}",$errCode);
    }

    /**
     * 创建tcp连接r
     * @param bool $force
     * @return resource
     */
    protected function connect($force = false)
    {
        if($force || is_null($this->socket)) {
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if (!$socket) {
                $this->socketException();
            }
            $conf = $this->conf;
            if (!socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO,
                ['sec' => ceil($conf['time_out']), 'usec' => $conf['time_out'] * 1000000])) {
                $this->socketException();
            }

            if (!socket_connect($socket, $conf['host'], $conf['port'])) {
                $this->socketException();
            }
            $this->socket = $socket;
        }
        return $this->socket;
    }

    public function closeConnection()
    {
        @socket_close($this->socket);
        $this->socket = null;
    }

    /**
     *
     * 立即写入日志
     * @param $level
     * @param $msg
     * @param int $callLevel
     * @return bool
     */
    public function write($level,$msg,$callLevel = 0)
    {
        $raw = [
            'app'      => $this->conf['db'],
            'level'    => $level,
            'user'     => $this->conf['user'],
            'password'     => $this->conf['password'],
            'time' => date('Y-m-d H:i:s'),
        ];
        if($msg instanceof \Exception || $msg instanceof \Error){
            $raw = array_merge($raw,[
                'log'  => [
                    'file'    => $msg->getFile(),
                    'line'    => strval($msg->getLine()),
                    'message' => $msg->getMessage(),
                    'stack'   => $msg->getTraceAsString()
                ]
            ]);
        }elseif(is_object($msg) || is_array($msg)){
            $raw['log'] = [];
            foreach ($msg as $k => $v){
                if(is_array($v) || is_object($v)){
                    $v = json_encode($v,JSON_UNESCAPED_UNICODE);
                }else{
                    $v = strval($v);
                }
                $raw['log'][$k] = $v;
            }
        }else{
            $raw['log'] = $msg;
        }
        $sendData = pack("a*", json_encode($raw,JSON_UNESCAPED_UNICODE));
        $len  = pack('L', strlen($sendData));
        $package = $len . $sendData;
        $this->connect();
        //如果发送报文失败，尝试强制重连一遍
        if(!socket_write($this->socket, $package,strlen($package))){
            if($callLevel === 0){
                $this->connect(true);
                return $this->write($level,$msg,$callLevel++);
            }else{
                return false;
            }
        }
        $h = unpack('Llen',socket_read($this->socket,4));
        if(!isset($h['len']) || !$h['len']){
            return false;
        }
        $resp = socket_read($this->socket,$h['len']);

        $ret  = json_decode($resp,true);
        if(!$ret ){
            return false;
        }
        return $ret['success'];
    }

    protected $logs = [];

    /**
     * 将消息放入队列，稍后调用 flush方法，一次写入
     * @param $level
     * @param $msg
     * @return bool
     */
    public function push($level,$msg)
    {
        $this->logs[] = [
            'level' => $level,
            'msg'   => $msg
        ];
        return true;
    }


    /**
     * 将队列中的所有日志内容推送到日志服务器
     */
    public function flush()
    {
        foreach ($this->logs as $log){
            $this->write($log['level'],$log['msg']);
        }
        $this->logs = [];
    }


    /**
     * <pre>
     * 载入本地配置(如果配置文件同目录下具有同名的.local.php后缀的本地文件)
     * e.g.
     * App/Conf/db.php （公共配置，可以认为是一个模板）
     *  ~~~~
     *      $conf = array(
     *              'DB_TYPE' => 'mysql',
     *              'DB_HOST' => '112.124.33.139',
     *              //...
     *              );
     *  ~~~~
     *
     * APP/Conf/db.local.php （某个环境本地所使用的配置，可能是你的开发环境，也可能是测试环境、生产环境）
     *  ~~~~
     *      return array(
     *              'DB_HOST' => '127.0.0.1',
     *              );
     *  ~~~~
     *
     * 那么项目运行时 'DB_HOST' 的值 为 db.local.php中的 '127.0.0.1'
     * @param array $conf
     * @return array
     */
    function loadLocalConf(array &$conf)
    {
        if(!function_exists("debug_backtrace")){
            return $conf;
        }
        $trace = @debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        if (!$trace || !isset($trace[0]['file'])) return $conf;
        $localFile = substr($trace[0]['file'], 0, -4) . '.local.php';
        if (is_file($localFile) && is_readable($localFile)) {
            $_conf = include $localFile;
            is_array($_conf) && $conf = array_merge($conf, $_conf);
        }
        return $conf;
    }


}

