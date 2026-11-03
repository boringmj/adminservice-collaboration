<?php

namespace Tests\Fixtures\command;

use AdminService\Console\Input;
use AdminService\Console\Output;
use base\AbstractCommand;
use RuntimeException;

/**
 * 命令行异常测试命令
 */
final class FailCommand extends AbstractCommand {

    /**
     * 命令名
     *
     * @return string
     */
    public function name(): string {
        return 'demo fail';
    }

    /**
     * 说明
     *
     * @return string
     */
    public function description(): string {
        return '抛出异常用于测试 CLI 错误出口';
    }

    /**
     * 执行
     *
     * @param Input $input 参数
     * @param Output $output 输出
     * @return int
     */
    public function execute(Input $input,Output $output): int {
        throw new RuntimeException('command boom');
    }

}
