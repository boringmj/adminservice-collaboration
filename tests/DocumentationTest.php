<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

use function array_map;
use function array_unique;
use function array_values;
use function class_exists;
use function dirname;
use function explode;
use function file_get_contents;
use function function_exists;
use function implode;
use function in_array;
use function interface_exists;
use function json_decode;
use function method_exists;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function rtrim;
use function sort;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function trait_exists;
use function trim;

/**
 * 文档(注释)与代码一致性用例
 *
 * 只断言**危险方向**: 注释里说了代码里没有的东西。覆盖两类:
 *  1. `@param $x` 的形参名必须真实存在(改名/删参后忘改注释 = 谎言)
 *  2. `@see X::y()` 的目标类与方法必须真实存在(方法搬家后忘改引用 = 死链)
 *
 * 不覆盖(避免噪声, 也不该强求):
 *  - "缺文档"(没有 docblock / 漏写 @param)
 *  - `@return` 与声明类型的宽窄差异(如 `@return false` 对 `:bool`, 文档更窄是合法的)
 *
 * 背景: 容器重构期间出现过"注释描述的是上一版 API"的漂移(如 `make()` 仍写着已删除的
 * `$is_force`/`$flags`), 靠人工 review 漏掉了 —— 因此用用例把它固定下来。
 */
class DocumentationTest extends TestCase {

    /**
     * 测试: 注释不得引用不存在的形参与 @see 目标
     * @return void
     */
    public function testDocblocksMatchCode(): void {
        $root=dirname(__DIR__);
        $psr4=$this->psr4Map($root);
        $problems=array();

        foreach($this->phpFiles($root.'/AdminService') as $file) {
            foreach($this->classesIn($file,$root,$psr4) as $fqn) {
                $ref=new \ReflectionClass($fqn);
                foreach($this->sees($ref->getDocComment()?:'') as $see)
                    if(!$this->seeTargetExists($see,$ref))
                        $problems[]=$this->short($file,$root).' 类 '.$fqn.': @see '.$see;

                foreach($ref->getMethods() as $method) {
                    if($method->getDeclaringClass()->getName()!==$fqn)
                        continue;
                    $doc=$method->getDocComment();
                    if($doc===false)
                        continue;
                    $label=$this->short($file,$root).':'.$method->getName().'()';
                    $real=array();
                    foreach($method->getParameters() as $param)
                        $real[]=$param->getName();
                    foreach($this->paramNames($doc) as $name)
                        if(!in_array($name,$real,true))
                            $problems[]=$label.'  @param $'.$name.' 不存在(实际形参: '.($real?implode(', ',array_map(fn($n)=>'$'.$n,$real)):'无').')';
                    foreach($this->sees($doc) as $see)
                        if(!$this->seeTargetExists($see,$ref))
                            $problems[]=$label.'  @see '.$see;
                }
            }
        }

        $this->assertSame(array(),array_values(array_unique($problems)),
            "注释与代码不一致:\n  - ".implode("\n  - ",array_values(array_unique($problems))));
    }

    /**
     * composer 的 PSR-4 映射(前缀 => 目录)
     *
     * @param string $root 项目根
     * @return array<string,string>
     */
    private function psr4Map(string $root): array {
        $composer=json_decode((string)file_get_contents($root.'/composer.json'),true);
        return $composer['autoload']['psr-4']??array();
    }

    /**
     * 列出目录下全部 php 文件
     *
     * @param string $dir 目录
     * @return array<string>
     */
    private function phpFiles(string $dir): array {
        $out=array();
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach($it as $f)
            if($f->isFile()&&$f->getExtension()==='php')
                $out[]=$f->getPathname();
        sort($out);
        return $out;
    }

    /**
     * 由路径推导类名(PSR-4; 文件里没有对应类时返回空数组)
     *
     * @param string $file 文件
     * @param string $root 项目根
     * @param array<string,string> $psr4 映射
     * @return array<string>
     */
    private function classesIn(string $file,string $root,array $psr4): array {
        $rel=str_replace('\\','/',substr($file,strlen($root)+1));
        $best_dir='';
        $best_fqn=null;
        foreach($psr4 as $prefix=>$dir) {
            $dir=trim(str_replace('\\','/',$dir),'/');
            if(strpos($rel,$dir.'/')!==0||strlen($dir)<strlen($best_dir))
                continue;
            $best_dir=$dir;
            $best_fqn=$prefix.str_replace('/',(string)'\\',substr($rel,strlen($dir)+1,-4));
        }
        if($best_fqn===null)
            return array();
        return (class_exists($best_fqn)||interface_exists($best_fqn)||trait_exists($best_fqn))?array($best_fqn):array();
    }

    /**
     * 取出 docblock 里说明的形参名
     *
     * - 类型里可能带括号与参数名(`callable(Db $db): mixed $callback`), 先剥掉括号内容再取第一个 `$name`
     *
     * @param string $doc 注释
     * @return array<string>
     */
    private function paramNames(string $doc): array {
        $out=array();
        foreach(preg_split('/\R/',$doc) as $line) {
            if(strpos($line,'@param')===false)
                continue;
            $clean=preg_replace('/\([^)]*\)/','()',$line);
            if(preg_match('/@param\s+\S+\s+(?:\.\.\.)?\$([A-Za-z_]\w*)/',$clean,$m))
                $out[]=$m[1];
        }
        return $out;
    }

    /**
     * 取出 docblock 里的 @see 目标(含 `{@see X}`)
     *
     * @param string $doc 注释
     * @return array<string>
     */
    private function sees(string $doc): array {
        $doc=preg_replace('/\{@see\s+([^\s\}]+)\}/','',$doc);   // 先吃掉 {@see X}(否则裸 @see 会多吃字符)
        $out=array();
        if(preg_match_all('/@see\s+(\S+)/',$doc,$m))
            foreach($m[1] as $s)
                $out[]=rtrim($s,'}`,).');
        return $out;
    }

    /**
     * @see 目标是否存在
     *
     * @param string $see 目标(类名 / Class::method / 函数名)
     * @param \ReflectionClass $ctx 当前类(解析 self / 同命名空间用)
     * @return bool
     */
    private function seeTargetExists(string $see,\ReflectionClass $ctx): bool {
        $see=trim($see,'`');
        if(strpos($see,'(')!==false)
            $see=substr($see,0,strpos($see,'('));
        if(strpos($see,'::')!==false) {
            [$class,$method]=explode('::',$see,2);
            if(in_array($class,['self','static','$this'],true))
                $class=$ctx->getName();
            elseif(strpos($class,'\\')!==0) {
                $guess=$ctx->getNamespaceName()!==''?$ctx->getNamespaceName().'\\'.$class:$class;
                $class=(class_exists($guess)||interface_exists($guess))?$guess:$class;
            } else
                $class=substr($class,1);
            if(!class_exists($class)&&!interface_exists($class)&&!trait_exists($class))
                return false;
            return method_exists($class,$method);
        }
        if(strpos($see,'\\')===0)
            $see=substr($see,1);
        if(class_exists($see)||interface_exists($see)||function_exists($see))
            return true;
        // 不含命名空间的 @see 可能是泛指或常量, 不苛求
        return strpos($see,'\\')===false;
    }

    /**
     * 相对项目根的短路径(报错信息里用)
     *
     * @param string $file 文件
     * @param string $root 项目根
     * @return string
     */
    private function short(string $file,string $root): string {
        return str_replace('\\','/',substr($file,strlen($root)+1));
    }

}
