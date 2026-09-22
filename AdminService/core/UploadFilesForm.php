<?php

namespace AdminService;
use AdminService\Config\Repository;
use base\Attribute\AutowireProperty;
use base\ConfigInterface;

use base\AbstractUploadFilesForm;

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
            $this->form_files[$name]=App::new(UploadFiles::class,$dir,$file,$rules);
        }
    }

}
