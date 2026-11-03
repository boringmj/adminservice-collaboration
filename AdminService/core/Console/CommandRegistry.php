<?php

namespace AdminService\Console;

use AdminService\Exception;
use base\CommandInterface;

use function array_filter;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function is_string;
use function preg_match;
use function preg_replace;
use function str_replace;
use function str_starts_with;
use function trim;

/**
 * 命令行命令注册表
 *
 * - 负责规范命令路径、分组、别名、快捷绑定与最长路径解析
 * - 解析时把冒号写法归一化为空格写法, 如 `cache:build` => `cache build`
 * - `resolve()` 返回的 `consumed` 是消耗的原始 token 数(冒号写法只消耗一个 token)
 *
 * @access public
 * @package AdminService\Console
 * @version 1.0.0
 */
final class CommandRegistry {

    /**
     * 规范命令路径 => 命令
     * @var array<string,CommandInterface>
     */
    private array $commands=array();

    /**
     * 分组名 => true
     * @var array<string,bool>
     */
    private array $groups=array();

    /**
     * 别名 => 规范命令路径
     * @var array<string,string>
     */
    private array $aliases=array();

    /**
     * 快捷绑定 => 规范命令路径
     * @var array<string,string>
     */
    private array $shortcuts=array();

    /**
     * 延迟到 finalize() 抛出的别名 / 快捷绑定冲突
     * @var array<int,Exception>
     */
    private array $conflicts=array();

    /**
     * 是否已完成冲突校验
     * @var bool
     */
    private bool $finalized=false;

    /**
     * 注册命令
     *
     * @access public
     * @param CommandInterface $command 命令
     * @return self
     * @throws Exception 命令路径为空或重复
     */
    public function register(CommandInterface $command): self {
        $name=$this->normalize($command->name());
        if($name===''||preg_match('/^[a-z0-9-]+(?: [a-z0-9-]+)*$/',$name)!==1)
            throw new Exception('Command name is invalid: '.$name,100601,array(
                'command'=>$name
            ));
        if(isset($this->commands[$name]))
            throw new Exception('Command is already registered: '.$name,100602,array(
                'command'=>$name
            ));
        foreach($this->commands as $existing=>$registered) {
            if(str_starts_with($existing,$name.' ')||str_starts_with($name,$existing.' '))
                throw new Exception('Command path conflicts with an existing command: '.$name,100611,array(
                    'command'=>$name,
                    'existing'=>$existing
                ));
        }
        $this->commands[$name]=$command;
        $segments=explode(' ',$name);
        if(count($segments)>1)
            $this->groups[$segments[0]]=true;
        foreach($command->aliases() as $alias) {
            if(!is_string($alias))
                continue;
            $alias=$this->normalize($alias);
            if($alias==='')
                continue;
            if(preg_match('/^[a-z0-9-]+(?: [a-z0-9-]+)*$/',$alias)!==1)
                throw new Exception('Command alias is invalid: '.$alias,100612,array(
                    'alias'=>$alias,
                    'command'=>$name
                ));
            if($alias===$name)
                continue;
            $existing=$this->aliases[$alias]??null;
            if($existing!==null&&$existing!==$name) {
                $this->conflicts[]=new Exception('Command alias conflict: '.$alias,100603,array(
                    'alias'=>$alias,
                    'existing'=>$existing,
                    'command'=>$name
                ));
                continue;
            }
            $this->aliases[$alias]=$name;
        }
        $this->finalized=false;
        return $this;
    }

    /**
     * 登记根级快捷绑定
     *
     * @access public
     * @param string $shortcut 简化路径
     * @param string $target 规范命令路径或命令别名
     * @return self
     * @throws Exception 快捷绑定为空或重复
     */
    public function shortcut(string $shortcut,string $target): self {
        $shortcut=$this->normalize($shortcut);
        $target=$this->normalize($target);
        if($shortcut===''||$target==='')
            throw new Exception('Command shortcut is empty.',100604);
        if(preg_match('/^[a-z0-9-]+(?: [a-z0-9-]+)*$/',$shortcut)!==1)
            throw new Exception('Command shortcut is invalid: '.$shortcut,100613,array(
                'shortcut'=>$shortcut
            ));
        $existing=$this->shortcuts[$shortcut]??null;
        if($existing!==null&&$existing!==$target) {
            $this->conflicts[]=new Exception('Command shortcut conflict: '.$shortcut,100605,array(
                'shortcut'=>$shortcut,
                'existing'=>$existing,
                'target'=>$target
            ));
            return $this;
        }
        $this->shortcuts[$shortcut]=$target;
        $this->finalized=false;
        return $this;
    }

    /**
     * 解析命令
     *
     * @access public
     * @param array<int,string> $tokens 命令 token(不含入口脚本与全局选项)
     * @return array{
     *     type:'command'|'group'|'none',
     *     name:string,
     *     command:?CommandInterface,
     *     consumed:int,
     *     remaining:array<int,string>
     * }
     * @throws Exception 别名 / 快捷绑定冲突
     */
    public function resolve(array $tokens): array {
        $this->finalize();
        $tokens=array_values($tokens);
        if($tokens===array())
            return $this->none(array());
        $segments=array();
        $best=null;
        $count=count($tokens);
        for($i=0;$i<$count;$i++) {
            $token=$tokens[$i];
            if(str_starts_with($token,'-'))
                break;
            foreach($this->segments($token) as $segment)
                $segments[]=$segment;
            // 只在 token 边界确认匹配, 避免把 `cache:build:extra` 误认成 `cache build`
            $path=implode(' ',$segments);
            $match=$this->matchCanonical($path);
            if($match!==null) {
                $match['consumed']=$i+1;
                $match['path']=$path;
                $best=$match;
            }
        }
        // 规范命令优先; 未命中命令时再尝试快捷绑定, 最后才回落组帮助
        $shortcut=$this->findShortcut($tokens);
        if($shortcut!==null&&($best===null||$best['type']!=='command'))
            return $this->expandShortcut($shortcut,$tokens);
        if($best===null)
            return $this->none($tokens);
        $remaining=array_slice($tokens,$best['consumed']);
        if($best['type']==='group') {
            foreach($remaining as $token)
                if(!str_starts_with($token,'-'))
                    return $this->none($tokens);
            return array(
                'type'=>'group',
                'name'=>$best['name'],
                'command'=>null,
                'consumed'=>$best['consumed'],
                'remaining'=>$remaining
            );
        }
        return array(
            'type'=>'command',
            'name'=>$best['name'],
            'command'=>$best['command'],
            'consumed'=>$best['consumed'],
            'remaining'=>$remaining
        );
    }

    /**
     * 全部命令
     *
     * @access public
     * @return array<string,CommandInterface>
     */
    public function commands(): array {
        return $this->commands;
    }

    /**
     * 分组及其命令
     *
     * @access public
     * @return array<string,array<int,CommandInterface>>
     */
    public function groups(): array {
        $groups=array();
        foreach($this->commands as $name=>$command) {
            $segments=explode(' ',$name);
            if(count($segments)<=1)
                continue;
            $groups[$segments[0]][]=$command;
        }
        return $groups;
    }

    /**
     * 指定分组的命令
     *
     * @access public
     * @param string $group 分组名
     * @return array<int,CommandInterface>
     */
    public function groupCommands(string $group): array {
        $group=$this->normalize($group);
        if($group==='')
            return array();
        $prefix=$group.' ';
        $commands=array();
        foreach($this->commands as $name=>$command)
            if(str_starts_with($name,$prefix))
                $commands[]=$command;
        return $commands;
    }

    /**
     * 全部别名
     *
     * @access public
     * @return array<string,string>
     */
    public function aliases(): array {
        $this->finalize();
        return $this->aliases;
    }

    /**
     * 全部快捷绑定
     *
     * @access public
     * @return array<string,string>
     */
    public function shortcuts(): array {
        $this->finalize();
        return $this->shortcuts;
    }

    /**
     * 校验冲突与目标有效性
     *
     * @access public
     * @return void
     * @throws Exception 别名 / 快捷绑定冲突或目标不存在
     */
    public function finalize(): void {
        if($this->conflicts!==array())
            throw $this->conflicts[0];
        if($this->finalized)
            return;
        foreach($this->aliases as $alias=>$target) {
            if(isset($this->commands[$alias])||isset($this->groups[$alias]))
                throw new Exception('Command alias conflicts with a command or group: '.$alias,100606,array(
                    'alias'=>$alias
                ));
            $resolved=$this->resolveTarget($target);
            if($resolved===null)
                throw new Exception('Command alias target is missing: '.$target,100607,array(
                    'alias'=>$alias,
                    'target'=>$target
                ));
            $this->aliases[$alias]=$resolved;
        }
        foreach($this->shortcuts as $shortcut=>$target) {
            if(isset($this->commands[$shortcut])
                ||isset($this->aliases[$shortcut])
                ||isset($this->groups[$shortcut]))
                throw new Exception('Command shortcut conflicts with a command, alias or group: '.$shortcut,100608,array(
                    'shortcut'=>$shortcut
                ));
            $resolved=$this->resolveTarget($target,true);
            if($resolved===null)
                throw new Exception('Command shortcut target is missing: '.$target,100609,array(
                    'shortcut'=>$shortcut,
                    'target'=>$target
                ));
            $this->shortcuts[$shortcut]=$resolved;
        }
        $this->finalized=true;
    }

    /**
     * 解析别名 / 快捷绑定目标
     *
     * @access private
     * @param string $target 规范路径或别名
     * @param bool $allow_group 是否允许指向分组
     * @return string|null 规范命令或分组路径
     * @throws Exception 别名循环
     */
    private function resolveTarget(string $target,bool $allow_group=false): ?string {
        $seen=array();
        while(isset($this->aliases[$target])) {
            if(isset($seen[$target]))
                throw new Exception('Command alias cycle detected: '.$target,100610,array(
                    'alias'=>$target
                ));
            $seen[$target]=true;
            $target=$this->aliases[$target];
        }
        if(isset($this->commands[$target]))
            return $target;
        if($allow_group&&isset($this->groups[$target]))
            return $target;
        return null;
    }

    /**
     * 匹配一个完整路径
     *
     * @access private
     * @param string $path 归一化路径
     * @return array{
     *     type:'command'|'group',
     *     name:string,
     *     command:?CommandInterface
     * }|null
     */
    private function matchCanonical(string $path): ?array {
        if(isset($this->commands[$path]))
            return array(
                'type'=>'command',
                'name'=>$path,
                'command'=>$this->commands[$path]
            );
        if(isset($this->aliases[$path])) {
            $target=$this->aliases[$path];
            if(isset($this->commands[$target]))
                return array(
                    'type'=>'command',
                    'name'=>$target,
                    'command'=>$this->commands[$target]
                );
        }
        if(isset($this->groups[$path]))
            return array(
                'type'=>'group',
                'name'=>$path,
                'command'=>null
            );
        return null;
    }

    /**
     * 查找最长的快捷绑定前缀
     *
     * @access private
     * @param array<int,string> $tokens 原始 token
     * @return array{target:string,tokens:int}|null
     */
    private function findShortcut(array $tokens): ?array {
        $segments=array();
        $best=null;
        foreach($tokens as $index=>$token) {
            if(str_starts_with($token,'-'))
                break;
            foreach($this->segments($token) as $segment)
                $segments[]=$segment;
            $path=implode(' ',$segments);
            if(isset($this->shortcuts[$path]))
                $best=array(
                    'target'=>$this->shortcuts[$path],
                    'tokens'=>$index+1
                );
        }
        return $best;
    }

    /**
     * 把快捷绑定替换为规范路径后重新解析
     *
     * @access private
     * @param array{target:string,tokens:int} $shortcut 快捷绑定
     * @param array<int,string> $tokens 原始 token
     * @return array<string,mixed>
     */
    private function expandShortcut(array $shortcut,array $tokens): array {
        $target=explode(' ',$shortcut['target']);
        $expanded=array_merge($target,array_slice($tokens,$shortcut['tokens']));
        $result=$this->resolve($expanded);
        if($result['type']==='none')
            return $this->none($tokens);
        $extra=max(0,$result['consumed']-count($target));
        $consumed=min(count($tokens),$shortcut['tokens']+$extra);
        return array(
            'type'=>$result['type'],
            'name'=>$result['name'],
            'command'=>$result['command'],
            'consumed'=>$consumed,
            'remaining'=>array_slice($tokens,$consumed)
        );
    }

    /**
     * 构造未命中结果
     *
     * @access private
     * @param array<int,string> $tokens 原始 token
     * @return array{
     *     type:'none',
     *     name:string,
     *     command:null,
     *     consumed:int,
     *     remaining:array<int,string>
     * }
     */
    private function none(array $tokens): array {
        $parts=array();
        foreach($tokens as $token) {
            if(str_starts_with($token,'-'))
                break;
            $part=$this->normalize($token);
            if($part!=='')
                $parts[]=$part;
        }
        return array(
            'type'=>'none',
            'name'=>implode(' ',$parts),
            'command'=>null,
            'consumed'=>0,
            'remaining'=>$tokens
        );
    }

    /**
     * 把 token 拆成路径段
     *
     * @access private
     * @param string $token 原始 token
     * @return array<int,string>
     */
    private function segments(string $token): array {
        $token=trim(str_replace(':',' ',$token));
        if($token==='')
            return array();
        return array_values(array_filter(explode(' ',$token),static fn(string $part): bool => $part!==''));
    }

    /**
     * 归一化命令路径
     *
     * @access private
     * @param string $name 命令路径
     * @return string
     */
    private function normalize(string $name): string {
        $name=str_replace(':',' ',$name);
        $name=preg_replace('/\s+/',' ',trim($name));
        return trim(is_string($name)?$name:'');
    }

}
