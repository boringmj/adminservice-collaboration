# AdminService-Collaboration

## 该如何开始?

在开始之前,您应该需要注意\
本项目要求 PHP 版本不低于 `8.2.0`\
如果您在开发中有新增文件, 请确保您已经使用 [composer](https://www.phpcomposer.com/) 更新 `composer update`, 新增的文件如果没有及时更新, 新增的程序将无法通过 autoload 自动加载

您可以简单阅读下面的内容, 或者点击 [我该如何开始?](https://github.com/boringmj/adminservice-collaboration/wiki/准备) 阅读更详细的信息, 并帮助你编写第一个程序!

1. 您应该先下载或 `clone`(推荐) 本项目至本地

    ```bash
    git clone https://github.com/boringmj/adminservice-collaboration.git
    ```

1. 通过 [composer](https://www.phpcomposer.com/) 安装依赖, 如果您没有下载 [composer](https://www.phpcomposer.com/), 您可以前往 [composer 官网](https://www.phpcomposer.com/) 获取帮助

    ```bash
    // 前往项目路径
    cd adminservice-collaboration
    // 开发或测试环境直接使用 composer 安装依赖
    composer install
    // 生产环境下, 建议使用下面的命令
    composer install --optimize-autoloader --no-dev
    ```

1. 启动开发服务器, 框架提供统一命令行入口

    ```bash
    // 需要配置 php 环境变量且 php>=8.2.0
    php console server start
    // 也可以使用快捷命令或分组启动器
    php console start
    php bin/server start
    ```

1. 访问 `localhost:8000`, 至此,您已经可以正常进行开发了\
如果您需要更多帮助,可以前往 [Wiki](https://github.com/boringmj/adminservice-collaboration/wiki/准备), 在那里有更加详细的教程和文档

## 命令行

统一入口是项目根目录的 `console`。无参数执行 `php console` 时展示命令列表, 不会启动 Web 服务。

```bash
php console list                 # 列出全部命令
php console list cache           # 查看 cache 分组
php console cache                # 查看 cache 分组帮助
php console help server start    # 查看指定命令帮助
php console server start         # 启动 PHP 内置 Web 服务
php console cache status         # 查看编译缓存状态
php console cache build          # 预热编译缓存
php console cache build --force  # 强制重建编译缓存
php console cache clear          # 清理编译缓存
```

`bin/<组名>` 是薄启动器, 只把组名转发给根 `console`:

```bash
php bin/server start             # 等价于 php console server start
php bin/cache status             # 等价于 php console cache status
php bin/cache                    # 等价于 php console cache, 展示组帮助
```

命令路径支持空格和冒号两种写法, 也支持命令别名与根级快捷绑定:

```bash
php console server:start         # server start
php console serve                # server start 的别名
php console start                # config/console.php 中的快捷绑定
php console cache:build          # cache build
```

自定义命令使用显式登记, 不扫描目录。推荐放在 `AdminService/app/{app}/command/`, 并在 `AdminService/config/console.php` 的 `commands` 中登记类名:

```php
'commands'=>array(
    \app\demo\command\HelloCommand::class,
),
```

命令类需实现 `base\CommandInterface`(通常继承 `base\AbstractCommand`), 业务逻辑放在 `execute(Input $input,Output $output): int`。命令通过 `valueOptions()` 声明需要接收值的选项, 通过 `booleanOptions()` 声明布尔选项; 未知选项与缺少值会返回退出码 `2`。内置命令与自定义命令都复用 `AdminService\Config\Bootstrapper`, 不会注册 HTTP 错误出口, 也不发送 HTTP 响应。完整说明见 Wiki [命令行](https://github.com/boringmj/adminservice-collaboration/wiki/命令行)。

### 编译缓存命令

- `cache status [all|config|routes|container]` 显示状态: `disabled` / `missing` / `valid` / `stale` / `corrupted`; 查询本身不会创建配置快照。
- `cache build` / `cache clear` 只操作 `runtime/cache/compile`, 不会清理 `runtime/cache/kv` 业务缓存池。
- 存在 `.env` 时配置编译缓存固定关闭; `cache build config` 会清理旧快照并输出 `[SKIP]`, 这是预期的安全行为。
- `cache build routes` / `cache build container` 可预热路由与容器编译缓存; 路由包含闭包、对象处理器等不可导出结构时输出 `[SKIP]` 并回退实时装配。
- `--force` / `-f` 只影响本次命令的构建, 不修改 `.env` 或配置文件。

## 从旧版升级

- PHP 最低版本为 `8.2.0`; 框架支持的 HTTP 入口是 `AdminService\Main`, 它在 `init()` 检查版本; 旧版入口/扩展若直接使用 `Application`, 需自行保证版本检查。
- 内容协商默认 `response.default.negotiation=strict`: 有正文的 `2xx` 没有可接受类型时返回 `406 text/plain`; 需要旧版回落行为时设为 `fallback`; 设为 `off` 则关闭自动协商, 不读取 `Accept`、不返回 `406`、不自动追加 `Vary`, 未显式指定类型时按类型表第一个具体类型(当前 `text/html`)渲染。
- `404` / `405` 固定返回 `text/plain`, 不再参与内容协商; 自动 `OPTIONS` 会列入 `Allow`。
- 自动注入的 `bool proxy` 第二参数迁移为 `AutowireMode`; `#[AutowireMethod]` 不再承担生命周期语义, 请改用 `#[AutowireLifecycle]`。旧写法保留一个版本的过渡兼容。
- 旧版约定式路由已移除, 改用显式路由或控制器属性路由。
- 旧根目录 `start` 脚本已移除, 请改用 `php console server start`、`php console start` 或 `php bin/server start`; 完整命令见 Wiki [命令行](https://github.com/boringmj/adminservice-collaboration/wiki/命令行)。
- 完整破坏性变更与迁移说明见 Wiki [新旧版本调整](https://github.com/boringmj/adminservice-collaboration/wiki/新旧版本调整)。

## 路由

路由由`AdminService\Route`(继承`base\Route`)实现, 支持两种方式

**显式路由(默认)**: 在 [AdminService/routes/](AdminService/routes) 目录下按应用拆分注册, 目录内全部 `.php` 会被加载; 支持请求方法、路径参数、分组、资源路由与命名\
路由文件须返回接收路由表的闭包(显式传参, 便于静态分析)

```php
// AdminService/routes/demo.php
use AdminService\Router\Router;

return function(Router $router): void {

    $router->get('/user/{id:\d+}', array(\app\demo\controller\Index::class,'index'))->name('user.show');
    $router->post('/user', array(\app\demo\controller\Index::class,'request'));

    $router->group(array('prefix'=>'/api/v1'), function(Router $router): void {
        $router->get('/profile', array(\app\demo\controller\Index::class,'request'));
    });

};
```

路由文件列表由配置项 `route.files` 指定, 可写目录(加载其中全部 `.php`, 按文件名排序)或具体文件路径\
路径参数支持 `{name}` / `{name:约束}` / `{name?}`(可选, 须位于末尾); 路径参数写入请求的 `attributes` 区(不并入查询串), 合并取值 `$request->param('id')` 时优先于同名查询参数\
也可在控制器上就近声明路由(类级 `#[RouteGroup]` 定前缀与中间件, 方法级 `#[Route]` 定子路径/命名/中间件, 可重复声明), 框架自动扫描控制器目录注册(规则见 [Wiki](https://github.com/boringmj/adminservice-collaboration/wiki/开始#属性路由自动扫描))

```php
use base\Attribute\Middleware;
use base\Attribute\Route;
use base\Attribute\RouteGroup;

#[RouteGroup('/index')]                     // 类级: 前缀 + 中间件
#[Middleware(AuthMiddleware::class)]        // 类级: 控制器级中间件
class Index {

    #[Route('GET','/{name?}',name:'index.home')]
    public function index(string $name="World"): string { ... }
}
```

中间件四级, 由外向内: 请求(`middlewares.request`, 在路由匹配前执行, 未命中的请求同样经过)→ 分组 → 路由 → 控制器(`middlewares.controller` 与 `#[Middleware]`); 同层可用 `array('middleware'=>类名或实例,'priority'=>10)` 声明**层内优先级**(数值大者靠外, 同值保持声明顺序), 优先级不跨层\
未命中即返回 `404`; 路径存在但方法不符返回 `405` 并带 `Allow` 头; `OPTIONS` 以 `204` 应答; `HEAD` 由 `GET` 承接且不输出响应体\
您可以在`AdminService/Main.php`中查看路由的引用

```php
public function run(): void {
    $middlewares=(array)Config::get('middlewares.request',array());
    (new Pipeline($middlewares,App::get(Request::class)))->then(function(): void {
        App::fresh(Route::class)->run();
    });
}
```

如果路由并不适用于您的项目,你可以自由创建一个适用的路由类,并在`AdminService/Main.php`中引入您的类,并实例化

## 响应协商

`prepare()` 依据请求 `Accept` 从 `response.default.type` 选择响应类型；默认 `response.default.negotiation=strict`，有正文的 `2xx` 没有可接受类型时返回 `406 text/plain`，设为 `fallback` 则回落到默认具体类型，设为 `off` 则跳过自动协商(不读取 `Accept`、不返回 `406`、不自动追加 `Vary`)。协商只作用于未显式指定内容类型的响应；`json()` / `html()` / `text()` / `type()` / `contentType()` 显式指定类型时始终优先。vendor 类型 `application/xxx+json` 与 `application/json` 等价，`Accept: application/*+json` 也兼容标准 JSON；无 `Accept` 或空正文的 `2xx` 不触发 `406`。`404` / `405` 固定为 `text/plain`，自动 `OPTIONS` 会列入 `Allow`。详见 Wiki [返回类型](https://github.com/boringmj/adminservice-collaboration/wiki/返回类型)

## 配置注入

用 `#[Config('配置键')]` 把**配置项的值**(而不是配置对象)注入进来, 不必再到处 `Config::get(...)`\
键为点分路径, 可给默认值;标量按容器既有规则静默转换(如配置里是 `int`、目标类型是 `string`)\
对象由容器构建时生效(`App::make()` / `App::get()` / `App::fresh()` 等);三种写法:

```php
use base\Attribute\Config;

class Demo {

    #[Config('data.path')]                       // ① 属性
    private string $dataPath='';

    #[Config('not.exist.key',default:'fallback')] // 注解默认值(键缺失时用)
    private string $fallback='';

    public function __construct(
        #[Config('data.name_cycle',default:0)] int $cycle=0   // ③ 构造函数形参
    ) {}

    #[Config('log.path')]                        // ② Setter:方法唯一的形参收到该值
    public function setLogPath(string $path): void {}
}
```

- 键缺失时的兜底顺序:注解 `default:` → **目标自身的默认值**(属性默认值 / 形参默认值)→ `null`(取到 `null` 而类型不可空会抛 `TypeError`, 请显式给 `default:` 或写成可空类型)
- 支持范围:属性 / Setter 方法 / 构造函数形参 / 方法或函数形参(见下)
- **生效条件**:凡是由框架解析实参的地方都会生效 —— 构造函数、`#[AutowireLifecycle]` / `#[AutowireMethod]` 方法,以及经 `App::exec_class_function()` / `exec_function()` 调用的方法与函数(控制器由框架经前者调用,所以控制器方法形参上的标注同样生效);框架不参与解析时(你自己直接调方法)自然不生效
- **实参优先**:具名实参 / 顺位实参 > `#[Config]` > 按类型注入 —— 路由参数与配置键同名时路由参数优先,因此"控制器形参只注入路由参数"的既有语义不变(**未标注**的形参不会拿到配置值)
- **不能与 `#[AutowireProperty]` / `#[AutowireSetter]` / `#[AutowireLifecycle]` / `#[AutowireMethod]` 同挂一处**:语义冲突, 会抛 `AutowireException`;`#[AutowireSetter]` 方法的**形参上**标 `#[Config]` 同样报错(那条路径按类型注入服务对象)
- 配置对象本身可按契约注入:依赖 `base\ConfigInterface` 即可 —— 引导期把**当前配置实例按实现类名**(`AdminService\Config\Repository`)登记进应用级容器,再由 `config/app.php` 的 `app.alias` 把契约名指向它(实例不占契约名,换实现只改那条别名)

## 自动注入

`AdminService\Container` 在构建对象时会按注解完成属性 / Setter / 生命周期 / 单依赖方法注入。模式由目标注解的 `mode:` 参数指定:

```php
use AdminService\DynamicProxy;
use base\Attribute\AutowireLifecycle;
use base\Attribute\AutowireMethod;
use base\Attribute\AutowireMode;
use base\Attribute\AutowireProperty;
use base\Attribute\AutowireSetter;

class Demo {

    #[AutowireProperty(Service::class)]                       // 直接注入
    private Service $service;

    #[AutowireProperty(Service::class, mode: AutowireMode::Proxy)]
    private DynamicProxy $proxy;                              // 惰性代理

    #[AutowireProperty(Service::class, mode: AutowireMode::Deferred)]
    private Service $deferred;                                // 根对象登记后注入

    #[AutowireSetter(Service::class)]                         // 单参数 Setter
    public function setService(Service $service): void {}

    #[AutowireLifecycle]                                      // 生命周期: 参数按类型注入
    public function boot(Service $service): void {}

    #[AutowireMethod(Service::class)]                         // 单依赖方法: 必须显式给类名
    public function injectService(Service $service): void {}
}
```

- `AutowireMode::Normal`: 立即解析并注入真实对象。
- `AutowireMode::Proxy`: 注入 `DynamicProxy`。必须显式给类名, 且声明类型必须是 `DynamicProxy`、`mixed` / `object`、未声明类型, 或包含 `DynamicProxy` 的联合类型; 具体类型 `A` 与交叉类型 `A&DynamicProxy` 都不满足(前者不是 `DynamicProxy`, 后者要求对象同时是 `A`, 而代理只是包装器), 会抛 `AutowireException`。代理首次使用时无构造参数走容器 `get()`(复用实例), 有构造参数走 `build()`(不登记)。
- `AutowireMode::Deferred`: 当前对象完成属性 / Setter 注入并登记后, 再由容器统一刷新真实对象。适合打断属性初始化顺序, 但 Setter / 方法的副作用也会被延后。根构建(包括延迟刷新)失败时会回滚本轮新增的实例登记, 避免半装配对象被后续调用复用。
- `#[AutowireLifecycle]` 不接受模式; 生命周期方法不允许 `Proxy` / `Deferred`。`#[AutowireMethod]` 只保留显式单依赖方法语义, 无 `name` 的旧生命周期写法仅作过渡兼容。
- `#[Config]` 不能与上述自动注入目标注解同挂一处。

## 配置与 `.env`

配置的**结构**一律由 `AdminService/config/*.php` 声明,`.env` 只用来给值 / 覆盖值。`.env` 里的键分两类:

| `.env` 里的键 | 作用 | 生效方式 |
| --- | --- | --- |
| **含点的路径键** `database.connections.default.host` | **覆盖**同名配置项 | 装载时即合并进生效值,`Config::get()` 自动生效 |
| **不含点的键** `DB_HOST` | 一个**值来源** | 配置文件里 `env('DB_HOST', 默认值)` 读它 |

第一类的用意:下游项目**不必改框架的配置文件**就能覆盖任何一项 —— 于是上游更新配置时不会与你的改动冲突。
它**只覆盖已存在的路径,绝不新建节点**(配置树的结构只由 `config/*.php` 决定),所以**路径必须写全**;
路径不存在、指向数组节点、或值与配置文件里的类型不符,都会在装配时抛异常(不静默跳过)。

第二类在配置文件里取值:

```php
// AdminService/config/app.php
use function AdminService\env;                 // 取值入口在 `AdminService\env`

return array(
    'debug'=>env('APP_DEBUG',false),           // 缺失就用默认值(框架不替使用者判断"该不该有这个键")
    'port'=>(int)env('DB_PORT',3306),           // 数字要自己转:`.env` 的值只转 bool/null
);
```

- **键名大小写敏感**;点号在解析层只是普通字符,但**含点的键会被当作配置路径**去覆盖(见上表) —— 它必须命中已存在的标量项,否则装配时报错
- **值的类型**:`true`/`false`/`null`(大小写不敏感)会转成 bool/null,**数字保持字符串**;要数字请在配置里显式 `(int)` / `(float)`
- **值的写法**:支持引号(`DB_PASSWORD="a=b # c"` —— 值里有 `=`、空格、`#` 时用得上)、`export` 前缀、整行与行尾注释(`#` 前要有空白)
- `.env` 缺失是正常的(它不入库),此时全部配置项用配置文件里的值
- **生效值的优先级**:运行时写入 → `.env` 路径键 → 配置文件 → 默认值
- **运行时写入**:`Config::setValue('log.path','/tmp/x')` 在生效值上就地改一项(不必重写整份配置),优先于 `.env` 与配置文件;只允许写在**已存在的配置项**上(写错路径直接报错)
- **`null` 也是一个值**:配置文件里写 `'x'=>null`(`.env` 里写 `X=null` 喂进去也一样),`Config::get('x','默认')` 给 `null` —— 只有**键缺失**才回落默认值。要"缺了就用默认值"就别声明这个键
- 排查"这个值到底来自哪":`Config::get()` 给**生效值**,`Config::config_raw()` 给**配置文件里的原值**,`Config::env()` 给**当前配置的 `.env` 层**里的原值(`AdminService\env()` 则直接读进程级 `.env`,与门面里是哪份配置无关)—— 后两者各看一层,不受覆盖影响
- **装配在哪里**:引导期 `Main::init()` 读取 `AdminService/config/*.php` 装配出一份配置,再调用 `Config::setRepository()` 把它设为当前配置(同时替换容器里按实现类名登记的那份实例)—— 门面不决定读哪个目录;自定义入口要指向别的目录,自行 `new Loader($dir)` + `new Repository(...)` 再 `Config::setRepository()`
- **用代码里的配置树**:`Config::setRepository(Config::new($tree))` —— `Config::new()` 只构建、不设置;`$merge_env` 默认 `false`(这份树就是生效值),传 `true` 则让 `.env` 的路径键照旧覆盖树里的标量叶子
- 覆盖的类型跟着配置文件里那个值走:文件里是 `int` 就把 `.env` 的字符串转成 `int`(否则 `port` 会变成字符串),`bool` 按"false/null/0/空 → false,其余 → true"判定;两者对不上(如 `int` 项写了非数字)会在装配时抛异常,不会静默保留文件值
- **部署前建议核对一遍**:`function.loader` 的条目是否都在、要写的目录是否可写 —— 这些是**事实**检查,不涉及"猜你的意图"(例如不该去查"某个大写键有没有被引用" —— `env()` 也会在代码里用、键还可能动态构造,那种静态推断必然误报)。`.env` 的语法错、路径键写错、类型不符已由装配期报错,不必再靠静态检查

## 运行时目录

运行期可写文件默认集中在 `AdminService/runtime`:

```text
AdminService/runtime/
  cache/      # 可安全清理: kv/ 通用缓存、compile/ 编译缓存、File 周期缓存
  data/       # 持久数据
  log/        # 日志
  uploads/    # 上传文件
```

- runtime 根由 `.env` 的 `RUNTIME_PATH` 提供; 缺失、空值或非法值时使用默认 `AdminService/runtime`
- `RUNTIME_PATH` 在配置装载前解析, 不依赖 `Config`; 不提供 `runtime.path` 配置键
- `runtime/cache` 可清空; `runtime/data` / `runtime/log` / `runtime/uploads` 不可随意清空
- runtime 不要放在 `public` 下, 避免被 Web 服务器直接暴露

### 缓存

通用缓存的契约是 `base\CacheInterface`, 默认实现是 `AdminService\Cache`。容器已注册 `\base\CacheInterface => \AdminService\Cache`, 可直接注入:

```php
use base\CacheInterface;

public function __construct(private CacheInterface $cache) {
}

$cache->set('user:1',$data,600);       // 10 分钟
$cache->forever('site:name','demo');   // 永不过期
$value=$cache->remember('stats',60,function(): array {
    return array('visits'=>1);
});
```

- 单键独立 TTL; `$ttl` 为 `null` 使用 `cache.default_ttl`, `<=0` 永不过期
- `remember($key,$ttl,$callback,$version)` 支持版本指纹, 版本变化时立即回源
- `scope('auth')` 分池; `clear()` 只清当前池
- 写入时按 `cache.gc_probability / cache.gc_divisor` 概率触发 GC, 受 `cache.gc_max_files` 单次上限保护
- 路由编译缓存已接入 `runtime/cache/compile/routes.cache.php`: `auto` 生产零探测、开发期按源文件指纹失效; 命中时跳过控制器扫描、属性反射与冲突检测；缓存命中时只保存路由数据，匹配命中后才构造一条 `RouteItem`，`getRoutes()` 才全量实例化 `RouteItem`，`export()` 直接返回已保存的路由数据
- 配置编译缓存已接入 `runtime/cache/compile/config.cache.php`: 引导期优先读取，命中时跳过配置文件扫描与合并；存在 `.env` 时固定关闭，避免缓存 `env()` 物化的敏感值。引导期配置快照固定写在 runtime；即使自定义 `cache.path`，清理接口也会覆盖该快照
- 容器编译缓存已接入 `runtime/cache/compile/container.cache.php`: `auto` 生产零探测，debug / `always` 按类自身、父类与 trait 源文件指纹校验；缓存属性/Setter/生命周期方法元数据与方法参数描述符，无注解类不再扫描全部反射成员；命令行预热只覆盖构造函数、路由动作方法与中间件 `handle`，动态注入/生命周期方法首次解析时可能补写
- debug + `auto` 下默认只跳过配置编译缓存，路由/容器编译缓存继续命中；可通过 `CACHE_COMPILE_SKIP_ON_DEBUG` 调整跳过名单
- 默认实现 `AdminService\Cache` 额外提供 `clearCompile()` / `clearAll()`; 需要清理系统编译缓存时按具体实现取用
- 编译缓存可由命令行检查与预热: `php console cache status`、`php console cache build`、`php console cache clear`; 详见 Wiki [命令行](https://github.com/boringmj/adminservice-collaboration/wiki/命令行)
- 目录与配置说明见 `wiki/缓存.md`

## 未来

我们将逐步构建出一个完善的轻量级快速响应框架,这可能需要非常久的时间\
我们由衷的希望大家提出意见,也由衷的欢迎大家加入到我们的开发之中\
如果您有疑问或其他事宜,请向`wuliaodemoji@wuliaomj.com`发送邮件,并不保证回复和解决

## 本项目有AI参与

由于需求与野心日益增长,独立维护已越发困难,因此本仓库已经正式引入AI参与设计开发\
如果您介意AI参与的项目,可以选择其他成熟框架

**AI生成代码非全量人工复审**:由于精力有限,无力全审,只会审核关键性代码和查阅AI报告,其余交由独立agent审阅(非同一个agent自审,通过自写skills审查)\
因此相关模块**不完全保证代码质量和代码安全**,只能做到尽力而为。
