<?php

namespace AdminService\Console\Command;

use AdminService\Console;
use AdminService\Console\Input;
use AdminService\Console\Output;
use base\AbstractCommand;

use function dirname;
use function implode;
use function is_file;

/**
 * 显示命令帮助
 *
 * @access public
 * @package AdminService\Console\Command
 * @version 1.0.0
 */
final class HelpCommand extends AbstractCommand {

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
        return 'help';
    }

    /**
     * 命令说明
     *
     * @access public
     * @return string
     */
    public function description(): string {
        return '显示命令或分组帮助';
    }

    /**
     * 简短用法
     *
     * @access public
     * @return string
     */
    public function usage(): string {
        return 'help [command|group]';
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
        return $this->show($input->arguments(),$output);
    }

    /**
     * 显示命令帮助
     *
     * @access public
     * @param array<int,string> $tokens 目标命令 token
     * @param Output $output 输出
     * @return int
     */
    public function show(array $tokens,Output $output): int {
        $registry=$this->console->registry();
        if($tokens===array()) {
            $output->info('AdminService Console');
            $output->info('');
            $output->info('Usage: php console <command> [arguments] [options]');
            $output->info('Run "php console list" to view all commands.');
            return self::SUCCESS;
        }
        $result=$registry->resolve($tokens);
        if($result['type']==='group') {
            $commands=$registry->groupCommands($result['name']);
            $bin='bin/'.$result['name'];
            $usage=is_file(dirname(__DIR__,4).'/'.$bin)
                ?'php '.$bin
                :'php console '.$result['name'];
            $output->info('Group: '.$result['name']);
            $output->info('Usage: '.$usage.' [arguments] [options]');
            $output->info('');
            foreach($commands as $command)
                $output->info('  '.$command->name().'  '.$command->description());
            return self::SUCCESS;
        }
        if($result['type']!=='command'||$result['command']===null) {
            $output->error('[ERROR] Unknown command: '.implode(' ',$tokens));
            return self::INVALID;
        }
        $command=$result['command'];
        $name=$result['name'];
        $aliases=array();
        foreach($registry->aliases() as $alias=>$target)
            if($target===$name)
                $aliases[]=$alias;
        $shortcuts=array();
        foreach($registry->shortcuts() as $shortcut=>$target)
            if($target===$name)
                $shortcuts[]=$shortcut;
        $output->info('Command: '.$name);
        $output->info('Description: '.$command->description());
        $output->info('Usage: php console '.$command->usage());
        $output->info('Aliases: '.($aliases===array()?'(none)':implode(', ',$aliases)));
        $output->info('Shortcuts: '.($shortcuts===array()?'(none)':implode(', ',$shortcuts)));
        $output->info('Value options: '.($command->valueOptions()===array()?'(none)':implode(', ',$command->valueOptions())));
        return self::SUCCESS;
    }

}
