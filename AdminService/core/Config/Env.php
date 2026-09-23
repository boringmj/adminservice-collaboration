<?php

namespace AdminService\Config;

use function array_key_exists;
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
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * `.env` 解析器(实例化, 不持有进程状态)
 *
 * - 职责: 把 `.env` **文本**解析成"键 => 值"的表, 并记录无法解析的行
 * - 依赖方向: 不依赖容器、不依赖 `AdminService\Config`, 可单独 new 出来用;
 *   文件读取只由 `fromFile()` 负责, 解析本身与文件系统无关
 * - 目录归属: `core/Config/` 与 `core/Database/`、`core/Router/` 同级同形;
 *   注意同名类 `AdminService\Config`(门面)与命名空间 `AdminService\Config\*` 并存,
 *   这是 PSR-4 下"类名与命名空间同名"的既定取舍, 两侧互不影响
 *
 * ## 口径按主流来(2026-09-23 定)
 *
 * 参照 Laravel/Symfony 那一套(`.env` 提供值、配置的结构由配置文件声明):
 *
 *  1. **键名大小写敏感**: `env('DB_HOST')` 只认 `DB_HOST`;`db_host` 是另一个键。
 *     点号在本类里**只是普通字符**(解析器不解释层级)—— "含点的键 = 覆盖同名配置项的路径"这条语义
 *     由 `Config\Repository` 实现, 与解析无关
 *  2. **只做 bool / null 的类型转换**(`true`/`false`/`null`, 大小写不敏感);
 *     **数字一律保持字符串** —— 躲开前导 `0`(`0755`)被当八进制、以及位数超 int 范围被静默截断这类惊喜。
 *     要数字请在配置里显式转换: `(int)env('DB_PORT', 3306)`
 *  3. **引号形式永远是字符串**(`"true"` 就是字符串), 这是"我就要字符串"的逃生口
 *  4. 无法解析的行**不抛异常**, 只记进 `errors()`: 本类只管解析, 由上层决定怎么呈现
 *     (框架侧只把它们并进引导期诊断, `app.debug` 时落一次日志)
 *
 * ## 关于"虚空节点"
 *
 *  约定: 配置项必须**显式出现在 `config/*.php`** 里, `.env` 只覆盖、不新增。理由是避免过度依赖 `.env`
 *  —— 若某个配置项只活在 `.env` 里, 使用者翻配置文件时既找不到它、也读不到它的说明。
 *
 *  `Config\Repository` 的查表覆盖正是照这条来的: 只覆盖**已存在的**路径、永不新建节点。
 *  因此本解析器**只负责解析**;键的含义(含不含点)由上层解释。
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
 * @access public
 * @package AdminService\Config
 * @version 2.0.0
 */
final class Env {

    /**
     * 键值表(键按写法保留, 大小写敏感; 完全同名的键后写覆盖前写)
     * @var array<string,mixed>
     */
    private array $values=array();

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
     * 取无法解析的行(空数组表示一切正常)
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
     * @param string $key 键(**大小写敏感**)
     * @return bool
     */
    public function has(string $key): bool {
        return array_key_exists($key,$this->values);
    }

    /**
     * 读取键值(键不存在时返回默认值)
     *
     * @access public
     * @param string $key 键(**大小写敏感**)
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key,mixed $default=null): mixed {
        return array_key_exists($key,$this->values)?$this->values[$key]:$default;
    }

    /**
     * 逐行解析
     *
     * - 按 `\n` 切行(不用 `preg_split('/\R/')`): `\R` 在没有 `u` 修饰时把 `\x85`(NEL)
     *   也当换行, 而 `\x85` 会出现在中文的 UTF-8 字节里 —— 实测 `关` = `E5 85 B3` 被腰斩。
     *   `\r\n` 先归一成 `\n`, 残余的 `\r` 由下面每行的 trim 收拾
     *
     * @access private
     * @param string $content `.env` 文本
     * @return void
     */
    private function parse(string $content): void {
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
        $this->values[$key]=$this->parseValue(substr($line,$pos+1),$key,$no);
    }

    /**
     * 解析 `=` 右侧的值
     *
     * @access private
     * @param string $raw 右侧原文(行首尾空白已去)
     * @param string $key 键(报错用)
     * @param int $no 行号(报错用)
     * @return mixed
     */
    private function parseValue(string $raw,string $key,int $no): mixed {
        // 引号形式: 原样保留, 不做类型推断
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
                return $quote==='"'?$this->unescape($inner):$inner;
            }
        }
        // 行尾注释: 只在 `#` 前有空白时生效(`va#lue` 里的 `#` 是值的一部分)
        if(preg_match('/\s#/',$raw)===1)
            $raw=rtrim((string)preg_replace('/\s#.*$/s','',$raw));
        return $this->cast($raw);
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
     * 类型推断(仅非引号值): **只转 bool 与 null, 数字一律保持字符串**
     *
     * - 这是主流口径(Laravel / phpdotenv): 数字转类型会带来前导 `0` 被当八进制、
     *   超 int 范围被静默截断之类的惊喜, 而收益很小 —— 要数字在配置里显式 `(int)` 转换即可
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
        return $value;
    }

}
