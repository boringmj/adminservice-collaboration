<?php

namespace AdminService;

use base\AbstractUploadFilesForm;

/**
 * 表单上传文件类
 *
 * - 作为构建 `UploadFiles` 的工厂: 上传规则在此一次性读入, 无文件时不读配置
 */
class UploadFilesForm extends AbstractUploadFilesForm {

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
        $rules=Config::get('request.default.upload',array());
        foreach($files as $name=>$file) {
            $this->form_files[$name]=App::new(UploadFiles::class,$dir,$file,$rules);
        }
    }

}
