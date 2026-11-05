<?php

namespace AdminService;

use AdminService\Config\Repository;
use AdminService\Router\Router;
use AdminService\Router\RouterFactory;
use base\AbstractSession;
use base\Container as ContainerContract;
use base\Database\Db as BaseDb;
use base\Request;
use base\Response;

use function array_keys;
use function array_merge;
use function class_exists;
use function interface_exists;
use function in_array;
use function is_int;

/**
 * 应用
 *
 * - 引导: 建立**应用级容器**, 按配置装配绑定与开关, 并安装到门面(使用者入口)
 * - 生命周期: 应用级(进程级)容器长期存活; 请求级对象由 `handle()` 每请求派生与清理
 *   (每次请求 fork 请求级容器并 reset 上一份, 常驻模式下每个请求都是独立 scope)
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class Application {

    /**
     * 应用级容器
     * @var ContainerContract
     */
    private ContainerContract $container;

    /**
     * 当前(最近一次)请求级容器
     * @var ContainerContract|null
     */
    private ?ContainerContract $request_container=null;

    /**
     * 当前活动请求上下文(嵌套 handle 时指向内层)
     * @var RequestContext|null
     */
    private ?RequestContext $request_context=null;

    /**
     * 最近一次请求上下文(关停阶段响应出口使用)
     * @var RequestContext|null
     */
    private ?RequestContext $last_request_context=null;

    /**
     * 下一次请求使用的 Request 种子(测试 / 自定义入口)
     * @var Request|null
     */
    private ?Request $request_seed=null;

    /**
     * 下一次请求使用的 Response 种子(测试 / 自定义入口)
     * @var Response|null
     */
    private ?Response $response_seed=null;

    /**
     * 下一次请求使用的 Session 种子(测试 / 自定义入口)
     * @var AbstractSession|null
     */
    private ?AbstractSession $session_seed=null;

    /**
     * 配置仓储(装配与请求期都要读它; 构造期定下, 之后不再变)
     * @var Repository
     */
    private Repository $config;

    /**
     * 顶层请求开始时捕获的应用级配置仓储
     * @var Repository|null
     */
    private ?Repository $application_config=null;

    /**
     * 是否已执行过 `init()`
     *
     * - 未初始化时 `config()` 必须尊重构造期显式传入的那份(测试会用它反证"不摸门面")
     * - 初始化后门面就是应用的当前配置, `config()` 跟随门面, 这样运行期替换配置不会分裂
     * @var bool
     */
    private bool $initialized=false;

    /**
     * 构造方法
     *
     * @access public
     * @param ContainerContract|null $container 容器实例
     *  - 未传入时:**复用门面当前已安装的容器**(同一进程内通常只有一个应用级容器),
     *    门面没有实例时才新建 —— 避免"新建一个空应用"把已装配好的容器顶掉
     * @param Repository|null $config 配置仓储
     *  - 引导期由 `Main::init()` **显式传入**(不依赖门面传递); 未传入时取 `Config::repository()`,
     *    门面里也没有(如只调 `App::init()` 的测试)时, 退化为 `new Repository(array())`(**空配置**)
     */
    public function __construct(?ContainerContract $container=null,?Repository $config=null) {
        if($container!==null)
            $this->container=$container;
        elseif(App::hasInstance())
            $this->container=App::getInstance();
        else
            $this->container=new Container();
        $this->config=$config??Config::repository()??new Repository(array());
    }

    /**
     * 初始化(引导)
     *
     * - **先把配置实例登记进容器**(只按实现类名): "契约名 → 实现类"的映射交给下面的 `app.alias`,
     *   组件(含内核的参数解析器)因此按契约拿配置, 不需要静态门面
     * - 按配置装配绑定表与别名: `app.classes`(数值键为"绑定自身", 字符串键为别名)与 `app.alias`
     * - 应用 `app.param_cast` 开关
     * - 安装容器到门面(重复调用不会重建已安装的容器, 语义幂等)
     *
     * @access public
     * @param array<int|string,class-string> $classes 需要初始化的类
     * @return static
     * @throws Exception
     */
    public function init(array $classes=array()): static {
        // 登记配置实例(**只按实现类名**): "依赖倒置"的落点是"使用者按契约注入 -> 容器把契约解析成实现类 ->
        // 找到这份实例"; 契约名到实现类的映射由 `app.alias` 承担(`config/app.php`), 不拿具体实例去占契约名
        $this->container->instance(Repository::class,$this->config);
        // 路由表提供器: 应用级单例, 让请求级 Route 在同一 Application/进程内复用同一份路由表;
        // 重复 init 时保留已有实例, 避免把同一应用内的缓存冲掉
        if(!$this->container->hasInstance(RouterFactory::class))
            $this->container->instance(RouterFactory::class,new RouterFactory());
        // 获取配置文件中的绑定与别名
        $extra_classes=$classes;
        [$binds,$aliases]=self::bindings($classes,$this->config);
        // 编译快照已在装载期完成类存在性校验; 额外传入的类仍需现场校验
        if(!($extra_classes===array()&&$this->config->isValidated()))
            self::validateBindings($binds,$aliases);
        // 设置是否允许标量参数静默转换(可在 config/app.php 中配置 app.param_cast)
        $this->container->setParamCast($this->config->get('app.param_cast',true));
        // 写入绑定表: 自身映射无意义(解析时原样返回), 直接跳过, 其余走 bind(含循环检测)
        foreach($binds as $name=>$class) {
            if($name===$class)
                continue;
            $this->container->bind($name,$class);
        }
        // 写入别名表
        foreach($aliases as $alias=>$abstract) {
            if($alias===$abstract)
                continue;
            $this->container->alias($alias,$abstract);
        }
        // 安装到门面(使用者入口)
        App::setInstance($this->container);
        // 同时把这份配置设为门面的当前配置(替换容器里按实现类名登记的那份);
        // 必须排在 `App::setInstance()` 之后 —— `setRepository()` 换的是"当前容器"里的登记
        Config::setRepository($this->config);
        $this->initialized=true;
        return $this;
    }

    /**
     * 校验配置里的绑定与别名
     *
     * - 供配置编译快照在落盘前执行; 运行期编译快照可跳过重复校验
     *
     * @access public
     * @param Repository $config 配置仓储
     * @return void
     * @throws Exception 绑定或别名目标不存在
     */
    public static function validateConfig(Repository $config): void {
        [$binds,$aliases]=self::bindings(array(),$config);
        self::validateBindings($binds,$aliases);
    }

    /**
     * 归一化绑定与别名
     *
     * @access private
     * @param array<int|string,class-string> $classes 额外类
     * @param Repository $config 配置仓储
     * @return array{0:array<string,class-string>,1:array<string,string>} [绑定, 别名]
     */
    private static function bindings(array $classes,Repository $config): array {
        $aliases=(array)$config->get('app.alias',array());
        $binds=array();
        $classes=array_merge($classes,$config->get('app.classes',array()));
        foreach($classes as $alias=>$class) {
            if(is_int($alias))
                $binds[$class]=$class;
            else
                $binds[$alias]=$class;
        }
        return array($binds,$aliases);
    }

    /**
     * 校验绑定与别名目标
     *
     * - 别名目标允许是另一个别名/绑定名(链式), 不要求它本身是类
     *
     * @access private
     * @param array<string,class-string> $binds 绑定表
     * @param array<string,string> $aliases 别名表
     * @return void
     * @throws Exception 目标不存在
     */
    private static function validateBindings(array $binds,array $aliases): void {
        $known_names=array_merge(array_keys($binds),array_keys($aliases));
        foreach(array_merge($binds,$aliases) as $class)
            if(!in_array($class,$known_names,true)&&!class_exists($class)&&!interface_exists($class))
                throw new Exception('Class "'.$class.'" not found.');
    }

    /**
     * 获取应用级容器
     *
     * @access public
     * @return ContainerContract
     */
    public function container(): ContainerContract {
        return $this->container;
    }

    /**
     * 获取配置仓储(框架内部取配置走这里, 不经静态门面)
     *
     * - 初始化前: 返回构造期传入/捕获的那份(测试可反证"不摸门面")
     * - 初始化后: 跟随门面当前仓储, 运行期 `Config::setRepository()` 不会造成"应用读旧、请求读新"
     *
     * @access public
     * @return Repository
     */
    public function config(): Repository {
        $context=ContextStorage::current();
        if($context!==null) {
            $config=$context->config();
            if($config instanceof Repository)
                return $config;
        }
        if($this->initialized)
            return Config::repository()??$this->config;
        return $this->config;
    }

    /**
     * 获取应用级配置仓储
     *
     * - 顶层请求开始时捕获门面当前配置, 嵌套请求复用该基线
     * - 未捕获时回退构造期配置
     *
     * @access private
     * @return Repository
     */
    private function applicationConfig(): Repository {
        return $this->application_config??$this->config;
    }

    /**
     * 处理一次请求(应用级入口)
     *
     * - **每请求一个干净的请求级容器**: 从应用级容器 `fork()` 派生, 保留到下一次请求开始时被 `reset()`;
     *   绑定/别名/单例表是 fork 时的快照, 反射缓存共享
     * - 进入请求作用域时把门面指针指向请求级容器; 请求结束收回应用级容器 —— 响应出口在关停阶段
     *   经 `Application::requestContainer()` 取请求级对象, 不依赖门面指针
     * - 请求级装配: 每请求只在请求容器本地新建请求 / 响应 / 会话; 父容器已有登记一律不复用;
     *   测试 / 自定义入口用 `seedRequest()` 注入下一次请求的种子对象
     *
     * @access public
     * @param callable $callback 请求处理过程(接收请求级容器)
     * @param bool $defer_metadata 是否延迟到响应发送后落盘容器元数据
     * @return mixed
     * @throws Exception
     */
    public function handle(callable $callback,bool $defer_metadata=false): mixed {
        $previous_context=$this->request_context;
        $previous_last=$this->last_request_context;
        $previous_instance=App::hasInstance()?App::getInstance():null;
        $is_nested=$previous_context!==null;
        // 顶层请求开始时回收上一轮请求容器与未结束的事务会话; 嵌套 handle 不能清掉外层状态
        if(!$is_nested) {
            $this->application_config=$this->config();
            BaseDb::releaseCurrentSessions();
            if($this->request_container!==null)
                $this->request_container->reset();
        }
        // 请求级 scope: 实例表独立; 绑定/别名/单例为 fork 快照; 反射缓存共享
        $container=$this->container->fork();
        $this->request_container=$container;
        // 容器元数据在请求处理期间只标脏, 结束时统一落盘
        if($container instanceof Container)
            $container->deferMetadataFlush();
        if($defer_metadata&&$this->container instanceof Container)
            $this->container->deferMetadataFlush();
        // 错误出口需要当前请求的 Response; 在请求开始时同步给错误处理器
        Error::beginRequest($container);
        // 请求开始时复制一份应用级配置: 请求内 setValue 只修改副本, 不泄漏到后续请求
        $repository=$this->applicationConfig();
        if($repository!==null) {
            $request_config=$repository->fork();
            // 请求配置只登记到请求容器, 不切换共享元数据缓存的配置;
            // 否则每请求都会清空应用级类元数据, 并把请求级 cache.path/debug 带入后续请求
            if($container instanceof Container)
                $container->instanceWithoutMetadata(Repository::class,$request_config);
            else
                $container->instance(Repository::class,$request_config);
        }
        App::setInstance($container);
        $completed=false;
        try {
            $context=$this->bootRequest($container);
            $this->request_context=$context;
            $this->last_request_context=$context;
            Error::setRequestContext($context);
            $completed=true;
            return ContextStorage::run(
                $context,
                function() use ($callback,$container): mixed {
                    try {
                        return $callback($container);
                    } finally {
                        // 请求回调结束后立即释放当前执行流的事务会话与路由命中态;
                        // 响应发送阶段不应再持有数据库事务
                        BaseDb::releaseCurrentSessions();
                        if($container->hasInstance(Router::class)) {
                            $router=$container->get(Router::class);
                            if($router instanceof Router)
                                $router->resetCurrent();
                        }
                    }
                }
            );
        } finally {
            // 正常 HTTP 请求把落盘推迟到响应发送后; 异常或普通调用在请求边界落盘
            if(!$defer_metadata||!$completed)
                $this->flushRequestMetadata();
            // 嵌套请求结束后恢复外层上下文; 顶层保留 last_request_context 供关停阶段响应出口使用
            $this->request_context=$previous_context;
            if($is_nested) {
                $this->last_request_context=$previous_last;
                $this->request_container=$previous_last?->container()??$this->request_container;
                Error::setRequestContainer($previous_last?->container());
                Error::setRequestContext($previous_last);
            }
            // 门面指针**收回应用级容器**(嵌套时回到外层请求容器), 避免"请求外的登记落进已废弃的请求容器"
            App::setInstance($previous_instance??$this->container);
        }
    }

    /**
     * 落盘当前请求积累的容器元数据
     *
     * - 应用级与请求级容器共享同一份元数据, 两次 flush 只有第一次会实际写入
     *
     * @access public
     * @return bool 是否成功落盘
     */
    public function flushRequestMetadata(): bool {
        $ok=true;
        if($this->request_container instanceof Container)
            $ok=$this->request_container->flushMetadata();
        if($this->container instanceof Container)
            $ok=$this->container->flushMetadata()&&$ok;
        return $ok;
    }

    /**
     * 获取当前(最近一次)请求级容器
     *
     * - 供框架内部(如关停阶段的响应出口)取请求级对象; 尚未处理过请求时回退到应用级容器
     *
     * @access public
     * @return ContainerContract
     */
    public function requestContainer(): ContainerContract {
        return $this->request_context?->container()
            ??$this->last_request_context?->container()
            ??$this->request_container
            ??$this->container;
    }

    /**
     * 获取当前(最近一次)请求上下文
     *
     * - 请求回调内返回活动上下文; 顶层请求结束后返回最近一次上下文供关停阶段使用
     *
     * @access public
     * @return RequestContext|null
     */
    public function requestContext(): ?RequestContext {
        return $this->request_context??$this->last_request_context;
    }

    /**
     * 为下一次请求注入种子对象
     *
     * - 只供测试 / 自定义入口使用; 种子在请求装配后立即清空
     * - 不通过应用级容器登记请求对象, 避免父容器残留被请求复用
     *
     * @access public
     * @param Request|null $request 请求对象
     * @param Response|null $response 响应对象
     * @param AbstractSession|null $session 会话对象
     * @return static
     */
    public function seedRequest(
        ?Request $request=null,
        ?Response $response=null,
        ?AbstractSession $session=null
    ): static {
        if($request!==null)
            $this->request_seed=$request;
        if($response!==null)
            $this->response_seed=$response;
        if($session!==null)
            $this->session_seed=$session;
        return $this;
    }

    /**
     * 结束当前请求并释放请求级引用
     *
     * - 供常驻 worker 在响应发送后显式调用; FPM 进程结束时可省略
     * - 不清空应用级容器
     *
     * @access public
     * @return void
     */
    public function endRequest(): void {
        $context=$this->request_context??$this->last_request_context;
        if($context!==null) {
            $context->session()->close();
            $container=$context->container();
            if($container->hasInstance(Router::class)) {
                $router=$container->get(Router::class);
                if($router instanceof Router)
                    $router->resetCurrent();
            }
        }
        BaseDb::releaseCurrentSessions();
        $this->request_context=null;
        $this->last_request_context=null;
        $this->request_container=null;
        Error::endRequest();
    }

    /**
     * 请求级装配(请求 / 响应 / 会话)
     *
     * - 只认请求容器本地登记; 父容器里的请求对象不复用
     * - 测试 / 自定义入口通过 `seedRequest()` 注入种子对象, 种子装配后立即清空
     * - 会话: 未开启原生会话时用内存驱动;`session.start` 为真时调用驱动的 `init()`
     *
     * @access private
     * @param ContainerContract $container 请求级容器
     * @return RequestContext
     * @throws Exception
     */
    private function bootRequest(ContainerContract $container): RequestContext {
        // 请求配置以请求容器本地副本为准; 未注入时回退应用级配置
        $config=$this->hasLocalInstance($container,Repository::class)
            ?$container->get(Repository::class)
            :$this->applicationConfig();
        // 请求对象只在请求容器本地构建; 父容器里的 Request/Response/Session 一律不复用
        $request=$this->request_seed;
        $this->request_seed=null;
        if($request===null) {
            if($this->hasLocalInstance($container,Request::class))
                $request=$container->get(Request::class);
            else {
                $request=$container->build(Request::class);
                $container->instance(Request::class,$request);
            }
        } else
            $container->instance(Request::class,$request);
        $response=$this->response_seed;
        $this->response_seed=null;
        if($response===null) {
            if($this->hasLocalInstance($container,Response::class))
                $response=$container->get(Response::class);
            else {
                $response=$container->build(Response::class,container:$container);
                $container->instance(Response::class,$response);
            }
        } else
            $container->instance(Response::class,$response);
        $session=$this->session_seed;
        $this->session_seed=null;
        if($session===null) {
            if($this->hasLocalInstance($container,AbstractSession::class))
                $session=$container->get(AbstractSession::class);
            else {
                /** @var AbstractSession $session */
                $session=$container->build($config->get('session.class',ArraySession::class));
                if($config->get('session.start',false))
                    $session->init();
                $container->instance(AbstractSession::class,$session);
            }
        } else
            $container->instance(AbstractSession::class,$session);
        $context=new RequestContext(
            $container,
            $request,
            $response,
            $session,
            $config
        );
        $container->instance(RequestContext::class,$context);
        return $context;
    }

    /**
     * 判断实例是否只登记在请求容器本地
     *
     * - 自定义容器契约没有本地判定时退化为 `hasInstance()`
     *
     * @access private
     * @param ContainerContract $container 请求容器
     * @param string $name 名称
     * @return bool
     */
    private function hasLocalInstance(ContainerContract $container,string $name): bool {
        if($container instanceof Container)
            return $container->hasLocalInstance($name);
        return $container->hasInstance($name);
    }

}
