<?php

namespace AdminService;

use AdminService\Config;
use AdminService\Exception;

final class File {

    /**
     * 文件绝对路径
     * @var string
     */
    private string $file_path;

    /**
     * 数据
     * @var array
     */
    private array $data;

    /**
     * 构造方法(如果有传入路径,则将会自动初始化)
     * 
     * @access public
     * @param string $file_name 文件名称(不含扩展名和多余的路径)
     */
    public function __construct(?string $file_name=null) {
        if($file_name!==null)
            $this->init($file_name);
    }

    /**
     * 初始化方法
     * 
     * @access public
     * @param string $file_name 文件名称(不含扩展名和多余的路径)
     * @return void
     */
    public function init(?string $file_name=null): void {
        if($file_name===null)
            $file_name='cache_'.\AdminService\common\uuid();
        if(!preg_match('/^[a-zA-Z0-9_-]+$/',$file_name))
            throw new Exception('File name is invalid',-1);
        $this->file_path=Config::get("data.path").'/'.$file_name.Config::get("data.ext_name");
        // 补全目录
        $dir=dirname($this->file_path);
        if(!is_dir($dir))
            mkdir($dir,Config::get("data.dir_mode"),true);
        // 读取数据
        $this->read();
    }

    /**
     * 将数据读取到缓存中
     * 
     * @access private
     * @return void
     */
    private function read(): void {
        if(!is_file($this->file_path)) {
            $this->data=array();
            return;
        }
        $data=file_get_contents($this->file_path);
        if($data===false)
            throw new Exception("File read failed: {$this->file_path}, please check the file permission.",100101,array(
                'file_path'=>$this->file_path
            ));
        $data=json_decode($data,true);
        if($data===null)
            throw new Exception("File decode failed: {$this->file_path}, please check the file content.",100102,array(
                'file_path'=>$this->file_path
            ));
        $this->data=$data;
    }

    /**
     * 将缓存中的数据写入文件
     * 
     * @access private
     * @return void
     */
    private function write(): void {
        $data=json_encode($this->data);
        try {
            $result=file_put_contents($this->file_path,$data);
            if($result===false)
                throw new Exception("File write failed: {$this->file_path}, please check the file permission.",100103,array(
                    'file_path'=>$this->file_path
                ));
        } catch (\Throwable) {
            throw new Exception("File write failed: {$this->file_path}, please check the file permission.",100103,array(
                'file_path'=>$this->file_path
            ));
        }
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
        if(!isset($this->data[$key]))
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
     */
    public function delete(string|int $key,bool $save=false): void {
        if(isset($this->data[$key]))
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
     */
    public function save(): void {
        if(empty($this->file_path))
            throw new Exception("File not saved, please use init() to initialize.",100104);
        $this->write();
    }

    /**
     * 销毁数据
     * 
     * @access public
     * @return void
     */
    public function destroy(): void {
        if(empty($this->file_path))
            throw new Exception("File not destroyed, please use init() to initialize.",100105);
        if(is_file($this->file_path))
        {
            try {
                $result=unlink($this->file_path);
                $this->file_path=null;
                $this->data=array();
            } catch (\Throwable $e) {
                throw new Exception("File destroy failed: {$this->file_path}, please check the file permission.",100106,array(
                    'file_path'=>$this->file_path
                ));
            }
        }
        else
            throw new Exception("File not found: {$this->file_path}, please check the file path.",100107,array(
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

?>