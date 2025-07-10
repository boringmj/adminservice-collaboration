<?php

namespace base;

use AdminService\Config;
use AdminService\Exception;

/**
 * 模型基类
 * 
 * @access public
 * @abstract
 * @package base
 * @version 1.0.0
 */
abstract class Model extends Database {

    /**
     * 数据表名(自动添加数据表前缀,优先级高于 $table_name)
     * 
     * @var string
     */
    public string $table='';

    /**
     * 数据表名(默认不启用自动添加数据表前缀)
     * 
     * @var string
     */
    public string $table_name='';

    /**
     * 查询结果集
     * 
     * @var array
     */
    public array $result=[];

    /**
     * 构造函数
     * 
     * @access public
     * @param array $data 结果集
     * @return void
     * @throws Exception
     */
    public function __construct(array $data=[]) {
        $this->autoGetTable();
        $this->result=$data;
        parent::__construct();
    }

    /**
     * 自动判断数据表名
     *
     * @access private
     * @return void
     * @throws Exception
     */
    private function autoGetTable(): void {
        $prefix=Config::get('database.default.prefix');
        if(empty($this->table) && empty($this->table_name))
            $this->table_name=$prefix.$this->classToTable(get_class($this));
        else if(!empty($this->table))
            $this->table_name=$prefix.$this->table;
    }

    /**
     * 将类名转为下划线分隔的小写表名
     * 
     * @author DeepSeek
     * @access protected
     * @param string $class_name 类名
     * @return string
     */
    protected function classToTable(string $class_name): string {
        // 剥离命名空间，获取基本类名
        $base_class=basename(str_replace('\\','/',$class_name));
        // 处理三种转换场景：
        // 1. 小写字母后的大写字母（驼峰边界）
        // 2. 大写字母后的大写字母+小写字母（首字母缩写边界）
        // 3. 字母与数字边界
        $converted=preg_replace([
            '/(?<=[a-z])(?=[A-Z])/',         // userInfo → user_Info
            '/(?<=[A-Z])(?=[A-Z][a-z])/',    // XMLParser → XML_Parser
            '/(?<=[a-zA-Z])(?=\d)|(?<=\d)(?=[a-zA-Z])/'  // user2FA → user_2FA
        ],'_',$base_class);
        return strtolower($converted);
    }

    /**
     * 获取结果集中的属性是否存在
     *
     * @access public
     * @param string $name 属性名
     * @return mixed
     * @throws Exception
     */
    public function __get(string $name): mixed {
        // 检查结果集中是否存在该属性
        if(!array_key_exists($name,$this->result))
            throw new Exception('Property "'.$name.'" not found.');
        // 返回结果集中的属性值
        return $this->result[$name];
    }

    /**
     * 设置结果集中的属性
     * 
     * @access public
     * @param string $name 属性名
     * @param mixed $value 属性值
     * @return void
     * @throws Exception
     */
    public function __set(string $name,mixed $value): void {
        // 检查结果集中是否存在该属性
        if (!array_key_exists($name,$this->result))
            throw new Exception('Property "'.$name.'" not found.');
        // 设置结果集中的属性值
        $this->result[$name]=$value;
    }

    /**
     * 以数组的形式获取结果集
     */
    public function toArray(): array {
        return $this->result;
    }

    /**
     * 查询一条数据
     *  
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return static
     * @throws Exception
     */
    public function find(string|array $fields='*'): static {
        // 执行父类的查询方法
        return new static(parent::find($fields));
    }

    /**
     * 查询一条数据
     *  
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return static
     * @throws Exception
     */
    public function get(string|array $fields='*'): static {
        return $this->find($fields);
    }
    
    /**
     * 查询数据
     *  
     * @access public
     * @param string|array $fields 查询字段(默认为*)
     * @return Collection
     * @throws Exception
     */
    public function select(string|array $fields='*'): Collection {
        // 调用父类的查询方法
        return new Collection(parent::select($fields));
    }

}