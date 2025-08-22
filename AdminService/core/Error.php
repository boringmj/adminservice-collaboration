<?php

namespace AdminService;

use base\Error as BaseError;

/**
 * 错误处理类
 * 
 * 用于收集和处理PHP错误，包括致命错误和非致命错误。
 */
final class Error extends BaseError {

    /**
     * 标记框架是否初始化完成(用于决定是否记录日志)
     * @var bool
     */
    private static $initialized=false;

    /**
     * 正常退出时的回调函数
     * @var callable|null
     */
    private static $normalExitCallback=null;

    /**
     * 标记是否已经处理过错误
     * @var bool
     */
    private static $handled=false;

    /**
     * 错误类型映射
     * @var array
     */
    private static $errorTypes=array(
        E_ERROR             => 'Fatal Error',
        E_WARNING           => 'Warning',
        E_PARSE             => 'Parse Error',
        E_NOTICE            => 'Notice',
        E_CORE_ERROR        => 'Core Error',
        E_CORE_WARNING      => 'Core Warning',
        E_COMPILE_ERROR     => 'Compile Error',
        E_COMPILE_WARNING   => 'Compile Warning',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        // E_STRICT         => 'Strict',// 已被弃用
        E_RECOVERABLE_ERROR=>'Recoverable Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated'
    );

    /**
     * 注册错误处理器
     * 
     * @access public
     * @param callable $normalExitCallback 正常退出时的回调函数
     * @param bool $initialized 标记框架是否初始化完成
     * @return void
     */
    public static function register(?callable $normalExitCallback=null,bool $initialized=false): void {
        // 如果已经处理过错误，则不再注册
        if(self::$handled)
            return;
        self::$initialized=$initialized;
        self::$normalExitCallback=$normalExitCallback;
        // 注册错误处理器(非致命错误)
        set_error_handler(array(self::class,'handleError'),E_ALL);
        // 注册异常处理器
        set_exception_handler(array(self::class,'handleException'));
        // 注册shutdown函数(致命错误)
        register_shutdown_function(array(self::class,'handleShutdown'));
    }

    /**
     * 错误处理器(非致命错误)
     * 
     * @access public
     * @param int $type
     * @param string $message
     * @param string $file
     * @param int $line
     * @return bool
     */
    public static function handleError(int $type,string $message,string $file,int $line): bool {
        // 清理错误消息(移除文件路径和行号)
        $message=self::cleanErrorMessage($message);
        // 获取堆栈跟踪(忽略当前错误处理器这一层)
        $stackTrace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        // 移除错误处理器自身的调用
        array_shift($stackTrace);
        self::$errors[]=array(
            'type'=>$type,
            'message'=>$message,
            'file'=>$file,
            'line'=>$line,
            'is_fatal'=>false,
            'stack_trace'=>$stackTrace,
            'is_exception'=>false
        );
        // 阻止默认错误处理
        return true;
    }

    /**
     * 异常处理器（未捕获的异常）
     * 
     * @access public
     * @param \Throwable $exception
     * @return void
     */
    public static function handleException(\Throwable $exception): void {
        // 清理异常消息
        $message=self::cleanErrorMessage($exception->getMessage());
        // 将异常视为致命错误
        self::$errors[]=array(
            'type'=>E_ERROR,
            'message'=>$message,
            'file'=>$exception->getFile(),
            'line'=>$exception->getLine(),
            'is_fatal'=>true,
            'stack_trace'=>$exception->getTrace(),
            'is_exception'=>true
        );
        
        // 直接处理并退出
        self::handleShutdown();
        exit();
    }

    /**
     * Shutdown函数(处理致命错误)
     * 
     * @access public
     * @return void
     */
    public static function handleShutdown(): void {
        // 如果已经处理过错误,则直接退出
        if(self::$handled) {
            return;
        }
        // 捕获致命错误
        $lastError=error_get_last();
        if($lastError&&in_array($lastError['type'],array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR))) {
            // 分离致命错误消息和堆栈跟踪
            $message=self::cleanErrorMessage($lastError['message']);
            $stackTrace='';
            if(strpos($lastError['message'],'Stack trace:')!==false) {
                list(,$stackTrace)=explode('Stack trace:',$lastError['message'],2);
                $stackTrace='Stack trace:'.$stackTrace;
            } else {
                // 获取当前堆栈(忽略shutdown函数这一层)
                $stackTrace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                // 移除shutdown函数调用
                array_shift($stackTrace);
            }
            self::$errors[]=array(
                'type'=>$lastError['type'],
                'message'=>trim($message),
                'file'=>$lastError['file'],
                'line'=>$lastError['line'],
                'is_fatal'=>true,
                'stack_trace'=>$stackTrace,
                'is_exception'=>false
            );
        }
        // 如果没有错误则正常退出
        if(empty(self::$errors)) {
            if(is_callable(self::$normalExitCallback)) {
                call_user_func(self::$normalExitCallback);
            }
            exit();
        }
        // 标记已处理
        self::$handled=true;
        // 渲染并退出
        self::renderAndExit();
    }

    /**
     * 渲染错误信息并退出
     * 
     * @access private
     * @return void
     */
    private static function renderAndExit(): void {
        // 清除输出缓冲区
        while(ob_get_level()>0) {
            ob_end_clean();
        }
        // 尝试强制设置响应类型为HTML
        if(!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        // 输出错误信息
        echo self::renderErrors();
        // 记录所有错误到日志
        if(self::$initialized) {
            try {
                foreach(self::$errors as $error) {
                    $errorType=self::$errorTypes[$error['type']]??'Unknown';
                    $logMessage="[{$errorType}] {message} in {file} on line {line}";
                    if(!empty($error['stack_trace'])) {
                        $stackTrace=is_array($error['stack_trace']) 
                            ? self::formatStackTraceForLog($error['stack_trace'])
                            : $error['stack_trace'];
                        $logMessage.="\nStack Trace:\n".$stackTrace;
                    }
                    App::exec_class_function(Log::class,'write',array($logMessage,array(
                        'message'=>$error['message'],
                        'file'=>$error['file'],
                        'line'=>$error['line']
                    )));
                }
            } catch(\Exception $e) {
                echo "<br>日志记录失败: ".htmlspecialchars($e->getMessage());
            }
        }
        exit();
    }

    /**
     * 清理错误消息移除文件路径和行号
     * 
     * @access private
     * @param string $message
     * @return string
     */
    private static function cleanErrorMessage(string $message): string {
        // 移除类似 "in /path/to/file:line" 的部分
        if(preg_match('/^(.*?)( in .*?\.php on line \d+)$/',$message,$matches))
            return $matches[1];
        // 移除类似 "in /path/to/file(line)" 的部分
        if(preg_match('/^(.*?)( in .*?\.php\(\d+\))$/',$message,$matches))
            return $matches[1];
        return $message;
    }

    /**
     * 渲染错误信息为HTML
     * 
     * @access private
     * @return string
     */
    private static function renderErrors(): string {
        $debug_mode=Config::get('app.debug',false);
        // 预处理错误数据
        $processed_errors=[];
        foreach(self::$errors as $index=>$error) {
            // 确定错误类型名称
            $type_name=self::$errorTypes[$error['type']]??'Unknown Error';
            // 确定错误类型标签
            $error_type=$error['is_fatal']?'致命错误':'常规错误';
            if($error['is_exception'])
                $error_type='未捕获异常';
            // 确定徽章类名
            $badge_class='error-badge ';
            if($error['is_fatal'])
                $badge_class.='fatal-badge';
            elseif ($error['type']===E_WARNING)
                $badge_class.='warning-badge';
            else
                $badge_class.='notice-badge';
            if ($error['is_exception'])
                $badge_class.=' exception-badge';
            // 处理堆栈跟踪
            $stack_trace=$error['stack_trace']??'';
            if (!$debug_mode&&!empty($stack_trace)) {
                $stack_trace='堆栈跟踪信息已被隐藏-请在日志中查看或开启调试模式'.PHP_EOL.
                    '如想开启调试模式,请在配置文件中配置 `app.debug` 为 `true`';
            } elseif($debug_mode&&is_array($stack_trace)) {
                $stack_trace=self::formatStackTrace($stack_trace);
            }
            // 添加到处理后的错误数组
            $processed_errors[$index]=[
                'type_name'=>$type_name,
                'error_type'=>$error_type,
                'badge_class'=>$badge_class,
                'message'=>$error['message']??'',
                'file'=>$error['file']??'',
                'line'=>$error['line']??'',
                'stack_trace'=>$stack_trace
            ];
        }
        // 准备模板数据
        $template_data=[
            'error_count'=>count(self::$errors),
            'errors'=>$processed_errors,
            'debug_mode'=>$debug_mode,
            'output_content'=>null,
        ];
        // 在调试模式下添加输出内容
        if($debug_mode) {
            $output_content=App::get(Response::class)->getReturnContent();
            if($output_content!==null&&$output_content!=='') {
                $template_data['output_content']=$output_content;
            }
        }
        // 使用模板引擎渲染
        try {
            $view=App::get(View::class);
            // 设置模板路径
            $template_path=Config::get('app.error_template',null);
            if($template_path==null||!is_file($template_path))
                $view->initWithContent(self::getDefaultErrorTemplate(),$template_data);
            else
                $view->init($template_path,$template_data);
            return $view->render();
        } catch(Exception $e) {
            // 如果模板渲染失败，回退到简单错误显示
            return '<h1>发生错误</h1><p>' . htmlspecialchars($e->getMessage()).'</p>';
        }
    }

    /**
     * 格式化堆栈跟踪信息
     * 
     * @access private
     * @param array $stack_trace 堆栈跟踪数组
     * @return string 格式化后的HTML
     */
    private static function formatStackTrace(array $stack_trace): string {
        $html='<table class="stack-table">';
        $html.='<tr><th>#</th><th>函数/方法</th><th>文件</th><th>行号</th></tr>';
        foreach($stack_trace as $index=>$frame) {
            $file=$frame['file']??'[内部函数]';
            $line=$frame['line']??'N/A';
            $function=$frame['function']??'';
            $class=$frame['class']??'';
            $type=$frame['type']??'';
            $html.=sprintf(
                '<tr><td>%s</td><td>%s%s%s()</td><td>%s</td><td>%d</td></tr>',
                $index+1,
                htmlspecialchars($class),
                htmlspecialchars($type),
                htmlspecialchars($function),
                htmlspecialchars($file),
                htmlspecialchars($line)
            );
        }
        $html.='</table>';
        return $html;
    }

    /**
     * 格式化堆栈跟踪用于日志记录
     * 
     * @access private
     * @param array $trace
     * @return string
     */
    private static function formatStackTraceForLog(array $trace): string {
        $formatted='';
        $index=0;
        foreach($trace as $frame) {
            $file=$frame['file']??'[internal function]';
            $line=$frame['line']??'';
            $function=$frame['function']??'';
            $class=$frame['class']??'';
            $type=$frame['type']??'';
            $formatted.="#{$index} {$file}({$line}): ";
            if($class) {
                $formatted.="{$class}{$type}{$function}()";
            } else {
                $formatted.="{$function}()";
            }
            $formatted.="\n";
            $index++;
        }
        return $formatted;
    }

    /**
     * 安全降级,获取内置渲染模板
     * 
     * @access public
     * @return string
     */
    public static function getDefaultErrorTemplate(): string {
        // 返回一个简单的HTML模板
        return <<<HTML
            {{foreach errors as index=>error}}
            <!-- 垂直排列 -->
            <div style="display:flex; flex-direction:column;margin-bottom:16px;">
                <strong>发生错误</strong> {{error.message}}
                {{if debug_mode}}
                <strong>错误文件</strong> {{error.file}}
                <strong>错误行号</strong> {{error.line}}
                {{/if}}
            </div>
            {{/foreach}}
        HTML;
    }

    /**
     * 设置框架初始化状态
     * 
     * @access public
     * @param bool $initialized
     * @return void
     */
    public static function setInitialized(bool $initialized): void {
        self::$initialized=$initialized;
    }

}