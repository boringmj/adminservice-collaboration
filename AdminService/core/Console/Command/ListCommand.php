<?php

namespace AdminService\Console\Command;

use AdminService\Console;
use AdminService\Console\Input;
use AdminService\Console\Output;
use base\AbstractCommand;
use base\CommandInterface;

use function dirname;
use function implode;
use function is_file;
use function str_contains;

/**
 * 列出命令
 *
 * @access public
 * @package AdminService\Console\Command
 * @version 1.0.0
 */
final class ListCommand extends AbstractCommand {

    /**
     * 命令行宿主
     * @var Console
     */
    private Console $console;

    /**
     * 构造方法
     *
     * @access public
     * @param Console $console 命令行宿主
     */
    public function __construct(Console $console) {
        $this->console=$console;
    }

    /**
     * 规范命令路径
     *
     * @access public
     * @return string
     */
    public function name(): string {
        return 'list';
    }

    /**
     * 命令说明
     *
     * @access public
     * @return string
     */
    public function description(): string {
        return '列出全部命令或指定分组的命令';
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return 'list [group]';
    }

    /**
     * 执行命令
     *
     * @access public
     * @param Input $input 已解析参数
     * @param Output $output 输出
     * @return int
     */
    public function execute(Input $input,Output $output): int {
        $group=$input->argument(0);
        $registry=$this->console->registry();
        $output->info('AdminService Console');
        $output->info('');
        $output->info('Usage:');
        $output->info('  php console <command> [arguments] [options]');
        $output->info('  php console <group> [arguments] [options]');
        $output->info('');
        if($group!==null&&$group!=='') {
            $commands=$registry->groupCommands($group);
            if($commands===array()) {
                $output->error('[ERROR] Unknown command group: '.$group);
                return self::INVALID;
            }
            $this->renderGroup($output,$group,$commands);
            return self::SUCCESS;
        }
        $root=array();
        foreach($registry->commands() as $name=>$command)
            if(!str_contains($name,' '))
                $root[]=$command;
        if($root!==array()) {
            $output->info('Commands:');
            $this->renderCommands($output,$root);
            $output->info('');
        }
        foreach($registry->groups() as $name=>$commands) {
            $this->renderGroup($output,$name,$commands);
            $output->info('');
        }
        return self::SUCCESS;
    }

    /**
     * 输出分组
     *
     * @access private
     * @param Output $output 输出
     * @param string $group 分组名
     * @param array<int,CommandInterface> $commands 命令
     * @return void
     */
    private function renderGroup(Output $output,string $group,array $commands): void {
        $bin='bin/'.$group;
        $usage=is_file(dirname(__DIR__,4).'/'.$bin)?'php '.$bin:'php console '.$group;
        $output->info('Group '.$group.':');
        $output->info('  '.$usage.' [arguments] [options]');
        $this->renderCommands($output,$commands);
    }

    /**
     * 输出命令表
     *
     * @access private
     * @param Output $output 输出
     * @param array<int,CommandInterface> $commands 命令
     * @return void
     */
    private function renderCommands(Output $output,array $commands): void {
        $rows=array();
        foreach($commands as $command) {
            $aliases=$this->aliasesFor($command->name());
            $shortcuts=$this->shortcutsFor($command->name());
            $rows[]=array(
                $command->name(),
                implode(', ',$aliases),
                implode(', ',$shortcuts),
                $command->description()
            );
        }
        $output->table(array('Command','Aliases','Shortcuts','Description'),$rows);
    }

    /**
     * 取命令别名
     *
     * @access private
     * @param string $name 规范命令路径
     * @return array<int,string>
     */
    private function aliasesFor(string $name): array {
        $aliases=array();
        foreach($this->console->registry()->aliases() as $alias=>$target)
            if($target===$name)
                $aliases[]=$alias;
        return $aliases;
    }

    /**
     * 取命令快捷绑定
     *
     * @access private
     * @param string $name 规范命令路径
     * @return array<int,string>
     */
    private function shortcutsFor(string $name): array {
        $shortcuts=array();
        foreach($this->console->registry()->shortcuts() as $shortcut=>$target)
            if($target===$name)
                $shortcuts[]=$shortcut;
        return $shortcuts;
    }

}
