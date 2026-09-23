<?php

namespace AdminService\Config;

use AdminService\exception\ConfigException;
use base\ConfigInterface;

use function array_key_exists;
use function count;
use function explode;
use function gettype;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function str_contains;
use function strtolower;
use function var_export;

/**
 * 配置存储
 *
 * @access public
 * @package AdminService\Config
 * @version 2.0.0
 */
final class Repository implements ConfigInterface {

    /**
     * 实际生效值
     * @var array<string,mixed>
     */
    private array $effective=[];

    /**
     * 配置树原始值
     * @var array<string,mixed>
     */
    private array $config_raw=[];

    /**
     * Env 实例
     * @var Env|null
     */
    private ?Env $env=null;

    /**
     * 构造方法
     *
     * - 含点的 env 键是路径键: 必须命中已存在的标量项, 否则抛 `ConfigException`(不静默跳过、不新建节点)
     *
     * @access public
     * @param array<string,mixed> $configs 配置树, 同时作为原始值与生效值的来源
     * @param Env|null $env Env 实例
     * @param bool $merge_env env 的路径键是否参与合并
     * @throws ConfigException 路径键在配置里不存在, 或指向数组节点
     */
    public function __construct(array $configs,?Env $env=null,bool $merge_env=true) {
        $this->config_raw=$configs;
        $this->effective=$configs;
        $this->env=$env;
        // 计算生效值
        if($merge_env&&$env!==null)
            foreach($env->all() as $key=>$env_value) {
                $key=(string)$key;
                // 不含点的键是值来源(由配置文件里的 `env()` 读取), 不参与覆盖
                if(!str_contains($key,'.'))
                    continue;
                $segments=explode('.',$key);
                $found=null;
                if(!$this->lookup($this->effective,$segments,$found))
                    throw new ConfigException('配置项 "'.$key.'" 不存在, env 的路径键只能覆盖已存在的配置项',100908);
                if(is_array($found))
                    throw new ConfigException('配置项 "'.$key.'" 是数组节点, 覆盖值只能是标量',100909);
                $this->writeNode($this->effective,$segments,$this->castEnvToFileType($key,$env_value,$found));
            }
    }

    /**
     * 获取配置项实际生效值
     *
     * @access public
     * @param string $key 配置项(点分键)
     * @param mixed $default 默认值(键不存在时返回)
     * @return mixed
     */
    public function get(string $key,mixed $default=null): mixed {
        $found=null;
        return $this->lookup($this->effective,explode('.',$key),$found)?$found:$default;
    }

    /**
     * 获取配置项原始值
     *
     * @access public
     * @param string $key 点分键
     * @param mixed $default 默认值(键不存在时返回)
     * @return mixed
     */
    public function config_raw(string $key,mixed $default=null): mixed {
        $found=null;
        return $this->lookup($this->config_raw,explode('.',$key),$found)?$found:$default;
    }

    /**
     * 获取 env 配置项原始值
     *
     * @access public
     * @param string $key 键(大小写敏感)
     * @param mixed $default 默认值(键不存在时返回)
     * @return mixed
     */
    public function env(string $key,mixed $default=null): mixed {
        return $this->env===null?$default:$this->env->get($key,$default);
    }

    /**
     * 判断配置项是否存在
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
     * 取全部配置实际生效值(非原始值)
     *
     * @access public
     * @return array<string,mixed>
     */
    public function all(): array {
        return $this->effective;
    }

    /**
     * 设置配置项生效值
     * 
     * - 配置项必须实际存在
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
            throw new ConfigException('配置项 "'.$key.'" 不存在',100905);
        $this->writeNode($this->effective,$segments,$value);
    }

    /**
     * 按路径段取节点
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
        $found=$node;
        return true;
    }

    /**
     * 沿已存在的路径写回
     *
     * - 节点不存在时静默跳过
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
     * 把 env 的覆盖值, 转成配置文件里那个值的类型
     *
     * - 类型无法转换时抛 `ConfigException`
     *
     * @access private
     * @param string $key 配置项(点分键)
     * @param mixed $env_value env 里的值(已被 `Env` 转过 bool/null)
     * @param mixed $file_value 配置文件里的值
     * @return mixed
     * @throws ConfigException 覆盖值与配置文件里的值类型不符
     */
    private function castEnvToFileType(string $key,mixed $env_value,mixed $file_value): mixed {
        // env 值为 null 表示该项未给值: bool 项按 false 处理, 其余类型不覆盖(保留配置文件里的值)
        if($env_value===null&&!is_bool($file_value))
            return $file_value;
        if(is_bool($file_value)) {
            if(is_bool($env_value)||$env_value===null)
                return (bool)$env_value;
            return !in_array(strtolower((string)$env_value),array('false','null','0',''),true);
        }
        // 覆盖值必须能转成配置文件里值的类型
        if(((is_int($file_value)||is_float($file_value))&&!is_numeric($env_value))
            ||(is_string($file_value)&&!is_string($env_value)))
            throw new ConfigException(
                '配置项 "'.$key.'" 的 env 覆盖值 '.var_export($env_value,true).
                    ' 无法转换为配置文件里的类型('.gettype($file_value).')',
                100907
            );
        if(is_int($file_value)||is_float($file_value))
            return is_int($file_value)?(int)$env_value:(float)$env_value;
        return $env_value;
    }

}
