<?php

namespace AdminService;
use base\ConfigInterface;

use base\Error as BaseError;
use base\Container as ContainerContract;
use base\Response;
use base\View;
use Fiber;

use function array_pop;
use function array_shift;
use function call_user_func;
use function count;
use function debug_backtrace;
use function error_get_last;
use function error_log;
use function error_reporting;
use function explode;
use function get_class;
use function headers_sent;
use function htmlspecialchars;
use function http_response_code;
use function implode;
use function in_array;
use function ini_set;
use function is_array;
use function is_bool;
use function is_callable;
use function is_file;
use function is_null;
use function is_object;
use function is_resource;
use function is_string;
use function ob_end_clean;
use function ob_get_level;
use function preg_match;
use function register_shutdown_function;
use function set_error_handler;
use function set_exception_handler;
use function spl_object_id;
use function sprintf;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * 错误处理类
 * 
 * 用于收集和处理PHP错误，包括致命错误和非致命错误。
 */
final class Error extends BaseError {
    /**
     * 取配置(错误 / 异常路径专用)
     *
     * - 容器可用时读容器里登记的配置实例; 容器不可用(引导未完成 / 容器自身出错)时回落门面
     * - 这条路径**不能**靠构造注入: 异常是 `new` 出来的, 且错误处理可能发生在容器就绪之前
     *
     * @access private
     * @return ConfigInterface|null
     */
    private static function config(?ContainerContract $container=null): ?ConfigInterface {
        if($container!==null&&$container->hasInstance(ConfigInterface::class))
            return $container->get(ConfigInterface::class);
        $context=ContextStorage::current();
        if($context!==null)
            return $context->config();
        // 关停阶段的错误出口在上下文弹出后仍需使用最近请求的配置
        $state=self::state();
        if($state['handled']&&$state['context']!==null)
            return $state['context']->config();
        $container=self::$container;
        if($container!==null&&$container->hasInstance(ConfigInterface::class))
            return $container->get(ConfigInterface::class);
        return Config::repository();
    }

    /**
     * 是否调试模式(错误 / 异常路径共用)
     *
     * @access public
     * @return bool
     */
    public static function isDebug(?ContainerContract $container=null): bool {
        return (bool)(self::config($container)?->get('app.debug',false)??false);
    }


    /**
     * 框架容器(引导期由 `Main::init()` 一次性注入)
     *
     * - 错误 / 异常路径**不再经静态门面**, 只在引导期拿一次容器引用
     * - 为 `null` 时(引导尚未完成或容器不可用)走**不依赖容器的错误出口**:
     *   内置最小错误页 + `error_log()` 直写, 从而避免"错误处理要容器、容器出错又要错误处理"的死循环
     *
     * @var ContainerContract|null
     */
    private static ?ContainerContract $container=null;

    /**
     * 按执行流隔离的请求态错误状态
     *
     * - 每个执行流一个栈: 首项为基础状态, 每次 `beginRequest()` 压入请求状态, `endRequest()` 弹出
     * - 避免常驻 worker 的协程并发请求共享错误记录与请求容器
     * - 键为 Fiber / Swoole 协程 / 顺序主流程标识
     *
     * @var array<string,array<int,array{
     *     errors:array<int,array<string,mixed>>,
     *     handled:bool,
     *     container:ContainerContract|null,
     *     context:RequestContext|null
     * }>>
     */
    private static array $states=array();

    /**
     * 标记框架是否初始化完成(用于决定是否记录日志)
     * @var bool
     */
    private static $initialized=false;

    /**
     * 当前执行流标识
     *
     * @access private
     * @return string
     */
    private static function executionKey(): string {
        if(($fiber=Fiber::getCurrent())!==null)
            return 'fiber:'.spl_object_id($fiber);
        return Coroutine::key()??'main';
    }

    /**
     * 新的空请求状态
     *
     * @access private
     * @return array{errors:array<int,array<string,mixed>>,handled:bool,container:ContainerContract|null,context:RequestContext|null}
     */
    private static function freshState(): array {
        return array(
            'errors'=>array(),
            'handled'=>false,
            'container'=>null,
            'context'=>null
        );
    }

    /**
     * 当前执行流的栈顶状态
     *
     * @access private
     * @return array{errors:array<int,array<string,mixed>>,handled:bool,container:ContainerContract|null,context:RequestContext|null}
     */
    private static function &state(): array {
        $key=self::executionKey();
        if(!isset(self::$states[$key])||self::$states[$key]===array())
            self::$states[$key]=array(self::freshState());
        $index=count(self::$states[$key])-1;
        return self::$states[$key][$index];
    }

    /**
     * 记录引导期错误
     *
     * @access private
     * @param array<int,array<string,mixed>> $errors 错误列表
     * @return void
     */
    private static function logBootErrors(array $errors): void {
        foreach($errors as $error) {
            $type=self::$errorTypes[$error['type']??0]??'Unknown';
            self::writeFallbackLog('[Boot] {type}: {message} in {file} on line {line}',array(
                'type'=>$type,
                'message'=>(string)($error['message']??''),
                'file'=>(string)($error['file']??''),
                'line'=>(string)($error['line']??'')
            ));
        }
    }

    /**
     * 注入容器(引导期调用; 传 null 可卸载)
     *
     * @access public
     * @param ContainerContract|null $container 容器契约
     * @return void
     */
    public static function setContainer(?ContainerContract $container): void {
        self::$container=$container;
    }

    /**
     * 获取已注入的容器(未注入时为 null)
     *
     * @access public
     * @return ContainerContract|null
     */
    public static function container(): ?ContainerContract {
        return self::$container;
    }

    /**
     * 获取当前执行流收集到的错误
     *
     * @access public
     * @return array<int,array<string,mixed>>
     */
    public static function getErrors(): array {
        $state=self::state();
        return $state['errors'];
    }

    /**
     * 注入当前请求级容器(请求开始时调用; 传 null 可卸载)
     *
     * @access public
     * @param ContainerContract|null $container 请求级容器契约
     * @return void
     */
    public static function setRequestContainer(?ContainerContract $container): void {
        $state=&self::state();
        $state['container']=$container;
    }

    /**
     * 注入当前请求上下文
     *
     * @access public
     * @param RequestContext|null $context 请求上下文
     * @return void
     */
    public static function setRequestContext(?RequestContext $context): void {
        $state=&self::state();
        $state['context']=$context;
    }

    /**
     * 获取当前请求上下文
     *
     * @access public
     * @return RequestContext|null
     */
    public static function requestContext(): ?RequestContext {
        $state=self::state();
        return ContextStorage::current()??$state['context'];
    }

    /**
     * 复位当前执行流的错误状态
     *
     * - 清空已收集错误与当前请求引用, 允许下一次请求重新开始
     * - 只影响当前执行流, 不抹掉其它请求的状态
     * - 不注销 PHP 处理器; 处理器本身是进程级资源
     *
     * @access public
     * @return void
     */
    public static function reset(): void {
        unset(self::$states[self::executionKey()]);
    }

    /**
     * 请求开始
     *
     * - 清空上一请求的错误; 重置单次错误处理闩锁
     * - 注入请求容器与请求上下文, 供请求内错误出口使用
     *
     * @access public
     * @param ContainerContract|null $container 请求级容器
     * @param RequestContext|null $context 请求上下文
     * @return void
     */
    public static function beginRequest(?ContainerContract $container=null,?RequestContext $context=null): void {
        $key=self::executionKey();
        if(!isset(self::$states[$key])||self::$states[$key]===array())
            self::$states[$key]=array(self::freshState());
        elseif(count(self::$states[$key])===1&&self::$states[$key][0]['errors']!==array()) {
            // 引导期错误没有请求可展示, 记录到 PHP 错误日志后丢弃
            self::logBootErrors(self::$states[$key][0]['errors']);
            self::$states[$key][0]=self::freshState();
        }
        self::$states[$key][]=array(
            'errors'=>array(),
            'handled'=>false,
            'container'=>$container,
            'context'=>$context
        );
    }

    /**
     * 请求结束
     *
     * - 释放指定请求(默认栈顶)的容器 / 上下文与已收集错误引用
     * - 栈回到基础状态时删除该执行流的条目, 避免常驻进程按执行流累积
     * - 错误出口已经渲染时也可安全调用; 不重置 `$handled`, 避免同一 shutdown 重复渲染
     *
     * @access public
     * @param RequestContext|null $context 要结束的请求上下文(null = 当前执行流栈顶)
     * @return void
     */
    public static function endRequest(?RequestContext $context=null): void {
        $key=self::executionKey();
        if(!isset(self::$states[$key])||self::$states[$key]===array())
            return;
        if($context!==null) {
            $found=false;
            for($index=count(self::$states[$key])-1;$index>0;$index--) {
                if(self::$states[$key][$index]['context']!==$context)
                    continue;
                array_splice(self::$states[$key],$index,1);
                $found=true;
                break;
            }
            if(!$found)
                return;
        } elseif(count(self::$states[$key])>1)
            array_pop(self::$states[$key]);
        else {
            unset(self::$states[$key]);
            return;
        }
        if(count(self::$states[$key])===1)
            unset(self::$states[$key]);
    }

    /**
     * 丢弃未结束的请求状态
     *
     * - 请求边界使用: 保留基础状态(引导期错误), 删除残留的请求层
     * - 用于未调用 `endRequest()` 的入口或请求开始前的异常路径
     *
     * @access public
     * @return void
     */
    public static function resetRequestStates(): void {
        $key=self::executionKey();
        if(!isset(self::$states[$key])||self::$states[$key]===array())
            return;
        $base=self::$states[$key][0];
        if($base['errors']===array()&&$base['container']===null&&$base['context']===null)
            unset(self::$states[$key]);
        else
            self::$states[$key]=array($base);
    }

    /**
     * 错误出口使用的容器
     *
     * - 优先当前请求级容器; 尚未处理过请求时回退应用级容器
     *
     * @access private
     * @return ContainerContract|null
     */
    private static function responseContainer(): ?ContainerContract {
        $context=ContextStorage::current();
        if($context!==null)
            return $context->container();
        // 关停阶段的错误出口在上下文弹出后仍需使用最近请求的响应容器
        $state=self::state();
        if($state['handled']&&$state['context']!==null)
            return $state['context']->container();
        return $state['container']??self::$container;
    }

    /**
     * 正常退出时的回调函数
     * @var callable|null
     */
    private static $normalExitCallback=null;

    /**
     * 是否已注册 PHP 错误 / 异常 / shutdown 处理器
     *
     * - 处理器是进程级资源, 只注册一次
     * - 每个请求只复位错误状态, 不重复注册处理器
     * @var bool
     */
    private static bool $registered=false;

    /**
     * 标记当前请求是否已经处理过错误
     *
     * - 由 `beginRequest()` 复位; 不再作为进程级永久闩锁
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
     * - 将 `error_reporting` 设为 `E_ALL` 并关闭原生错误显示;
     *   `@` 抑制仍由 `handleError()` 通过当前错误级别判断
     *
     * @access public
     * @param callable $normalExitCallback 正常退出时的回调函数
     * @param bool $initialized 标记框架是否初始化完成
     * @return void
     */
    public static function register(?callable $normalExitCallback=null,bool $initialized=false): void {
        // 回调与初始化状态可随入口更新; PHP 处理器只注册一次
        self::$initialized=$initialized;
        self::$normalExitCallback=$normalExitCallback;
        if(self::$registered)
            return;
        self::$registered=true;
        // 自定义处理器需要收到所有未被 @ 抑制的错误:
        // error_reporting 保持 E_ALL, 原生显示关闭, 由错误页统一决定是否输出
        error_reporting(E_ALL);
        ini_set('display_errors','0');
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
        // 尊重 @ 抑制与当前 error_reporting 配置
        if(!(error_reporting()&$type))
            return false;
        // 清理错误消息(移除文件路径和行号)
        $message=self::cleanErrorMessage($message);
        // 获取堆栈跟踪(保留参数)
        $stackTrace=debug_backtrace();
        // 移除错误处理器自身的调用
        array_shift($stackTrace);
        $state=&self::state();
        $state['errors'][]=array(
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
        $state=&self::state();
        $state['errors'][]=self::exceptionRecord($exception);
        // 处理并让脚本自然结束; 不主动 exit(), 保留后续 shutdown 回调
        self::handleShutdown();
    }

    /**
     * 渲染当前请求的错误页并写入日志
     *
     * - 供常驻入口等自行捕获异常的调用方使用; 不发送响应头, 不退出进程
     * - 传入容器时按该请求的配置、视图与日志写入, 不回落进程级状态
     * - 无已收集错误且未传入异常时返回空字符串
     *
     * @access public
     * @param \Throwable|null $exception 当前请求的异常
     * @param ContainerContract|null $container 当前请求容器(null = 按现有回落)
     * @return string HTML 错误页
     */
    public static function renderRequestPage(
        ?\Throwable $exception=null,
        ?ContainerContract $container=null
    ): string {
        $state=&self::state();
        if($exception!==null)
            $state['errors'][]=self::exceptionRecord($exception);
        if($state['errors']===array())
            return '';
        $state['handled']=true;
        $errorPage=self::renderErrors($container);
        $logFailure=self::writeErrorLogs($container);
        if($logFailure!==null) {
            self::writeOutletFailureLog('log',$logFailure);
            $errorPage.='<br>日志记录失败，请检查日志配置。';
        }
        return $errorPage;
    }

    /**
     * 把异常转成错误记录
     *
     * @access private
     * @param \Throwable $exception 异常
     * @return array{type:int,message:string,file:string,line:int,is_fatal:bool,stack_trace:array<mixed>,is_exception:bool}
     */
    private static function exceptionRecord(\Throwable $exception): array {
        return array(
            'type'=>E_ERROR,
            'message'=>self::cleanErrorMessage($exception->getMessage()),
            'file'=>$exception->getFile(),
            'line'=>$exception->getLine(),
            'is_fatal'=>true,
            'stack_trace'=>$exception->getTrace(),
            'is_exception'=>true
        );
    }

    /**
     * Shutdown函数(处理致命错误)
     * 
     * @access public
     * @return void
     */
    public static function handleShutdown(): void {
        $state=&self::state();
        // 如果已经处理过错误,则直接退出
        if($state['handled']) {
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
                // 获取当前堆栈(保留参数)
                $stackTrace=debug_backtrace();
                // 移除shutdown函数调用
                array_shift($stackTrace);
            }
            $state['errors'][]=array(
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
        if(empty($state['errors'])) {
            if(is_callable(self::$normalExitCallback)) {
                call_user_func(self::$normalExitCallback);
            }
            // 不在 shutdown 回调里主动 exit(), 否则会跳过后续注册的 shutdown 回调
            return;
        }
        // 标记已处理
        $state['handled']=true;
        // 渲染并退出
        self::renderAndExit();
    }

    /**
     * 渲染错误信息
     *
     * - 不主动 exit(): 该方法可能在 shutdown 回调中执行, 主动退出会跳过后续 shutdown 回调
     *
     * @access private
     * @return void
     */
    private static function renderAndExit(): void {
        // 清除输出缓冲区
        while(ob_get_level()>0) {
            ob_end_clean();
        }
        // 准备错误信息(优先当前请求的响应实例)
        $container=self::responseContainer();
        $response=null;
        if(self::$initialized&&$container!==null) {
            try {
                $response=$container->get(Response::class);
                $response->status(500);
                $response->html();
                $response->header('Is-Error','true');
            } catch(\Throwable) {
                // 取不到响应对象时仍要输出错误页
                $response=null;
            }
        }
        $errorPage=self::renderErrors();
        // 记录所有错误到日志(先于正文输出: 客户端断开后仍应留下日志)
        $logFailure=self::writeErrorLogs();
        $headerFailure=null;
        // 发送正文前兜底 500: 响应对象取不到或 sendHeaders() 提前失败时也不能让客户端看到 200
        if(!headers_sent())
            http_response_code(500);
        if($response!==null) {
            try {
                $response->sendHeaders();
            } catch(\Throwable $e) {
                // 响应头发送失败不能吞掉错误页正文
                $headerFailure=$e;
            }
        }
        echo $errorPage;
        // 页面只给通用提示, 详细原因写日志; 日志失败与响应头失败必须分开, 避免误导排障
        if($logFailure!==null) {
            self::writeOutletFailureLog('log',$logFailure);
            echo '<br>日志记录失败，请检查日志配置。';
        }
        if($headerFailure!==null) {
            self::writeOutletFailureLog('header',$headerFailure);
            echo '<br>响应头发送失败，请检查是否已有输出。';
        }
    }

    /**
     * 写入已收集错误的日志
     *
     * - 引导完成且容器可用: 走应用的 Log 服务
     * - 引导未完成 / 容器不可用: 直写 PHP 错误日志(不依赖容器, 也不依赖配置)
     *
     * @access private
     * @param ContainerContract|null $provided 指定请求容器(null = 按现有回落)
     * @return \Throwable|null 写入失败时的异常
     */
    private static function writeErrorLogs(?ContainerContract $provided=null): ?\Throwable {
        try {
            $state=self::state();
            foreach($state['errors'] as $error) {
                $errorType=self::$errorTypes[$error['type']]??'Unknown';
                $logMessage="[{$errorType}] {message} in {file} on line {line}";
                if(!empty($error['stack_trace'])) {
                    $stackTrace=is_array($error['stack_trace'])
                        ? self::formatStackTraceForLog($error['stack_trace'])
                        : $error['stack_trace'];
                    $logMessage.="\nStack Trace:\n".$stackTrace;
                }
                $context=array(
                    'message'=>$error['message'],
                    'file'=>$error['file'],
                    'line'=>$error['line']
                );
                $container=$provided??self::responseContainer();
                if(!self::$initialized||$container===null)
                    self::writeFallbackLog($logMessage,$context);
                else
                    $container->exec_class_function(Log::class,'write',array($logMessage,$context));
            }
        } catch(\Throwable $e) {
            return $e;
        }
        return null;
    }

    /**
     * 记录错误出口自身的失败(不依赖应用 Log 服务)
     *
     * @access private
     * @param string $stage 失败阶段(log/header)
     * @param \Throwable $failure 失败原因
     * @return void
     */
    private static function writeOutletFailureLog(string $stage,\Throwable $failure): void {
        error_log(
            '[AdminService] error outlet '.$stage.' failed: '.$failure->getMessage()
            .' in '.$failure->getFile().' on line '.$failure->getLine()
        );
    }

    /**
     * 回落日志(容器不可用时直写 PHP 错误日志)
     *
     * @access private
     * @param string $logMessage 日志模板
     * @param array<string,mixed> $context 模板变量
     * @return void
     */
    private static function writeFallbackLog(string $logMessage,array $context): void {
        $line=$logMessage;
        foreach($context as $key=>$value)
            $line=str_replace('{'.$key.'}',$value,$line);
        error_log('[AdminService] '.str_replace(array("\r","\n"),' ',$line));
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
     * @param ContainerContract|null $provided 指定请求容器(null = 按现有回落)
     * @return string
     */
    private static function renderErrors(?ContainerContract $provided=null): string {
        $container=$provided??self::responseContainer();
        $debug_mode=self::isDebug($container);
        // 预处理错误数据
        $processed_errors=[];
        $state=self::state();
        foreach($state['errors'] as $index=>$error) {
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
            elseif($error['type']===E_WARNING)
                $badge_class.='warning-badge';
            else
                $badge_class.='notice-badge';
            if($error['is_exception'])
                $badge_class.=' exception-badge';
            // 处理堆栈跟踪
            $stack_trace=$error['stack_trace']??'';
            if(!$debug_mode&&!empty($stack_trace)) {
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
            'error_count'=>count($state['errors']),
            'errors'=>$processed_errors,
            'debug_mode'=>$debug_mode,
            'output_content'=>null,
        ];
        // 在调试模式下添加输出内容(优先当前请求的响应实例)
        if($debug_mode&&self::$initialized&&$container!==null) {
            try {
                $response=$container->get(Response::class);
                $response->render();
                $output_content=$response->rendered();
                if($output_content!==null&&$output_content!=='') {
                    $template_data['output_content']=$output_content;
                }
            } catch(\Throwable) {
                // 错误页自身不能因为展示响应内容失败而再次失败
            }
        }
        // 使用模板引擎渲染
        try {
            // 容器不可用(引导未完成/容器出错)时走内置最小错误页, 不依赖视图服务
            $view=$container?->get(View::class);
            if($view===null)
                return self::renderMinimalErrors($processed_errors);
            // 设置模板路径
            $template_path=self::config($container)?->get('app.error_template');
            if($template_path==null||!is_file($template_path))
                $view->initWithContent(self::getDefaultErrorTemplate(),$template_data);
            else
                $view->init($template_path,$template_data);
            return $view->render();
        } catch(\Throwable $e) {
            // 如果模板渲染失败，回退到简单错误显示
            return self::renderMinimalErrors($processed_errors,htmlspecialchars($e->getMessage()));
        }
    }

    /**
     * 内置最小错误页(不依赖容器与配置)
     *
     * @access private
     * @param array<mixed> $processed_errors 处理后的错误列表
     * @param string|null $reason 回退原因(模板渲染失败时给出)
     * @return string
     */
    private static function renderMinimalErrors(array $processed_errors,?string $reason=null): string {
        $html='<h1>发生错误</h1>';
        if($reason!==null)
            $html.='<p>'.$reason.'</p>';
        $html.='<ul>';
        foreach($processed_errors as $error)
            $html.='<li>'.htmlspecialchars((string)($error['message']??'')).'</li>';
        return $html.'</ul>';
    }

    /**
     * 格式化堆栈跟踪信息
     * 
     * @access private
     * @param array<mixed> $stack_trace 堆栈跟踪数组
     * @return string 格式化后的HTML
     */
    private static function formatStackTrace(array $stack_trace): string {
        $html='<table class="stack-table">';
        $html.='<tr><th>#</th><th>函数/方法</th><th>文件</th><th>行号</th><th>参数</th></tr>';
        foreach($stack_trace as $index=>$frame) {
            $file=$frame['file']??'[内部函数]';
            $line=$frame['line']??'N/A';
            $function=$frame['function']??'';
            $class=$frame['class']??'';
            $type=$frame['type']??'';
            $args=$frame['args']??[];
            $args_str=self::stringifyArgs($args);
            $html.=sprintf(
                '<tr><td>%s</td><td>%s%s%s()</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $index+1,
                htmlspecialchars($class),
                htmlspecialchars($type),
                htmlspecialchars($function),
                htmlspecialchars($file),
                htmlspecialchars((string)$line),
                htmlspecialchars($args_str)
            );
        }
        $html.='</table>';
        return $html;
    }

    /**
     * 格式化堆栈跟踪用于日志记录
     * 
     * @access private
     * @param array<mixed> $trace
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
            $args=$frame['args']??[];
            $args_str=self::stringifyArgs($args);
            $formatted.="#{$index} {$file}({$line}): ";
            if($class) {
                $formatted.="{$class}{$type}{$function}({$args_str})";
            } else {
                $formatted.="{$function}({$args_str})";
            }
            $formatted.="\n";
            $index++;
        }
        return $formatted;
    }

    /**
     * 将参数转为字符串
     * 
     * @access private
     * @param array<mixed> $args
     * @return string
     */
    private static function stringifyArgs(array $args): string {
        $out=[];
        foreach($args as $arg) {
            if(is_object($arg)) {
                $out[]='Object('.get_class($arg).')';
            } elseif(is_array($arg)) {
                $out[]='Array['.count($arg).']';
            } elseif(is_resource($arg)) {
                $out[]='Resource';
            } elseif(is_string($arg)) {
                $out[]="'".substr($arg,0,50).(strlen($arg)>50?'...':'')."'";
            } elseif(is_null($arg)) {
                $out[]='NULL';
            } elseif(is_bool($arg)) {
                $out[]=$arg?'true':'false';
            } else {
                $out[]=(string)$arg;
            }
        }
        return implode(', ',$out);
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