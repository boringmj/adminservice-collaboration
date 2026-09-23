<?php

namespace AdminService;

use AdminService\Config\Repository;
use base\AbstractSession;
use base\Container as ContainerContract;
use base\ConfigInterface;
use base\Request;
use base\Response;

use function array_merge;
use function class_exists;
use function interface_exists;
use function is_int;

/**
 * 应用
 *
 * - 引导: 建立**应用级容器**, 按配置装配绑定与开关, 并安装到门面(使用者入口)
 * - 生命周期: 应用级(进程级)容器长期存活; 请求级对象的派生 / 清理由后续步骤接入
 *   (`handle()` 是唯一入口, 请求级 fork/reset 与门面指针切换将落在这里, 常驻模式下每次请求都是一个干净 scope)
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
     * - **先把配置实例登记进容器**: 契约名与实现类名都能取到, 组件(含内核的参数解析器)因此按契约拿配置,
     *   不需要静态门面
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
        // 登记配置实例(按契约名 + 实现类名): 这是"依赖倒置"的落点 —— 使用者注入 `base\ConfigInterface`,
        // 拿到的是真配置, 而不是转发到全局静态的替身
        $this->container->instance(ConfigInterface::class,$this->config);
        $this->container->instance(Repository::class,$this->config);
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
        foreach(array_merge($binds,$aliases) as $class)
            if(!class_exists($class)&&!interface_exists($class))
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
        // 让门面的当前配置也是这一份: 门面的静态指针与容器里按 `base\ConfigInterface` 登记的实例
        // 必须指向同一个对象, 否则 `Config::get()` 与按契约注入拿到的会是两份不同的配置;
        // 必须排在 `App::setInstance()` 之后 —— `setRepository()` 替换的是"当前容器"里的登记
        Config::setRepository($this->config);
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
     * @access public
     * @return Repository
     */
    public function config(): Repository {
        return $this->config;
    }

    /**
     * 处理一次请求(应用级入口)
     *
     * - **每请求一个干净的请求级容器**: 从应用级容器 `fork()` 派生, 请求结束随引用释放(绑定与反射缓存仍共享)
     * - 进入请求作用域时把门面指针指向请求级容器;**不收回指针** —— 响应出口在关停阶段仍要经门面取响应对象,
     *   而下一个请求进来时会重新指向它自己的容器(常驻模式下同样安全)
     * - 请求级装配: 每请求新建请求 / 响应 / 会话;若容器(含父容器)已有登记则沿用, 便于测试与自定义入口注入
     *
     * @access public
     * @param callable $callback 请求处理过程(接收请求级容器)
     * @return mixed
     * @throws Exception
     */
    public function handle(callable $callback): mixed {
        // 上一个请求的容器回收(常驻模式下及时释放请求级对象; 首个请求时为空)
        if($this->request_container!==null)
            $this->request_container->reset();
        // 请求级 scope: 实例与全局数据独立, 绑定/别名/单例与反射缓存共享
        $container=$this->container->fork();
        $this->request_container=$container;
        // 请求开始时把 `Config::repository()` 当前返回的配置登记进请求容器(契约名与实现类名各一条):
        // 应用级容器里登记的可能已经被 `Config::setRepository()/set()` 换成新的了, 只靠父容器会让请求内的组件读到旧配置
        $repository=Config::repository();
        if($repository!==null) {
            $container->instance(ConfigInterface::class,$repository);
            $container->instance(Repository::class,$repository);
        }
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
            $session=$container->build($this->config->get('session.class',ArraySession::class));
            if($this->config->get('session.start',false))
                $session->init();
            $container->instance(AbstractSession::class,$session);
        }
    }

}
