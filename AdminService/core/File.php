<?php

namespace AdminService;

use AdminService\Config\Repository;
use base\ConfigInterface;

use Throwable;

use function array_keys;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function floor;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function json_last_error_msg;
use function mkdir;
use function preg_match;
use function strlen;
use function time;
use function unlink;
use function AdminService\common\uuid;


final class File {

    /**
     * 错误码(每类失败使用唯一值)
     */
    private const ERROR_INVALID_NAME=-1;
    private const ERROR_READ_FAILED=100101;
    private const ERROR_DECODE_FAILED=100102;
    private const ERROR_WRITE_FAILED=100103;
    private const ERROR_ENCODE_FAILED=100104;
    private const ERROR_DESTROY_NOT_INITIALIZED=100105;
    private const ERROR_DESTROY_FAILED=100106;
    private const ERROR_FILE_NOT_FOUND=100107;
    private const ERROR_SAVE_NOT_INITIALIZED=100108;
    private const ERROR_DIRECTORY_FAILED=100109;
    private const ERROR_PARTIAL_WRITE=100110;

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
     *   注入过的那份则固定使用(不跟随门面切换; 实例本身仍可被修改, 不是值快照)
     *
     * @access private
     * @return ConfigInterface
     */
    private function config(): ConfigInterface {
        return $this->config??Config::repository()??new Repository(array());
    }

    /**
     * 文件绝对路径
     * @var string|null
     */
    private ?string $file_path;

    /**
     * 数据
     * @var array
     */
    private array $data;

    /**
     * 构造方法(如果有传入路径,则将会自动初始化)
     *
     * @access public
     * @param string|null $file_name 文件名称(不含扩展名和多余的路径)
     * @throws \AdminService\Exception
     * @param ConfigInterface|null $config 配置契约实例(未注入时回落门面当前那份)
     */
    public function __construct(?string $file_name=null,?ConfigInterface $config=null) {
        $this->config=$config;
        $this->init($file_name);
    }

    /**
     * 初始化方法
     *
     * @access public
     * @param string|null $file_name 文件名称(不含扩展名和多余的路径)
     * @return void
     * @throws \AdminService\Exception
     */
    public function init(?string $file_name=null): void {
        if($file_name===null) {
            $file_name_cycle=$this->config()->get("data.name_cycle");
            if($file_name_cycle>0)
                $file_name='cache_'.floor(time()/$file_name_cycle);
            else
                $file_name='cache_'.uuid();
        }
        if(!preg_match('/^[a-zA-Z0-9_-]+$/',$file_name))
            throw new Exception('File name is invalid',self::ERROR_INVALID_NAME);
        $this->file_path=$this->config()->get("data.path").'/'.$file_name.$this->config()->get("data.ext_name");
        // 补全目录; 创建失败时转成受控异常, 不把 warning 留给全局错误栈
        $dir=dirname($this->file_path);
        if(!is_dir($dir)) {
            if(!@mkdir($dir,$this->config()->get("data.dir_mode"),true)&&!is_dir($dir))
                throw new Exception("File directory create failed: $dir, please check the path permission.",self::ERROR_DIRECTORY_FAILED,array(
                    'dir'=>$dir
                ));
        }
        // 读取数据
        $this->read();
    }

    /**
     * 将数据读取到缓存中
     *
     * @access private
     * @return void
     * @throws \AdminService\Exception
     */
    private function read(): void {
        if(!is_file($this->file_path)) {
            $this->data=array();
            return;
        }
        // 预期 I/O 失败统一抑制 warning, 由异常携带错误码; 避免污染全局错误栈
        $data=@file_get_contents($this->file_path);
        if($data===false)
            throw new Exception("File read failed: $this->file_path, please check the file permission.",self::ERROR_READ_FAILED,array(
                'file_path'=>$this->file_path
            ));
        $data=json_decode($data,true);
        if($data===null)
            throw new Exception("File decode failed: $this->file_path, please check the file content.",self::ERROR_DECODE_FAILED,array(
                'file_path'=>$this->file_path
            ));
        $this->data=$data;
    }

    /**
     * 将缓存中的数据写入文件
     *
     * @access private
     * @return void
     * @throws \AdminService\Exception
     */
    private function write(): void {
        $data=json_encode($this->data);
        if($data===false)
            throw new Exception("File encode failed: $this->file_path, please check the file content.",self::ERROR_ENCODE_FAILED,array(
                'file_path'=>$this->file_path,
                'json_error'=>json_last_error_msg()
            ));
        $expected=strlen($data);
        try {
            $written=@file_put_contents($this->file_path,$data);
        } catch(Throwable $e) {
            throw new Exception("File write failed: $this->file_path, please check the file permission.",self::ERROR_WRITE_FAILED,array(
                'file_path'=>$this->file_path,
                'exception'=>$e->getMessage()
            ));
        }
        if($written===false)
            throw new Exception("File write failed: $this->file_path, please check the file permission.",self::ERROR_WRITE_FAILED,array(
                'file_path'=>$this->file_path
            ));
        if($written<$expected)
            throw new Exception("File write incomplete: $this->file_path.",self::ERROR_PARTIAL_WRITE,array(
                'file_path'=>$this->file_path,
                'expected'=>$expected,
                'written'=>$written
            ));
    }

    /**
     * 获取数据
     * 
     * @access public
     * @param string|int $key 键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(string|int $key,mixed $default=null): mixed {
        if(!array_key_exists($key,$this->data))
            return $default;
        return $this->data[$key];
    }

    /**
     * 设置数据
     *
     * @access public
     * @param string|int $key 键名
     * @param mixed $value 值
     * @param bool $save 是否立即保存
     * @return void
     * @throws \AdminService\Exception
     */
    public function set(string|int $key,mixed $value,bool $save=false): void {
        $this->data[$key]=$value;
        if($save)
            $this->write();
    }

    /**
     * 删除数据
     *
     * @access public
     * @param string|int $key 键名
     * @param bool $save 是否立即保存
     * @return void
     * @throws \AdminService\Exception
     */
    public function delete(string|int $key,bool $save=false): void {
        if(array_key_exists($key,$this->data))
            unset($this->data[$key]);
        if($save)
            $this->write();
    }

    /**
     * 清空数据
     *
     * @access public
     * @param bool $save 是否立即保存
     * @return void
     * @throws \AdminService\Exception
     */
    public function clear(bool $save=false): void {
        $this->data=array();
        if($save)
            $this->write();
    }

    /**
     * 保存数据
     *
     * @access public
     * @return void
     * @throws \AdminService\Exception
     */
    public function save(): void {
        if(empty($this->file_path))
            throw new Exception("File not saved, please use init() to initialize.",self::ERROR_SAVE_NOT_INITIALIZED);
        $this->write();
    }

    /**
     * 销毁数据
     *
     * @access public
     * @return void
     * @throws \AdminService\Exception
     */
    public function destroy(): void {
        if(empty($this->file_path))
            throw new Exception("File not destroyed, please use init() to initialize.",self::ERROR_DESTROY_NOT_INITIALIZED);
        if(is_file($this->file_path)) {
            try {
                if(!@unlink($this->file_path))
                    throw new Exception("File destroy failed: $this->file_path, please check the file permission.",self::ERROR_DESTROY_FAILED,array(
                        'file_path'=>$this->file_path
                    ));
                $this->file_path=null;
                $this->data=array();
            } catch(Throwable $e) {
                if($e instanceof Exception)
                    throw $e;
                throw new Exception("File destroy failed: $this->file_path, please check the file permission.",self::ERROR_DESTROY_FAILED,array(
                    'file_path'=>$this->file_path
                ));
            }
        }
        else
            throw new Exception("File not found: $this->file_path, please check the file path.",self::ERROR_FILE_NOT_FOUND,array(
                'file_path'=>$this->file_path
            ));
    }

    /**
     * 返回全部数据
     * 
     * @access public
     * @param bool $only_key 是否只返回键名
     * @return array
     */
    public function all(bool $only_key=false): array {
        if($only_key)
            return array_keys($this->data);
        return $this->data;
    }

}