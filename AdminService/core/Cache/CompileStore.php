<?php

namespace AdminService\Cache;

use AdminService\Config;
use AdminService\Config\Repository;
use AdminService\Exception;
use AdminService\Runtime;
use base\ConfigInterface;
use Closure;

use function array_key_exists;
use function dirname;
use function file_put_contents;
use function function_exists;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_object;
use function is_resource;
use function is_string;
use function mkdir;
use function opcache_invalidate;
use function preg_match;
use function rename;
use function rmdir;
use function rtrim;
use function scandir;
use function serialize;
use function sha1;
use function strlen;
use function str_replace;
use function time;
use function trim;
use function uniqid;
use function unlink;
use function var_export;

/**
 * 系统编译缓存存储
 *
 * - 把纯数组导出为 `compile/<name>.cache.php`, 由 OPcache 承载
 * - `auto`: 开发期校验指纹, 生产期直接读取; `always`: 始终校验; `off`: 关闭
 * - 写入使用同目录临时文件 + rename; 不可导出或写入失败时仅跳过缓存
 *
 * @access public
 * @package AdminService\Cache
 * @version 1.0.0
 */
final class CompileStore {

    /**
     * 错误码
     */
    private const ERROR_INVALID_NAME=100301;
    private const ERROR_DIRECTORY_FAILED=100302;

    /**
     * 缓存名到编译开关的映射
     * @var array<string,string>
     */
    private const ENABLE_KEYS=array(
        'config'=>'config',
        'routes'=>'route',
        'route'=>'route',
        'container'=>'container'
    );

    /**
     * 配置
     * @var ConfigInterface|null
     */
    private ?ConfigInterface $config=null;

    /**
     * 构造方法
     *
     * @access public
     * @param ConfigInterface|null $config 配置契约实例(未注入时回落门面当前那份)
     */
    public function __construct(?ConfigInterface $config=null) {
        $this->config=$config;
    }

    /**
     * 取配置契约实例
     *
     * @access private
     * @return ConfigInterface
     */
    private function config(): ConfigInterface {
        return $this->config??Config::repository()??new Repository(array());
    }

    /**
     * 当前编译缓存模式
     *
     * @access public
     * @return string auto / always / off
     */
    public function mode(): string {
        if($this->config()->get('cache.compile.enabled',true)===false)
            return 'off';
        $mode=$this->config()->get('cache.compile.mode','auto');
        return in_array($mode,array('auto','always','off'),true)?$mode:'auto';
    }

    /**
     * 读取编译缓存; 未命中时执行构建并尝试写入
     *
     * - `$fingerprint` 只在需要校验或写入时调用; 生产零探测命中不会调用
     * - `$builder` 返回 null 或不可导出数组时不写缓存
     *
     * @access public
     * @param string $name 缓存名
     * @param callable():string $fingerprint 源指纹回调(无参数, 返回字符串)
     * @param callable():mixed $builder 构建回调(无参数)
     * @param string|null $context 配置上下文(用于零探测模式下识别配置切换)
     * @return mixed
     * @throws Exception 缓存名非法
     */
    public function load(string $name,callable $fingerprint,callable $builder,?string $context=null): mixed {
        $name=$this->assertName($name);
        if($this->mode()==='off'||!$this->enabledFor($name))
            return $builder();
        $file=$this->path($name);
        $validate=$this->shouldValidate();
        $current=$validate?(string)$fingerprint():null;
        if(is_file($file)) {
            $cached=$this->read($file);
            if($cached!==null
                &&($context===null||$cached['context']===$context)
                &&(!$validate||$cached['fingerprint']===$current))
                return $cached['payload'];
            $this->removeFile($file);
        }
        $payload=$builder();
        if($payload===null||!$this->isExportable($payload))
            return $payload;
        $this->write($file,$validate?$current:(string)$fingerprint(),$payload,$context);
        return $payload;
    }

    /**
     * 编译缓存文件路径
     *
     * @access public
     * @param string $name 缓存名
     * @return string
     * @throws Exception 缓存名非法
     */
    public function path(string $name): string {
        return $this->compileRoot().'/'.$this->assertName($name).'.cache.php';
    }

    /**
     * 删除指定编译缓存
     *
     * @access public
     * @param string $name 缓存名
     * @return bool
     * @throws Exception 缓存名非法
     */
    public function invalidate(string $name): bool {
        return $this->removeFile($this->path($name));
    }

    /**
     * 清空编译缓存
     *
     * @access public
     * @param string|null $name 缓存名(null = 全部)
     * @return bool
     * @throws Exception 缓存名非法
     */
    public function clear(?string $name=null): bool {
        if($name!==null)
            return $this->invalidate($name);
        $dir=$this->compileRoot();
        if(!is_dir($dir)||is_link($dir))
            return true;
        $entries=@scandir($dir);
        if($entries===false)
            return false;
        $ok=true;
        foreach($entries as $entry) {
            if($entry==='.'||$entry==='..')
                continue;
            $path=$dir.'/'.$entry;
            if(!$this->removeFile($path))
                $ok=false;
        }
        return $ok;
    }

    /**
     * 生成值的稳定哈希
     *
     * - 不可序列化的值返回随机哈希, 使缓存无法误命中
     *
     * @access public
     * @param mixed $value 值
     * @return string
     */
    public function hash(mixed $value): string {
        try {
            return sha1(serialize($value));
        } catch(\Throwable) {
            return sha1(uniqid('',true));
        }
    }

    /**
     * 是否需要校验源指纹
     *
     * @access private
     * @return bool
     */
    private function shouldValidate(): bool {
        $mode=$this->mode();
        if($mode==='always')
            return true;
        if($mode==='off')
            return false;
        return (bool)$this->config()->get('app.debug',false);
    }

    /**
     * 指定缓存名是否启用
     *
     * @access private
     * @param string $name 缓存名
     * @return bool
     */
    private function enabledFor(string $name): bool {
        $key=self::ENABLE_KEYS[$name]??$name;
        return $this->config()->get('cache.compile.'.$key,true)!==false;
    }

    /**
     * 读取缓存文件
     *
     * @access private
     * @param string $file 文件路径
     * @return array{fingerprint:string,context:string|null,payload:mixed}|null
     */
    private function read(string $file): ?array {
        if(!is_file($file))
            return null;
        try {
            $data=@include $file;
        } catch(\Throwable) {
            $this->removeFile($file);
            return null;
        }
        if(!is_array($data)
            ||!isset($data['fingerprint'])
            ||!is_string($data['fingerprint'])
            ||!array_key_exists('payload',$data)) {
            $this->removeFile($file);
            return null;
        }
        return array(
            'fingerprint'=>$data['fingerprint'],
            'context'=>isset($data['context'])&&is_string($data['context'])?$data['context']:null,
            'payload'=>$data['payload']
        );
    }

    /**
     * 写入缓存文件
     *
     * @access private
     * @param string $file 文件路径
     * @param string $fingerprint 源指纹
     * @param mixed $payload 数据
     * @param string|null $context 配置上下文
     * @return bool
     */
    private function write(string $file,string $fingerprint,mixed $payload,?string $context=null): bool {
        try {
            $dir=dirname($file);
            $this->ensureDirectory($dir);
            $content="<?php\n\nreturn ".var_export(array(
                'fingerprint'=>$fingerprint,
                'context'=>$context,
                'created_at'=>time(),
                'payload'=>$payload
            ),true).";\n";
            $tmp=$dir.'/.cachetmp_'.str_replace('.','',uniqid('',true));
            $written=@file_put_contents($tmp,$content);
            if($written===false)
                return $this->writeFailed($tmp);
            if($written<strlen($content))
                return $this->writeFailed($tmp);
            if(!@rename($tmp,$file)) {
                @unlink($file);
                if(!@rename($tmp,$file))
                    return $this->writeFailed($tmp);
            }
            if(function_exists('opcache_invalidate'))
                @opcache_invalidate($file,true);
            return true;
        } catch(\Throwable) {
            return false;
        }
    }

    /**
     * 写入失败清理
     *
     * @access private
     * @param string $tmp 临时文件
     * @return false
     */
    private function writeFailed(string $tmp): bool {
        @unlink($tmp);
        return false;
    }

    /**
     * 判断值是否可由 var_export 导出
     *
     * @access private
     * @param mixed $value 值
     * @param int $depth 当前深度
     * @return bool
     */
    private function isExportable(mixed $value,int $depth=0): bool {
        if(is_object($value)||is_resource($value)||$value instanceof Closure)
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
     * 缓存根目录
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
     * 编译缓存目录
     *
     * @access private
     * @return string
     */
    private function compileRoot(): string {
        return $this->cacheRoot().'/compile';
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

    /**
     * 校验并返回缓存名
     *
     * @access private
     * @param string $name 缓存名
     * @return string
     * @throws Exception 缓存名非法
     */
    private function assertName(string $name): string {
        if(preg_match('/^[a-zA-Z0-9_-]+$/',$name)!==1)
            throw new Exception('Compile cache name is invalid.',self::ERROR_INVALID_NAME,array(
                'name'=>$name
            ));
        return $name;
    }

}
