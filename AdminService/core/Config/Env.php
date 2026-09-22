<?php

namespace AdminService\Config;

use function explode;
use function file_get_contents;
use function is_file;
use function is_string;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpbrk;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * `.env` 解析器(实例化, 不持有进程状态)
 *
 * - 职责: 把 `.env` **文本**解析成"键 => 值"的平坦表, 并记录无法解析的行
 * - 依赖方向: 不依赖容器、不依赖 `AdminService\Config`, 可单独 new 出来用;
 *   文件读取只由 `fromFile()` 负责, 解析本身与文件系统无关
 * - 目录归属: `core/Config/` 与 `core/Database/`、`core/Router/` 同级同形;
 *   注意同名类 `AdminService\Config`(门面)与命名空间 `AdminService\Config\*` 并存,
 *   这是 PSR-4 下"类名与命名空间同名"的既定取舍, 两侧互不影响
 *
 * ## 值语法
 *
 * `KEY=VALUE`, 支持:
 *  1. 值里含 `=` —— 只在**第一个** `=` 处切分(`KEY=a=b` → `a=b`)
 *  2. `=` **左边**的空格会被忽略(`KEY =VALUE` → 键为 `KEY`), 这是 `.env.example` 的既有承诺
 *  3. `=` **右边**的空格会被保留(`KEY= VALUE` → `" VALUE"`), 同样依 `.env.example` 的承诺;
 *     要去掉请用引号形式(见下)
 *  4. 单/双引号 —— 引号内的内容原样保留(不做类型推断); 双引号额外处理 `\n` `\r` `\t` `\"` `\\`
 *  5. `export ` 前缀(大小写不敏感)
 *  6. 整行注释(`#` 开头)与**行尾注释**(`#` 前必须有空白, 且只作用于非引号值)
 *  7. 空行、行首尾空白、CRLF
 *
 * ## 类型推断(仅非引号值)
 *
 *  `true`/`false`(大小写不敏感)→ bool; `null` → null; 可直接还原的十进制整数 → int
 *  (前导 0 与超出 int 范围的数字**保留字符串**, 不静默截断); 浮点/科学计数法 → float;
 *  其余一律字符串。引号形式**永远**是字符串(`K="true"` 就是字符串 `true`)。
 *
 * ## 与旧实现(`Config::load()` 内联 30 行)的三条兼容口径
 *
 *  1. **键名大小写不敏感**: 后写的覆盖先写的, 保留后写的写法(旧的 `strtolower()` 等价于此)
 *  2. **后写覆盖前写**(同名键)
 *  3. **点分键是普通键**: `a.b=1` 的键名就是 `a.b`(旧的"建嵌套节点"属于合并策略, 由 `Loader` 决定)
 *
 * ## 无法解析的行
 *
 *  缺 `=`、键为空、引号未闭合、引号闭合后还有多余内容 —— 一律记进 `errors()`,
 *  **本类不决定策略**(是忽略、告警还是 fail-fast, 留给上层的合并器; 见计划 D4)。
 *  能抢救的值仍会保留(如引号未闭合时按普通字符串处理)。
 *
 * @access public
 * @package AdminService\Config
 * @version 1.0.0
 */
final class Env {

    /**
     * 生效的键值表(键按写法保留; 同名键只留最后一个)
     * @var array<string,mixed>
     */
    private array $values=array();

    /**
     * 小写键 => 实际键(大小写不敏感查找用)
     * @var array<string,string>
     */
    private array $index=array();

    /**
     * 与 `all()` 同键的**原文**值(未做类型推断)
     *
     * - 存在的理由只有一条: 旧的 `.env` 合并行为是"值一律按**字符串**写进配置树, 仅当目标节点原本是布尔时才换算"
     *   —— 过渡期(计划 S3–S7)要原样保留这个行为, 就必须拿得到原文。S8 把 `.env` 语义迁到 `env()` 之后,
     *   合并路径消失, 本方法即可删除
     * @var array<string,string>
     */
    private array $raw=array();

    /**
     * 无法解析的行(形如 `第 3 行 缺少 "=": ...`)
     * @var array<string>
     */
    private array $errors=array();

    /**
     * 来源文件路径(仅记录, 便于报错时定位; 解析本身不读文件)
     * @var string
     */
    private string $path='';

    /**
     * 构造方法
     *
     * @access public
     * @param string $content `.env` 文本
     * @param string $path 来源路径(可选, 只用于记录)
     */
    public function __construct(string $content='',string $path='') {
        $this->path=$path;
        if($content!=='')
            $this->parse($content);
    }

    /**
     * 从文件构造(文件不存在时返回空实例并记一条错误, 不抛异常)
     *
     * @access public
     * @param string $path `.env` 路径
     * @return self
     */
    public static function fromFile(string $path): self {
        if(!is_file($path)) {
            $env=new self('',$path);
            $env->errors[]=$path.' 不存在, 按空配置处理';
            return $env;
        }
        return new self((string)file_get_contents($path),$path);
    }

    /**
     * 取全部键值
     *
     * @access public
     * @return array<string,mixed>
     */
    public function all(): array {
        return $this->values;
    }

    /**
     * 取全部键值(与 `all()` 同键, 值为未做类型推断的原文)
     *
     * @access public
     * @return array<string,string>
     */
    public function raw(): array {
        return $this->raw;
    }

    /**
     * 取无法解析的行
     *
     * @access public
     * @return array<string>
     */
    public function errors(): array {
        return $this->errors;
    }

    /**
     * 取来源路径
     *
     * @access public
     * @return string
     */
    public function path(): string {
        return $this->path;
    }

    /**
     * 判断键是否存在(键不存在与"值为 null"是两回事)
     *
     * @access public
     * @param string $key 键(大小写不敏感)
     * @return bool
     */
    public function has(string $key): bool {
        return isset($this->index[strtolower($key)]);
    }

    /**
     * 读取键值(大小写不敏感; 键不存在时返回默认值)
     *
     * @access public
     * @param string $key 键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key,mixed $default=null): mixed {
        $real=$this->index[strtolower($key)]??null;
        return $real===null?$default:$this->values[$real];
    }

    /**
     * 逐行解析
     *
     * @access private
     * @param string $content `.env` 文本
     * @return void
     */
    private function parse(string $content): void {
        // 按 `\n` 切行(不用 `preg_split('/\R/')`): `\R` 在没有 `u` 修饰时把 `\x85`(NEL)
        // 也当换行, 而 `\x85` 会出现在中文的 UTF-8 字节里 —— 实测 `关` = `E5 85 B3` 被腰斩。
        // `\r\n` 先归一成 `\n`, 残余的 `\r` 由下面每行的 trim 收拾。
        $lines=explode("\n",str_replace("\r\n","\n",$content));
        $no=0;
        foreach($lines as $line) {
            $no++;
            $this->parseLine($line,$no);
        }
    }

    /**
     * 解析一行
     *
     * @access private
     * @param string $line 行内容
     * @param int $no 行号(1 起, 报错用)
     * @return void
     */
    private function parseLine(string $line,int $no): void {
        // 行首尾空白与 CRLF 的 `\r` 在此一并去掉; 值右侧的空白在下面另作处理
        $line=trim($line);
        if($line===''||str_starts_with($line,'#'))
            return;
        // `export KEY=VALUE` 前缀
        if(preg_match('/^export\s+/i',$line)===1)
            $line=trim((string)preg_replace('/^export\s+/i','',$line,1));
        $pos=strpos($line,'=');
        if($pos===false) {
            $this->errors[]='第 '.$no.' 行缺少 "=": '.$line;
            return;
        }
        $key=trim(substr($line,0,$pos));
        if($key==='') {
            $this->errors[]='第 '.$no.' 行为空键: '.$line;
            return;
        }
        $parsed=$this->parseValue(substr($line,$pos+1),$key,$no);
        $this->set($key,$parsed[0],$parsed[1]);
    }

    /**
     * 解析 `=` 右侧的值
     *
     * @access private
     * @param string $raw 右侧原文(行首尾空白已去)
     * @param string $key 键(报错用)
     * @param int $no 行号(报错用)
     * @return array{0:mixed,1:string} `[类型推断后的值, 原文]`
     */
    private function parseValue(string $raw,string $key,int $no): array {
        // 引号形式: 原样保留, 不做类型推断(原文 = 引号内的内容)
        if($raw!==''&&($raw[0]==='"'||$raw[0]==="'")) {
            $quote=$raw[0];
            $end=$this->closingQuote($raw,$quote);
            if($end<0) {
                $this->errors[]='第 '.$no.' 行引号未闭合('.$key.'), 已按普通值处理';
            } else {
                $rest=trim(substr($raw,$end+1));
                if($rest!==''&&!str_starts_with($rest,'#'))
                    $this->errors[]='第 '.$no.' 行引号闭合后仍有多余内容('.$key.'), 已忽略: '.$rest;
                $inner=substr($raw,1,$end-1);
                $value=$quote==='"'?$this->unescape($inner):$inner;
                return array($value,$value);
            }
        }
        // 行尾注释: 只在 `#` 前有空白时生效(`va#lue` 里的 `#` 是值的一部分)
        if(preg_match('/\s#/',$raw)===1)
            $raw=rtrim((string)preg_replace('/\s#.*$/s','',$raw));
        return array($this->cast($raw),$raw);
    }

    /**
     * 找一个**未被转义**的收尾引号(双引号认 `\"`, 单引号内无转义)
     *
     * @access private
     * @param string $raw 右侧原文(以引号开头)
     * @param string $quote 引号字符
     * @return int 收尾引号的位置; 未闭合返回 -1
     */
    private function closingQuote(string $raw,string $quote): int {
        $len=strlen($raw);
        for($i=1;$i<$len;$i++) {
            if($quote==='"'&&$raw[$i]==='\\') {
                $i++;
                continue;
            }
            if($raw[$i]===$quote)
                return $i;
        }
        return -1;
    }

    /**
     * 双引号内的转义还原(未识别的转义原样保留)
     *
     * @access private
     * @param string $value 引号内原文
     * @return string
     */
    private function unescape(string $value): string {
        $map=array('n'=>"\n",'r'=>"\r",'t'=>"\t",'"'=>'"','\\'=>'\\');
        return (string)preg_replace_callback('/\\\\(.)/s',function(array $m) use ($map): string {
            $char=$m[1];
            return is_string($map[$char]??null)?$map[$char]:$m[0];
        },$value);
    }

    /**
     * 类型推断(仅非引号值)
     *
     * - 整数只在"能直接还原"时才转: 前导 `0` 与超出 int 范围的一律保留字符串, 不静默截断
     *
     * @access private
     * @param string $value 原值
     * @return mixed
     */
    private function cast(string $value): mixed {
        $lower=strtolower($value);
        if($lower==='true')
            return true;
        if($lower==='false')
            return false;
        if($lower==='null')
            return null;
        if(preg_match('/^-?\d+$/',$value)===1) {
            $int=(int)$value;
            return (string)$int===$value?$int:$value;
        }
        if(preg_match('/^-?(\d+\.\d*|\.\d+|\d+)([eE][+-]?\d+)?$/',$value)===1&&strpbrk($value,'.eE')!==false)
            return (float)$value;
        return $value;
    }

    /**
     * 写入一个键值(同名键按大小写不敏感覆盖, 保留后写的写法)
     *
     * @access private
     * @param string $key 键
     * @param mixed $value 值(已做类型推断)
     * @param string $raw 原文(未做类型推断; 引号值取引号内的内容)
     * @return void
     */
    private function set(string $key,mixed $value,string $raw): void {
        $lower=strtolower($key);
        $prev=$this->index[$lower]??null;
        if($prev!==null&&$prev!==$key) {
            unset($this->values[$prev]);
            unset($this->raw[$prev]);
        }
        $this->values[$key]=$value;
        $this->raw[$key]=$raw;
        $this->index[$lower]=$key;
    }

}
