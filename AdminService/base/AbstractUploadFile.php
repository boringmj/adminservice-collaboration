<?php

namespace base;

/**
 * 上传文件类
 */
abstract class AbstractUploadFile {

    /**
     * 文件名
     * @var string
     */
    protected string $name='';

    /**
     * 文件类型
     * @var string
     */
    protected string $type='';

    /**
     * 文件大小
     * @var int
     */
    protected int $size=0;

    /**
     * 文件扩展名
     * @var string
     */
    protected string $extension='';

    /**
     * 文件哈希值
     * @var string
     */
    protected ?string $hash=null;

    /**
     * 临时文件路径
     * @var string
     */
    protected string $path='';

    /**
     * 确认上传目录
     * @var string
     */
    protected string $confirm_dir='';

    /**
     * 最终上传名称
     * @var string|null
     */
    protected ?string $confirm_name=null;

    /**
     * 默认hash算法
     * @var string
     */
    protected string $hash_algo='';

    /**
     * 构造方法
     * 
     * @access public
     * @param string $name 文件名
     * @param string $type 文件类型
     * @param int $size 文件大小
     * @param string $extension 文件扩展名
     * @param string $path 临时文件路径
     * @param string $confirm_dir 确认上传目录
     * @param string $hash_algo 默认hash算法
     * @return void
     */
    public function __construct(
        string $name,
        string $type,
        int $size,
        string $extension,
        string $path,
        string $confirm_dir,
        string $hash_algo='sha1'
    ) {
        $this->name=$name;
        $this->type=$type;
        $this->size=$size;
        $this->extension=$extension;
        $this->path=$path;
        $this->confirm_dir=$confirm_dir;
        $this->hash_algo=$hash_algo;
    }

    /**
     * 获取文件名称
     * 
     * @access public
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * 获取文件类型
     * 
     * @access public
     * @return string
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * 获取文件大小
     * 
     * @access public
     * @return int
     */
    public function getSize(): int {
        return $this->size;
    }

    /**
     * 获取文件扩展名
     * 
     * @access public
     * @return string
     */
    public function getExtension(): string {
        return $this->extension;
    }

    /**
     * 获取文件临时存放目录
     * 
     * @access public
     * @return string
     */
    public function getTempPath(): string {
        return $this->path;
    }

    /**
     * 获取确认上传目录
     * 
     * @access public
     * @return string
     */
    public function getConfirmDir(): string {
        return $this->confirm_dir;
    }

    /**
     * 获取文件哈希值(使用默认算法)
     * 
     * @access public
     * @return string
     */
    public function getHash(): string {
        if($this->hash==null)
            $this->hash=$this->calcHash($this->hash_algo);
        return $this->hash;
    }

    /**
     * 设置最终上传名称(完整名称,如果不包含扩展名则保存后同样不包含扩展名)
     * 
     * @access public
     * @param string $name 最终上传名称
     * @return static
     */
    public function setConfirmName(string $name): static {
        $this->confirm_name=$name;
        return $this;
    }

    /**
     * 获取最终上传名称(完整名称,如果不包含扩展名则保存后同样不包含扩展名)
     * 
     * @access public
     * @return string
     */
    function getConfirmName(): string {
        if(!empty($this->confirm_name))
            return $this->confirm_name;
        return $this->confirm_name=$this->generateRandomName();
    }

    /**
     * 生成一个随机文件名(纯生成,不会修改确认名)
     * 
     * @access public
     * @param string $prefix 前缀
     * @param bool $with_extension 是否包含扩展名
     * @return string
     */
    public function generateRandomName(string $prefix='upload_', bool $with_extension=true): string {
        $extension=$with_extension&&$this->extension!==''?'.'.$this->extension:'';
        return uniqid($prefix,false).bin2hex(random_bytes(4)).$extension;
    }

    /**
     * 计算文件哈希值
     * 
     * @access public
     * @param string|null $algo 哈希算法
     * @throws UploadExceptionInterface
     * @return string
     */
    abstract public function calcHash(?string $algo=null): string;

    /**
     * 保存文件
     * 
     * @access public
     * @param UploadStorageInterface $upload_storage 文件存储对象
     * @throws UploadExceptionInterface
     * @return void
     */
    abstract public function save(UploadStorageInterface $upload_storage): void;

    /**
     * 获取最终保存路径
     * 
     * @access public
     * @throws UploadExceptionInterface
     * @return string
     */
    abstract function getSavePath(): string;

    /**
     * 返回文件信息数组
     * 
     * @access public
     * @return array
     */
    abstract public function toArray(): array;

}