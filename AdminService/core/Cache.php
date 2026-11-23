<?php

namespace AdminService;

use AdminService\Cache\DirectoryCleaner;
use AdminService\Cache\EnvCache;
use AdminService\Cache\PathGuard;
use AdminService\Config\EnvSnapshot;
use AdminService\Config\Repository;
use base\CacheInterface;
use base\ConfigInterface;
use Closure;
use ReflectionReference;

use function array_key_exists;
use function array_keys;
use function base64_decode;
use function base64_encode;
use function basename;
use function dirname;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function fclose;
use function get_class;
use function flock;
use function fopen;
use function function_exists;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_object;
use function is_resource;
use function is_string;
use function ltrim;
use function mkdir;
use function mt_getrandmax;
use function mt_rand;
use function opcache_invalidate;
use function preg_match;
use function rename;
use function rmdir;
use function rtrim;
use function scandir;
use function serialize;
use function sha1;
use function spl_object_id;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function strlen;
use function substr;
use function time;
use function trim;
use function uniqid;
use function unlink;
use function unserialize;
use function usort;
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
    private const ERROR_INVALID_PATH=100211;

    /**
     * 缓存值遍历深度上限
     *
     * - 超过上限按不可缓存处理, 避免深层结构绕过对象与资源检查
     * - 数组引用环与对象环会被去重; 只有没有引用的超深结构会被拒绝
     */
    private const VALUE_SCAN_MAX_DEPTH=512;

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
        $entry=$this->readEntryClean($this->entryPath($key),$key);
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
        $entry=$this->readEntryClean($this->entryPath($key),$key);
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
        $entry=$this->readEntryClean($path,$key);
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
        $entry=$this->readEntryClean($path,$key);
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
     * @param string|null $version 版本指纹
     * @return bool
     * @throws Exception 值无法缓存或写入失败
     */
    public function setMultiple(iterable $values,?int $ttl=null,?string $version=null): bool {
        $written=false;
        foreach($values as $key=>$value) {
            $this->store((string)$key,$value,$ttl,$version);
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
     * - 保留 `.lock` 文件, 避免与并发 increment/decrement 形成新旧 inode 双锁
     *
     * @access public
     * @return bool
     */
    public function clear(): bool {
        $path=$this->poolPath();
        if(is_link($path))
            return false;
        if(!is_dir($path))
            return true;
        return $this->removeDirectoryContents($path,true);
    }

    /**
     * 触发一次增量垃圾回收
     *
     * - 只清理 KV 缓存, 不动编译缓存
     * - 按 `pool/aa/bb/文件名` 顺序有界遍历, 内存不随缓存总量增长
     * - `$max_files` 限制单次检查文件数, 辅助临时文件也计入
     *
     * @access public
     * @param int|null $max_files 本次最多检查文件数; null = 配置, <=0 = 不限制
     * @return int 本次清理的文件数
     */
    public function gc(?int $max_files=null): int {
        $limit=$max_files??(int)$this->config()->get('cache.gc_max_files',200);
        if($limit<=0)
            $limit=PHP_INT_MAX;
        $kv=$this->kvRoot();
        if(!is_dir($kv)||is_link($kv))
            return 0;
        $visited=array();
        $result=$this->gcScan($kv,$this->readGcCursor(),$limit,$visited);
        $this->writeGcCursor($result['cursor']);
        $this->gcCleanupVisited($visited);
        return $result['removed'];
    }

    /**
     * 清理编译缓存
     *
     * @access public
     * @param string|null $target 目标(`env` / `config` / `routes` / `route` / `container`); null = 全部编译缓存
     * @return bool
     * @throws Exception 目标名非法
     */
    public function clearCompile(?string $target=null): bool {
        $root=$this->compileRoot();
        if($target===null) {
            $ok=true;
            if(is_link($root))
                $ok=false;
            elseif(is_dir($root))
                $ok=$this->removeDirectoryContents($root);
            // 引导期快照固定写入 runtime; cache.path 改到别处时也要清掉它们
            $runtime=$this->runtimeCompileRoot();
            if($runtime!==$root) {
                if(is_link($runtime))
                    $ok=false;
                elseif(is_dir($runtime))
                    $ok=$this->removeDirectoryContents($runtime)&&$ok;
            }
            if(EnvCache::clear())
                EnvSnapshot::reset();
            else
                $ok=false;
            return $ok;
        }
        if($target==='env') {
            $ok=EnvCache::clear();
            if($ok)
                EnvSnapshot::reset();
            return $ok;
        }
        if($target==='view')
            return DirectoryCleaner::clear($root.'/view');
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
     * - 包括所有 KV 池与编译缓存
     * - 只保留 `cache.path` 目录本身与并发锁文件
     *
     * @access public
     * @return bool
     */
    public function clearAll(): bool {
        $root=$this->cacheRoot();
        $ok=true;
        // 不跟随符号链接, 避免清理范围越过配置的缓存目录
        if(is_link($root))
            $ok=false;
        elseif(is_dir($root))
            $ok=$this->removeDirectoryContents($root,true);
        // 配置快照是引导期例外, 即使 cache.path 自定义也要一并清理
        $runtime=$this->runtimeCompileRoot();
        if($runtime!==$this->compileRoot())
            $ok=$this->removeFile($runtime.'/config.cache.php')&&$ok;
        if(EnvCache::clear())
            EnvSnapshot::reset();
        else
            $ok=false;
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
            $entry=$this->readEntryClean($path,$key);
            if($entry===null) {
                $value=$step;
                $expire=$this->normalizeExpire($ttl);
                $version=null;
            } else {
                $current=$entry['value'];
                if(is_int($current))
                    $value=$current;
                elseif(is_string($current)&&preg_match('/^[+-]?\d+$/',$current)===1)
                    $value=$this->integerFromString($current,$key);
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
     * 把整数字符串转换为无溢出整数
     *
     * @access private
     * @param string $value 整数字符串
     * @param string $key 缓存键
     * @return int
     * @throws Exception 超出本机整数范围
     */
    private function integerFromString(string $value,string $key): int {
        $sign='';
        $digits=$value;
        if($digits!==''&&($digits[0]==='+'||$digits[0]==='-')) {
            $sign=$digits[0];
            $digits=substr($digits,1);
        }
        $digits=ltrim($digits,'0');
        if($digits==='')
            $digits='0';
        $integer=(int)$value;
        $expected=($sign==='-'&&$digits!=='0'?'-':'').$digits;
        if((string)$integer!==$expected)
            throw new Exception('Cache value is outside integer range: '.$key,self::ERROR_NOT_INTEGER,array(
                'key'=>$key
            ));
        return $integer;
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
     * @throws Exception 值不可缓存或不可序列化
     */
    private function encodeValue(mixed $value): array {
        if($this->isExportable($value))
            return array(false,$value);
        $this->assertCacheableValue($value,$this->allowedUnserializeClasses());
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
     * 校验值能否写入缓存
     *
     * - 拒绝资源、闭包、白名单之外的对象与超过遍历深度上限的结构
     *
     * @access private
     * @param mixed $value 值
     * @param array<int,string>|bool $allowed 允许的类
     * @param int $depth 当前深度
     * @param array<int,bool>|null $seen 已检查的对象(引用)
     * @param array<string,bool>|null $array_seen 已检查的数组引用(引用)
     * @return void
     * @throws Exception 值不可缓存
     */
    private function assertCacheableValue(mixed $value,array|bool $allowed,int $depth=0,?array &$seen=null,?array &$array_seen=null): void {
        if($depth>=self::VALUE_SCAN_MAX_DEPTH)
            throw new Exception('Cache value exceeds the scan depth limit.',self::ERROR_ENCODE_FAILED,array(
                'max_depth'=>self::VALUE_SCAN_MAX_DEPTH
            ));
        if(is_resource($value)||$value instanceof Closure)
            throw new Exception('Cache value contains a resource or closure.',self::ERROR_ENCODE_FAILED);
        if(is_object($value)) {
            if($allowed!==true&&($allowed===false||!in_array(get_class($value),$allowed,true)))
                throw new Exception('Cache value contains a class not allowed by cache.unserialize_allowed_classes.',self::ERROR_ENCODE_FAILED,array(
                    'allowed_classes'=>$allowed,
                    'class'=>get_class($value)
                ));
            $seen??=array();
            $id=spl_object_id($value);
            if(isset($seen[$id]))
                return;
            $seen[$id]=true;
            foreach((array)$value as $item)
                $this->assertCacheableValue($item,$allowed,$depth+1,$seen,$array_seen);
            return;
        }
        if(is_array($value)) {
            $array_seen??=array();
            foreach($value as $key=>$item) {
                if($this->seenArrayReference($value,$key,$array_seen))
                    continue;
                $this->assertCacheableValue($item,$allowed,$depth+1,$seen,$array_seen);
            }
        }
    }

    /**
     * 数组元素引用是否已经检查过
     *
     * - 用于跳过自引用数组; 非引用元素返回 false
     *
     * @access private
     * @param array<mixed> $value 数组
     * @param int|string $key 键
     * @param array<string,bool> $seen 已检查的引用(引用)
     * @return bool 已检查过时为 true
     */
    private function seenArrayReference(array $value,int|string $key,array &$seen): bool {
        $reference=ReflectionReference::fromArrayElement($value,$key);
        if($reference===null)
            return false;
        $id=$reference->getId();
        if(isset($seen[$id]))
            return true;
        $seen[$id]=true;
        return false;
    }

    /**
     * 反序列化允许的类
     *
     * - `false`: 禁止对象; 数组: 类名白名单; `true`: 全部允许
     *
     * @access private
     * @return array<int,string>|bool
     */
    private function allowedUnserializeClasses(): array|bool {
        $value=$this->config()->get('cache.unserialize_allowed_classes',false);
        if(is_bool($value))
            return $value;
        if(!is_array($value))
            return false;
        $allowed=array();
        foreach($value as $class)
            if(is_string($class)&&$class!=='')
                $allowed[]=ltrim($class,'\\');
        return $allowed;
    }

    /**
     * 判断值是否包含未完成反序列化的对象
     *
     * - 超过遍历深度上限时按包含处理, 避免深层结构绕过检查
     *
     * @access private
     * @param mixed $value 值
     * @param int $depth 当前深度
     * @param array<int,bool>|null $seen 已检查的对象(引用)
     * @param array<string,bool>|null $array_seen 已检查的数组引用(引用)
     * @return bool
     */
    private function containsIncompleteClass(mixed $value,int $depth=0,?array &$seen=null,?array &$array_seen=null): bool {
        if($depth>=self::VALUE_SCAN_MAX_DEPTH)
            return true;
        if(is_object($value)) {
            if(get_class($value)==='__PHP_Incomplete_Class')
                return true;
            $seen??=array();
            $id=spl_object_id($value);
            if(isset($seen[$id]))
                return false;
            $seen[$id]=true;
            foreach((array)$value as $item)
                if($this->containsIncompleteClass($item,$depth+1,$seen,$array_seen))
                    return true;
            return false;
        }
        if(is_array($value)) {
            $array_seen??=array();
            foreach($value as $key=>$item) {
                if($this->seenArrayReference($value,$key,$array_seen))
                    continue;
                if($this->containsIncompleteClass($item,$depth+1,$seen,$array_seen))
                    return true;
            }
        }
        return false;
    }

    /**
     * 读取缓存条目(纯读取, 不删除文件)
     *
     * - 过期、损坏或键不匹配时返回 null, 由调用方决定是否清理
     *
     * @access private
     * @param string $path 条目路径
     * @param string|null $expected_key 期望键(null = 不校验, GC 用)
     * @param bool $decode 是否解码序列化值(GC 不需要)
     * @param bool $exists 文件是否存在(引用返回, 避免调用方重复 stat)
     * @return array<string,mixed>|null
     */
    private function readEntry(string $path,?string $expected_key=null,bool $decode=true,bool &$exists=false): ?array {
        $exists=is_file($path);
        if(!$exists)
            return null;
        try {
            $entry=@include $path;
        } catch(\Throwable) {
            return null;
        }
        if(!is_array($entry)
            ||!isset($entry['key'])
            ||!is_string($entry['key'])
            ||!array_key_exists('expire',$entry)
            ||!array_key_exists('value',$entry))
            return null;
        if($expected_key!==null&&$entry['key']!==$expected_key)
            return null;
        $expire=(int)$entry['expire'];
        if($expire>0&&$expire<=time())
            return null;
        if($decode&&!empty($entry['serialized'])) {
            if(!is_string($entry['value']))
                return null;
            $raw=base64_decode($entry['value'],true);
            if($raw===false)
                return null;
            try {
                $value=@unserialize($raw,array(
                    'allowed_classes'=>$this->allowedUnserializeClasses()
                ));
            } catch(\Throwable) {
                return null;
            }
            if($value===false&&$raw!==serialize(false))
                return null;
            if($this->containsIncompleteClass($value))
                return null;
            $entry['value']=$value;
            $entry['serialized']=false;
        }
        return $entry;
    }

    /**
     * 读取缓存条目, 无效时删除文件
     *
     * @access private
     * @param string $path 条目路径
     * @param string|null $expected_key 期望键
     * @param bool $decode 是否解码序列化值
     * @return array<string,mixed>|null
     */
    private function readEntryClean(string $path,?string $expected_key=null,bool $decode=true): ?array {
        $exists=false;
        $entry=$this->readEntry($path,$expected_key,$decode,$exists);
        if($entry===null&&$exists)
            $this->removeFile($path);
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
     * - `$preserve_locks=true` 时保留 `.lock`: 删除仍可能被等待者持有的锁文件会
     *   让新旧 inode 同时被加锁, 破坏 increment/decrement 的互斥
     *
     * @access private
     * @param string $dir 目录
     * @param bool $preserve_locks 是否保留锁文件
     * @return bool
     */
    private function removeDirectoryContents(string $dir,bool $preserve_locks=false): bool {
        $entries=@scandir($dir);
        if($entries===false)
            return false;
        $ok=true;
        foreach($entries as $entry) {
            if($entry==='.'||$entry==='..')
                continue;
            if(!$this->deletePath($dir.'/'.$entry,$preserve_locks))
                $ok=false;
        }
        return $ok;
    }

    /**
     * 删除文件或目录(递归)
     *
     * - 保留锁文件时, 目录内只剩锁文件则保留目录, 避免 rmdir 失败被误报为清理失败
     *
     * @access private
     * @param string $path 路径
     * @param bool $preserve_locks 是否保留锁文件
     * @return bool
     */
    private function deletePath(string $path,bool $preserve_locks=false): bool {
        if(is_link($path)||is_file($path)) {
            if($preserve_locks&&str_ends_with(basename($path),'.lock'))
                return true;
            return $this->removeFile($path);
        }
        if(!is_dir($path))
            return true;
        if(!$this->removeDirectoryContents($path,$preserve_locks))
            return false;
        if(!$preserve_locks)
            return @rmdir($path);
        $entries=@scandir($path);
        if($entries===false)
            return false;
        foreach($entries as $entry)
            if($entry!=='.'&&$entry!=='..')
                return true;
        return @rmdir($path);
    }

    /**
     * 有界扫描 KV 候选
     *
     * - 从游标之后扫到末尾; 未达上限时再从头扫到游标之前
     * - 游标为最后一次检查的相对路径; 空值或旧整数游标从头开始
     *
     * @access private
     * @param string $kv KV 根目录
     * @param string $after 上次游标
     * @param int $limit 本次最多检查的候选数
     * @param array<string,true> $visited 访问过的目录(引用)
     * @return array{checked:int,removed:int,cursor:string}
     */
    private function gcScan(string $kv,string $after,int $limit,array &$visited): array {
        $start=$this->parseGcCursor($after);
        $phases=$start===null
            ?array(array(null,null))
            :array(array($start,null),array(null,$start));
        $checked=0;
        $removed=0;
        $cursor='';
        foreach($phases as $phase) {
            if($checked>=$limit)
                break;
            $this->gcTraverse($kv,$phase[0],$phase[1],$limit,$checked,$removed,$cursor,$visited);
        }
        return array('checked'=>$checked,'removed'=>$removed,'cursor'=>$cursor);
    }

    /**
     * 按目录顺序遍历候选文件
     *
     * - 只访问 `$from` 之后、`$to` 及其之前的位置; null 表示不限制
     * - 达到上限立即停止
     *
     * @access private
     * @param string $kv KV 根目录
     * @param array{0:string,1:string,2:string,3:string}|null $from 起始位置(不含)
     * @param array{0:string,1:string,2:string,3:string}|null $to 结束位置(含)
     * @param int $limit 本次上限
     * @param int $checked 已检查数(引用)
     * @param int $removed 已清理数(引用)
     * @param string $cursor 最后检查的相对路径(引用)
     * @param array<string,true> $visited 访问过的目录(引用)
     * @return void
     */
    private function gcTraverse(string $kv,?array $from,?array $to,int $limit,int &$checked,int &$removed,string &$cursor,array &$visited): void {
        $pools=@scandir($kv);
        if($pools===false)
            return;
        foreach($pools as $pool) {
            if($pool==='.'||$pool==='..')
                continue;
            $pool_path=$kv.'/'.$pool;
            if(!is_dir($pool_path)||is_link($pool_path))
                continue;
            $visited[$pool_path]=true;
            $aa_entries=@scandir($pool_path);
            if($aa_entries===false)
                continue;
            foreach($aa_entries as $aa) {
                if($aa==='.'||$aa==='..')
                    continue;
                $aa_path=$pool_path.'/'.$aa;
                if(!is_dir($aa_path)||is_link($aa_path))
                    continue;
                $visited[$aa_path]=true;
                $bb_entries=@scandir($aa_path);
                if($bb_entries===false)
                    continue;
                foreach($bb_entries as $bb) {
                    if($bb==='.'||$bb==='..')
                        continue;
                    $bb_path=$aa_path.'/'.$bb;
                    if(!is_dir($bb_path)||is_link($bb_path))
                        continue;
                    $visited[$bb_path]=true;
                    $files=@scandir($bb_path);
                    if($files===false)
                        continue;
                    foreach($files as $file) {
                        if($file==='.'||$file==='..')
                            continue;
                        if(!$this->isAuxiliaryFile($file)&&!str_ends_with($file,'.cache.php'))
                            continue;
                        $path=$bb_path.'/'.$file;
                        if(!is_file($path))
                            continue;
                        $pos=array($pool,$aa,$bb,$file);
                        if($from!==null&&$this->gcCompare($pos,$from)<=0)
                            continue;
                        if($to!==null&&$this->gcCompare($pos,$to)>0)
                            return;
                        $cursor=$pool.'/'.$aa.'/'.$bb.'/'.$file;
                        $checked++;
                        if($this->isAuxiliaryFile($file)) {
                            $this->gcAuxiliaryFile($path,$removed);
                        } else {
                            $exists=false;
                            $entry=$this->readEntry($path,null,false,$exists);
                            if($entry===null&&$exists&&$this->removeFile($path))
                                $removed++;
                        }
                        if($checked>=$limit)
                            return;
                    }
                }
            }
        }
    }

    /**
     * 解析 GC 游标
     *
     * - 旧版整数游标或格式非法时返回 null, 从头扫描
     *
     * @access private
     * @param string $cursor 游标文本
     * @return array{0:string,1:string,2:string,3:string}|null
     */
    private function parseGcCursor(string $cursor): ?array {
        if($cursor===''
            ||preg_match('#^[a-zA-Z0-9_-]+/[a-zA-Z0-9]+/[a-zA-Z0-9]+/[^/]+$#',$cursor)!==1)
            return null;
        $parts=explode('/',$cursor);
        return count($parts)===4?array($parts[0],$parts[1],$parts[2],$parts[3]):null;
    }

    /**
     * 比较两个 GC 位置
     *
     * @access private
     * @param array{0:string,1:string,2:string,3:string} $a 位置
     * @param array{0:string,1:string,2:string,3:string} $b 位置
     * @return int
     */
    private function gcCompare(array $a,array $b): int {
        for($i=0;$i<4;$i++) {
            $cmp=strcmp($a[$i],$b[$i]);
            if($cmp!==0)
                return $cmp;
        }
        return 0;
    }

    /**
     * 移除本次访问过的空目录
     *
     * - 由深到浅; 非空目录 rmdir 失败即保留
     *
     * @access private
     * @param array<string,true> $visited 访问过的目录
     * @return void
     */
    private function gcCleanupVisited(array $visited): void {
        $dirs=array_keys($visited);
        usort($dirs,static fn(string $a,string $b): int => strlen($b)<=>strlen($a));
        foreach($dirs as $dir)
            @rmdir($dir);
    }

    /**
     * 读取增量 GC 游标
     *
     * - 旧版整数游标视为没有游标, 从头扫描
     *
     * @access private
     * @return string 相对路径; 空字符串 = 从头
     */
    private function readGcCursor(): string {
        $content=@file_get_contents($this->cacheRoot().'/gc.cursor');
        if($content===false)
            return '';
        $content=trim($content);
        if($content===''||preg_match('/^[0-9]+$/',$content)===1)
            return '';
        return $content;
    }

    /**
     * 写入增量 GC 游标
     *
     * @access private
     * @param string $cursor 最后一次检查的相对路径(空 = 从头)
     * @return void
     */
    private function writeGcCursor(string $cursor): void {
        @file_put_contents($this->cacheRoot().'/gc.cursor',$cursor,LOCK_EX);
    }

    /**
     * 是否为崩溃残留的临时文件
     *
     * - `.lock` 不参与 GC: 删除仍被并发等待者持有的锁文件会让新旧 inode
     *   各自被加锁, 破坏 increment/decrement 的互斥
     *
     * @access private
     * @param string $name 文件名
     * @return bool
     */
    private function isAuxiliaryFile(string $name): bool {
        return str_starts_with($name,'.cachetmp_');
    }

    /**
     * 清理超过保留期的崩溃残留临时文件
     *
     * - 保留期由 `cache.aux_ttl` 控制, `<=0` 表示不清理
     * - 锁文件不在此处清理; 删除锁文件会破坏并发 increment/decrement 的 inode 互斥
     * - 检查失败时保留文件, 不影响本次 GC
     *
     * @access private
     * @param string $path 文件路径
     * @param int $removed 已清理文件数(引用)
     * @return void
     */
    private function gcAuxiliaryFile(string $path,int &$removed): void {
        $ttl=(int)$this->config()->get('cache.aux_ttl',3600);
        if($ttl<=0||!is_file($path))
            return;
        $mtime=@filemtime($path);
        if($mtime===false||$mtime>time()-$ttl)
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
     * 按配置概率触发自动 GC
     *
     * - 抽签只影响本轮要不要清理, 无安全语义, 用普通随机数
     * - gc() 内部已尽力而为, 失败不影响本次写入
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
        // mt_rand 上限之外的分母按上限抽, 避免荒谬配置抛错
        if($divisor>mt_getrandmax())
            $divisor=mt_getrandmax();
        if(mt_rand(1,$divisor)<=$probability)
            $this->gc();
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
     * - 拒绝文件系统根与 `.` / `..` 路径段: 这些值会让清理操作越过缓存目录
     *
     * @access private
     * @return string
     * @throws Exception 配置的缓存路径指向根目录或包含 `.` / `..` 段
     */
    private function cacheRoot(): string {
        $path=$this->config()->get('cache.path');
        if(!is_string($path)||trim($path)==='')
            return Runtime::path().'/cache';
        $path=rtrim(str_replace('\\','/',trim($path)),'/');
        if($path==='')
            return Runtime::path().'/cache';
        PathGuard::assertSafe($path);
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
