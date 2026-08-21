<?php

namespace base\Database\Sql\Compiler;

use base\Database\Sql\Dialect\DialectInterface;
use base\Database\Sql\Type\CompileFeature;
use base\Database\Sql\Type\CompileMode;

/**
 * 编译器上下文
 *
 * - 携带方言、编译模式、表前缀与命名策略
 * - hasFeature 将 CompileFeature 标志归一化为 CompileMode 模式进行判断
 */
final class CompilerContext implements CompilerContextInterface {

    /**
     * 方言
     * @var DialectInterface
     */
    private DialectInterface $dialect;

    /**
     * 编译模式
     * @var CompileModeInterface
     */
    private CompileModeInterface $mode;

    /**
     * 表前缀
     * @var string
     */
    private string $tablePrefix;

    /**
     * 命名策略
     * @var NamingStrategyInterface
     */
    private NamingStrategyInterface $namingStrategy;

    /**
     * 构造方法
     *
     * @access public
     * @param DialectInterface $dialect 方言
     * @param CompileModeInterface|null $mode 编译模式(默认空模式)
     * @param string $tablePrefix 表前缀
     * @param NamingStrategyInterface|null $namingStrategy 命名策略(默认原样透传)
     */
    public function __construct(
        DialectInterface $dialect,
        ?CompileModeInterface $mode=null,
        string $tablePrefix='',
        ?NamingStrategyInterface $namingStrategy=null
    ) {
        $this->dialect=$dialect;
        $this->mode=$mode??CompileModeSet::none();
        $this->tablePrefix=$tablePrefix;
        $this->namingStrategy=$namingStrategy??new DefaultNamingStrategy();
    }

    /**
     * 获取方言
     *
     * @access public
     * @return DialectInterface
     */
    public function getDialect(): DialectInterface {
        return $this->dialect;
    }

    /**
     * 获取编译模式
     *
     * @access public
     * @return CompileModeInterface
     */
    public function getMode(): CompileModeInterface {
        return $this->mode;
    }

    /**
     * 获取表前缀
     *
     * @access public
     * @return string
     */
    public function getTablePrefix(): string {
        return $this->tablePrefix;
    }

    /**
     * 获取命名策略
     *
     * @access public
     * @return NamingStrategyInterface
     */
    public function getNamingStrategy(): NamingStrategyInterface {
        return $this->namingStrategy;
    }

    /**
     * 判断是否支持指定特性
     *
     * @access public
     * @param int $flag 特性标志
     * @see \base\Database\Sql\Type\CompileFeature
     * @return bool
     */
    public function hasFeature(int $flag): bool {
        // 当前仅实现内联参数特性
        return $flag===CompileFeature::INLINE_PARAM
            && $this->mode->isEnabled(CompileMode::INLINE_PARAM);
    }

}
