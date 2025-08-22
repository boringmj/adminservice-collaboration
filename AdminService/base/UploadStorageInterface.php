<?php

namespace base;

interface UploadStorageInterface {

    /**
     * 保存文件
     * 
     * @access public
     * @param AbstractUploadFile $file 文件对象
     * @return void
     */
    public function save(AbstractUploadFile $file): void;

    /**
     * 校验是否可以保存文件
     * 
     * @access public
     * @param AbstractUploadFile $file 文件对象
     * @return void
     */
    public function validate(AbstractUploadFile $file): void;

    /**
     * 获取文件保存地址
     * 
     * @access public
     * @return string
     */
    public function getLastSavePath(): string;

}