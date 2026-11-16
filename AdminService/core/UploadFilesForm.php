<?php

namespace AdminService;
use AdminService\Config\Repository;
use base\ConfigInterface;
use base\Container as ContainerContract;

use base\AbstractUploadFilesForm;

use function array_key_exists;
use function is_array;
use function is_string;

/**
 * 表单上传文件类
 *
 * - 作为构建 `UploadFiles` 的工厂: 上传规则在此一次性读入, 无文件时不读配置
 */
class UploadFilesForm extends AbstractUploadFilesForm {
    /**
     * 配置(构造时注入; 未注入时回落门面当前那份)
     *
     * - 不使用属性自动注入: 构造参数显式传入的配置必须优先
     * @var ConfigInterface|null
     */
    private ?ConfigInterface $config=null;

    /**
     * 请求级容器(用于构建 UploadFiles)
     * @var ContainerContract|null
     */
    private ?ContainerContract $container=null;

    /**
     * 文件是否由驱动托管
     * @var bool
     */
    private bool $trusted_files=false;

    /**
     * 构造方法
     *
     * @access public
     * @param string $dir 上传目录
     * @param array<string,mixed> $files 上传文件列表
     * @param ContainerContract|null $container 请求级容器
     * @param ConfigInterface|null $config 配置契约实例
     * @param bool $trusted_files 文件是否由驱动托管
     */
    public function __construct(
        string $dir,
        array $files,
        ?ContainerContract $container=null,
        ?ConfigInterface $config=null,
        bool $trusted_files=false
    ) {
        $this->container=$container;
        $this->config=$config;
        $this->trusted_files=$trusted_files;
        parent::__construct($dir,$files);
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
     * 解析上传文件列表
     *
     * @access protected
     * @param array<string,mixed> $files 上传文件列表
     * @param string $dir 上传目录
     * @return void
     */
    protected function parse(
        array $files,
        string $dir
    ): void {
        if($files===array()) return;
        $rules=$this->config()->get('request.default.upload',array());
        foreach($files as $name=>$file) {
            if(!is_array($file))
                continue;
            if($this->hasNestedFiles($file))
                $this->parseNested((string)$name,array(),$file,$dir,$rules);
            else
                $this->form_files[$name]=$this->buildFiles($dir,$file,$rules);
        }
    }

    /**
     * 判断上传字段是否包含嵌套数组(name[a][b] / name[a][])
     *
     * @access private
     * @param array<string,mixed> $file 上传字段
     * @return bool
     */
    private function hasNestedFiles(array $file): bool {
        $names=$file['name']??null;
        if(!is_array($names))
            return false;
        foreach($names as $key=>$name) {
            // 字符串键(name[a])或继续嵌套(name[0][x])才是嵌套字段;
            // 纯数字键 + 标量叶子(name[])仍按多文件字段处理
            if(is_string($key)||is_array($name))
                return true;
        }
        return false;
    }

    /**
     * 将嵌套上传字段展开为 `name[a][b]` 形式的独立字段
     *
     * @access private
     * @param string $prefix 顶层字段名
     * @param array<int|string> $path 当前嵌套路径
     * @param array<string,mixed> $file 当前层上传结构
     * @param string $dir 上传目录
     * @param array<string,mixed> $rules 上传规则
     * @return void
     */
    private function parseNested(
        string $prefix,
        array $path,
        array $file,
        string $dir,
        array $rules
    ): void {
        $names=$file['name']??null;
        if(is_array($names)) {
            foreach($names as $key=>$name) {
                $child=array();
                foreach(array('name','type','tmp_name','error','size') as $field) {
                    $value=null;
                    if(isset($file[$field])&&is_array($file[$field])&&array_key_exists($key,$file[$field]))
                        $value=$file[$field][$key];
                    $child[$field]=$value;
                }
                $next=$path;
                $next[]=$key;
                $this->parseNested($prefix,$next,$child,$dir,$rules);
            }
            return;
        }
        $field=$prefix;
        foreach($path as $key)
            $field.='['.$key.']';
        $this->form_files[$field]=$this->buildFiles($dir,$file,$rules);
    }

    /**
     * 构建上传文件列表
     *
     * @access private
     * @param string $dir 上传目录
     * @param array<string,mixed> $file 上传字段
     * @param array<string,mixed> $rules 上传规则
     * @return UploadFiles
     */
    private function buildFiles(string $dir,array $file,array $rules): UploadFiles {
        if($this->container!==null)
            return $this->container->build(
                UploadFiles::class,
                dir:$dir,
                files:$file,
                rules:$rules,
                container:$this->container,
                trusted_files:$this->trusted_files
            );
        return new UploadFiles($dir,$file,$rules,null,$this->trusted_files);
    }

}
