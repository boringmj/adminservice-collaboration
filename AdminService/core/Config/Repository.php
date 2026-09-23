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
use function strtolower;

/**
 * 配置仓储
 *
 * - **装载时就把 `.env` 的路径键覆盖合并进生效树**(`$effective`), 之后运行期只有这一份真相:
 *   `get()` / `all()` / `put()` 都在它上面, 因此"读数组节点"与"读叶子"不可能给出不同答案
 * - `$file` 是**冻结的文件原值**(构造后再不写), 只给 `file()` 排查用;`env()` 读 `.env` 文件层
 * - 生效值的优先级(装载期合成一份): 运行时写入(`put()`) > `.env` 路径键 > 配置文件 > 默认值
 * - 只覆盖**已存在的标量叶子**, **绝不新建节点**(配置树的结构只由 `config/*.php` 决定);
 *   类型跟着配置文件里那个值的类型(见 `castOverride()`)
 * - 点分键按 `.` 逐段下钻;**字面含 `.` 的键取不到**(但仍原样留在 `all()` 里)
 * - `null` 视为不存在(`get()`/`file()`/`has()` 都按这条口径)
 *
 * @access public
 * @package AdminService\Config
 * @version 2.0.0
 */
final class Repository implements ConfigInterface {

    /**
     * 生效值(装载时已合并 `.env` 覆盖;运行期 `put()` 就地写它)
     * @var array<string,mixed>
     */
    private array $effective=array();

    /**
     * 冻结的文件原值(构造后再不写;只给 `file()` 用)
     * @var array<string,mixed>
     */
    private array $file=array();

    /**
     * `.env` 实例(没有则为 null —— 那时 `.env` 那层视为空)
     * @var Env|null
     */
    private ?Env $env=null;

    /**
     * 装配期诊断(来自 `Loader`)
     * @var array<string>
     */
    private array $diagnostics=array();

    /**
     * 构造方法
     *
     * @access public
     * @param array<string,mixed> $configs 配置树(**必填**)
     * @param array<string> $diagnostics 装配期诊断
     * @param Env|null $env `.env` 实例(传了才启用"路径键覆盖")
     */
    public function __construct(array $configs,array $diagnostics=array(),?Env $env=null) {
        $this->file=$configs;
        $this->effective=$configs;
        $this->diagnostics=$diagnostics;
        $this->env=$env;
        // 装载期合并: 只写已存在的标量叶子(数组节点与不存在的路径一律跳过), 类型跟着文件里的值
        if($env!==null)
            foreach($env->all() as $key=>$raw) {
                $segments=explode('.',(string)$key);
                $found=null;
                if(!$this->lookup($this->effective,$segments,$found)||is_array($found))
                    continue;
                $this->writeNode($this->effective,$segments,$this->castOverride($raw,$found));
            }
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
        $found=null;
        return $this->lookup($this->effective,explode('.',$key),$found)?$found:$default;
    }

    /**
     * 读取配置项(**只看配置文件**, 不看 `.env` 覆盖与运行时写入)
     *
     * - 排查用: "这个值到底是文件里写的, 还是被覆盖了?"
     *
     * @access public
     * @param string $key 点分键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function file(string $key,mixed $default=null): mixed {
        $found=null;
        return $this->lookup($this->file,explode('.',$key),$found)?$found:$default;
    }

    /**
     * 读取**本仓储的 `.env` 层**(`.env` 里怎么写就怎么给)
     *
     * - 只做 `Env` 解析时的那一次转换(`true` / `false` / `null` 三个词成 bool / null, 数字仍是字符串),
     *   不再按配置文件里的类型转(见 `castOverride()`), 也不套路径键覆盖
     * - 与 `file()` 对称: 一个看配置文件那层, 一个看 `.env` 那层
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
        $found=null;
        return $this->lookup($this->effective,explode('.',$key),$found);
    }

    /**
     * 取全部配置(**生效值**)
     *
     * - 就是那棵生效树: 装载时已合并 `.env` 覆盖, 运行期写入也落在它上面
     * - 想拿"纯文件树"请用 `file()` 逐项读, 或直接看 `config/*.php`
     *
     * @access public
     * @return array<string,mixed>
     */
    public function all(): array {
        return $this->effective;
    }

    /**
     * 在生效值上**就地改一项**(不必重写整份配置)
     *
     * - 只允许写在**已存在的路径**上: 不存在的路径属代码写错(不是配置数据的问题), 直接抛
     * - 就地写进生效树 ⇒ 读父路径、读叶子、`all()` 三处立刻一致
     *
     * @access public
     * @param string $key 点分键
     * @param mixed $value 值
     * @return void
     * @throws ConfigException 路径在配置里不存在
     */
    public function put(string $key,mixed $value): void {
        $segments=explode('.',$key);
        $found=null;
        if(!$this->lookup($this->effective,$segments,$found))
            throw new ConfigException('配置项 "'.$key.'" 不存在, 不能就地改这一项(只允许写在已存在的配置项上)',100905);
        $this->writeNode($this->effective,$segments,$value);
    }

    /**
     * 把一棵配置树里**已存在的标量叶子**一次性写进生效树
     *
     * - 供 `Config::set()` 用: 让"刚 set 进去的值"赢过 `.env` 的覆盖
     *
     * @access public
     * @param array<string,mixed> $configs 配置树
     * @return void
     */
    public function putAll(array $configs): void {
        $flat=array();
        $this->collectScalars($configs,'',$flat);
        foreach($flat as $key=>$value) {
            $segments=explode('.',(string)$key);
            $found=null;
            if(!$this->lookup($this->effective,$segments,$found)||is_array($found))
                continue;
            $this->writeNode($this->effective,$segments,$value);
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
     * 按路径段取节点(纯读)
     *
     * - **不复制数据**: 内部 `$node=$tree` 只是引用计数共享, 全程不写, 因此不会触发写时复制
     * - `null` 视为不存在 → 返回 false
     *
     * @access private
     * @param array<string,mixed> $tree 树
     * @param array<string> $segments 路径段
     * @param mixed $found 找到的节点(引用传递)
     * @return bool
     */
    private function lookup(array $tree,array $segments,mixed &$found): bool {
        $node=$tree;
        foreach($segments as $segment) {
            if(!is_array($node)||!array_key_exists($segment,$node))
                return false;
            $node=$node[$segment];
        }
        if($node===null)
            return false;
        $found=$node;
        return true;
    }

    /**
     * 沿已存在的路径写回(不新建节点)
     *
     * @access private
     * @param array<string,mixed> $tree 树(引用传递)
     * @param array<string> $segments 路径段
     * @param mixed $value 值
     * @return void
     */
    private function writeNode(array &$tree,array $segments,mixed $value): void {
        $node=&$tree;
        $last=count($segments)-1;
        for($i=0;$i<$last;$i++) {
            $segment=$segments[$i];
            if(!isset($node[$segment])||!is_array($node[$segment]))
                return;
            $node=&$node[$segment];
        }
        $leaf=$segments[$last];
        if(!array_key_exists($leaf,$node))
            return;
        $node[$leaf]=$value;
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

}
