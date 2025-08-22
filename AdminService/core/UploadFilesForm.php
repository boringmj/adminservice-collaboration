<?php

namespace AdminService;

use AdminService\App;
use base\AbstractUploadFilesForm;

class UploadFilesForm extends AbstractUploadFilesForm {

    /**
     * 解析上传文件列表
     * 
     * @access protected
     * @param array $files 上传文件列表
     * @param string $dir 上传目录
     * @return void
     */
    protected function parse(
        array $files,
        string $dir
    ): void {
        foreach($files as $name=>$file) {
            $this->form_files[$name]=App::new(UploadFiles::class,$dir,$file);
        }
    }

}