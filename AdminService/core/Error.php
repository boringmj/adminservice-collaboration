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
        self::$initialized=$initialized;
        self::$normalExitCallback=$normalExitCallback;
        // 注册错误处理器(非致命错误)
        set_error_handler(array(self::class,'handleError'),E_ALL);
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
            'stack_trace'=>$stackTrace
        );
        // 阻止默认错误处理
        return true;
    }

    /**
     * Shutdown函数(处理致命错误)
     * 
     * @access public
     * @return void
     */
    public static function handleShutdown(): void {
        // 捕获致命错误
        $lastError=error_get_last();
        if($lastError&&in_array($lastError['type'],array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR))) {
            // 分离致命错误消息和堆栈跟踪
            $message=$lastError['message'];
            $stackTrace='';
            // 尝试从消息中分离堆栈
            if(strpos($message,'Stack trace:')!==false) {
                list($message,$stackTrace)=explode('Stack trace:',$message,2);
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
                'stack_trace'=>$stackTrace
            );
        }
        // 如果没有错误则正常退出
        if(empty(self::$errors)) {
            if(is_callable(self::$normalExitCallback)) {
                call_user_func(self::$normalExitCallback);
            }
            exit();
        }
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
     * 渲染错误信息为HTML
     * 
     * @access private
     * @return string
     */
    private static function renderErrors(): string {
        $html='<style>
            .error-container { 
                font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; 
                margin: 2rem; 
                color: #333;
                line-height: 1.6;
            }
            .error-header {
                background: #dc3545;
                color: white;
                padding: 1rem;
                border-radius: 5px 5px 0 0;
                margin-bottom: 0;
            }
            .error-entry { 
                padding: 1.5rem; 
                margin-bottom: 1.5rem;
                background: #f8f9fa;
                border-left: 4px solid #dc3545;
                border-radius: 0 0 5px 5px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .error-entry h2 {
                margin-top: 0;
                color: #dc3545;
                border-bottom: 1px solid #eee;
                padding-bottom: 0.5rem;
            }
            .error-detail {
                margin-bottom: 0.5rem;
            }
            .error-detail strong {
                display: inline-block;
                width: 100px;
                color: #6c757d;
            }
            .stack-trace {
                background: #e9ecef;
                border: 1px solid #ced4da;
                border-radius: 4px;
                padding: 15px;
                margin-top: 15px;
                font-family: "SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace;
                font-size: 14px;
                max-height: 300px;
                overflow: auto;
                white-space: pre-wrap;
                line-height: 1.4;
            }
            .stack-title {
                font-weight: bold;
                margin-bottom: 10px;
                color: #495057;
                font-size: 16px;
            }
            .toggle-stack {
                cursor: pointer;
                color: #0d6efd;
                font-size: 14px;
                margin-top: 10px;
                display: inline-block;
                padding: 5px 10px;
                background: #e7f1ff;
                border-radius: 4px;
                transition: all 0.2s;
            }
            .toggle-stack:hover {
                background: #d0e2ff;
            }
            .error-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 4px;
                font-size: 12px;
                font-weight: bold;
                margin-right: 8px;
            }
            .fatal-badge {
                background: #dc3545;
                color: white;
            }
            .warning-badge {
                background: #ffc107;
                color: #212529;
            }
            .notice-badge {
                background: #0dcaf0;
                color: #212529;
            }
            .output-section {
                margin-top: 2rem;
                padding: 1.5rem;
                background: #e9f7ef;
                border-left: 4px solid #28a745;
                border-radius: 5px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .output-header {
                margin-top: 0;
                color: #28a745;
                border-bottom: 1px solid #c3e6cb;
                padding-bottom: 0.5rem;
            }
            .output-content {
                padding: 15px;
                background: white;
                border: 1px solid #c3e6cb;
                border-radius: 4px;
                font-family: monospace;
                white-space: pre-wrap;
                max-height: 300px;
                overflow: auto;
                margin-top: 10px;
            }
        </style>';
        $html.='<div class="error-container">';
        $html.='<h1 class="error-header">发生错误</h1>';
        foreach(self::$errors as $index=>$error) {
            $typeName=self::$errorTypes[$error['type']]??'Unknown Error';
            $errorType=$error['is_fatal'] ? '致命错误' : '常规错误';
            // 禁用非调试模式下的文件显示和行号追踪
            if(!Config::get('app.debug',false)) {
                $error['file']='文件信息已被隐藏';
                $error['line']='行号信息已被隐藏';
                $error['stack_trace']='堆栈跟踪信息已被隐藏-请在日志中查看或开启调试模式';
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
            $html.=<<<HTML
            <div class="error-entry">
                <h2>
                    <span class="{$badgeClass}">{$errorType}</span>
                    错误 #{$index} - {$typeName}
                </h2>
                <div class="error-detail">
                    <strong>错误信息:</strong> {$error['message']}
                </div>
                <div class="error-detail">
                    <strong>错误文件:</strong> {$error['file']}
                </div>
                <div class="error-detail">
                    <strong>错误行号:</strong> {$error['line']}
                </div>
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
        $outputContent=Request::getOutput();
        if ($outputContent!==null&&$outputContent!=='') {
            $html.='<div class="output-section">';
            $html.='<h2 class="output-header">应用准备输出的内容</h2>';
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

}