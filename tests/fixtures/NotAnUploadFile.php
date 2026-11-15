<?php

namespace Tests\Fixtures;

/**
 * 上传文件错误绑定的替身
 */
class NotAnUploadFile {

    /**
     * 接受与上传文件相同的构造参数
     *
     * @param string $name 文件名
     * @param string $type MIME 类型
     * @param int $size 文件大小
     * @param string $extension 扩展名
     * @param string $path 临时路径
     * @param string $confirm_dir 确认目录
     * @param string $hash_algo 哈希算法
     */
    public function __construct(
        string $name='',
        string $type='',
        int $size=0,
        string $extension='',
        string $path='',
        string $confirm_dir='',
        string $hash_algo=''
    ) {}

}
