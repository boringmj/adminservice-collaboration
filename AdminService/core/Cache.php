<?php

namespace AdminService;

use AdminService\Config\Repository;
use base\CacheInterface;
use base\ConfigInterface;
use Closure;

use function array_diff;
use function array_key_exists;
use function base64_decode;
use function base64_encode;
use function dirname;
use function file_put_contents;
use function filemtime;
use function floor;
use function fclose;
use function flock;
use function fopen;
use function function_exists;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_object;
use function is_resource;
use function is_string;
use function mkdir;
use function opcache_invalidate;
use function preg_match;
use function preg_quote;
use function random_int;
use function rename;
use function rmdir;
use function rtrim;
use function scandir;
use function serialize;
use function sha1;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function time;
use function trim;
use function uniqid;
use function unlink;
use function unserialize;
use function var_export;

/**
 * 通用文件缓存
 *
 * - 用户业务与框架运行期动态数据共用; 单键一个文件, 按 `sha1(key)` 两级分片
 * - 支持 TTL 惰性过期、版本指纹、原子自增/自减、批量操作与分池隔离
 * - 所有写入走"同目录临时文件 + rename()", 避免并发读到半截 PHP 文件
 * - 过期 / 损坏条目在读时立即删除; 写入时按配置概率触发增量 GC
 * - 与系统编译缓存(`runtime/cache/compile`)物理隔离, `clear()` 不会误删编译产物
 *
 * @access public
 * @package AdminService
 * @version 1.0.0
 */
final class Cache implements CacheInterface {

    /**
     * 错误码(每类失败使用唯一值)
     */
    private const ERROR_INVALID_POOL=100201;
    private const ERROR_INVALID_KEY=100202;
    private const ERROR_DIRECTORY_FAILED=100203;
    private const ERROR_ENCODE_FAILED=100204;
    private const ERROR_WRITE_FAILED=100205;
    private const ERROR_PARTIAL_WRITE=100206;
    private const ERROR_LOCK_FAILED=100207;
    private const ERROR_NOT_INTEGER=100208;
    private const ERROR_SERIALIZE_FAILED=100209;
    private const ERROR_INVALID_CLEAR_TARGET=100210;

    /**
     * 编译缓存文件名映射
     * @var array<string,string>
     */
    private const COMPILE_FILES=array(
        'config'=>'config.cache.php',
        'routes'=>'routes.cache.php',
        'route'=>'routes.cache.php',
        'container'=>'container.cache.php'
    );

    /**
     * 配置(构造期可注入; 未注入时回落门面当前那份)
     * @var ConfigInterface|null
     */
    private ?ConfigInterface $config=null;

    /**
     * 当前缓存池
     * @var string
     */
    private string $pool;

    /**
     * 构造方法
     *
     * @access public
     * @param ConfigInterface|null $config 配置契约实例(未注入时回落门面当前那份)
     * @param string|null $pool 缓存池(null = `cache.default_pool`)
     * @throws Exception 池名非法
     */
    public function __construct(?ConfigInterface $config=null,?string $pool=null) {
        $this->config=$config;
        $this->pool=$pool??$this->defaultPool();
        self::assertPool($this->pool);
    }

    /**
     * 取配置契约实例
     *
     * - 注入后固定使用, 未注入时每次回落门面
     *
     * @access private
     * @return ConfigInterface
     */
    private function config(): ConfigInterface {
        return $this->config??Config::repository()??new Repository(array());
    }

    /**
     * 默认缓存池名
     *
     * @access private
     * @return string
     */
    private function defaultPool(): string {
        $pool=$this->config()->get('cache.default_pool','default');
        return is_string($pool)&&$pool!==''?$pool:'default';
    }

    /**
     * 当前缓存池名
     *
     * @access public
     * @return string
     */
    public function pool(): string {
        return $this->pool;
    }

    /**
     * 派生到另一个缓存池
     *
     * @access public
     * @param string $pool 缓存池名
     * @return static
     * @throws Exception 池名非法
     */
    public function scope(string $pool): static {
        return new static($this->config,$pool);
    }

    /**
     * 读取缓存
     *
     * @access public
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string $key,mixed $default=null): mixed {
        $entry=$this->readEntry($this->entryPath($key),$key);
        return $entry===null?$default:$entry['value'];
    }

    /**
     * 写入缓存
     *
     * @access public
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int|null $ttl 过期秒数; null = 默认 TTL, <=0 = 永不过期
     * @param string|null $version 版本指纹
     * @return bool
     * @throws Exception 值无法缓存或写入失败
     */
    public function set(string $key,mixed $value,?int $ttl=null,?string $version=null): bool {
        $this->store($key,$value,$ttl,$version);
        $this->maybeGc();
        return true;
    }

    /**
     * 写入永不过期缓存
     *
     * @access public
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param string|null $version 版本指纹
     * @return bool
     * @throws Exception 值无法缓存或写入失败
     */
    public function forever(string $key,mixed $value,?string $version=null): bool {
        return $this->set($key,$value,0,$version);
    }

    /**
     * 判断缓存是否存在且未过期
     *
     * @access public
     * @param string $key 缓存键
     * @param string|null $version 版本指纹; null = 不校验
     * @return bool
     */
    public function has(string $key,?string $version=null): bool {
        $entry=$this->readEntry($this->entryPath($key),$key);
        if($entry===null)
            return false;
        return $version===null||($entry['version']??null)===$version;
    }

    /**
     * 命中则返回缓存; 未命中或版本不符时执行回调并写入
     *
     * @access public
     * @param string $key 缓存键
     * @param int|null $ttl 过期秒数
     * @param callable $callback 回源回调(无参数)
     * @param string|null $version 版本指纹
     * @return mixed
     * @throws Exception 回调后的值无法缓存
     */
    public function remember(string $key,?int $ttl,callable $callback,?string $version=null): mixed {
        $path=$this->entryPath($key);
        $entry=$this->readEntry($path,$key);
        if($entry!==null&&($version===null||($entry['version']??null)===$version))
            return $entry['value'];
        if($entry!==null)
            $this->removeFile($path);
        $value=$callback();
        $this->store($key,$value,$ttl,$version);
        $this->maybeGc();
        return $value;
    }

    /**
     * 读取并删除缓存
     *
     * @access public
     * @param string $key 缓存键
     * @param mixed $default 默认值
     * @return mixed
     */
    public function pull(string $key,mixed $default=null): mixed {
        $path=$this->entryPath($key);
        $entry=$this->readEntry($path,$key);
        if($entry===null)
            return $default;
        $value=$entry['value'];
        $this->removeFile($path);
        return $value;
    }

    /**
     * 原子自增
     *
     * @access public
     * @param string $key 缓存键
     * @param int $step 步长
     * @param int|null $ttl 键不存在时的过期秒数
     * @return int
     * @throws Exception 缓存值不是整数或加锁失败
     */
    public function increment(string $key,int $step=1,?int $ttl=null): int {
        return $this->change($key,$step,$ttl);
    }

    /**
     * 原子自减
     *
     * @access public
     * @param string $key 缓存键
     * @param int $step 步长
     * @param int|null $ttl 键不存在时的过期秒数
     * @return int
     * @throws Exception 缓存值不是整数或加锁失败
     */
    public function decrement(string $key,int $step=1,?int $ttl=null): int {
        return $this->change($key,0-$step,$ttl);
    }

    /**
     * 删除缓存
     *
     * @access public
     * @param string $key 缓存键
     * @return bool 缓存已不存在时返回 true(幂等)
     */
    public function delete(string $key): bool {
        return $this->removeFile($this->entryPath($key));
    }

    /**
     * 批量读取
     *
     * @access public
     * @param iterable<string> $keys 键列表
     * @param mixed $default 默认值
     * @return array<string,mixed>
     */
    public function getMultiple(iterable $keys,mixed $default=null): array {
        $values=array();
        foreach($keys as $key) {
            $key=(string)$key;
            $values[$key]=$this->get($key,$default);
        }
        return $values;
    }

    /**
     * 批量写入
     *
     * @access public
     * @param iterable<string,mixed> $values 键值表
     * @param int|null $ttl 过期秒数
     * @return bool
     * @throws Exception 值无法缓存或写入失败
     */
    public function setMultiple(iterable $values,?int $ttl=null): bool {
        $written=false;
        foreach($values as $key=>$value) {
            $this->store((string)$key,$value,$ttl,null);
            $written=true;
        }
        if($written)
            $this->maybeGc();
        return true;
    }

    /**
     * 批量删除
     *
     * @access public
     * @param iterable<string> $keys 键列表
     * @return bool
     */
    public function deleteMultiple(iterable $keys): bool {
        $ok=true;
        foreach($keys as $key)
            if(!$this->delete((string)$key))
                $ok=false;
        return $ok;
    }

    /**
     * 清空当前缓存池
     *
     * @access public
     * @return bool
     */
    public function clear(): bool {
        $path=$this->poolPath();
        if(!is_dir($path)||is_link($path))
            return true;
        return $this->removeDirectoryContents($path);
    }

    /**
     * 触发一次增量垃圾回收
     *
     * - 只清理 KV 缓存与旧 `File(null)` 周期文件, 不动编译缓存
     * - `$max_files` 限制单次检查文件数, 避免大目录阻塞请求
     *
     * @access public
     * @param int|null $max_files 本次最多检查文件数; null = 配置, <=0 = 不限制
     * @return int 本次清理的文件数
     */
    public function gc(?int $max_files=null): int {
        $limit=$max_files??(int)$this->config()->get('cache.gc_max_files',200);
        if($limit<=0)
            $limit=PHP_INT_MAX;
        $checked=0;
        $removed=0;
        $kv=$this->kvRoot();
        if(is_dir($kv)&&!is_link($kv)) {
            $pools=@scandir($kv);
            if($pools!==false) {
                foreach($pools as $pool) {
                    if($checked>=$limit)
                        break;
                    if($pool==='.'||$pool==='..')
                        continue;
                    $path=$kv.'/'.$pool;
                    if(!is_dir($path)||is_link($path))
                        continue;
                    $this->gcDirectory($path,$limit,$checked,$removed);
                    // limit 已用尽时不再遍历整个目录树做空目录清理
                    if($checked<$limit)
                        $this->removeEmptyDirectories($path,$path);
                }
            }
        }
        if($checked<$limit)
            $removed+=$this->gcLegacy($limit,$checked);
        return $removed;
    }

    /**
     * 清理编译缓存
     *
     * @access public
     * @param string|null $target 目标(`config` / `routes` / `route` / `container`); null = 全部编译缓存
     * @return bool
     * @throws Exception 目标名非法
     */
    public function clearCompile(?string $target=null): bool {
        $root=$this->compileRoot();
        if($target===null) {
            $ok=true;
            if(is_dir($root)&&!is_link($root))
                $ok=$this->removeDirectoryContents($root);
            // 引导期配置快照固定写入 runtime; cache.path 改到别处时也要清掉它
            $runtime=$this->runtimeCompileRoot();
            if($runtime!==$root&&is_dir($runtime)&&!is_link($runtime))
                $ok=$this->removeDirectoryContents($runtime)&&$ok;
            return $ok;
        }
        if(!array_key_exists($target,self::COMPILE_FILES))
            throw new Exception('Cache compile target is invalid.',self::ERROR_INVALID_CLEAR_TARGET,array(
                'target'=>$target
            ));
        $ok=$this->removeFile($root.'/'.self::COMPILE_FILES[$target]);
        if($target==='config') {
            $runtime=$this->runtimeCompileRoot();
            if($runtime!==$root)
                $ok=$this->removeFile($runtime.'/config.cache.php')&&$ok;
        }
        return $ok;
    }

    /**
     * 清空整个缓存目录
     *
     * - 包括所有 KV 池、编译缓存与旧 `File(null)` 周期文件
     * - 只保留 `cache.path` 目录本身
     *
     * @access public
     * @return bool
     */
    public function clearAll(): bool {
        $root=$this->cacheRoot();
        $ok=true;
        if(is_dir($root)&&!is_link($root))
            $ok=$this->removeDirectoryContents($root);
        // 配置快照是引导期例外, 即使 cache.path 自定义也要一并清理
        $runtime=$this->runtimeCompileRoot();
        if($runtime!==$this->compileRoot())
            $ok=$this->removeFile($runtime.'/config.cache.php')&&$ok;
        return $ok;
    }

    /**
     * 写入缓存条目
     *
     * @access private
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int|null $ttl 过期秒数
     * @param string|null $version 版本指纹
     * @return void
     * @throws Exception
     */
    private function store(string $key,mixed $value,?int $ttl,?string $version): void {
        $this->storeEntry($key,$value,$this->normalizeExpire($ttl),$version);
    }

    /**
     * 按绝对过期时间写入缓存条目
     *
     * @access private
     * @param string $key 缓存键
     * @param mixed $value 缓存值
     * @param int $expire 绝对过期时间(0 = 永不过期)
     * @param string|null $version 版本指纹
     * @return void
     * @throws Exception
     */
    private function storeEntry(string $key,mixed $value,int $expire,?string $version): void {
        $encoded=$this->encodeValue($value);
        $entry=array(
            'key'=>$key,
            'expire'=>$expire,
            'version'=>$version,
            'mtime'=>time(),
            'serialized'=>$encoded[0],
            'value'=>$encoded[1]
        );
        $this->writeEntry($this->entryPath($key),$entry);
    }

    /**
     * 自增/自减主体(持有文件排他锁)
     *
     * @access private
     * @param string $key 缓存键
     * @param int $step 步长
     * @param int|null $ttl 键不存在时的过期秒数
     * @return int
     * @throws Exception
     */
    private function change(string $key,int $step,?int $ttl): int {
        $path=$this->entryPath($key);
        $this->ensureDirectory(dirname($path));
        $lock_path=$path.'.lock';
        $handle=@fopen($lock_path,'c');
        if($handle===false)
            throw new Exception('Cache lock open failed: '.$lock_path,self::ERROR_LOCK_FAILED,array(
                'file_path'=>$lock_path
            ));
        if(!@flock($handle,LOCK_EX)) {
            @fclose($handle);
            throw new Exception('Cache lock acquire failed: '.$lock_path,self::ERROR_LOCK_FAILED,array(
                'file_path'=>$lock_path
            ));
        }
        try {
            $entry=$this->readEntry($path,$key);
            if($entry===null) {
                $value=$step;
                $expire=$this->normalizeExpire($ttl);
                $version=null;
            } else {
                $current=$entry['value'];
                if(is_int($current))
                    $value=$current;
                elseif(is_string($current)&&preg_match('/^[+-]?\d+$/',$current)===1)
                    $value=(int)$current;
                else
                    throw new Exception('Cache value is not an integer: '.$key,self::ERROR_NOT_INTEGER,array(
                        'key'=>$key
                    ));
                $value+=$step;
                if(!is_int($value))
                    throw new Exception('Cache increment overflow: '.$key,self::ERROR_NOT_INTEGER,array(
                        'key'=>$key
                    ));
                $expire=(int)($entry['expire']??0);
                $version=isset($entry['version'])&&is_string($entry['version'])?$entry['version']:null;
            }
            $this->storeEntry($key,$value,$expire,$version);
        } finally {
            @flock($handle,LOCK_UN);
            @fclose($handle);
        }
        $this->maybeGc();
        return $value;
    }

    /**
     * 计算绝对过期时间
     *
     * @access private
     * @param int|null $ttl 过期秒数
     * @return int 0 = 永不过期
     */
    private function normalizeExpire(?int $ttl): int {
        if($ttl===null)
            $ttl=(int)$this->config()->get('cache.default_ttl',3600);
        return $ttl<=0?0:time()+$ttl;
    }

    /**
     * 编码缓存值
     *
     * - 纯标量/纯数组直接 var_export; 对象等走 serialize + base64
     *
     * @access private
     * @param mixed $value 缓存值
     * @return array{0:bool,1:mixed} [是否序列化, 落盘值]
     * @throws Exception 资源或不可序列化值
     */
    private function encodeValue(mixed $value): array {
        if($this->isExportable($value))
            return array(false,$value);
        if($this->containsUnsupported($value))
            throw new Exception('Cache value contains a resource or closure.',self::ERROR_ENCODE_FAILED);
        try {
            $serialized=serialize($value);
        } catch(\Throwable $e) {
            throw new Exception('Cache value serialize failed: '.$e->getMessage(),self::ERROR_SERIALIZE_FAILED);
        }
        return array(true,base64_encode($serialized));
    }

    /**
     * 判断值能否直接 var_export
     *
     * - 数组递归检查(带深度保护); 对象/资源走序列化路径
     *
     * @access private
     * @param mixed $value 值
     * @param int $depth 当前深度
     * @return bool
     */
    private function isExportable(mixed $value,int $depth=0): bool {
        if(is_object($value)||is_resource($value))
            return false;
        if(!is_array($value))
            return true;
        if($depth>=64)
            return false;
        foreach($value as $item)
            if(!$this->isExportable($item,$depth+1))
                return false;
        return true;
    }

    /**
     * 判断值是否包含资源或闭包
     *
     * @access private
     * @param mixed $value 值
     * @param int $depth 当前深度
     * @return bool
     */
    private function containsUnsupported(mixed $value,int $depth=0): bool {
        if($depth>=64)
            return false;
        if(is_resource($value)||$value instanceof Closure)
            return true;
        if(is_array($value)) {
            foreach($value as $item)
                if($this->containsUnsupported($item,$depth+1))
                    return true;
            return false;
        }
        if(is_object($value)) {
            foreach((array)$value as $item)
                if($this->containsUnsupported($item,$depth+1))
                    return true;
        }
        return false;
    }

    /**
     * 读取缓存条目
     *
     * - 过期、损坏或键不匹配时立即删除并返回 null
     *
     * @access private
     * @param string $path 条目路径
     * @param string|null $expected_key 期望键(null = 不校验, GC 用)
     * @param bool $decode 是否解码序列化值(GC 不需要)
     * @return array<string,mixed>|null
     */
    private function readEntry(string $path,?string $expected_key=null,bool $decode=true): ?array {
        if(!is_file($path))
            return null;
        try {
            $entry=@include $path;
        } catch(\Throwable) {
            $this->removeFile($path);
            return null;
        }
        if(!is_array($entry)
            ||!isset($entry['key'])
            ||!is_string($entry['key'])
            ||!array_key_exists('expire',$entry)
            ||!array_key_exists('value',$entry)) {
            $this->removeFile($path);
            return null;
        }
        if($expected_key!==null&&$entry['key']!==$expected_key) {
            $this->removeFile($path);
            return null;
        }
        $expire=(int)$entry['expire'];
        if($expire>0&&$expire<=time()) {
            $this->removeFile($path);
            return null;
        }
        if($decode&&!empty($entry['serialized'])) {
            if(!is_string($entry['value'])) {
                $this->removeFile($path);
                return null;
            }
            $raw=base64_decode($entry['value'],true);
            if($raw===false) {
                $this->removeFile($path);
                return null;
            }
            try {
                $value=@unserialize($raw);
            } catch(\Throwable) {
                $this->removeFile($path);
                return null;
            }
            if($value===false&&$raw!==serialize(false)) {
                $this->removeFile($path);
                return null;
            }
            $entry['value']=$value;
            $entry['serialized']=false;
        }
        return $entry;
    }

    /**
     * 原子写入缓存文件
     *
     * - 先写同目录临时文件, 校验字节数后 rename 覆盖
     * - Windows 下目标被占用时回退为 unlink + rename
     *
     * @access private
     * @param string $path 目标路径
     * @param array<string,mixed> $entry 条目
     * @return void
     * @throws Exception
     */
    private function writeEntry(string $path,array $entry): void {
        $dir=dirname($path);
        $this->ensureDirectory($dir);
        $content="<?php\n\nreturn ".var_export($entry,true).";\n";
        $tmp=$dir.'/.cachetmp_'.str_replace('.','',uniqid('',true));
        $written=@file_put_contents($tmp,$content);
        if($written===false) {
            @unlink($tmp);
            throw new Exception('Cache write failed: '.$path,self::ERROR_WRITE_FAILED,array(
                'file_path'=>$path
            ));
        }
        if($written<strlen($content)) {
            @unlink($tmp);
            throw new Exception('Cache write incomplete: '.$path,self::ERROR_PARTIAL_WRITE,array(
                'file_path'=>$path,
                'expected'=>strlen($content),
                'written'=>$written
            ));
        }
        if(!@rename($tmp,$path)) {
            // Windows 下 rename 不能覆盖已存在文件; 先删除再重试
            @unlink($path);
            if(!@rename($tmp,$path)) {
                @unlink($tmp);
                throw new Exception('Cache rename failed: '.$path,self::ERROR_WRITE_FAILED,array(
                    'file_path'=>$path
                ));
            }
        }
        if(function_exists('opcache_invalidate'))
            @opcache_invalidate($path,true);
    }

    /**
     * 删除缓存文件
     *
     * @access private
     * @param string $path 文件路径
     * @return bool 文件已不存在时返回 true
     */
    private function removeFile(string $path): bool {
        if(is_link($path)||is_file($path)) {
            $ok=@unlink($path);
            if($ok&&function_exists('opcache_invalidate'))
                @opcache_invalidate($path,true);
            return $ok;
        }
        return true;
    }

    /**
     * 递归清理目录内容(保留目录本身)
     *
     * @access private
     * @param string $dir 目录
     * @return bool
     */
    private function removeDirectoryContents(string $dir): bool {
        $entries=@scandir($dir);
        if($entries===false)
            return false;
        $ok=true;
        foreach($entries as $entry) {
            if($entry==='.'||$entry==='..')
                continue;
            if(!$this->deletePath($dir.'/'.$entry))
                $ok=false;
        }
        return $ok;
    }

    /**
     * 删除文件或目录(递归)
     *
     * @access private
     * @param string $path 路径
     * @return bool
     */
    private function deletePath(string $path): bool {
        if(is_link($path)||is_file($path))
            return $this->removeFile($path);
        if(!is_dir($path))
            return true;
        if(!$this->removeDirectoryContents($path))
            return false;
        return @rmdir($path);
    }

    /**
     * 增量清理单个池目录
     *
     * @access private
     * @param string $dir 当前目录
     * @param int $limit 本次检查上限
     * @param int $checked 已检查文件数(引用)
     * @param int $removed 已清理文件数(引用)
     * @return void
     */
    private function gcDirectory(string $dir,int $limit,int &$checked,int &$removed): void {
        $entries=@scandir($dir);
        if($entries===false)
            return;
        foreach($entries as $entry) {
            if($checked>=$limit)
                return;
            if($entry==='.'||$entry==='..')
                continue;
            $path=$dir.'/'.$entry;
            if(is_link($path))
                continue;
            if(is_dir($path)) {
                $this->gcDirectory($path,$limit,$checked,$removed);
                continue;
            }
            if($this->isAuxiliaryFile($entry)) {
                $this->gcAuxiliaryFile($path,$removed);
                continue;
            }
            if(!is_file($path)||!str_ends_with($path,'.cache.php'))
                continue;
            $checked++;
            $before=is_file($path);
            $this->readEntry($path,null,false);
            if($before&&!is_file($path))
                $removed++;
        }
    }

    /**
     * 是否为锁文件或崩溃残留的临时文件
     *
     * @access private
     * @param string $name 文件名
     * @return bool
     */
    private function isAuxiliaryFile(string $name): bool {
        return str_ends_with($name,'.lock')||str_starts_with($name,'.cachetmp_');
    }

    /**
     * 清理超过一小时且未被占用的锁文件/临时文件
     *
     * - 锁文件可能被活动进程持有, 只在非阻塞 flock 成功时删除
     * - 检查失败时保留文件, 不影响本次 GC
     *
     * @access private
     * @param string $path 文件路径
     * @param int $removed 已清理文件数(引用)
     * @return void
     */
    private function gcAuxiliaryFile(string $path,int &$removed): void {
        if(!is_file($path))
            return;
        $mtime=@filemtime($path);
        if($mtime===false||$mtime>time()-3600)
            return;
        $handle=@fopen($path,'c');
        if($handle===false)
            return;
        try {
            if(!@flock($handle,LOCK_EX|LOCK_NB))
                return;
            @flock($handle,LOCK_UN);
            if($this->removeFile($path))
                $removed++;
        } finally {
            @fclose($handle);
        }
    }

    /**
     * 清理旧 `File(null)` 周期文件
     *
     * - 保留当前周期与上一周期, 避免跨周期边界误删
     *
     * @access private
     * @param int $limit 本次检查上限
     * @param int $checked 已检查文件数(引用)
     * @return int 清理数
     */
    private function gcLegacy(int $limit,int &$checked): int {
        $cycle=(int)$this->config()->get('cache.name_cycle',0);
        if($cycle<=0)
            $cycle=(int)$this->config()->get('data.name_cycle',0);
        if($cycle<=0)
            return 0;
        $ext=$this->config()->get('cache.ext_name','.data.json');
        if(!is_string($ext)||$ext==='')
            $ext=$this->config()->get('data.ext_name','.data.json');
        $root=$this->cacheRoot();
        $entries=@scandir($root);
        if($entries===false)
            return 0;
        $current=(int)floor(time()/$cycle);
        $pattern='/^cache_(\d+)'.preg_quote((string)$ext,'/').'$/';
        $removed=0;
        foreach($entries as $entry) {
            if($checked>=$limit)
                break;
            if($entry==='.'||$entry==='..')
                continue;
            $path=$root.'/'.$entry;
            if(is_link($path)||!is_file($path)||preg_match($pattern,$entry,$matches)!==1)
                continue;
            $checked++;
            if((int)$matches[1]<$current-1&&$this->removeFile($path))
                $removed++;
        }
        return $removed;
    }

    /**
     * 递归移除空目录(保留池根目录)
     *
     * @access private
     * @param string $dir 当前目录
     * @param string $root 池根目录
     * @return void
     */
    private function removeEmptyDirectories(string $dir,string $root): void {
        if(!is_dir($dir)||is_link($dir))
            return;
        $entries=@scandir($dir);
        if($entries===false)
            return;
        foreach($entries as $entry) {
            if($entry==='.'||$entry==='..')
                continue;
            $path=$dir.'/'.$entry;
            if(is_dir($path)&&!is_link($path))
                $this->removeEmptyDirectories($path,$root);
        }
        if($dir===$root)
            return;
        $entries=@scandir($dir);
        if($entries===false)
            return;
        if(array_diff($entries,array('.','..'))===array())
            @rmdir($dir);
    }

    /**
     * 按配置概率触发自动 GC
     *
     * - 任何 GC 失败都不影响本次写入
     *
     * @access private
     * @return void
     */
    private function maybeGc(): void {
        $probability=(int)$this->config()->get('cache.gc_probability',1);
        $divisor=(int)$this->config()->get('cache.gc_divisor',100);
        if($probability<=0||$divisor<=0)
            return;
        if($probability>$divisor)
            $probability=$divisor;
        try {
            if(random_int(1,$divisor)<=$probability)
                $this->gc();
        } catch(\Throwable) {
            // GC 是尽力而为, 不影响正常写入
        }
    }

    /**
     * 校验缓存池名
     *
     * @access private
     * @param string $pool 池名
     * @return void
     * @throws Exception 池名非法
     */
    private static function assertPool(string $pool): void {
        if(preg_match('/^[a-zA-Z0-9_-]+$/',$pool)!==1)
            throw new Exception('Cache pool name is invalid.',self::ERROR_INVALID_POOL,array(
                'pool'=>$pool
            ));
    }

    /**
     * 校验缓存键
     *
     * @access private
     * @param string $key 缓存键
     * @return void
     * @throws Exception 键非法
     */
    private function assertKey(string $key): void {
        if($key===''||strlen($key)>250||preg_match('/[\x00-\x1F\x7F]/',$key)===1)
            throw new Exception('Cache key is invalid.',self::ERROR_INVALID_KEY,array(
                'key'=>$key
            ));
    }

    /**
     * 缓存条目路径
     *
     * @access private
     * @param string $key 缓存键
     * @return string
     * @throws Exception 键非法
     */
    private function entryPath(string $key): string {
        $this->assertKey($key);
        $hash=sha1($key);
        return $this->poolPath().'/'.substr($hash,0,2).'/'.substr($hash,2,2).'/'.$hash.'.cache.php';
    }

    /**
     * 当前池目录
     *
     * @access private
     * @return string
     */
    private function poolPath(): string {
        return $this->kvRoot().'/'.$this->pool;
    }

    /**
     * KV 缓存根目录
     *
     * @access private
     * @return string
     */
    private function kvRoot(): string {
        return $this->cacheRoot().'/kv';
    }

    /**
     * 编译缓存目录
     *
     * @access private
     * @return string
     */
    private function compileRoot(): string {
        return $this->cacheRoot().'/compile';
    }

    /**
     * Runtime 下的编译缓存根目录
     *
     * - `Main` 引导期无法读取 `cache.path`, 配置快照固定写在这里
     *
     * @access private
     * @return string
     */
    private function runtimeCompileRoot(): string {
        return rtrim(str_replace('\\','/',Runtime::path()),'/').'/cache/compile';
    }

    /**
     * 缓存根目录
     *
     * - `cache.path` 缺失或为空时回落到 `Runtime::path().'/cache'`
     *
     * @access private
     * @return string
     */
    private function cacheRoot(): string {
        $path=$this->config()->get('cache.path');
        if(!is_string($path)||trim($path)==='')
            $path=Runtime::path().'/cache';
        $path=rtrim(str_replace('\\','/',$path),'/');
        if($path==='')
            $path=Runtime::path().'/cache';
        return $path;
    }

    /**
     * 确保目录存在
     *
     * @access private
     * @param string $dir 目录
     * @return void
     * @throws Exception 目录创建失败
     */
    private function ensureDirectory(string $dir): void {
        if(is_dir($dir))
            return;
        $mode=(int)$this->config()->get('cache.dir_mode',0755);
        if($mode<=0)
            $mode=0755;
        if(!@mkdir($dir,$mode,true)&&!is_dir($dir))
            throw new Exception('Cache directory create failed: '.$dir,self::ERROR_DIRECTORY_FAILED,array(
                'dir'=>$dir
            ));
    }

}
