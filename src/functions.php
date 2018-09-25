<?php
//+-------------------------------------------------------------
//| 
//+-------------------------------------------------------------
//| Author Liu LianSen <liansen@d3zz.com> 
//+-------------------------------------------------------------
//| Date 2018-07-13
//+-------------------------------------------------------------
if(!defined('LIANSEN_UTILS')) {
    define('LIANSEN_UTILS', true);

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
        if(!function_exists('debug_backtrace')){
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