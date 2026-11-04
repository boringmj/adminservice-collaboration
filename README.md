# AdminService-Collaboration

一个仍在开发中的轻量级 PHP 框架，主要面向后端 API 接口。

## 环境要求

- PHP `>=8.2.0`
- Composer
- PHP 扩展：`pdo`、`curl`

## 快速开始

1. 克隆项目：

   ```bash
   git clone https://github.com/boringmj/adminservice-collaboration.git
   cd adminservice-collaboration
   ```

2. 安装依赖：

   ```bash
   composer install
   ```

   生产环境可使用：

   ```bash
   composer install --optimize-autoloader --no-dev
   ```

3. 启动开发服务器：

   ```bash
   php console server start
   ```

   也可以使用快捷命令或分组启动器：

   ```bash
   php console start
   php bin/server start
   ```

4. 访问 `http://localhost:8000/`。

更完整的安装与第一个程序说明见 [准备](https://github.com/boringmj/adminservice-collaboration/wiki/准备) 和 [开始](https://github.com/boringmj/adminservice-collaboration/wiki/开始)。

## 文档

完整文档入口见 [Wiki 首页](https://github.com/boringmj/adminservice-collaboration/wiki/Home)。

| 主题 | 页面 |
| --- | --- |
| 安装与启动 | [准备](https://github.com/boringmj/adminservice-collaboration/wiki/准备) |
| 第一个控制器 | [开始](https://github.com/boringmj/adminservice-collaboration/wiki/开始) |
| 路由 | [路由](https://github.com/boringmj/adminservice-collaboration/wiki/路由) |
| 请求参数 | [请求参数](https://github.com/boringmj/adminservice-collaboration/wiki/请求参数) |
| 响应与内容协商 | [返回类型](https://github.com/boringmj/adminservice-collaboration/wiki/返回类型) |
| 容器与依赖注入 | [容器与依赖注入](https://github.com/boringmj/adminservice-collaboration/wiki/容器与依赖注入) |
| 配置与 `.env` | [配置](https://github.com/boringmj/adminservice-collaboration/wiki/配置) |
| 运行时目录 | [运行时目录](https://github.com/boringmj/adminservice-collaboration/wiki/运行时目录) |
| 缓存 | [缓存](https://github.com/boringmj/adminservice-collaboration/wiki/缓存) |
| 命令行 | [命令行](https://github.com/boringmj/adminservice-collaboration/wiki/命令行) |
| 数据库 | [数据库](https://github.com/boringmj/adminservice-collaboration/wiki/数据库) |
| ORM | [ORM 使用指南](https://github.com/boringmj/adminservice-collaboration/wiki/Orm) |
| 版本迁移 | [新旧版本调整](https://github.com/boringmj/adminservice-collaboration/wiki/新旧版本调整) |

## 常用命令

```bash
php console list                 # 列出全部命令
php console start                # 启动开发服务器
php console cache status         # 查看编译缓存状态
php console cache build          # 预热编译缓存
php console cache clear          # 清理编译缓存
```

完整命令、参数和自定义命令说明见 [命令行](https://github.com/boringmj/adminservice-collaboration/wiki/命令行)。

## 测试

```bash
php vendor/bin/phpunit
```

## 从旧版升级

- PHP 最低版本为 `8.2.0`。
- HTTP 入口使用 `AdminService\Main`。
- 旧版约定式路由已移除，改用属性路由或显式路由。
- 旧根目录 `start` 脚本已移除，改用 `php console server start`。

完整破坏性变更与迁移说明见 [新旧版本调整](https://github.com/boringmj/adminservice-collaboration/wiki/新旧版本调整)。

## 项目说明

项目仍在持续开发中，需求和接口可能随版本调整。参与方式、项目目标与联系方式见 [项目说明](https://github.com/boringmj/adminservice-collaboration/wiki/项目说明)。

## AI 参与

由于需求与野心日益增长，独立维护已越发困难，因此本仓库已经正式引入 AI 参与设计开发。

如果您介意 AI 参与的项目，可以选择其他成熟框架。

**AI 生成代码非全量人工复审**：由于精力有限，无力全审，只会审核关键性代码和查阅 AI 报告，其余交由独立 agent 审阅（非同一个 agent 自审，通过自写 skills 审查）。

因此相关模块**不完全保证代码质量和代码安全**，只能做到尽力而为。
