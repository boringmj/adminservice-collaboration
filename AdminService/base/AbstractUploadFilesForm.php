<?php

namespace base;

use \Countable;
use \ArrayIterator;
use \IteratorAggregate;

/**
 * 表单上传文件类
 */
abstract class AbstractUploadFilesForm implements IteratorAggregate,Countable {

    /**
     * 上传文件实例
     * @var array<string,AbstractUploadFiles>
     */
    protected array $form_files=[];

    /**
     * 构造方法
     * 
     * @access public
     * @param string $dir 上传目录
     * @param array $files 上传文件列表
     * @return void
     */
    public function __construct(
        string $dir,
        array $files
    ) {
        $this->parse($files,$dir);
    }

    /**
     * 通过指定字段名获取上传文件
     * 
     * @access public
     * @param string $name 字段名
     * @return AbstractUploadFiles|null
     */
    public function getFilesByField(
        string $name
    ): ?AbstractUploadFiles {
        return $this->form_files[$name]??null;
    }

    /**
     * 通过指定字段名获取上传文件列表数组
     * 
     * @access public
     * @param string $name 字段名
     * @return array
     */
    public function toArrayByField(string $name): array {
        $files=$this->getFilesByField($name);
        if($files===null) return [];
        return $files->toArray();
    }

    /**
     * 转为数组返回
     * 
     * @access public
     * @return array
     */
    public function toArray(): array {
        return array_map(function($file) {
            return $file->toArray();
        },$this->getFiles());
    }

    /**
     * 获取迭代器
     * 
     * @access public
     * @return ArrayIterator<string,AbstractUploadFiles>
     */
    public function getIterator(): ArrayIterator {
        return new ArrayIterator($this->getFiles());
    }

    /**
     * 获取上传文件列表
     * 
     * @access public
     * @return array<string,AbstractUploadFiles>
     */
    public function getFiles(): array {
        return $this->form_files;
    }

    /**
     * 获取上传文件数量
     * 
     * @access public
     * @return int
     */
    public function count(): int {
        return count($this->getFiles());
    }

    /**
     * 构建一个空集
     * 
     * @access public
     * @return static
     */
    public function buildEmpty(): static {
        return new static('',[]);
    }

    /**
     * 解析上传文件列表
     * 
     * @access protected
     * @param array $files 上传文件列表
     * @param string $dir 上传目录
     * @return void
     */
    abstract protected function parse(
        array $files,
        string $dir
    ): void;

}