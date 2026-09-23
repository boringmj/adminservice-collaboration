<?php

namespace AdminService\Config;

use AdminService\exception\ConfigException;
use base\ConfigInterface;

use function array_key_exists;
use function array_keys;
use function array_pop;
use function count;
use function explode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * 配置仓储
 *
 * @access public
 * @package AdminService\Config
 * @version 1.1.0
 */
final class Repository implements ConfigInterface {

    /**
     * 配置树
     * @var array<string,mixed>
     */
    private array $configs=array();

    /**
     * 平坦点分键表(含中间节点与列表下标)
     * @var array<string,mixed>
     */
    private array $flat=array();

    /**
     * `.env` 实例
     * @var Env|null
     */
    private ?Env $env=null;

    /**
     * 运行时写入的临时值(扁平点分键表, **优先级最高**)
     * @var array<string,mixed>
     */
    private array $runtime=array();

    /**
     * "有覆盖/临时值的路径"的**祖先前缀**集合(键 => true)
     *
     * - 用来回答"这个数组节点下有没有覆盖": `get()` 命中它才去合并一份子树, 否则原样返回
     *   —— 于是没有子项覆盖的数组读(绝大多数)零开销
     * @var array<string,true>
     */
    private array $coveredParents=array();

    /**
     * 装配期诊断(来自 `Loader`)
     *
     * - 为什么放在这里: 引导期日志子系统还没就绪(`log.path` 本身就来自配置), `Loader` 只能先把诊断**收集**起来;
     *   而门面只允许有"一个静态"(`Repository` 指针), 没地方挂第二份状态 —— 于是让"装配结果"连同"装配过程的告警"
     *   一起走。上层(如 `Main::init()`)在日志就绪后再统一记录一次
     * @var array<string>
     */
    private array $diagnostics=array();

    /**
     * 构造方法
     *
     * @access public
     * @param array<string,mixed> $configs 配置树(**必填**)
     * @param array<string> $diagnostics 装配期诊断
     * @param Env|null $env `.env` 快照(传了才启用"路径键覆盖")
     */
    public function __construct(array $configs,array $diagnostics=array(),?Env $env=null) {
        $this->configs=$configs;
        $this->diagnostics=$diagnostics;
        $this->env=$env;
        $this->flatten($configs,'');
        // 记下 `.env` 路径键的祖先前缀: `get()` 靠它判断数组节点要不要合并子项覆盖
        if($env!==null)
            foreach(array_keys($env->all()) as $key)
                $this->noteCoveredParents((string)$key);
    }

    /**
     * 读取配置项(生效值)
     *
     * @access public
     * @param string $key 点分键
     * @param mixed $default 默认值(键不存在或值为 null 时返回)
     * @return mixed
     */
    public function get(string $key,mixed $default=null): mixed {
        // ① 运行时写入的临时值(最高优先级)
        if(array_key_exists($key,$this->runtime)) {
            $value=$this->runtime[$key];
            // 临时值本身是数组时, 它下面更深的覆盖还要盖在上面(且不再套 `.env` —— 运行时优先级更高)
            return is_array($value)&&isset($this->coveredParents[$key])?$this->materialize($key,$value,false):$value;
        }
        if(!isset($this->flat[$key]))
            return $default;
        $file=$this->flat[$key];
        // ② 数组节点: 该路径下若有覆盖/临时值, 返回"合并后的副本";没有则原样返回(零开销)
        if(is_array($file))
            return isset($this->coveredParents[$key])?$this->materialize($key,$file,true):$file;
        // ③ 标量叶子: `.env` 的路径键覆盖
        if($this->env!==null&&$this->env->has($key))
            return $this->castOverride($this->env->get($key),$file);
        return $file;
    }

    /**
     * 读取配置项(**只看配置文件**, 不看 `.env` 覆盖)
     *
     * - 排查用: "这个值到底是文件里写的, 还是被 `.env` 覆盖了?"
     *
     * @access public
     * @param string $key 点分键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function file(string $key,mixed $default=null): mixed {
        return isset($this->flat[$key])?$this->flat[$key]:$default;
    }

    /**
     * 读取**本仓储的 `.env` 层**(`.env` 里怎么写就怎么给)
     *
     * - 只做 `Env` 解析时的那一次转换(`true` / `false` / `null` 三个词成 bool / null, 数字仍是字符串),
     *   **不**像 `get()` 那样再按配置文件里的类型转(见 `castOverride()`), 也**不**套路径键覆盖
     *   —— 同一个键: `get('database.connections.default.port')` 给 `13306`(int), 本方法给 `'13306'`(string)
     * - 读的是构造时传入的那份 `.env` 快照(`__construct()` 的第三个参数);构造时没传则本层为空, 一律回落默认值 ——
     *   与 `get()` / `all()` 在没有快照时不套 `.env` 覆盖是同一口径
     * - 与 `file()` 对称: 一个看配置文件那层, 一个看 `.env` 那层;`file()` 也不受覆盖影响
     *
     * @access public
     * @param string $key 键(大小写敏感)
     * @param mixed $default 默认值
     * @return mixed
     */
    public function env(string $key,mixed $default=null): mixed {
        return $this->env===null?$default:$this->env->get($key,$default);
    }

    /**
     * 判断配置项是否存在(口径同 `get()`: 值为 `null` 视为不存在)
     *
     * @access public
     * @param string $key 点分键
     * @return bool
     */
    public function has(string $key): bool {
        return array_key_exists($key,$this->runtime)||isset($this->flat[$key]);
    }

    /**
     * 取全部配置(**生效值**)
     *
     * - 覆盖只写回**已存在的路径**, 且**绝不新建节点** —— 所以这份结果里不会出现"只活在 `.env` 里的配置项"
     * - 想拿"纯文件树"请用 `file()` 逐项读, 或直接看 `config/*.php`
     *
     * @access public
     * @return array<string,mixed>
     */
    public function all(): array {
        // 既没有 `.env` 快照也没有临时值时, 直接给文件树(常见情况, 不复制)
        if($this->env===null&&$this->runtime===array())
            return $this->configs;
        $out=$this->configs;
        if($this->env!==null)
            foreach($this->env->all() as $key=>$raw) {
                $key=(string)$key;
                // 只覆盖已存在的标量路径(中间节点/数组/不存在的路径一律跳过)
                if(!isset($this->flat[$key])||is_array($this->flat[$key]))
                    continue;
                $this->applyOverride($out,explode('.',$key),$this->castOverride($raw,$this->flat[$key]));
            }
        // 临时值最后写回 —— 优先级最高
        foreach($this->runtime as $key=>$value)
            $this->applyOverride($out,explode('.',(string)$key),$value);
        return $out;
    }

    /**
     * 写入一个**运行时临时值**(优先级最高, 谁都盖不掉)
     *
     * - 只允许写在**已存在的路径**上: 不存在的路径属代码写错(不是配置数据的问题), 直接抛 ——
     *   既不让它变成"只活在代码里的配置项", 也不让它静默失效
     *
     * @access public
     * @param string $key 点分键
     * @param mixed $value 值
     * @return void
     * @throws ConfigException 路径在配置里不存在
     */
    public function put(string $key,mixed $value): void {
        if(!isset($this->flat[$key]))
            throw new ConfigException('配置项 "'.$key.'" 不存在, 不能写入临时值(只允许覆盖已存在的配置项)',100905);
        $this->runtime[$key]=$value;
        $this->noteCoveredParents($key);
    }

    /**
     * 把一棵配置树里**已存在的标量叶子**一次性写进临时层
     *
     * - 供 `Config::set()` 用: 让"刚 set 进去的值"赢过 `.env` 的覆盖
     *   (否则会出现"我明明 set 了却被部署侧的 `.env` 改掉")
     *
     * @access public
     * @param array<string,mixed> $configs 配置树
     * @return void
     */
    public function putAll(array $configs): void {
        $flat=array();
        $this->collectScalars($configs,'',$flat);
        foreach($flat as $key=>$value)
            if(isset($this->flat[$key])) {
                $this->runtime[$key]=$value;
                $this->noteCoveredParents((string)$key);
            }
    }

    /**
     * 把树里"不含点的标量叶子"收集成扁平表(供 `putAll()` 用)
     *
     * @access private
     * @param array<string,mixed> $data 当前层
     * @param string $prefix 前缀
     * @param array<string,mixed> $out 收集结果(引用传递)
     * @return void
     */
    private function collectScalars(array $data,string $prefix,array &$out): void {
        foreach($data as $key=>$value) {
            $key=(string)$key;
            if(str_contains($key,'.'))
                continue;
            $path=$prefix===''?$key:$prefix.'.'.$key;
            if(is_array($value))
                $this->collectScalars($value,$path,$out);
            else
                $out[$path]=$value;
        }
    }

    /**
     * 取装配期诊断(空数组表示一切正常)
     *
     * @access public
     * @return array<string>
     */
    public function diagnostics(): array {
        return $this->diagnostics;
    }

    /**
     * 记下"这条覆盖/临时值"的**所有祖先前缀**(供 `get()` 判断数组节点是否需要合并)
     *
     * @access private
     * @param string $key 被覆盖的点分键
     * @return void
     */
    private function noteCoveredParents(string $key): void {
        $segments=explode('.',$key);
        array_pop($segments);
        $prefix='';
        foreach($segments as $segment) {
            $prefix=$prefix===''?$segment:$prefix.'.'.$segment;
            $this->coveredParents[$prefix]=true;
        }
    }

    /**
     * 把 `$key` 之下的覆盖/临时值合并进 `$base` 的一份副本
     *
     * - 只在"确有子项覆盖"时才被调用(`get()` 靠 `$coveredParents` 判断), 所以这里不做省事优化
     * - `$applyEnv` 为 false 表示基准值来自运行时写入 —— 那种情况 `.env` 不该再盖上去(运行时优先级更高)
     *
     * @access private
     * @param string $key 数组节点的点分键
     * @param array<string,mixed> $base 基准值(文件树里的那份, 或运行时写入的那份)
     * @param bool $applyEnv 是否套 `.env` 的路径键覆盖
     * @return array<string,mixed>
     */
    private function materialize(string $key,array $base,bool $applyEnv): array {
        $out=$base;
        $prefix=$key.'.';
        $length=strlen($prefix);
        if($applyEnv&&$this->env!==null)
            foreach($this->env->all() as $env_key=>$raw) {
                $env_key=(string)$env_key;
                // 只覆盖已存在的标量叶子(与 `all()` 同一口径)
                if(!str_starts_with($env_key,$prefix)||!isset($this->flat[$env_key])||is_array($this->flat[$env_key]))
                    continue;
                $this->applyOverride($out,explode('.',substr($env_key,$length)),$this->castOverride($raw,$this->flat[$env_key]));
            }
        foreach($this->runtime as $runtime_key=>$value) {
            $runtime_key=(string)$runtime_key;
            if(!str_starts_with($runtime_key,$prefix))
                continue;
            $this->applyOverride($out,explode('.',substr($runtime_key,$length)),$value);
        }
        return $out;
    }

    /**
     * 把覆盖值按路径写回(仅沿已存在的路径走, 因此不会新建节点)
     *
     * @access private
     * @param array<string,mixed> $tree 目标树(引用传递)
     * @param array<string> $segments 路径段
     * @param mixed $value 覆盖值
     * @return void
     */
    private function applyOverride(array &$tree,array $segments,mixed $value): void {
        $node=&$tree;
        $last=count($segments)-1;
        for($i=0;$i<$last;$i++) {
            $segment=$segments[$i];
            if(!isset($node[$segment])||!is_array($node[$segment]))
                return;
            $node=&$node[$segment];
        }
        $leaf=$segments[$last];
        if(!isset($node[$leaf]))
            return;
        $node[$leaf]=$value;
    }

    /**
     * 按"配置文件里那个值的类型"转换覆盖值
     *
     * - 转不了就原样给出(不猜、不抛): 例如文件里是 `int` 而 `.env` 写了个非数字串
     *
     * @access private
     * @param mixed $raw `.env` 里的值(已被 `Env` 转过 bool/null)
     * @param mixed $file 配置文件里的值
     * @return mixed
     */
    private function castOverride(mixed $raw,mixed $file): mixed {
        // `.env` 里写 `null` 表示"这一项没有值": bool 项 → false;其余类型**忽略这次覆盖**(保留文件值)
        //  —— 因为 null 表达不了字符串/数字, 硬转只会给出 '' 或 0 这类假值(空串与 0 都是"真值", 更危险)
        if($raw===null&&!is_bool($file))
            return $file;
        if(is_bool($file)) {
            if(is_bool($raw)||$raw===null)
                return (bool)$raw;
            return !in_array(strtolower((string)$raw),array('false','null','0',''),true);
        }
        if(is_int($file))
            return is_numeric($raw)?(int)$raw:$raw;
        if(is_float($file))
            return is_numeric($raw)?(float)$raw:$raw;
        if(is_string($file))
            return $raw===null?'':(is_string($raw)?$raw:(string)$raw);
        return $raw;
    }

    /**
     * 递归建平坦表
     *
     * @access private
     * @param array<string,mixed> $data 当前层
     * @param string $prefix 前缀(空表示顶层)
     * @return void
     */
    private function flatten(array $data,string $prefix): void {
        foreach($data as $key=>$value) {
            $key=(string)$key;
            // 字面含 `.` 的键按路径逐段下钻取不到, 不入表
            if(str_contains($key,'.'))
                continue;
            $path=$prefix===''?$key:$prefix.'.'.$key;
            $this->flat[$path]=$value;
            if(is_array($value)&&$value!==array())
                $this->flatten($value,$path);
        }
    }

}
