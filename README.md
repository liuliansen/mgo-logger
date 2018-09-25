说明
----------------
本程序是作为内部系统日志收集php客户端，需要配合内部的日志服务器程序工作。

通过在当前包src目录下新建config.local.php文件进行必要参数配置

使用
----
本程序使用简单，只需要在你项目目前的日志组件下进行日志记录时同时调用本程序方法即可

例如 你当前项目中通过调用 Log::error("错误信息。。。。"); 写入信息
那么只需要在Log类中实际完成日志的代码处加上：\mgologer\Logger::newInstance()->write('error'','错误信息。。。''); 即可
如果不想改动现有日志组件，也可以当前日志调用后加上调用本程序写入方法既可以，如：
Log::error("错误信息。。。。");  
\mgologer\Logger::newInstance()->write('error'','错误信息。。。'');

~~~~
\mgologer\Logger::write()方法是即时写入。但是由于在运行时连接可能断掉，会造成写入失败，
并且在整个程序运行期间一直占用一个连接。所以推荐在上面说的两处修改处 
改成 \mgologer\Logger::newInstance()->push('error'','错误信息。。。'');
当程序完成时本程序会自动将所有运行期间的日志写入到服务器，
当然你也可以在程序结束前通过调用\mgologer\Logger::flush方法将日志完成写入。

另外最好在项目中对 register_shutdown_function() 和 set_error_handler()进行注册回调函数。
在回调函数中调用 \mgologer\Logger::flush将当前队列中的消息完成推送，如果不注册这两个函数，
那么当系统发生致命错误或exit()后，队列中的日志将会丢失。







