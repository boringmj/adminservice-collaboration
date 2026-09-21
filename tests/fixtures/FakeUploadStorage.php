<?php

namespace Tests\Fixtures;

use base\AbstractUploadFile;
use base\UploadStorageInterface;

/**
 * 测试用上传存储
 *
 * - 用删除临时文件模拟真实存储的 move 行为(CLI下 move_uploaded_file 不可用)
 */
class FakeUploadStorage implements UploadStorageInterface {

    /**
     * 最后一次保存的目标路径
     * @var string|null
     */
    public ?string $saved_to=null;

    /**
     * 是否在保存后移除临时文件
     * @var bool
     */
    public bool $remove_temp=true;

    /**
     * 保存文件
     *
     * @access public
     * @param AbstractUploadFile $file 文件对象
     * @return void
     */
    public function save(AbstractUploadFile $file): void {
        $this->saved_to=$file->getConfirmDir().DIRECTORY_SEPARATOR.$file->getConfirmName();
        if($this->remove_temp&&is_file($file->getTempPath()))
            unlink($file->getTempPath());
    }

    /**
     * 校验是否可以保存文件
     *
     * @access public
     * @param AbstractUploadFile $file 文件对象
     * @return void
     */
    public function validate(AbstractUploadFile $file): void { }

    /**
     * 获取文件保存地址
     *
     * @access public
     * @return string
     */
    public function getLastSavePath(): string {
        return $this->saved_to??'';
    }

}
