# 系统用户管理

## 功能目标

为共享救助信息服务平台提供后台操作账号、JWT 登录、角色权限和镇街数据范围。系统用户是管理端登录人员，不是台账中的参保人或救助对象。

## 已实现能力

- 用户名/密码登录、登出、当前用户信息和本人修改密码。
- 用户列表、详情、创建、编辑、删除。
- 角色列表与用户角色同步分配。
- 角色和权限的独立维护及菜单树生成。
- 用户可绑定一个镇街；为空表示全局账号。
- 用户权限和菜单缓存 Redis 2 小时，账号/角色变更时相关流程清理缓存。

## 主要代码

- 路由：`config/routes.php`
- 认证：`app/Controller/AuthController.php`、`app/Middleware/JwtAuthMiddleware.php`
- 用户：`app/Controller/UserController.php`、`app/Model/User.php`
- RBAC：`app/Controller/RoleController.php`、`PermissionController.php`、`app/Service/RbacService.php`
- 模型：`User`、`Role`、`Permission`、`UserRole`

## 接口

| 方法 | 路径 | 认证 | 用途 |
|---|---|---:|---|
| POST | `/api/login` | 否 | 登录并签发2小时JWT |
| POST | `/api/logout` | 否（当前路由现状） | 返回登出成功；由于未经过JWT中间件，通常拿不到 `userId`，前端仍以清理本地Token为准 |
| GET | `/api/user/info` | 是 | 返回用户、镇街、权限和菜单 |
| POST | `/api/user/change-password` | 是 | 校验原密码并更新本人密码 |
| GET | `/api/users` | 是 | 用户列表，排除 `id=1` |
| POST | `/api/users` | 是 | 创建用户 |
| GET | `/api/users/{id}` | 是 | 用户详情 |
| PUT | `/api/users/{id}` | 是 | 编辑资料或密码 |
| DELETE | `/api/users/{id}` | 是 | 删除用户 |
| GET | `/api/users/roles` | 是 | 可分配角色，排除 `id=1` |
| POST | `/api/users/{id}/roles` | 是 | 完整同步角色集合 |

除登录和登出外，表中的用户接口位于 JWT 路由组。当前路由组未统一挂载 `PermissionMiddleware`，细粒度权限主要由前端控制和其他权限逻辑配合；这不是允许绕过权限的设计承诺，而是现状限制。

## 核心规则

- 用户名创建和更新时必须唯一。
- 密码使用 `password_hash(PASSWORD_DEFAULT)` 保存，登录使用 `password_verify()`。
- 编辑密码为空时保持原值。
- `town_id` 为空表示全局账号；非空用于台账镇街范围。
- `users.id = 1` 视为超级管理员，获得全部权限和菜单。
- 普通用户只汇总角色中 `status = 1` 的权限。
- 角色同步使用 `sync(role_ids)`，请求中的数组是最终完整集合。

## 已知限制

- 用户没有状态和软删除字段，当前无法启停，删除为直接删除。
- 登录失败、JWT异常和部分业务接口响应字段不完全统一。
- JWT 中间件仍兼容 query Token 且异常时可能返回底层异常消息；新接口不应扩散这种方式。
- `PermissionMiddleware` 不是当前用户管理路由组的统一强制中间件。
- 管理员改他人密码通过通用编辑接口完成，没有独立重置密码审计流程。
