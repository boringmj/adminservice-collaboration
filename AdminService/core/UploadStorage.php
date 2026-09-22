<?php

namespace AdminService;

use AdminService\Config\Repository;
use base\ConfigInterface;

use base\AbstractUploadFile;
use base\UploadStorageInterface;
use AdminService\exception\UploadStorageException;

use function file_exists;
use function is_dir;
use function mkdir;

final class UploadStorage implements UploadStorageInterface {


    /**
     * 配置(构造期可注入; 未注入时回落门面当前那份 —— 手工构造 / 测试用)
     * @var ConfigInterface|null
     */
    private ?ConfigInterface $config=null;

    /**
     * 取配置契约实例
     *
     * - 容器构建本对象时由构造参数注入(即 `Application::init()` 登记进容器的那一份)
     * - 手工 `new` 时没有注入 → **每次**回落门面当前那份(**不缓存**);
     *   注入过的那份则保持不变(这就是"配置是快照"的语义)
     *
     * @access private
     * @return ConfigInterface
     */
    private function config(): ConfigInterface {
        return $this->config??Config::repository()??new Repository(array());
    }
    /**
     * 构造方法
     *
     * @access public
     * @param ConfigInterface|null $config 配置契约实例(未注入时回落门面当前那份)
     */
    public function __construct(?ConfigInterface $config=null) {
        $this->config=$config;
    }

    /**
     * 最后一次最终保存路径
     * @var string $last_save_path
     */
    protected ?string $last_save_path=null;

    /**
     * 保存文件
     *
     * - 目标目录由存储方准备: 上传目录不再在请求解析阶段创建
     *
     * @access public
     * @param AbstractUploadFile $file 文件对象
     * @return void
     */
    public function save(AbstractUploadFile $file): void {
        $this->prepareDir($file->getConfirmDir());
        $this->validate($file);
        $save_path=$this->generateSavePath($file);
        if(!@move_uploaded_file($file->getTempPath(),$save_path))
            throw new UploadStorageException('保存文件失败');
        $real_path=realpath($save_path);
        $this->last_save_path=$real_path===false?$save_path:$real_path;
    }

    /**
     * 校验是否可以保存文件
     *
     * @access public
     * @param AbstractUploadFile $file 文件对象
     * @return void
     */
    public function validate(AbstractUploadFile $file): void {
        // 检查存放目录是否可写
        $dir=$file->getConfirmDir();
        if(!is_dir($dir)||!is_writable($dir))
            throw new UploadStorageException('保存目录不可写或不为目录');
        // 检查文件是否存在
        $temp_path=$file->getTempPath();
        if(!file_exists($temp_path)||!is_readable($temp_path))
            throw new UploadStorageException('文件不存在或不可读');
    }

    /**
     * 获取文件保存地址
     *
     * @access public
     * @return string
     */
    public function getLastSavePath(): string {
        if($this->last_save_path!==null) return $this->last_save_path;
        throw new UploadStorageException('文件未保存');
    }

    /**
     * 准备保存目录
     *
     * @access protected
     * @param string $dir 保存目录
     * @throws UploadStorageException
     * @return void
     */
    protected function prepareDir(string $dir): void {
        if(is_dir($dir)) return;
        $dir_mode=$this->config()->get('request.default.upload.save.mode',0755);
        if(!@mkdir($dir,$dir_mode,true)&&!is_dir($dir))
            throw new UploadStorageException('创建上传目录失败');
    }

    /**
     * 生成最终保存路径
     *
     * @access protected
     * @param AbstractUploadFile $file 文件对象
     * @return string
     */
    protected function generateSavePath(AbstractUploadFile $file): string {
        $path=$file->getConfirmDir()
            .DIRECTORY_SEPARATOR
            .$file->getConfirmName();
        return $path;
    }

}
