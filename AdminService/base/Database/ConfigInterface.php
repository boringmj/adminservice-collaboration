<?php

namespace base\Database;

/**
 * 数据库配置接口
 */
interface ConfigInterface {

    /**
     * 从数组加载配置
     * @param array<string,mixed> $config 配置数组
     * @return self
     */
    public static function fromArray(array $config): self;

    /**
     * 检查数据库配置是否完整(密码可为空)
     * @return void
     */
    public function checkConfig(): void;

    /**
     * 获取DSN
     * @return string
     * @param string|null $dsn_template dsn模板
     */
    public function getDsn(?string $dsn_template=null): string;

    /**
     * 获取用户名
     * @return string
     */
    public function getUsername(): string;

    /**
     * 获取密码
     * @return string
     */
    public function getPassword(): string;

    /**
     * 获取连接选项
     * @return array<int,mixed>
     */
    public function getOptions(): array;

    /**
     * 序列化为数组
     * @param bool $withPassword 是否包含密码
     * @return array<string,mixed>
     */
    public function toArray(bool $withPassword=false): array;

}