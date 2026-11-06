<?php

namespace AdminService\Console\Command;

use AdminService\Console;
use AdminService\Console\Input;
use AdminService\Console\Output;
use base\AbstractCommand;

use function array_slice;
use function count;
use function dirname;
use function explode;
use function function_exists;
use function implode;
use function is_file;
use function max;
use function mb_strwidth;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

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
            $output->info('用法(Usage): php console <command> [arguments] [options]');
            $output->info('运行 "php console list" 查看全部命令。');
            return self::SUCCESS;
        }
        $result=$registry->resolve($tokens);
        if($result['type']==='group') {
            $commands=$registry->groupCommands($result['name']);
            $bin='bin/'.$result['name'];
            $usage=is_file(dirname(__DIR__,4).'/'.$bin)
                ?'php '.$bin
                :'php console '.$result['name'];
            $output->info('分组(Group): '.$result['name']);
            $output->info('用法(Usage): '.$usage.' [arguments] [options]');
            $output->info('');
            foreach($commands as $command)
                $output->info('  '.$command->name().'  '.$command->description());
            $output->info('');
            $output->info('运行 "'.$usage.' <command> --help" 查看命令选项。');
            return self::SUCCESS;
        }
        if($result['type']!=='command'||$result['command']===null) {
            $output->error('[ERROR] 未知命令(Unknown command): '.implode(' ',$tokens));
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
        // 入口存在 bin 启动器时用 `php bin/<group> <command>` 形式, 与分组帮助一致
        $segments=explode(' ',$name);
        $bin='bin/'.$segments[0];
        $command_usage=$command->usage();
        if(str_starts_with($command_usage,$name))
            $command_usage=trim(substr($command_usage,strlen($name)));
        if(count($segments)>1&&is_file(dirname(__DIR__,4).'/'.$bin)) {
            $usage='php '.$bin.' '.implode(' ',array_slice($segments,1));
            if($command_usage!=='')
                $usage.=' '.$command_usage;
        } else
            $usage='php console '.$command->usage();
        $output->info('命令(Command): '.$name);
        $output->info('描述(Description): '.$command->description());
        $output->info('用法(Usage): '.$usage);
        $output->info('别名(Aliases): '.($aliases===array()?'(无)':implode(', ',$aliases)));
        $output->info('快捷方式(Shortcuts): '.($shortcuts===array()?'(无)':implode(', ',$shortcuts)));
        $option_help=$command instanceof AbstractCommand?$command->optionHelp():array();
        if($option_help===array()) {
            $output->info('带值选项(Value options): '.($command->valueOptions()===array()?'(无)':implode(', ',$command->valueOptions())));
            $output->info('布尔选项(Boolean options): '.($command->booleanOptions()===array()?'(无)':implode(', ',$command->booleanOptions())));
        } else {
            $output->info('选项(Options):');
            $rows=array();
            $width=0;
            foreach($option_help as $item) {
                $names=array();
                foreach($item['names'] as $option_name)
                    $names[]=(strlen($option_name)===1?'-':'--').$option_name;
                $signature=implode(', ',$names);
                if($item['value']!==null)
                    $signature.=' <'.$item['value'].'>';
                // 中文占两列, 有 mbstring 时按显示宽度对齐
                $size=function_exists('mb_strwidth')?mb_strwidth($signature,'UTF-8'):strlen($signature);
                $rows[]=array($signature,$size,$item['description']);
                $width=max($width,$size);
            }
            foreach($rows as $row)
                $output->info('  '.$row[0].str_repeat(' ',$width-$row[1]+2).$row[2]);
        }
        return self::SUCCESS;
    }

}
