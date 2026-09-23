<?php

namespace AdminService\Config;

use AdminService\exception\ConfigException;

use function basename;
use function gettype;
use function is_array;
use function is_file;
use function preg_match;
use function rtrim;
use function scandir;
use function sort;
use function str_ends_with;

/**
 * 配置加载器(实例化)
 *
 * - 职责: 读 `config/*.php`, 产出**尚未包进 `Repository` 的配置树**
 * - 依赖方向: 不依赖容器、不依赖 `AdminService\Config`, **也不碰 `.env`**
 *   (`.env` 的取值与覆盖见 `Env` 与 `Repository`)
 * - 装载期发现的问题直接抛 `ConfigException`: 配置文件名不合规、配置文件没有返回数组、
 *   清单里的文件不存在、配置目录无法扫描 —— 引导期日志子系统尚未就绪(`log.path` 本身就来自配置),
 *   报错不该等日志
 *
 * ## 文件来源
 *
 *  1. **目录扫描**: `scandir` + 过滤扩展名(不用 `glob('*.php')` —— 后者要对每个条目做模式匹配, 更慢)
 *  2. **显式清单**: 由上层传入(测试与可控调用方走这条路), 此时**不扫目录**
 *
 *  两种方式都按文件名的 `basename` 作为键(`app.php` → `app`), 且都要求名字只含字母/数字/下划线
 *
 * @access public
 * @package AdminService\Config
 * @version 2.0.0
 */
final class Loader {

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
     * 本次实际加载的配置文件
     * @var array<string>
     */
    private array $loaded=array();

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
     * 加载配置
     *
     * - 配置项的第一层就是文件名: `app.php` 里的配置项前缀是 `app.`(如 `app.debug`)
     *
     * @access public
     * @return array<string,mixed> 配置树
     * @throws ConfigException 文件名不合规, 或文件没有返回数组
     */
    public function load(): array {
        $this->loaded=array();
        $configs=array();
        foreach($this->sourceFiles() as $file) {
            $name=basename($file,'.php');
            if(preg_match('/^[a-zA-Z0-9_]+$/',$name)!==1)
                throw new ConfigException('配置文件 "'.$file.'" 的名字不符合规范: 只允许字母、数字、下划线',100910);
            $value=include $file;
            if(!is_array($value))
                throw new ConfigException('配置文件 "'.$file.'" 没有返回数组(实际 '.gettype($value).')',100911);
            $configs[$name]=$value;
            $this->loaded[]=$file;
        }
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
     * 列出待加载的文件
     *
     * - 显式清单走这条路时**不扫目录**(清单来自上层, 见 `__construct()`)
     * - 结果显式排序: 免得依赖目录扫描的默认顺序
     *
     * @access private
     * @return array<string>
     * @throws ConfigException 清单里的文件不存在, 或配置目录无法扫描
     */
    private function sourceFiles(): array {
        if($this->files!==null) {
            $out=array();
            foreach($this->files as $file) {
                $path=$this->dir.'/'.(str_ends_with($file,'.php')?$file:$file.'.php');
                if(!is_file($path))
                    throw new ConfigException('清单里的配置文件不存在: '.$path,100912);
                $out[]=$path;
            }
            return $out;
        }
        $entries=scandir($this->dir);
        if($entries===false)
            throw new ConfigException('无法扫描配置目录: '.$this->dir,100913);
        $found=array();
        foreach($entries as $entry) {
            // `.` 与 `..` 不以 .php 结尾, 自然被排除; 名字是否合规由 `load()` 统一判定(它会抛异常)
            if(!str_ends_with($entry,'.php'))
                continue;
            $found[]=$this->dir.'/'.$entry;
        }
        sort($found);
        return $found;
    }

}
