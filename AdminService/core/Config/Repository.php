<?php

namespace AdminService\Config;

use AdminService\exception\ConfigException;
use base\ConfigInterface;

use function array_key_exists;
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
use function strtolower;

/**
 * 配置仓储(实例化)
 *
 * - 职责: 持有装配好的配置**数组树**, 提供点分键读取; 应用级容器持有一个实例
 * - 依赖方向: 只依赖契约 `base\ConfigInterface`(实现它)与 `.env` 快照, 不依赖容器、不依赖 `AdminService\Config`
 * - 与 `Loader` 的分工: `Loader` 负责"读配置文件", 本类负责"读取 + 生效值计算"
 *
 * ## 生效值 = 运行时写入 > `.env` 路径键 > 配置文件 > 默认值
 *
 *  - **运行时写入**(`put()` / `Config::setValue()`): 优先级**最高**, 用来临时改一项而不必重写整份配置。
 *    ⚠ 它活在**本实例**上: 应用级仓储是进程级对象, 常驻模式下临时值会跨请求保留;
 *    需要请求级隔离请 `fork()` 一个请求级容器并在其仓储上写
 *  - **`.env` 的路径键**(小写点分, 如 `database.connections.default.host=…`)覆盖同名配置项 ——
 *    用意是下游**不必改配置文件**就能覆盖任何一项
 *  - 三条边界: ①只覆盖**已存在**的路径, **绝不新建节点**(配置树的结构只由 `config/*.php` 决定 ——
 *    即"不允许虚空节点"那条约定) ②只覆盖标量叶子 ③**类型跟着配置文件里那个值走**:
 *    文件里是 `int` 就转 `int`, `bool` 按"false/null/0/空 → false, 其余 → true"折算, 转不了就原样给出
 *
 * ## 点分键口径
 *
 *  - 内部预先算一张**平坦表**(`'log.path' => 值`, 中间节点与叶子都入表), 故 `get()` 是一次查表
 *  - 表里含中间节点, 所以 `get('log')`(整棵子树)与 `get('log.path')` 都成立;列表下标也是键(`route.files.0`)
 *  - **字面含 `.` 的键不入表**: 按 `.` 逐段下钻取不到它们
 *  - `null` 视为"不存在"(值缺失), `get()` 回落默认值
 *
 * ## 其它
 *
 *  - 没有"整体替换"式的写入: 那由上层重建一个新实例来表达(`Config::set()`)
 *  - 平坦表是**代码整洁性**改进, 不是性能手段 —— 别把它当性能亮点汇报
 *
 * @access public
 * @package AdminService\Config
 * @version 1.1.0
 */
final class Repository implements ConfigInterface {

    /**
     * 配置树(纯配置文件的结果, 不含 `.env` 覆盖)
     * @var array<string,mixed>
     */
    private array $configs=array();

    /**
     * 平坦点分键表(含中间节点与列表下标)
     * @var array<string,mixed>
     */
    private array $flat=array();

    /**
     * `.env` 快照(用于"路径键覆盖"; 没有则为 null —— 此时 `get()` 不套 `.env` 覆盖)
     * @var Env|null
     */
    private ?Env $env=null;

    /**
     * 运行时写入的临时值(扁平点分键表, **优先级最高**)
     * @var array<string,mixed>
     */
    private array $runtime=array();

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
        if(array_key_exists($key,$this->runtime))
            return $this->runtime[$key];
        if(!isset($this->flat[$key]))
            return $default;
        $file=$this->flat[$key];
        if($this->env!==null&&!is_array($file)&&$this->env->has($key))
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
        if($this->env===null)
            return $this->configs;
        $out=$this->configs;
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
            if(isset($this->flat[$key]))
                $this->runtime[$key]=$value;
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
