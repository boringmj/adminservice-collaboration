<?php

namespace AdminService;

use AdminService\Cache\CompileStore;
use AdminService\Config\Env;
use AdminService\Config\Loader;
use AdminService\Config\Repository;
use base\Database\Db as BaseDb;
use base\Request;
use base\Response;
use base\Route;
use AdminService\Database\DatabaseConfig;
use ReflectionException;

use function array_filter;
use function array_map;
use function array_values;
use function date_default_timezone_set;
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function scandir;
use function sort;
use function str_ends_with;
use function trim;
use function version_compare;

final class Main {

    /**
     * 框架配置目录
     */
    private const CONFIG_DIR=__DIR__.'/config';

    /**
     * 应用(引导 + 生命周期宿主)
     * @var Application|null
     */
    private ?Application $application=null;

    /**
     * 获取应用(不存在则建立)
     *
     * - 请求级容器由它承载: 关停阶段或自定义入口需要请求级对象时用它取(`application()->requestContainer()`)
     *
     * @access public
     * @return Application
     */
    public function application(): Application {
        return $this->application??=new Application();
    }

    /**
     * 初始化
     *
     * @access public
     * @return self
     * @throws Exception
     * @throws \Exception
     */
    public function init(): self {
        // 判断PHP版本
        if(version_compare(PHP_VERSION,'8.2.0','<'))
            exit('无法兼容您的PHP版本('.PHP_VERSION.'),需要PHP8.2.0及以上版本');
        // 注册错误处理(响应出口: 正常退出时按请求准备好响应再发送)
        // 关停阶段门面已收回应用级容器, 故这里直接向 Application 取请求级容器(框架内部不走门面)
        Error::register(function(): void {
            $container=$this->application===null?App::getInstance():$this->application->requestContainer();
            $response=$container->get(Response::class);
            $response->prepare($container->get(Request::class));
            $response->send();
        },false);
        // 先解析 runtime 根目录: 配置文件默认路径与后续缓存都依赖它, 且不能反向读取 Config
        Runtime::path();
        // 配置优先走编译快照; 快照不可用时回退实时加载
        $config=$this->loadConfig(env_snapshot());
        // 时区由配置统一控制, 避免 CLI / HTTP / 测试环境各用一套
        date_default_timezone_set((string)$config->get('app.timezone','Asia/Shanghai'));
        Config::setRepository($config);
        // 加载函数库
        $this->loadFunction($config);
        // App初始化(建应用级容器并按配置装配,同时把这份配置登记进容器,并安装到门面)
        $this->application=(new Application(null,$config))->init();
        // 安装数据库配置提供者: 数据库层(契约层)不读全局配置, 由应用层把配置能力交给它
        // 直接注入配置实例(而非让它回落门面): 引导期已定下这份配置, 之后只读
        BaseDb::setConfig(new DatabaseConfig($config));
        // 注入容器到错误处理器: 错误 / 异常路径不再经静态门面(容器不可用时走内置错误出口)
        Error::setContainer($this->application->container());
        // 请求 / 响应 / 会话属**请求级**对象: 由 Application::handle() 每请求新建, 不再在引导期注册
        // 初始化完成
        Error::setInitialized(true);
        return $this;
    }

    /**
     * 加载配置
     *
     * - 优先读取 `runtime/cache/compile/config.cache.php`
     * - 未命中或快照非法时实时加载, 并尝试写入快照
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return Repository
     * @throws Exception
     */
    private function loadConfig(Env $env): Repository {
        $store=$this->bootstrapStore($env);
        $loader=new Loader(self::CONFIG_DIR);
        if(!$this->compileConfigEnabled($env)) {
            // 存在 .env 时默认不生成配置快照, 并清理旧版本可能留下的快照
            $store->invalidate('config');
            return new Repository($loader->load(),$env);
        }
        $payload=$store->load('config',function() use($store,$env): string {
            return $this->configFingerprint($store,$env);
        },function() use($loader,$env): array {
            $raw=$loader->load();
            $repository=new Repository($raw,$env);
            Application::validateConfig($repository);
            return array(
                'version'=>1,
                'validated'=>true,
                'raw'=>$raw,
                'effective'=>$repository->all()
            );
        },$this->configContext($store,$env));
        if(is_array($payload)
            &&(int)($payload['version']??0)===1
            &&!empty($payload['validated'])
            &&isset($payload['raw'],$payload['effective'])
            &&is_array($payload['raw'])
            &&is_array($payload['effective']))
            return Repository::fromValidated($payload['raw'],$payload['effective'],$env);
        $store->invalidate('config');
        return new Repository($loader->load(),$env);
    }

    /**
     * 构造引导期编译缓存存储
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return CompileStore
     */
    private function bootstrapStore(Env $env): CompileStore {
        $enabled=$this->compileEnabled($env);
        return new CompileStore(new Repository(array(
            'app'=>array('debug'=>$this->compileDebug($env)),
            'cache'=>array(
                'path'=>Runtime::path().'/cache',
                'compile'=>array(
                    'enabled'=>$enabled,
                    'mode'=>$this->compileMode($env),
                    'config'=>$this->compileConfigEnabled($env),
                    'skip_on_debug'=>$this->compileSkipOnDebug($env)
                )
            )
        )));
    }

    /**
     * 配置编译缓存是否启用
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return bool
     */
    private function compileEnabled(Env $env): bool {
        $value=$env->get('CACHE_COMPILE',true);
        return $value!==false&&$value!=='0';
    }

    /**
     * 配置编译缓存是否启用
     *
     * - 存在 `.env` 时固定关闭, 避免配置快照物化敏感值
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return bool
     */
    private function compileConfigEnabled(Env $env): bool {
        return $this->compileEnabled($env)&&$env->path()==='';
    }

    /**
     * 配置编译缓存模式
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return string auto / always / off
     */
    private function compileMode(Env $env): string {
        if(!$this->compileEnabled($env))
            return 'off';
        $mode=$env->get('CACHE_COMPILE_MODE','auto');
        return in_array($mode,array('auto','always','off'),true)?$mode:'auto';
    }

    /**
     * 引导期调试开关
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return bool
     */
    private function compileDebug(Env $env): bool {
        return (bool)$env->get('APP_DEBUG',false);
    }

    /**
     * debug 模式下跳过的编译缓存名
     *
     * - 字符串按逗号拆分; 布尔值表示全部跳过或不跳过
     *
     * @access private
     * @param Env $env `.env` 快照
     * @return array<string>
     */
    private function compileSkipOnDebug(Env $env): array {
        $value=$env->get('CACHE_COMPILE_SKIP_ON_DEBUG','config');
        if(is_array($value))
            return array_values(array_filter($value,'is_string'));
        if($value===true)
            return array('config','route','container');
        if($value===false)
            return array();
        $value=trim((string)$value);
        if($value==='')
            return array();
        return array_values(array_filter(
            array_map('trim',explode(',',$value)),
            static fn(string $name): bool => $name!==''
        ));
    }

    /**
     * 配置缓存上下文
     *
     * @access private
     * @param CompileStore $store 编译缓存存储
     * @param Env $env `.env` 快照
     * @return string
     */
    private function configContext(CompileStore $store,Env $env): string {
        return $store->hash(array(
            'runtime'=>Runtime::path(),
            'env'=>$env->all()
        ));
    }

    /**
     * 配置源指纹
     *
     * @access private
     * @param CompileStore $store 编译缓存存储
     * @param Env $env `.env` 快照
     * @param string|null $config_dir 配置目录(null = 框架配置目录)
     * @return string
     */
    private function configFingerprint(CompileStore $store,Env $env,?string $config_dir=null): string {
        $stats=array();
        foreach($this->listPhpFiles($config_dir??self::CONFIG_DIR) as $file)
            $stats['file:'.$file]=$this->statFile($file);
        return $store->hash(array('env'=>$env->all(),'files'=>$stats));
    }

    /**
     * 文件指纹
     *
     * @access private
     * @param string $file 文件
     * @return array{mtime:int,size:int}|null
     */
    private function statFile(string $file): ?array {
        $stat=@stat($file);
        if($stat===false)
            return null;
        return array(
            'mtime'=>(int)$stat['mtime'],
            'size'=>(int)$stat['size']
        );
    }

    /**
     * 列出目录下的 PHP 文件
     *
     * @access private
     * @param string $dir 目录
     * @return array<string>
     */
    private function listPhpFiles(string $dir): array {
        $entries=@scandir($dir);
        if($entries===false)
            return array();
        $files=array();
        foreach($entries as $entry)
            if(str_ends_with($entry,'.php'))
                $files[]=$dir.'/'.$entry;
        sort($files);
        return $files;
    }

    /**
     * 加载函数库
     *
     * @access private
     * @param Repository $config 配置仓储(引导期运行在容器建立之前, 故由调用方直接传入)
     * @return void
     */
    private function loadFunction(Repository $config): void {
        $function_path=$config->get('function.path');
        $function_loader=$config->get('function.loader');
        if(is_array($function_loader)) {
            foreach($function_loader as $function) {
                // 直接加载(因性能原因不检查文件存在性)
                include_once $function_path.'/'.$function.'.php';
            }
        }
    }

    /**
     * 开始运行
     *
     * - 请求中间件在路由匹配前执行: 未命中的请求(404/405)同样经过
     * - 中间件若不调用 `$next` 则中断后续(含路由匹配), 此时由中间件自行设置响应
     * - 路由协调器持有请求对象, 属请求级对象: 每请求强制新建, 不复用容器中的旧实例
     * - 路由表本身由应用级 `RouterFactory` 缓存, 只在**同一 Application/进程内**复用; 默认 HTTP 入口每请求新建 `Main`
     *
     * @access public
     * @return void
     * @throws Exception|ReflectionException
     */
    public function run(): void {
        // 由应用承载请求处理: 每请求一个请求级容器(fork/reset 与门面指针切换都在 Application::handle() 内)
        $this->application()->handle(function(): void {
            $middlewares=Pipeline::order(Pipeline::normalize($this->application()->config()->get('middlewares.request',array())));
            (new Pipeline($middlewares,App::get(Request::class)))->then(function(): void {
                App::fresh(Route::class)->run();
            });
        });
    }

}
