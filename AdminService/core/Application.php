<?php

namespace AdminService;

use AdminService\Config\Repository;
use AdminService\Router\RouterFactory;
use base\AbstractSession;
use base\Container as ContainerContract;
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
     * 配置仓储(装配与请求期都要读它; 构造期定下, 之后不再变)
     * @var Repository
     */
    private Repository $config;

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
     * @param array<int|string,string> $classes 需要初始化的类
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
        // 获取配置文件中的别名(与绑定分表: 别名只描述"名字 → 名字")
        $aliases=$this->config->get('app.alias',array());
        // 获取配置文件中需要直接绑定到容器中的类(数值键为"绑定自身", 字符串键为"别名 → 类")
        $binds=array();
        $classes=array_merge($classes,$this->config->get('app.classes',array()));
        foreach($classes as $alias=>$class) {
            if(is_int($alias))
                $binds[$class]=$class;
            else
                $binds[$alias]=$class;
        }
        // 遍历类是否存在
        // - 别名目标允许是另一个别名/绑定名(链式), 不要求它本身是类
        $known_names=array_merge(array_keys($binds),array_keys($aliases));
        foreach(array_merge($binds,$aliases) as $class)
            if(!in_array($class,$known_names,true)&&!class_exists($class)&&!interface_exists($class))
                throw new Exception('Class "'.$class.'" not found.');
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
        if($this->initialized)
            return Config::repository()??$this->config;
        return $this->config;
    }

    /**
     * 处理一次请求(应用级入口)
     *
     * - **每请求一个干净的请求级容器**: 从应用级容器 `fork()` 派生, 保留到下一次请求开始时被 `reset()`;
     *   绑定/别名/单例表是 fork 时的快照, 反射缓存共享
     * - 进入请求作用域时把门面指针指向请求级容器; 请求结束收回应用级容器 —— 响应出口在关停阶段
     *   经 `Application::requestContainer()` 取请求级对象, 不依赖门面指针
     * - 请求级装配: 每请求新建请求 / 响应 / 会话;若容器(含父容器)已有登记则沿用, 便于测试与自定义入口注入
     *
     * @access public
     * @param callable $callback 请求处理过程(接收请求级容器)
     * @return mixed
     * @throws Exception
     */
    public function handle(callable $callback): mixed {
        // 复用请求级容器前清空上一轮的实例(首个请求时为空)
        if($this->request_container!==null)
            $this->request_container->reset();
        // 请求级 scope: 实例表独立; 绑定/别名/单例为 fork 快照; 反射缓存共享
        $container=$this->container->fork();
        $this->request_container=$container;
        // 错误出口需要当前请求的 Response; 在请求开始时同步给错误处理器
        Error::setRequestContainer($container);
        // 请求开始时把 `Config::repository()` 当前返回的配置登记进请求容器(按实现类名, 与 `Application::init()` 一致):
        // 应用级容器里登记的可能已经被 `Config::setRepository()/set()` 换成新的了, 只靠父容器会让请求内的组件读到旧配置
        $repository=$this->config();
        if($repository!==null)
            $container->instance(Repository::class,$repository);
        App::setInstance($container);
        try {
            $this->bootRequest($container);
            return $callback($container);
        } finally {
            // 请求处理结束: 门面指针**收回应用级容器**, 避免"请求外的登记落进已废弃的请求容器"
            // 响应在关停阶段才发送, 届时由 Application::requestContainer() 取请求级对象(不经门面)
            App::setInstance($this->container);
        }
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
        return $this->request_container??$this->container;
    }

    /**
     * 请求级装配(请求 / 响应 / 会话)
     *
     * - 已登记则沿用(测试或自定义入口可预先 `App::instance()` 注入, 用于控制请求内容)
     * - 会话: 未开启原生会话时用内存驱动;`session.start` 为真时调用驱动的 `init()`
     *
     * @access private
     * @param ContainerContract $container 请求级容器
     * @return void
     * @throws Exception
     */
    private function bootRequest(ContainerContract $container): void {
        if(!$container->hasInstance(Request::class))
            $container->instance(Request::class,$container->build(Request::class));
        if(!$container->hasInstance(Response::class))
            $container->instance(Response::class,$container->build(Response::class));
        if(!$container->hasInstance(AbstractSession::class)) {
            /** @var AbstractSession $session */
            $session=$container->build($this->config()->get('session.class',ArraySession::class));
            if($this->config()->get('session.start',false))
                $session->init();
            $container->instance(AbstractSession::class,$session);
        }
    }

}
