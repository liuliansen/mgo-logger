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


    protected $logPath = '';

    /**
     * @var \utils\Logger
     */
    protected $logger = null;

    protected $fp = null;

    protected $logs = [];

    /**
     * 获取日志实例
     * @param string $logPath
     * @return Logger
     */
    public static function newInstance($logPath = '')
    {
        if(is_null(static::$instance)){
            static::$instance = new static($logPath);
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
     * @param $logPath
     */
    public function __construct($logPath)
    {
        $this->conf = include __DIR__. '/config.php';
        $this->logPath = $logPath;
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        if(!empty($this->logs)) {
            $this->flush();
        }
        $package = pack("L",-1);
        @fwrite($this->fp, $package,strlen($package)); //发送一个特殊的结束标记包
        $this->closeConnection();
    }


    /**
     * 记录日志
     * @param $level
     * @param $log
     */
    public function log($level,$log)
    {
        if(!$this->logPath) return;
        if(is_null($this->logger)){
            $this->logger = new \utils\Logger(['path' => $this->logPath]);
        }
        call_user_func_array([$this->logger,$level],[$log]);
    }

    /**
     * 连接服务器
     * @param bool $force
     * @return resource|false
     */
    protected function connect($force = false)
    {
        if($force || is_null($this->fp)) {
            $conf = $this->conf;
            $fp = @pfsockopen($conf['host'],$conf['port'],$errno,$errstr,$conf['connect_sec']);
            if (!$fp) {
                $this->log('error',"Could not connect to {$conf['host']}:{$conf['port']}.([{$errno}]{$errstr})");
                return false;
            }
            $this->fp = $fp;
        }
        return $this->fp;
    }

    public function closeConnection()
    {
        @fclose($this->fp);
        $this->fp = null;
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
        $fp = $this->connect();
        if(!$fp) return false;
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
        $log = json_encode($raw,JSON_UNESCAPED_UNICODE);

        if(!$this->streamWrite($log) && $callLevel === 0){
            $callLevel++;
            $this->connect(true);
            return $this->streamWrite($raw);
        }
        return $this->streamRead($log);
    }

    /**
     * 读取结果
     * @param $log
     * @return bool
     */
    protected function streamRead(&$log)
    {
        $null = null;
        $read = array($this->fp);
        $readable = @stream_select($read, $null, $null,$this->conf['recv_sec']);
        if (!$readable) {
            $this->log('alert',"响应结果读取失败\t{$log}");
            return false;
        }
        $head = @fread($this->fp,4);
        if(!$head){
            $this->log('alert',"响应结果读取失败\t{$log}");
            return false;
        }
        $head = unpack('Llen',$head);
        if(!isset($head['len']) || !$head['len']){
            $this->log('alert',"响应结果头读取失败\t{$log}");
            return false;
        }
        $resp = @fread($this->fp,$head['len']);
        if(!$resp){
            $this->log('alert',"响应内容读取失败\t{$log}");
            return false;
        }
        $ret  = json_decode($resp,true);
        if(!$ret){
            $this->log('alert',"响应结果解析失败\t{$log}");
            return false;
        }
        if(!$ret['success']){
            $this->log('error',"{$ret['message']}\t{$log}");
        }
        return $ret['success'];
    }

    /**
     * 写日志
     * @param $log
     * @return bool
     */
    protected function streamWrite(&$log)
    {
        $null = null;
        $write = array($this->fp);
        $writable = @stream_select($null, $write, $null,$this->conf['send_sec']);
        if (!$writable) {
            $this->log('error','日志写入超时');
            return false;
        }
        $binData = @pack("a*",$log );
        $len  = @pack('L', strlen($binData));
        $package = $len . $binData;
        if(!@fwrite($this->fp,$package,strlen($package))){
            $this->log('error','日志写入失败');
            return false;
        }
        return true;
    }



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

}

