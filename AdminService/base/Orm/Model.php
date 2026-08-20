<?php

namespace base\Orm;

use base\Database\Db;
use base\Database\Query\Query;

use function array_key_exists;
use function basename;
use function implode;
use function in_array;
use function method_exists;
use function preg_replace;
use function sort;
use function str_replace;
use function strtolower;

/**
 * 模型基类(实体)
 *
 * - 代表数据库中的一行记录, 纯数据职责
 * - 查询通过静态入口(如 Model::query() / Model::where())委托给 ModelQueryBuilder
 * - 行与对象的转换(水合)由构建器与 newFromRow 完成
 * - 关系: 通过 hasMany/hasOne/belongsTo 声明, $model->relation 惰性加载
 *
 * @method static ModelQueryBuilder from(string|\base\Database\Sql\Definition\Table $table, ?string $alias=null)
 * @method static ModelQueryBuilder field(string|\base\Database\Sql\Definition\Field|array $columns, ?string $alias=null)
 * @method static ModelQueryBuilder distinct()
 * @method static ModelQueryBuilder where(string|\base\Database\Sql\Definition\Field $field, mixed $value=null, string $operator='=')
 * @method static ModelQueryBuilder whereIn(string|\base\Database\Sql\Definition\Field $field, array $values, bool $not=false)
 * @method static ModelQueryBuilder whereNotIn(string|\base\Database\Sql\Definition\Field $field, array $values)
 * @method static ModelQueryBuilder whereBetween(string|\base\Database\Sql\Definition\Field $field, mixed $min, mixed $max, bool $not=false)
 * @method static ModelQueryBuilder whereNull(string|\base\Database\Sql\Definition\Field $field, bool $not=false)
 * @method static ModelQueryBuilder whereGroup(string $connector, callable $callback)
 * @method static ModelQueryBuilder join(string $type, string|\base\Database\Sql\Definition\Table $table, array $on)
 * @method static ModelQueryBuilder order(string|\base\Database\Sql\Definition\Field $field, string $direction='ASC')
 * @method static ModelQueryBuilder orderBy(string|\base\Database\Sql\Definition\Field $field, string $direction='ASC')
 * @method static ModelQueryBuilder group(string|\base\Database\Sql\Definition\Field $field)
 * @method static ModelQueryBuilder groupBy(string|\base\Database\Sql\Definition\Field $field)
 * @method static ModelQueryBuilder limit(int $limit, ?int $offset=null)
 * @method static ModelQueryBuilder offset(int $offset)
 * @method static ModelQueryBuilder lock(string $type='update')
 * @method static ModelQueryBuilder alias(string $alias)
 * @method static ModelQueryBuilder with(array|string $relations)
 * @method static ModelCollection get()
 * @method static ?Model first()
 * @method static int count()
 * @method static mixed value(string $field)
 * @method static array pluck(string $field)
 * @method static Paginator paginate(int $perPage=15, int $page=1)
 * @method static int update(array $data)
 * @method static int delete()
 * @method static int forceDelete()
 * @method static string getLastSql()
 */
abstract class Model {

    /**
     * 按类名注入的数据库入口缓存(setDb 专用, 测试注入 FakePdo)
     *
     * - 使用 self::(而非 static::)确保所有模型共享这一块存储, 按类名区分
     * - 模型自身的连接经 Db::fromConfig(static::$connection) 解析(每类独立)
     *
     * @var array
     */
    private static array $dbCache=array();

    /**
     * 数据库连接名(经 Db::fromConfig 注册表解析, 同名连接共享单例)
     * @var string
     */
    protected static string $connection='default';

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
        if($db===null)
            unset(self::$dbCache[static::class]);
        else
            self::$dbCache[static::class]=$db;
    }

    /**
     * 获取数据库入口(未注入时经注册表按连接名解析, 同名连接共享单例)
     *
     * - setDb 注入优先(测试/自定义连接); 未注入则 Db::fromConfig(static::$connection)
     * - 共享单例意味着模型写入与 Db::transaction() 落在同一连接, 可跨模型进事务
     *
     * @access protected
     * @return Db
     */
    protected static function db(): Db {
        // setDb 注入优先(测试); 否则按本类 $connection 经注册表解析(每类独立)
        if(isset(self::$dbCache[static::class]))
            return self::$dbCache[static::class];
        return Db::fromConfig(static::$connection);
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
        // 关系查询走相关模型自身的连接(支持跨库关系查询)
        return new HasMany($related::db(),$related,$this,$foreignKey,$ownerKey);
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
        // 关系查询走相关模型自身的连接(支持跨库关系查询)
        return new HasOne($related::db(),$related,$this,$foreignKey,$ownerKey);
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
        // 关系查询走相关模型自身的连接(支持跨库关系查询)
        return new BelongsTo($related::db(),$related,$this,$foreignKey,$ownerKey);
    }

    /**
     * 声明多对多关系(通过中间表)
     *
     * - 默认中间表 = 双方表名(类名 snake)按字母序拼接, 例: User↔Role → role_user
     * - 默认中间表外键 = 当前模型类名 snake + _id, 关联键 = 关联模型类名 snake + _id
     * - 例: $this->belongsToMany(Role::class) → role_user(user_id, role_id)
     *
     * @access protected
     * @param string $related 关联模型类名
     * @param string|null $pivotTable 中间表名
     * @param string|null $foreignPivotKey 中间表外键(父侧)
     * @param string|null $relatedPivotKey 中间表关联键(相关侧)
     * @param string|null $parentKey 父模型主键
     * @param string|null $relatedKey 相关模型主键
     * @return BelongsToMany
     */
    protected function belongsToMany(
        string $related,
        ?string $pivotTable=null,
        ?string $foreignPivotKey=null,
        ?string $relatedPivotKey=null,
        ?string $parentKey=null,
        ?string $relatedKey=null
    ): BelongsToMany {
        $parentKey=$parentKey??static::$primaryKey;
        $relatedKey=$relatedKey??$related::primaryKey();
        $foreignPivotKey=$foreignPivotKey??self::classToTable(static::class).'_id';
        $relatedPivotKey=$relatedPivotKey??self::classToTable($related).'_id';
        $pivotTable=$pivotTable??$this->pivotTableName($related);
        // 中间表与相关查询均走相关模型自身的连接(支持跨库关系查询)
        return new BelongsToMany(
            $related::db(),$related,$this,
            $pivotTable,$foreignPivotKey,$relatedPivotKey,$parentKey,$relatedKey
        );
    }

    /**
     * 计算默认中间表名(双方表名按字母序用下划线拼接)
     *
     * @access protected
     * @param string $related 关联模型类名
     * @return string
     */
    protected function pivotTableName(string $related): string {
        $tables=array(self::classToTable(static::class),self::classToTable($related));
        sort($tables);
        return implode('_',$tables);
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
