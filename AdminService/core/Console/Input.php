<?php

namespace AdminService\Console;

use InvalidArgumentException;

use function array_key_exists;
use function array_slice;
use function count;
use function explode;
use function is_string;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * 命令行参数解析
 *
 * - 支持 `--name=value`、`--name value`、`-n value`、`-n=value`; 不支持 `-nvalue`
 * - 只有 `valueOptions` 中声明的选项会吞掉下一个 token, 普通布尔选项不会误吞位置参数
 * - `--name=` 与 `--name ""` 都保留为空字符串值
 * - `--` 之后的内容全部按位置参数保留
 *
 * @access public
 * @package AdminService\Console
 * @version 1.0.0
 */
final class Input {

    /**
     * 位置参数
     * @var array<int,string>
     */
    private array $arguments=array();

    /**
     * 选项
     * @var array<string,string|bool>
     */
    private array $options=array();

    /**
     * `--` 之后的原始内容
     * @var array<int,string>
     */
    private array $passthrough=array();

    /**
     * 需要接收值的选项
     * @var array<string,bool>
     */
    private array $value_options=array();

    /**
     * 构造方法
     *
     * @access public
     * @param array<int,string> $tokens 命令 token
     * @param array<int,string> $value_options 需要接收值的选项名
     */
    public function __construct(array $tokens,array $value_options=array()) {
        foreach($value_options as $option) {
            if(!is_string($option))
                continue;
            $option=trim($option,'-');
            if($option!=='')
                $this->value_options[$option]=true;
        }
        $this->parse($tokens);
    }

    /**
     * 全部位置参数
     *
     * @access public
     * @return array<int,string>
     */
    public function arguments(): array {
        return $this->arguments;
    }

    /**
     * 取指定位置参数
     *
     * @access public
     * @param int $index 下标
     * @param string|null $default 默认值
     * @return string|null
     */
    public function argument(int $index,?string $default=null): ?string {
        return $this->arguments[$index]??$default;
    }

    /**
     * 全部选项
     *
     * @access public
     * @return array<string,string|bool>
     */
    public function options(): array {
        return $this->options;
    }

    /**
     * 取选项值
     *
     * @access public
     * @param string $name 长选项名
     * @param string|null $shortcut 短选项字母
     * @param string|bool|null $default 默认值
     * @return string|bool|null
     */
    public function option(string $name,?string $shortcut=null,string|bool|null $default=null): string|bool|null {
        $name=trim($name,'-');
        if(array_key_exists($name,$this->options))
            return $this->options[$name];
        if($shortcut!==null) {
            $shortcut=trim($shortcut,'-');
            if(array_key_exists($shortcut,$this->options))
                return $this->options[$shortcut];
        }
        return $default;
    }

    /**
     * 是否存在选项
     *
     * @access public
     * @param string $name 长选项名
     * @param string|null $shortcut 短选项字母
     * @return bool
     */
    public function hasOption(string $name,?string $shortcut=null): bool {
        $name=trim($name,'-');
        if(array_key_exists($name,$this->options))
            return true;
        if($shortcut!==null)
            return array_key_exists(trim($shortcut,'-'),$this->options);
        return false;
    }

    /**
     * `--` 之后的原始内容
     *
     * @access public
     * @return array<int,string>
     */
    public function passthrough(): array {
        return $this->passthrough;
    }

    /**
     * 解析 token
     *
     * @access private
     * @param array<int,string> $tokens 原始 token
     * @return void
     * @throws InvalidArgumentException 选项缺少值
     */
    private function parse(array $tokens): void {
        $tokens=array_values($tokens);
        $count=count($tokens);
        for($i=0;$i<$count;$i++) {
            $token=$tokens[$i];
            if($token==='--') {
                $this->passthrough=array_slice($tokens,$i+1);
                foreach($this->passthrough as $argument)
                    $this->arguments[]=$argument;
                break;
            }
            if($token===''||$token==='-'||!str_starts_with($token,'-')) {
                $this->arguments[]=$token;
                continue;
            }
            if(str_starts_with($token,'--')) {
                $body=substr($token,2);
                if($body==='') {
                    $this->arguments[]=$token;
                    continue;
                }
                if(str_contains($body,'=')) {
                    [$name,$value]=explode('=',$body,2);
                    $this->options[$name]=$value;
                    continue;
                }
                if($this->isValueOption($body)) {
                    $this->options[$body]=$this->nextValue($tokens,$i,$body);
                    continue;
                }
                $this->options[$body]=true;
                continue;
            }
            $body=substr($token,1);
            if($body==='') {
                $this->arguments[]=$token;
                continue;
            }
            if(str_contains($body,'=')) {
                [$name,$value]=explode('=',$body,2);
                $this->options[$name]=$value;
                continue;
            }
            if($this->isValueOption($body)) {
                $this->options[$body]=$this->nextValue($tokens,$i,$body);
                continue;
            }
            // 短选项簇仅支持布尔开关; 需要值的短选项必须写成 `-p 8000` 或 `-p=8000`
            $length=strlen($body);
            for($j=0;$j<$length;$j++) {
                $shortcut=$body[$j];
                if($this->isValueOption($shortcut)) {
                    $value=substr($body,$j+1);
                    if($value!=='')
                        throw new InvalidArgumentException('Short option value must use -'.$shortcut.'=value or -'.$shortcut.' value: -'.$body);
                    $this->options[$shortcut]=$this->nextValue($tokens,$i,$shortcut);
                    break;
                }
                $this->options[$shortcut]=true;
            }
        }
    }

    /**
     * 取下一个 token 作为选项值
     *
     * @access private
     * @param array<int,string> $tokens token 列表
     * @param int $index 当前下标(引用)
     * @param string $name 选项名
     * @return string
     * @throws InvalidArgumentException 缺少值
     */
    private function nextValue(array $tokens,int &$index,string $name): string {
        if(!array_key_exists($index+1,$tokens)
            ||str_starts_with($tokens[$index+1],'-'))
            throw new InvalidArgumentException('Option requires a value: --'.$name);
        $index++;
        return $tokens[$index];
    }

    /**
     * 判断选项是否需要值
     *
     * @access private
     * @param string $name 选项名
     * @return bool
     */
    private function isValueOption(string $name): bool {
        return isset($this->value_options[$name]);
    }

}
