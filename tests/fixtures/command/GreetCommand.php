<?php

namespace Tests\Fixtures\command;

use AdminService\Config\Repository;
use AdminService\Console\Input;
use AdminService\Console\Output;
use base\AbstractCommand;
use base\Attribute\Config;

/**
 * 命令行测试命令
 */
final class GreetCommand extends AbstractCommand {

    /**
     * 配置
     * @var Repository
     */
    private Repository $config;

    /**
     * 注入的时区
     * @var string
     */
    #[Config('app.timezone')]
    public string $timezone='';

    /**
     * 构造方法
     *
     * @param Repository $config 配置
     */
    public function __construct(Repository $config) {
        $this->config=$config;
    }

    /**
     * 命令名
     *
     * @return string
     */
    public function name(): string {
        return 'demo greet';
    }

    /**
     * 说明
     *
     * @return string
     */
    public function description(): string {
        return '测试自定义命令';
    }

    /**
     * 需要值的选项
     *
     * @return array<int,string>
     */
    public function valueOptions(): array {
        return array('name');
    }

    /**
     * 执行
     *
     * @param Input $input 参数
     * @param Output $output 输出
     * @return int
     */
    public function execute(Input $input,Output $output): int {
        $name=$input->option('name',null,$input->argument(0,'world'));
        $output->writeln('Hello '.$name);
        $output->writeln('timezone='.$this->timezone);
        $output->writeln('default='.$this->config->get('console.default_command'));
        return self::SUCCESS;
    }

}
