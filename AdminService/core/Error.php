<?php

namespace AdminService;

/**
 * 错误处理类
 * 
 * 用于收集和处理PHP错误，包括致命错误和非致命错误。
 */
final class Error {

    /**
     * 存储收集到的错误
     * @var array
     */
    private static $errors=array();

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
        $html='<!DOCTYPE html><html lang="zh-CN"><head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1.0">
        <link rel="stylesheet" href="/css/error.css">
        </head><body>';
        $html.='<div class="mobile-only error-summary">';
        $html.='<p><strong>共发现 ' . count(self::$errors).' 个错误</strong></p>';
        $html.='<p>点击下方错误条目查看详情</p>';
        $html.='</div>';
        $html.='<div class="error-container">';
        $html.='<h1 class="error-header">发生错误</h1>';
        foreach(self::$errors as $index=>$error) {
            $typeName=self::$errorTypes[$error['type']]??'Unknown Error';
            $errorType=$error['is_fatal']?'致命错误':'常规错误';
            // 禁用非调试模式下的文件显示和行号追踪
            $error_info='';
            if(Config::get('app.debug',false)) {
                $error_info=<<<HTML
                <div class="error-detail">
                    <strong>错误文件:</strong> {$error['file']}
                </div>
                <div class="error-detail">
                    <strong>错误行号:</strong> {$error['line']}
                </div>
                HTML;
            } else {
                $error['stack_trace']='堆栈跟踪信息已被隐藏-请在日志中查看或开启调试模式'
                .PHP_EOL
                .'如想开启调试模式,请在配置文件中配置 `app.debug` 为 `true`';
            }
            // 根据错误类型设置徽章
            $badgeClass='error-badge ';
            if($error['is_fatal']) {
                $badgeClass.='fatal-badge';
            } elseif($error['type']===E_WARNING) {
                $badgeClass.='warning-badge';
            } else {
                $badgeClass.='notice-badge';
            }
            // 特殊样式标记异常
            if($error['is_exception']) {
                $badgeClass.=' exception-badge';
                $errorType='未捕获异常';
            }
            $html.=<<<HTML
            <div class="error-entry">
                <h2>
                    <span class="{$badgeClass}">{$errorType}</span>
                    错误 #{$index} - {$typeName}
                </h2>
                <div class="error-detail">
                    <strong>错误信息:</strong> {$error['message']}
                </div>
                {$error_info}
            HTML;
            if(!empty($error['stack_trace'])) {
                $stackId="stack-{$index}";
                $stackContent=is_array($error['stack_trace']) 
                    ? self::formatStackTrace($error['stack_trace'])
                    : htmlspecialchars($error['stack_trace']);
                $html.=<<<HTML
                <div class="stack-title">堆栈跟踪:</div>
                <div class="toggle-stack" onclick="
                    document.getElementById('{$stackId}').style.display='block';
                    this.style.display='none';
                ">
                    <i class="toggle-icon">▼</i> 点击查看堆栈跟踪
                </div>
                <div id="{$stackId}" class="stack-trace" style="display:none">
                {$stackContent}
                </div>
                HTML;
            }
            $html.='</div>';
        }
        $outputContent=null;
        // 调试模式下显示请求模块输出内容
        if(Config::get('app.debug',false))
            $outputContent=Request::getOutput();
        if($outputContent!==null&&$outputContent!=='') {
            $html.='<div class="output-section">';
            $html.='<h2 class="output-header">请求模块输出内容</h2>';
            $html.='<div class="output-content">';
            $html.=htmlspecialchars($outputContent);
            $html.='</div>';
            $html.='</div>';
        }
        $html.='</div>';
        // 添加简单的JS功能
        $html.=<<<HTML
        <script>
            // 自动展开所有堆栈跟踪的按钮
            document.write('<div style="margin: 0 2rem 2rem; text-align: center">');
            document.write('<button onclick="toggleAllStacks()" style="padding: 8px 16px; background: #0d6efd; color: white; border: none; border-radius: 4px; cursor: pointer">切换所有堆栈跟踪</button>');
            document.write('</div>');
            function toggleAllStacks() {
                var allStacks=document.querySelectorAll('.stack-trace');
                var allButtons=document.querySelectorAll('.toggle-stack');
                if(allStacks.length>0) {
                    var isVisible=allStacks[0].style.display==='block';
                    allStacks.forEach(function(stack) {
                        stack.style.display=isVisible?'none':'block';
                    });
                    allButtons.forEach(function(button) {
                        button.style.display=isVisible?'inline-block':'none';
                    });
                }
            }
        </script>
        HTML;
        $html.='</body></html>';
        // 返回完整的HTML内容
        return $html;
    }

    /**
     * 格式化堆栈跟踪用于HTML显示
     * 
     * @access private
     * @param array $trace
     * @return string
     */
    private static function formatStackTrace(array $trace): string {
        $formatted='';
        $index=0;
        foreach($trace as $frame) {
            $file=$frame['file']??'[internal function]';
            $line=$frame['line']??'';
            $function=$frame['function']??'';
            $class=$frame['class']??'';
            $type=$frame['type']??'';
            $args='';
            // 格式化参数(如果可用)
            if(isset($frame['args'])&&is_array($frame['args'])) {
                $args=array_map(function($arg) {
                    if(is_object($arg)) {
                        return get_class($arg).' object';
                    } elseif(is_array($arg)) {
                        return 'array('.count($arg).')';
                    } elseif(is_string($arg)) {
                        return "'".(strlen($arg)>20?substr($arg,0,20).'...':$arg)."'";
                    } elseif(is_scalar($arg)) {
                        return var_export($arg,true);
                    }
                    return gettype($arg);
                },$frame['args']);
                $args=implode(',',$args);
            }
            $formatted.="#{$index} ";
            if($class) {
                $formatted.="{$class}{$type}{$function}({$args})";
            } else {
                $formatted.="{$function}({$args})";
            }
            $formatted.="\n    at {$file}";
            if($line) {
                $formatted.="({$line})";
            }
            $formatted.="\n\n";
            $index++;
        }
        return nl2br(htmlspecialchars($formatted));
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
     * 设置框架初始化状态
     * 
     * @access public
     * @param bool $initialized
     * @return void
     */
    public static function setInitialized(bool $initialized): void {
        self::$initialized=$initialized;
    }

    /**
     * 获取收集到的错误
     * 
     * @access public
     * @return array
     */
    public static function getErrors(): array {
        return self::$errors;
    }

}