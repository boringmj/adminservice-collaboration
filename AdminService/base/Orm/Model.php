<?php

namespace base\Orm;

use base\Database\Db;
use base\Database\Query\Query;

use function array_key_exists;
use function basename;
use function in_array;
use function method_exists;
use function preg_replace;
use function str_replace;
use function strtolower;

/**
 * 模型基类(实体)
 *
 * - 代表数据库中的一行记录, 纯数据职责
 * - 查询通过静态入口(如 Model::query() / Model::where())委托给 ModelQueryBuilder
 * - 行与对象的转换(水合)由构建器与 newFromRow 完成
 * - 关系: 通过 hasMany/hasOne/belongsTo 声明, $model->relation 惰性加载
 */
abstract class Model {

    /**
     * 数据库入口(共享, 测试可通过 setDb 注入)
     * @var Db|null
     */
    protected static ?Db $db=null;

    /**
     * 数据表名(为空时由类名自动推导)
     * @var string
     */
    protected static string $table='';

    /**
     * 主键字段名
     * @var string
     */
    protected static string $primaryKey='id';

    /**
     * 批量赋值白名单(为空时全部字段可批量赋值)
     * @var array
     */
    protected array $fillable=array();

    /**
     * 属性(数据库行数据)
     * @var array
     */
    protected array $attributes=array();

    /**
     * 已加载的关系
     * @var array
     */
    protected array $relations=array();

    /**
     * 是否已存在于数据库中
     * @var bool
     */
    protected bool $exists=false;

    /**
     * 是否自动维护时间戳
     * @var bool
     */
    protected static bool $timestamps=true;

    /**
     * 是否启用软删除
     * @var bool
     */
    protected static bool $softDelete=false;

    /**
     * 创建时间字段名
     * @var string
     */
    protected static string $createdAtField='created_at';

    /**
     * 更新时间字段名
     * @var string
     */
    protected static string $updatedAtField='updated_at';

    /**
     * 软删除字段名
     * @var string
     */
    protected static string $deletedAtField='deleted_at';

    /**
     * 注入数据库入口(供测试/自定义连接)
     *
     * @access public
     * @param Db|null $db 数据库入口
     * @return void
     */
    public static function setDb(?Db $db): void {
        static::$db=$db;
    }

    /**
     * 获取数据库入口(未注入时从框架配置创建)
     *
     * @access protected
     * @return Db
     */
    protected static function db(): Db {
        return static::$db??=Db::fromConfig();
    }

    /**
     * 获取数据表名
     *
     * - 已设置 $table 则直接返回, 否则由类名转为下划线小写
     *
     * @access public
     * @return string
     */
    public static function tableName(): string {
        if(static::$table!=='')
            return static::$table;
        return self::classToTable(static::class);
    }

    /**
     * 获取主键字段名
     *
     * @access public
     * @return string
     */
    public static function primaryKey(): string {
        return static::$primaryKey;
    }

    /**
     * 是否自动维护时间戳
     *
     * @access public
     * @return bool
     */
    public static function usesTimestamps(): bool {
        return static::$timestamps;
    }

    /**
     * 是否启用软删除
     *
     * @access public
     * @return bool
     */
    public static function usesSoftDelete(): bool {
        return static::$softDelete;
    }

    /**
     * 获取创建时间字段名
     *
     * @access public
     * @return string
     */
    public static function createdAtField(): string {
        return static::$createdAtField;
    }

    /**
     * 获取更新时间字段名
     *
     * @access public
     * @return string
     */
    public static function updatedAtField(): string {
        return static::$updatedAtField;
    }

    /**
     * 获取软删除字段名
     *
     * @access public
     * @return string
     */
    public static function deletedAtField(): string {
        return static::$deletedAtField;
    }

    /**
     * 生成当前时间戳(可覆盖以自定义格式/时区)
     *
     * @access public
     * @return string
     */
    public static function freshTimestamp(): string {
        return date('Y-m-d H:i:s');
    }

    /**
     * 创建模型查询构建器(核心实现)
     *
     * @access public
     * @return ModelQueryBuilder
     */
    public static function query(): ModelQueryBuilder {
        return new ModelQueryBuilder(static::db(),static::class);
    }

    /**
     * 按主键查找
     *
     * @access public
     * @param mixed $id 主键值
     * @return static|null
     */
    public static function find(mixed $id): ?static {
        return static::query()->find($id);
    }

    /**
     * 创建并保存一条记录
     *
     * @access public
     * @param array $data 数据
     * @return static
     */
    public static function create(array $data): static {
        return static::query()->create($data);
    }

    /**
     * 静态调用转发到查询构建器
     *
     * - 支持 Model::where(...)->get() 等链式写法
     *
     * @access public
     * @param string $name 方法名
     * @param array $arguments 参数
     * @return mixed
     */
    public static function __callStatic(string $name,array $arguments): mixed {
        return static::query()->$name(...$arguments);
    }

    /**
     * 构造方法
     *
     * @access public
     * @param array $attributes 属性
     * @param bool $exists 是否已存在于数据库
     */
    public function __construct(array $attributes=array(),bool $exists=false) {
        $this->attributes=$attributes;
        $this->exists=$exists;
    }

    /**
     * 从数据库行创建模型实例
     *
     * @access public
     * @param array $row 数据库行
     * @return static
     */
    public static function newFromRow(array $row): static {
        return new static($row,true);
    }

    /**
     * 获取属性(已加载的关系直接返回, 同名关系方法则惰性加载)
     *
     * @access public
     * @param string $name 属性名
     * @return mixed
     */
    public function __get(string $name): mixed {
        if(array_key_exists($name,$this->relations))
            return $this->relations[$name];
        if(method_exists($this,$name)) {
            $relation=$this->{$name}();
            if($relation instanceof Relation) {
                $this->setRelation($name,$relation->getResults());
                return $this->relations[$name];
            }
        }
        return $this->attributes[$name]??null;
    }

    /**
     * 设置属性
     *
     * @access public
     * @param string $name 属性名
     * @param mixed $value 值
     * @return void
     */
    public function __set(string $name,mixed $value): void {
        $this->attributes[$name]=$value;
    }

    /**
     * 判断属性是否存在
     *
     * @access public
     * @param string $name 属性名
     * @return bool
     */
    public function __isset(string $name): bool {
        return isset($this->attributes[$name]);
    }

    /**
     * 获取属性
     *
     * @access public
     * @param string $name 属性名
     * @return mixed
     */
    public function getAttribute(string $name): mixed {
        return $this->attributes[$name]??null;
    }

    /**
     * 设置属性
     *
     * @access public
     * @param string $name 属性名
     * @param mixed $value 值
     * @return static
     */
    public function setAttribute(string $name,mixed $value): static {
        $this->attributes[$name]=$value;
        return $this;
    }

    /**
     * 批量赋值(受 fillable 白名单约束)
     *
     * @access public
     * @param array $data 数据
     * @return static
     */
    public function fill(array $data): static {
        foreach($data as $key=>$value) {
            if($this->isFillable($key))
                $this->attributes[$key]=$value;
        }
        return $this;
    }

    /**
     * 判断字段是否可批量赋值
     *
     * @access protected
     * @param string $key 字段名
     * @return bool
     */
    protected function isFillable(string $key): bool {
        return empty($this->fillable)||in_array($key,$this->fillable,true);
    }

    /**
     * 获取全部属性
     *
     * @access public
     * @return array
     */
    public function getAttributes(): array {
        return $this->attributes;
    }

    /**
     * 转为数组
     *
     * @access public
     * @return array
     */
    public function toArray(): array {
        return $this->attributes;
    }

    /**
     * 获取主键值
     *
     * @access public
     * @return mixed
     */
    public function getKey(): mixed {
        return $this->attributes[static::$primaryKey]??null;
    }

    /**
     * 是否已存在于数据库
     *
     * @access public
     * @return bool
     */
    public function exists(): bool {
        return $this->exists;
    }

    /**
     * 标记为已存在(构建器插入后调用)
     *
     * @access public
     * @return void
     */
    public function markExists(): void {
        $this->exists=true;
    }

    /**
     * 声明一对多关系
     *
     * - 默认外键 = 当前模型类名 snake + _id, 默认主键 = 当前模型主键
     * - 例: $this->hasMany(Post::class)  →  posts.user_id = users.id
     *
     * @access protected
     * @param string $related 关联模型类名
     * @param string|null $foreignKey 外键字段
     * @param string|null $ownerKey 主键字段
     * @return HasMany
     */
    protected function hasMany(string $related,?string $foreignKey=null,?string $ownerKey=null): HasMany {
        $ownerKey=$ownerKey??static::$primaryKey;
        $foreignKey=$foreignKey??self::classToTable(static::class).'_id';
        return new HasMany(static::db(),$related,$this,$foreignKey,$ownerKey);
    }

    /**
     * 声明一对一关系
     *
     * - 默认外键 = 当前模型类名 snake + _id, 默认主键 = 当前模型主键
     *
     * @access protected
     * @param string $related 关联模型类名
     * @param string|null $foreignKey 外键字段
     * @param string|null $ownerKey 主键字段
     * @return HasOne
     */
    protected function hasOne(string $related,?string $foreignKey=null,?string $ownerKey=null): HasOne {
        $ownerKey=$ownerKey??static::$primaryKey;
        $foreignKey=$foreignKey??self::classToTable(static::class).'_id';
        return new HasOne(static::db(),$related,$this,$foreignKey,$ownerKey);
    }

    /**
     * 声明多对一关系(属于)
     *
     * - 默认外键 = 关联模型类名 snake + _id, 默认主键 = 关联模型主键
     * - 例: $this->belongsTo(User::class)  →  posts.user_id = users.id
     *
     * @access protected
     * @param string $related 关联模型类名
     * @param string|null $foreignKey 外键字段
     * @param string|null $ownerKey 主键字段
     * @return BelongsTo
     */
    protected function belongsTo(string $related,?string $foreignKey=null,?string $ownerKey=null): BelongsTo {
        $ownerKey=$ownerKey??$related::primaryKey();
        $foreignKey=$foreignKey??self::classToTable($related).'_id';
        return new BelongsTo(static::db(),$related,$this,$foreignKey,$ownerKey);
    }

    /**
     * 设置已加载的关系
     *
     * @access public
     * @param string $name 关系名
     * @param mixed $value 关系值
     * @return static
     */
    public function setRelation(string $name,mixed $value): static {
        $this->relations[$name]=$value;
        return $this;
    }

    /**
     * 获取已加载的关系
     *
     * @access public
     * @param string $name 关系名
     * @return mixed
     */
    public function getRelation(string $name): mixed {
        return $this->relations[$name]??null;
    }

    /**
     * 保存(已存在则更新, 否则插入)
     *
     * @access public
     * @return bool
     */
    public function save(): bool {
        if($this->exists)
            return $this->updateExisting();
        return $this->insertNew();
    }

    /**
     * 删除当前记录
     *
     * - 启用软删除时标记 deleted_at, 否则物理删除
     *
     * @access public
     * @return bool
     */
    public function delete(): bool {
        if(!$this->exists)
            return false;
        if(static::usesSoftDelete()) {
            $this->attributes[static::deletedAtField()]=static::freshTimestamp();
            $result=static::db()->query(
                Query::update(array(static::deletedAtField()=>$this->attributes[static::deletedAtField()]))
                    ->from(static::tableName())
                    ->where(static::$primaryKey,$this->getKey())
            );
            return $result->isSuccess();
        }
        return $this->forceDelete();
    }

    /**
     * 物理删除当前记录(无视软删除)
     *
     * @access public
     * @return bool
     */
    public function forceDelete(): bool {
        if(!$this->exists)
            return false;
        $result=static::db()->query(
            Query::delete()
                ->from(static::tableName())
                ->where(static::$primaryKey,$this->getKey())
        );
        $this->exists=false;
        return $result->isSuccess()&&$result->getAffectedRows()>0;
    }

    /**
     * 是否已软删除
     *
     * @access public
     * @return bool
     */
    public function trashed(): bool {
        return static::usesSoftDelete()&&$this->getAttribute(static::deletedAtField())!==null;
    }

    /**
     * 恢复软删除的记录
     *
     * @access public
     * @return bool
     */
    public function restore(): bool {
        if(!static::usesSoftDelete()||!$this->exists)
            return false;
        $this->attributes[static::deletedAtField()]=null;
        $result=static::db()->query(
            Query::update(array(static::deletedAtField()=>null))
                ->from(static::tableName())
                ->where(static::$primaryKey,$this->getKey())
        );
        return $result->isSuccess();
    }

    /**
     * 更新已存在的记录
     *
     * @access private
     * @return bool
     */
    private function updateExisting(): bool {
        if(static::usesTimestamps())
            $this->attributes[static::updatedAtField()]=static::freshTimestamp();
        $sets=$this->attributes;
        unset($sets[static::$primaryKey]);
        if(empty($sets))
            return true; // 无字段需要更新
        $result=static::db()->query(
            Query::update($sets)
                ->from(static::tableName())
                ->where(static::$primaryKey,$this->getKey())
        );
        return $result->isSuccess();
    }

    /**
     * 插入新记录并设置主键
     *
     * @access private
     * @return bool
     */
    private function insertNew(): bool {
        if(static::usesTimestamps()) {
            $now=static::freshTimestamp();
            if(($this->attributes[static::createdAtField()]??null)===null)
                $this->attributes[static::createdAtField()]=$now;
            if(($this->attributes[static::updatedAtField()]??null)===null)
                $this->attributes[static::updatedAtField()]=$now;
        }
        $result=static::db()->query(
            Query::insert($this->attributes)->from(static::tableName())
        );
        if(!$result->isSuccess())
            return false;
        $this->attributes[static::$primaryKey]=$result->getLastInsertId();
        $this->exists=true;
        return true;
    }

    /**
     * 类名转为下划线小写表名
     *
     * @access protected
     * @param string $class 类名
     * @return string
     */
    protected static function classToTable(string $class): string {
        $base=basename(str_replace('\\','/',$class));
        $converted=preg_replace(array(
            '/(?<=[a-z])(?=[A-Z])/',
            '/(?<=[A-Z])(?=[A-Z][a-z])/',
            '/(?<=[a-zA-Z])(?=\d)|(?<=\d)(?=[a-zA-Z])/',
        ),'_',$base);
        return strtolower($converted);
    }

}
