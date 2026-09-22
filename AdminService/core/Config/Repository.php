<?php

namespace AdminService\Config;

use base\ConfigInterface;

use function is_array;
use function str_contains;

/**
 * 配置仓储(实例化, 只读)
 *
 * - 职责: 持有装配好的配置**数组树**, 提供点分键读取; 应用级容器持有一个实例
 * - 依赖方向: 只依赖契约 `base\ConfigInterface`(实现它), 不依赖容器、不依赖 `AdminService\Config`
 * - 与 `Loader` 的分工: `Loader` 负责"读文件 + 合并", 本类只负责"已装配结果的读取"
 *
 * ## 点分键口径(与旧 `Config::get()` 逐段 `isset` 下钻**等价**)
 *
 *  - 内部预先算一张**平坦表**(`'log.path' => 值`, 中间节点与叶子都入表), 故 `get()` 是一次 `isset` 查表
 *  - 表里同时含中间节点, 所以 `get('log')`(取整棵子树)与 `get('log.path')` 都成立
 *  - 列表下标也是键: `get('route.files.0')` 成立(与旧实现一致)
 *  - **字面含 `.` 的键不入表**: 旧实现按 `.` 逐段下钻, 这类键本来就取不到; 不入表正好等价
 *  - `null` 视为"不存在": 旧实现用 `isset()` 判段, 值为 `null` 时会回落默认值 —— `has()`/`get()` 都保持这个口径
 *
 * ## 只读
 *
 *  没有 `set()`: 旧 `Config::set()` 的"整体替换"由上层重建一个新实例来表达(计划 S4)。
 *
 * ## 性能口径
 *
 *  平坦表是**代码整洁性**改进, 不是性能手段 —— 实测 `Config::get()` 24 次调用合计 0.156 ms
 *  (单请求 0.07%, 见评估报告 C7)。别把它当性能亮点汇报。
 *
 * @access public
 * @package AdminService\Config
 * @version 1.0.0
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
     * 构造方法
     *
     * @access public
     * @param array<string,mixed> $configs 配置树
     */
    public function __construct(array $configs=array()) {
        $this->configs=$configs;
        $this->flatten($configs,'');
    }

    /**
     * 读取配置项
     *
     * @access public
     * @param string $key 点分键
     * @param mixed $default 默认值(键不存在或值为 null 时返回)
     * @return mixed
     */
    public function get(string $key,mixed $default=null): mixed {
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
        return isset($this->flat[$key]);
    }

    /**
     * 取全部配置
     *
     * @access public
     * @return array<string,mixed>
     */
    public function all(): array {
        return $this->configs;
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
            // 字面含 `.` 的键取不到(旧实现按 `.` 下钻), 不入表
            if(str_contains($key,'.'))
                continue;
            $path=$prefix===''?$key:$prefix.'.'.$key;
            $this->flat[$path]=$value;
            if(is_array($value)&&$value!==array())
                $this->flatten($value,$path);
        }
    }

}
