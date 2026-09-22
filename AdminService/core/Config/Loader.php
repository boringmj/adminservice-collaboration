<?php

namespace AdminService\Config;

use AdminService\exception\ConfigException;

use function basename;
use function count;
use function explode;
use function gettype;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_file;
use function preg_match;
use function scandir;
use function sort;
use function str_ends_with;
use function strtolower;

/**
 * 配置加载器(实例化)
 *
 * - 职责: 读 `config/*.php`(+ 可选的 `.env` 合并), 产出**尚未包进 `Repository` 的配置树**
 * - 依赖方向: 不依赖容器、不依赖 `AdminService\Config`; 只依赖同目录的 `Env` 与配置域异常
 * - 诊断不落日志: 引导期日志子系统还没就绪(`log.path` 本身来自配置), 故只**收集**诊断,
 *   由上层决定怎么呈现(见 `diagnostics()`)。这也是"引导期 fail-fast 抛 `ConfigException`"的同一个理由
 *
 * ## 两份来源
 *
 *  1. **配置文件**: 目录扫描(`scandir` + 过滤扩展名; 不用 `glob`, 见 `sourceFiles()` 的实测口径)或**显式清单**。
 *     两种方式都按文件名的 `basename` 作为键(`app.php` → `app`), 且都要求名字只含字母/数字/下划线
 *     (旧实现同样跳过不合规的名字, 但**不告诉任何人**; 本类会记一条诊断)
 *  2. **`.env`**: 可选。合并口径见下
 *
 * ## `.env` 合并口径(过渡期, 计划 S8 会连这条路径一起删掉)
 *
 *  - 键先**整体小写**再按 `.` 切段, 逐段下钻; 段不存在则新建数组节点 —— 即 `.env` 是"点分键覆盖通道"
 *  - 值按**字符串**写入(`Env::raw()`), **仅当**目标节点原本是布尔时套用旧换算:
 *    `false`/`null`/`0`(大小写不敏感) → `false`, 其余一律 `true`。这条与 `.env.example` 的
 *    "值只能是字符串和布尔值"一致, 故过渡期原样保留; S8 迁到 `env()` 后不再存在
 *  - **键在配置里不存在**时怎么办, 由 `setUnknownKeyPolicy()` 决定。默认 `UNKNOWN_KEY_CREATE`
 *    —— 即**旧行为**(静默新建节点), 这样 S3–S7 的行为完全不变; 是否改成 `IGNORE`/`THROW`
 *   就是计划里 D4 那个待用户拍板的问题, 本类把三种策略都实现好并各有用例
 *  - 路径冲突(中间段已是标量、或标量覆盖整棵子树)不再"留半个节点": 记诊断并跳过/照写, 见 `assign()`
 *
 * ## 关于"虚空节点"的历史约定(2026-09-23 用户补记, 待重新评估)
 *
 *  本框架早期的约定是 **不允许虚空节点** —— 所有配置项都必须**显式出现在 `config/*.php`** 里,
 *  `.env` **只做覆盖、不做新增**。理由: 避免过度依赖 `.env` —— 若某个配置项只活在 `.env` 里,
 *  普通用户翻配置文件时既找不到它、也读不到它的说明, 会造成困惑。
 *
 *  —— 而旧 `Config::load()` 里那句 `if(!isset($config[$n])) $config[$n]=array();` **恰恰违背了这条约定**:
 *  它把不认识的键静默建成"虚空节点"。这就是评估报告 A5 的由来, 也是线上"`.env` 键名写错却静默失效
 *  (最终表现为 `Database connection failed.`)"的根因。
 *
 *  —— 要不要恢复这条约定, 见计划 D4: 恢复 = 选 `UNKNOWN_KEY_IGNORE`(不建节点、只记诊断)或
 *  `UNKNOWN_KEY_THROW`(直接报错)。本节留档只为日后回看, 不代表当前已改。
 *
 * @access public
 * @package AdminService\Config
 * @version 1.0.0
 */
final class Loader {

    /**
     * 未知键策略: 静默新建节点(旧行为, 默认)
     * @var string
     */
    public const UNKNOWN_KEY_CREATE='create';

    /**
     * 未知键策略: 不新建节点, 记一条诊断
     * @var string
     */
    public const UNKNOWN_KEY_IGNORE='ignore';

    /**
     * 未知键策略: 直接抛 `ConfigException`(fail-fast)
     * @var string
     */
    public const UNKNOWN_KEY_THROW='throw';

    /**
     * 配置目录
     * @var string
     */
    private string $dir;

    /**
     * 显式文件清单(null = 目录扫描)
     * @var array<string>|null
     */
    private ?array $files=null;

    /**
     * `.env` 路径(null = 不合并)
     * @var string|null
     */
    private ?string $env_file=null;

    /**
     * 未知键策略
     * @var string
     */
    private string $unknown_key_policy=self::UNKNOWN_KEY_CREATE;

    /**
     * 诊断信息(引导期不落日志, 只收集)
     * @var array<string>
     */
    private array $diagnostics=array();

    /**
     * 本次实际加载的配置文件
     * @var array<string>
     */
    private array $loaded=array();

    /**
     * 本次解析出的 `.env`(未启用 `.env` 时为 null)
     * @var Env|null
     */
    private ?Env $env=null;

    /**
     * 构造方法
     *
     * @access public
     * @param string $dir 配置目录
     * @param array<string>|null $files 显式文件清单(条目为配置目录下的**文件名**, 带不带 `.php` 都可); 传 null 则扫描目录
     */
    public function __construct(string $dir,?array $files=null) {
        $this->dir=rtrim($dir,'/\\');
        $this->files=$files;
    }

    /**
     * 设置 `.env` 路径
     *
     * @access public
     * @param string|null $path `.env` 路径; null 表示不合并
     * @return void
     */
    public function setEnvFile(?string $path): void {
        $this->env_file=$path;
    }

    /**
     * 设置未知键策略(取值见本类常量)
     *
     * @access public
     * @param string $policy 策略
     * @return void
     * @throws ConfigException 策略取值非法
     */
    public function setUnknownKeyPolicy(string $policy): void {
        $allowed=array(self::UNKNOWN_KEY_CREATE,self::UNKNOWN_KEY_IGNORE,self::UNKNOWN_KEY_THROW);
        if(!in_array($policy,$allowed,true))
            throw new ConfigException('未知的 .env 未知键策略 "'.$policy.'"(可选: '.implode(' / ',$allowed).')',100902);
        $this->unknown_key_policy=$policy;
    }

    /**
     * 加载配置
     *
     * @access public
     * @return array<string,mixed> 配置树
     * @throws ConfigException 未知键策略为 THROW 且 `.env` 里出现了配置中不存在的键
     */
    public function load(): array {
        $this->diagnostics=array();
        $this->loaded=array();
        $this->env=null;
        $configs=array();
        foreach($this->sourceFiles() as $file) {
            $name=basename($file,'.php');
            if(preg_match('/^[a-zA-Z0-9_]+$/',$name)!==1) {
                $this->diagnostics[]='配置文件 '.basename($file).' 的名字不符合规范(仅字母/数字/下划线), 本次未加载';
                continue;
            }
            $value=include $file;
            if(!is_array($value))
                $this->diagnostics[]='配置文件 '.basename($file).' 没有返回数组(实际 '.gettype($value).'), 值被原样收下';
            $configs[$name]=$value;
            $this->loaded[]=$file;
        }
        if($this->env_file!==null)
            $this->mergeEnv($this->env_file,$configs);
        return $configs;
    }

    /**
     * 取本次加载的配置文件清单(按加载顺序)
     *
     * @access public
     * @return array<string>
     */
    public function files(): array {
        return $this->loaded;
    }

    /**
     * 取诊断信息
     *
     * @access public
     * @return array<string>
     */
    public function diagnostics(): array {
        return $this->diagnostics;
    }

    /**
     * 取本次解析出的 `.env`(可看 `errors()`)
     *
     * @access public
     * @return Env|null
     */
    public function env(): ?Env {
        return $this->env;
    }

    /**
     * 列出待加载的文件
     *
     * - 显式清单走这条路时**不扫目录**(清单来自上层, 见 `__construct()`)
     * - 目录扫描用 **`scandir` + 过滤扩展名**, 而不是 `glob('*.php')`:
     *   两者都会自动发现新配置文件, 但 `glob` 要对每个条目做一次模式匹配, 在本机实测
     *   `glob` 3.16 ms vs `scandir` 1.38 ms(FPM 新进程口径; 命中系统缓存时 2.29 vs 0.28),
     *   见 `tools/baseline/config-load.md` 与计划 S6 —— **这是 S6 在"opcache 开"下唯一还稳吃的收益**
     *   (编译已被 opcache 免掉, 而"dump 成文件 + mtime 校验"的校验成本会把 glob 省下的吃回去)
     * - 结果显式排序: 旧实现靠 `glob` 自身的排序, `scandir` 虽也排序, 但这里写下来免得依赖平台行为
     *
     * @access private
     * @return array<string>
     */
    private function sourceFiles(): array {
        if($this->files!==null) {
            $out=array();
            foreach($this->files as $file) {
                $path=$this->dir.'/'.(str_ends_with($file,'.php')?$file:$file.'.php');
                if(!is_file($path)) {
                    $this->diagnostics[]='清单里的配置文件不存在: '.$path;
                    continue;
                }
                $out[]=$path;
            }
            return $out;
        }
        $entries=scandir($this->dir);
        if($entries===false) {
            $this->diagnostics[]='无法扫描配置目录: '.$this->dir;
            return array();
        }
        $found=array();
        foreach($entries as $entry) {
            // `.` 与 `..` 不以 .php 结尾, 自然被排除; 名字是否合规由 `load()` 统一判定(它会记诊断)
            if(!str_ends_with($entry,'.php'))
                continue;
            $found[]=$this->dir.'/'.$entry;
        }
        sort($found);
        return $found;
    }

    /**
     * 把 `.env` 合并进配置树
     *
     * @access private
     * @param string $env_file `.env` 路径
     * @param array<string,mixed> $configs 配置树(按引用就地合并)
     * @return void
     */
    private function mergeEnv(string $env_file,array &$configs): void {
        $env=Env::fromFile($env_file);
        $this->env=$env;
        foreach($env->errors() as $error)
            $this->diagnostics[]='.env '.$error;
        $raw_values=$env->raw();
        foreach($raw_values as $key=>$raw) {
            $key=(string)$key;
            // 旧实现先把整键小写再按 `.` 切段, 故段一律小写 —— 保持等价
            $segments=explode('.',strtolower($key));
            if(!$this->pathExists($configs,$segments)&&!$this->allowUnknownKey($key))
                continue;
            $this->assign($configs,$segments,$raw,$key);
        }
    }

    /**
     * 未知键是否放行(并按策略处理)
     *
     * - "未知键"即评估报告 A5 里的**虚空节点**: 配置里没有、只存在于 `.env` 的键。
     *   早期的约定是**不允许**它存在(所有配置项都要显式写在 `config/*.php` 里, `.env` 只覆盖不新增),
     *   理由见类注释"关于虚空节点的历史约定"一节 —— 是否恢复该约定待定(计划 D4)
     *
     * @access private
     * @param string $key `.env` 里的键(报错/诊断用)
     * @return bool 是否继续合并
     * @throws ConfigException 策略为 THROW
     */
    private function allowUnknownKey(string $key): bool {
        if($this->unknown_key_policy===self::UNKNOWN_KEY_THROW)
            throw new ConfigException('`.env` 里的键 "'.$key.'" 在配置里不存在(键名写错? 对照 .env.example)',100901);
        if($this->unknown_key_policy===self::UNKNOWN_KEY_IGNORE) {
            $this->diagnostics[]='.env 键 "'.$key.'" 在配置里不存在, 已按 ignore 策略忽略';
            return false;
        }
        $this->diagnostics[]='.env 键 "'.$key.'" 在配置里不存在, 按旧的 create 策略新建了节点(很可能就是键名写错)';
        return true;
    }

    /**
     * 判断点分路径是否存在(与旧实现的逐段 `isset` 一致: 值为 null 视作不存在)
     *
     * @access private
     * @param array<string,mixed> $configs 配置树
     * @param array<string> $segments 路径段
     * @return bool
     */
    private function pathExists(array $configs,array $segments): bool {
        $node=$configs;
        foreach($segments as $segment) {
            if(!isset($node[$segment]))
                return false;
            $node=$node[$segment];
        }
        return true;
    }

    /**
     * 按路径写入一个值(中途按需新建数组节点)
     *
     * - 布尔节点套用旧换算: 值一律按字符串写入, 仅当目标原本是布尔时按
     *   `false`/`null`/`0` → `false`、其余 → `true` 折算(与 `.env.example` 的口径一致)
     * - 中途遇到标量节点**不再**硬往下钻: 旧实现会在这里产生 PHP 警告并留下半个节点, 本类记诊断并跳过该行
     *
     * @access private
     * @param array<string,mixed> $configs 配置树(按引用就地写入)
     * @param array<string> $segments 路径段
     * @param string $raw 原文值(未做类型推断)
     * @param string $key `.env` 里的键(报错/诊断用)
     * @return void
     */
    private function assign(array &$configs,array $segments,string $raw,string $key): void {
        $node=&$configs;
        $last=count($segments)-1;
        for($i=0;$i<$last;$i++) {
            $segment=$segments[$i];
            if(!isset($node[$segment]))
                $node[$segment]=array();
            elseif(!is_array($node[$segment])) {
                $this->diagnostics[]='.env 键 "'.$key.'" 与现有配置冲突(段 "'.$segment.'" 已是 '.gettype($node[$segment]).'), 该行已跳过';
                return;
            }
            $node=&$node[$segment];
        }
        $leaf=$segments[$last];
        $current=$node[$leaf]??null;
        if(is_array($current))
            $this->diagnostics[]='.env 键 "'.$key.'" 用标量覆盖了配置里的整棵子树';
        // 目标原本是布尔 → 旧换算(得到真正的 bool); 否则原样按字符串写入
        $node[$leaf]=is_bool($current)?!$this->isFalseLike($raw):$raw;
    }

    /**
     * 旧布尔换算的判假口径
     *
     * @access private
     * @param string $raw 原文值
     * @return bool
     */
    private function isFalseLike(string $raw): bool {
        $lower=strtolower($raw);
        return $lower==='false'||$lower==='null'||$raw==='0';
    }

}
