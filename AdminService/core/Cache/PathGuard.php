<?php

namespace AdminService\Cache;

use AdminService\Exception;

use function array_pop;
use function count;
use function explode;
use function getcwd;
use function implode;
use function preg_match;
use function realpath;
use function rtrim;
use function str_replace;

/**
 * 缓存根路径校验
 *
 * - 拒绝文件系统根、盘符根与当前目录; 符号链接解析后的真实路径同样校验
 * - 成功解析的“路径 + 当前目录”在进程内只做一次 `realpath`, 解析失败不记忆
 *
 * @access public
 * @package AdminService\Cache
 * @version 1.0.0
 */
final class PathGuard {

    /**
     * 已校验的路径
     * @var array<string,bool>
     */
    private static array $validated=array();

    /**
     * 构造函数(禁止实例化)
     *
     * @access private
     */
    private function __construct() {
    }

    /**
     * 校验缓存根
     *
     * @access public
     * @param string $path 已把反斜杠转为正斜杠并去掉尾部斜杠的缓存根
     * @return void
     * @throws Exception 路径非法(100211)
     */
    public static function assertSafe(string $path): void {
        $normalized=array();
        foreach(explode('/',$path) as $segment) {
            if($segment===''||$segment==='.')
                continue;
            if($segment==='..') {
                $last=count($normalized)-1;
                if($last>=0&&$normalized[$last]!=='..')
                    array_pop($normalized);
                else
                    $normalized[]='..';
                continue;
            }
            $normalized[]=$segment;
        }
        $probe=implode('/',$normalized);
        if($probe===''||$probe==='.'||$probe==='..'||preg_match('#^(?:[a-zA-Z]:)?/*$#',$probe)===1)
            throw new Exception('Cache path is invalid.',100211,array('path'=>$path));
        $cwd=getcwd();
        $key=$path.'|'.($cwd===false?'':$cwd);
        if(isset(self::$validated[$key]))
            return;
        $real=realpath($path);
        if($real===false)
            return;
        $real=rtrim(str_replace('\\','/',$real),'/');
        $current=$cwd===false?'':rtrim(str_replace('\\','/',$cwd),'/');
        if($real===''||$real===$current||preg_match('#^(?:[a-zA-Z]:)?/*$#',$real)===1)
            throw new Exception('Cache path is invalid.',100211,array('path'=>$path));
        if(count(self::$validated)>=256)
            self::$validated=array();
        self::$validated[$key]=true;
    }

}
