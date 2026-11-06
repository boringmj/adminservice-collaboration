<?php

namespace AdminService\Cache;

use AdminService\Config\Env;

use function clearstatcache;
use function dirname;
use function file_put_contents;
use function filemtime;
use function filesize;
use function function_exists;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_scalar;
use function is_string;
use function mkdir;
use function opcache_invalidate;
use function rename;
use function stat;
use function str_replace;
use function strlen;
use function time;
use function trim;
use function uniqid;
use function unlink;
use function var_export;

/**
 * 环境快照存储
 *
 * - 把 `.env` 的解析结果导出为 `env.cache.php`, 引导期不再读取与解析环境文件
 * - 固定写入默认 runtime, **不跟随** `RUNTIME_PATH`: 定位环境快照时还没有 env 值
 * - 文件存在即启用; 普通请求只刷新已存在的快照, 不会隐式创建
 * - 快照是权威值, 读取时不比对来源文件; 修改 `.env` 后必须重新构建
 * - 快照包含 `.env` 的全部键值, 落盘前必须确认 runtime 不在文档根内
 *
 * @access public
 * @package AdminService\Cache
 * @version 1.0.0
 */
final class EnvCache {

    /**
     * 快照结构版本
     */
    private const VERSION=1;

    /**
     * 快照文件名
     */
    private const FILE='env.cache.php';

    /**
     * 测试用快照路径覆盖
     */
    private const TEST_PATH='ADMINSERVICE_TEST_ENV_CACHE';

    /**
     * 快照文件路径
     *
     * - 默认固定在 `AdminService/runtime/cache/compile/`, 不跟随 `RUNTIME_PATH`
     * - 测试可通过 `ADMINSERVICE_TEST_ENV_CACHE` 指向临时文件
     *
     * @access public
     * @return string
     */
    public static function path(): string {
        $override=getenv(self::TEST_PATH);
        if(is_string($override)&&trim($override)!=='')
            return $override;
        return dirname(__DIR__,2).'/runtime/cache/compile/'.self::FILE;
    }

    /**
     * 是否设置了测试用快照路径
     *
     * @access public
     * @return bool
     */
    public static function hasPathOverride(): bool {
        $override=getenv(self::TEST_PATH);
        return is_string($override)&&trim($override)!=='';
    }

    /**
     * 快照文件是否存在
     *
     * @access public
     * @return bool
     */
    public static function exists(): bool {
        return is_file(self::path());
    }

    /**
     * 读取快照
     *
     * - 结构非法或来源路径不符时返回 null
     * - 不比对来源文件, 快照建立后即为权威值
     *
     * @access public
     * @param string $env_file 环境文件路径
     * @param bool $check_exists 是否先检查文件存在(调用方已检查时传 false, 避免重复 stat)
     * @return Env|null
     */
    public static function load(string $env_file,bool $check_exists=true): ?Env {
        $path=self::path();
        if($check_exists&&!is_file($path))
            return null;
        $data=self::read($path);
        if($data===null)
            return null;
        if(($data['source']['path']??null)!==$env_file)
            return null;
        return Env::fromArray($data['values'],$env_file);
    }

    /**
     * 写入快照
     *
     * - 环境文件不存在或不可读时不写入
     * - 写入失败返回 false, 调用方继续使用实时解析结果
     *
     * @access public
     * @param string $env_file 环境文件路径
     * @param Env $env 已解析的 env 快照
     * @return bool
     */
    public static function write(string $env_file,Env $env): bool {
        clearstatcache(true,$env_file);
        $stat=@stat($env_file);
        if($stat===false)
            return false;
        $path=self::path();
        $dir=dirname($path);
        if(!is_dir($dir)&&!@mkdir($dir,0755,true)&&!is_dir($dir))
            return false;
        $payload=array(
            'version'=>self::VERSION,
            'created_at'=>time(),
            'source'=>array(
                'path'=>$env_file,
                'mtime'=>(int)$stat['mtime'],
                'size'=>(int)$stat['size']
            ),
            'values'=>$env->all()
        );
        $content="<?php\n\nreturn ".var_export($payload,true).";\n";
        $tmp=$dir.'/.envtmp_'.str_replace('.','',uniqid('',true));
        $written=@file_put_contents($tmp,$content);
        if($written===false||$written<strlen($content)) {
            @unlink($tmp);
            return false;
        }
        if(!@rename($tmp,$path)) {
            @unlink($path);
            if(!@rename($tmp,$path)) {
                @unlink($tmp);
                return false;
            }
        }
        if(function_exists('opcache_invalidate'))
            @opcache_invalidate($path,true);
        return true;
    }

    /**
     * 删除快照
     *
     * @access public
     * @return bool 文件已不存在时返回 true
     */
    public static function clear(): bool {
        $path=self::path();
        if(!is_file($path))
            return true;
        $ok=@unlink($path);
        if($ok&&function_exists('opcache_invalidate'))
            @opcache_invalidate($path,true);
        return $ok;
    }

    /**
     * 快照状态
     *
     * @access public
     * @param string $env_file 环境文件路径
     * @return array{
     *     path:string,
     *     exists:bool,
     *     size:int|null,
     *     mtime:int|null,
     *     created_at:int|null,
     *     status:string,
     *     reason:string|null
     * }
     */
    public static function status(string $env_file): array {
        $path=self::path();
        $result=array(
            'path'=>$path,
            'exists'=>is_file($path),
            'size'=>null,
            'mtime'=>null,
            'created_at'=>null,
            'status'=>'missing',
            'reason'=>'环境快照不存在'
        );
        if(!$result['exists'])
            return $result;
        $size=@filesize($path);
        $mtime=@filemtime($path);
        $result['size']=$size===false?null:(int)$size;
        $result['mtime']=$mtime===false?null:(int)$mtime;
        $data=self::read($path);
        if($data===null) {
            $result['status']='corrupted';
            $result['reason']='环境快照结构无效';
            return $result;
        }
        $result['created_at']=is_int($data['created_at']??null)?$data['created_at']:null;
        if(($data['source']['path']??null)!==$env_file) {
            $result['status']='stale';
            $result['reason']='环境快照来源路径已变化';
            return $result;
        }
        if(!self::sourceMatches($data['source'],$env_file)) {
            $result['status']='stale';
            $result['reason']='.env 已变化';
            return $result;
        }
        $result['status']='valid';
        $result['reason']='包含 .env 全部键值';
        return $result;
    }

    /**
     * 读取并校验快照结构
     *
     * @access private
     * @param string $path 快照路径
     * @return array{source:array<string,mixed>,values:array<string,mixed>,created_at:int|null}|null
     */
    private static function read(string $path): ?array {
        try {
            $data=@include $path;
        } catch(\Throwable) {
            return null;
        }
        if(!is_array($data)||(int)($data['version']??0)!==self::VERSION)
            return null;
        $source=$data['source']??null;
        $values=$data['values']??null;
        if(!is_array($source)||!is_array($values)
            ||!is_string($source['path']??null)
            ||!is_int($source['mtime']??null)
            ||!is_int($source['size']??null))
            return null;
        foreach($values as $value)
            if($value!==null&&!is_scalar($value))
                return null;
        return array(
            'source'=>$source,
            'values'=>$values,
            'created_at'=>is_int($data['created_at']??null)?$data['created_at']:null
        );
    }

    /**
     * 来源文件是否仍与快照一致
     *
     * @access private
     * @param array<string,mixed> $source 快照记录的来源信息
     * @param string $env_file 环境文件路径
     * @return bool
     */
    private static function sourceMatches(array $source,string $env_file): bool {
        // 常驻进程跨请求保留 stat 缓存, 变化检测前先清掉
        clearstatcache(true,$env_file);
        $stat=@stat($env_file);
        if($stat===false)
            return false;
        return (int)($source['mtime']??-1)===(int)$stat['mtime']
            &&(int)($source['size']??-1)===(int)$stat['size'];
    }

}
