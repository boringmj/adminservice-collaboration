<?php

namespace AdminService;
use AdminService\Config\Repository;
use base\Attribute\AutowireProperty;
use base\ConfigInterface;

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
     * 配置(由容器装配时注入; 直接 new 时为 null → 每次回落门面当前那份)
     * @var ConfigInterface|null
     */
    #[AutowireProperty(ConfigInterface::class)]
    private ?ConfigInterface $config=null;

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
                $this->form_files[$name]=App::new(UploadFiles::class,$dir,$file,$rules);
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
        $this->form_files[$field]=App::new(UploadFiles::class,$dir,$file,$rules);
    }

}
