<?php

namespace base\Database\Sql\Compiler;

/**
 * 编译模式集合
 *
 * - 使用位掩码表示模式组合
 * - 实现 CompileModeInterface
 */
final class CompileModeSet implements CompileModeInterface {

    /**
     * 模式标志位
     * @var int
     */
    private int $flags;

    /**
     * 构造方法
     *
     * @access public
     * @param int $flags 模式标志位
     */
    public function __construct(int $flags=0) {
        $this->flags=$flags;
    }

    /**
     * 创建空模式集合
     *
     * @access public
     * @return static
     */
    public static function none(): static {
        return new static();
    }

    /**
     * 创建指定模式的集合
     *
     * @access public
     * @param int ...$modes 模式标志
     * @return static
     */
    public static function with(int ...$modes): static {
        $flags=0;
        foreach($modes as $mode)
            $flags|=$mode;
        return new static($flags);
    }

    /**
     * 判断是否启用指定模式
     *
     * @access public
     * @param int $mode 模式标志
     * @see \base\Database\Sql\Type\CompileMode
     * @return bool
     */
    public function isEnabled(int $mode): bool {
        return ($this->flags&$mode)===$mode;
    }

    /**
     * 获取模式标志位
     *
     * @access public
     * @return int
     */
    public function flags(): int {
        return $this->flags;
    }

}
