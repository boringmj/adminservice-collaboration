<?php

namespace AdminService\View\Cache;

use AdminService\Cache\CompileStore;
use AdminService\View\Compiler\CompiledClosure;
use AdminService\View\Compiler\PhpCompiler;
use base\ConfigInterface;
use base\View\CodeTemplateCompilerInterface;
use base\View\CompiledTemplateInterface;
use base\View\TemplateCacheInterface;
use base\View\TemplateCompilerInterface;
use base\View\TemplateSourceInterface;
use Closure;
use Throwable;

use function dirname;
use function file_put_contents;
use function function_exists;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function mkdir;
use function opcache_invalidate;
use function rename;
use function rmdir;
use function scandir;
use function sha1;
use function strlen;
use function uniqid;
use function unlink;
use function var_export;

/**
 * 模板编译产物文件缓存
 *
 * - 复用 `cache.compile` 的模式与开关
 * - 写入使用临时文件 + rename; 目录不可写时退化为解释器
 *
 * @access public
 * @package AdminService\View\Cache
 * @version 1.0.0
 */
final class FileCache implements TemplateCacheInterface {

    /**
     * 编译缓存存储
     * @var CompileStore
     */
    private CompileStore $store;

    /**
     * 构造方法
     *
     * @access public
     * @param ConfigInterface|null $config 配置(null = 门面当前配置)
     * @param bool $force 是否忽略 debug 跳过名单
     */
    public function __construct(?ConfigInterface $config=null,bool $force=false) {
        $this->store=new CompileStore($config);
        if($force)
            $this->store->force(true);
    }

    /**
     * 读取编译产物, 未命中时编译并写入
     *
     * @access public
     * @param TemplateSourceInterface $source 模板来源
     * @param TemplateCompilerInterface $compiler 编译器
     * @return CompiledTemplateInterface
     */
    public function load(TemplateSourceInterface $source,TemplateCompilerInterface $compiler): CompiledTemplateInterface {
        $path=$source->path();
        if($path===null
            ||!$this->store->enabled('view')
            ||!$compiler instanceof CodeTemplateCompilerInterface)
            return $compiler->compile($source);
        $file=$this->file($path);
        $validate=$this->store->shouldValidate();
        if(is_file($file)) {
            $payload=$this->read($file);
            if($this->valid($payload,$source,$validate))
                return new CompiledClosure($payload['compiled']);
            @unlink($file);
        }
        // 先取指纹再读取模板: 文件在这两步之间变化时, 旧指纹会让下一次校验触发重建
        $fingerprint=$source->fingerprint();
        $code=$compiler->code($source);
        if($this->write($file,$fingerprint,$code,$path)) {
            $payload=$this->read($file);
            if($this->valid($payload,$source,$validate))
                return new CompiledClosure($payload['compiled']);
        }
        return $compiler->compile($source);
    }

    /**
     * 强制编译并写入缓存
     *
     * - 供命令行预热使用，不读取旧缓存
     *
     * @access public
     * @param TemplateSourceInterface $source 模板来源
     * @param TemplateCompilerInterface $compiler 编译器
     * @return bool 是否写入成功
     */
    public function build(TemplateSourceInterface $source,TemplateCompilerInterface $compiler): bool {
        $path=$source->path();
        if($path===null
            ||!$this->store->enabled('view')
            ||!$compiler instanceof CodeTemplateCompilerInterface)
            return false;
        $fingerprint=$source->fingerprint();
        $code=$compiler->code($source);
        return $this->write($this->file($path),$fingerprint,$code,$path);
    }

    /**
     * 清理缓存
     *
     * @access public
     * @return bool
     */
    public function clear(): bool {
        $dir=$this->directory();
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
            if(is_dir($path)&&!is_link($path))
                $ok=$this->clearDirectory($path)&&$ok;
            else
                $ok=(@unlink($path)&&$ok);
        }
        return @rmdir($dir)&&$ok;
    }

    /**
     * 递归清理子目录
     *
     * @access private
     * @param string $dir 目录
     * @return bool
     */
    private function clearDirectory(string $dir): bool {
        $entries=@scandir($dir);
        if($entries===false)
            return false;
        $ok=true;
        foreach($entries as $entry) {
            if($entry==='.'||$entry==='..')
                continue;
            $path=$dir.'/'.$entry;
            if(is_dir($path)&&!is_link($path))
                $ok=$this->clearDirectory($path)&&$ok;
            else
                $ok=(@unlink($path)&&$ok);
        }
        return @rmdir($dir)&&$ok;
    }

    /**
     * 读取缓存文件
     *
     * @access private
     * @param string $file 文件
     * @return array{version:string,fingerprint:string,source:string,compiled:mixed}|null
     */
    private function read(string $file): ?array {
        try {
            $payload=@include $file;
        } catch(Throwable) {
            return null;
        }
        if(!is_array($payload)
            ||!isset($payload['version'],$payload['fingerprint'],$payload['source'],$payload['compiled'])
            ||!is_string($payload['version'])
            ||!is_string($payload['fingerprint'])
            ||!is_string($payload['source'])
            ||!$payload['compiled'] instanceof Closure)
            return null;
        return $payload;
    }

    /**
     * 校验缓存产物
     *
     * @access private
     * @param array{version:string,fingerprint:string,source:string,compiled:mixed}|null $payload 产物
     * @param TemplateSourceInterface $source 来源
     * @param bool $validate 是否校验源指纹
     * @return bool
     */
    private function valid(?array $payload,TemplateSourceInterface $source,bool $validate): bool {
        if($payload===null||$payload['version']!==PhpCompiler::VERSION)
            return false;
        return !$validate||$payload['fingerprint']===$source->fingerprint();
    }

    /**
     * 写入缓存文件
     *
     * @access private
     * @param string $file 文件
     * @param string $fingerprint 指纹
     * @param string $code 闭包代码
     * @param string $source_path 模板路径
     * @return bool
     */
    private function write(string $file,string $fingerprint,string $code,string $source_path): bool {
        $dir=dirname($file);
        $mode=(int)$this->store->config()->get('cache.dir_mode',0755);
        if($mode<=0)
            $mode=0755;
        if(!is_dir($dir)&&!@mkdir($dir,$mode,true)&&!is_dir($dir))
            return false;
        $content="<?php\n\nreturn array(\n"
            ."    'version'=>".var_export(PhpCompiler::VERSION,true).",\n"
            ."    'fingerprint'=>".var_export($fingerprint,true).",\n"
            ."    'source'=>".var_export($source_path,true).",\n"
            ."    'compiled'=>".$code.",\n"
            .");\n";
        $tmp=$dir.'/.viewtmp_'.uniqid('',true);
        $written=@file_put_contents($tmp,$content);
        if($written===false||$written<strlen($content)) {
            @unlink($tmp);
            return false;
        }
        if(!@rename($tmp,$file)) {
            @unlink($file);
            if(!@rename($tmp,$file)) {
                @unlink($tmp);
                return false;
            }
        }
        if(function_exists('opcache_invalidate'))
            @opcache_invalidate($file,true);
        return true;
    }

    /**
     * 缓存文件路径
     *
     * @access private
     * @param string $path 模板路径
     * @return string
     */
    private function file(string $path): string {
        return $this->directory().'/'.sha1($path).'.php';
    }

    /**
     * 缓存目录
     *
     * @access private
     * @return string
     */
    private function directory(): string {
        return dirname($this->store->path('view')).'/view';
    }

}
