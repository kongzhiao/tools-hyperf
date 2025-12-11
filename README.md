# 共享救助信息服务平台 - 后端

基于 Hyperf 3.x 框架的高性能后端服务，提供参保数据转换相关的 API 接口。

## 技术栈

- **框架**: Hyperf 3.x (基于 Swoole 的协程框架)
- **数据库**: MySQL
- **缓存**: Redis
- **认证**: JWT
- **API文档**: Swagger
- **队列**: AMQP
- **日志**: Monolog

## 系统要求

- PHP >= 7.3
- Swoole PHP 扩展 >= 4.5
- MySQL >= 5.7
- Redis >= 3.0

## 安装与运行

### 1. 安装依赖
```bash
composer install
```

### 2. 环境配置
```bash
# 复制环境配置文件
cp .env.example .env

# 编辑配置文件，设置数据库连接等信息
vim .env
```

### 3. 数据库迁移
```bash
# 运行数据库迁移
php bin/hyperf.php migrate

# 初始化菜单权限
php bin/hyperf.php init:menu-permissions

# 初始化管理员账户
php bin/hyperf.php init:admin
```

### 4. 启动服务
```bash
# 开发环境启动
php bin/hyperf.php start

# 生产环境启动
php bin/hyperf.php server:start
```

服务将在 `http://localhost:9500` 启动

## 项目结构

```
app/
├── Controller/     # 控制器
├── Model/         # 数据模型
├── Service/       # 业务服务
├── Command/       # 命令行工具
├── Middleware/    # 中间件
├── Exception/     # 异常处理
└── Listener/      # 事件监听器

config/            # 配置文件
database/          # 数据库迁移
storage/           # 存储文件
test/              # 测试文件
```

## 主要功能模块

### 用户管理
- 用户认证与授权
- 角色权限管理
- 权限验证工具

### 业务配置
- 类别转换配置
- 参保档次配置

### 数据管理
- 参保数据管理
- 身份信息核实
- 税务数据汇总
- 参保数据汇总

## API 文档

启动服务后，访问 `http://localhost:9500/swagger` 查看 API 文档。

## 开发命令

```bash
# 代码风格检查
composer cs-fix

# 静态分析
composer analyse

# 运行测试
composer test
```

## 许可证

Apache-2.0
