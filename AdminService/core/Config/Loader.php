<?php

namespace AdminService\Config;

use function basename;
use function gettype;
use function is_array;
use function is_file;
use function preg_match;
use function scandir;
use function sort;
use function str_ends_with;

/**
 * 配置加载器(实例化)
 *
 * - 职责: 读 `config/*.php`, 产出**尚未包进 `Repository` 的配置树**
 * - 依赖方向: 不依赖容器、不依赖 `AdminService\Config`、**也不碰 `.env`**
 * - 诊断不落日志: 引导期日志子系统还没就绪(`log.path` 本身来自配置), 故只**收集**诊断,
 *   由上层决定怎么呈现(见 `diagnostics()`)
 *
 * ## 为什么这里不再合并 `.env`(2026-09-23, 计划 S8)
 *
 *  旧实现把 `.env` 当成"点分键覆盖通道"合并进配置树, 于是有了两类病:
 *  ① 不认识的键被**静默建成节点**(评估 A5, 也是线上"键名写错却静默失效"的根因);
 *  ② 值一律按字符串写进配置树, 只有"目标节点原本是布尔"时才换算, 语义隐晦。
 *
 *  现在按主流口径分工: **`.env` 只提供值, `config/*.php` 声明结构** ——
 *  配置文件里显式写 `env('KEY', $default)`, 骨架与解释都在配置文件里, 谁都不用去 `.env` 里找配置项。
 *  合并通道连同"未知键三种策略"一起删除; "配置项必须显式出现在配置文件里"这条早期约定
 *  由此**结构性成立**, 不再需要靠校验去禁止(历史留档见 `Env` 的类注释)。
 *
 * ## 文件来源
 *
 *  1. **目录扫描**: `scandir` + 过滤扩展名(不用 `glob('*.php')` —— 后者要对每个条目做模式匹配,
 *     本机实测 3.16 ms vs 1.38 ms(FPM 新进程口径), 见 `tools/baseline/config-load.md` 与计划 S6)
 *  2. **显式清单**: 由上层传入(测试与将来的编译产物走这条路), 此时**不扫目录**
 *
 *  两种方式都按文件名的 `basename` 作为键(`app.php` → `app`), 且都要求名字只含字母/数字/下划线
 *  (旧实现同样跳过不合规的名字, 但**不告诉任何人**; 本类会记一条诊断)
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
     * @access public
     * @return array<string,mixed> 配置树
     */
    public function load(): array {
        $this->diagnostics=array();
        $this->loaded=array();
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
     * 列出待加载的文件
     *
     * - 显式清单走这条路时**不扫目录**(清单来自上层, 见 `__construct()`)
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

}
