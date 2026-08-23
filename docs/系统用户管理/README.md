# 系统用户管理

## 功能目标

为共享救助信息服务平台提供后台账号、角色权限、镇街数据范围以及安全认证能力。系统用户是管理端登录人员，不是台账中的参保人或救助对象。

## 已实现能力

- 用户名和密码登录；登录成功、失败、锁定和退出均写入操作记录。
- 新密码至少 8 位，并且必须同时包含字母和特殊符号；存量密码不强制批量修改。
- 按登录账号限制连续失败次数，不按 IP 限制。15 分钟内失败 5 次触发锁定，锁定时长依次为 10、30、60 分钟。
- 支持 RFC 6238 TOTP，可使用苹果“密码”、Microsoft Authenticator、2FAS 或 Google Authenticator 等兼容应用。
- 仅 `APP_ENV=prod` 强制校验 TOTP；其他环境保留绑定流程，但验证码校验直接放行。
- 超级管理员（`users.id=1`）、精确名称为“管理员”的角色以及被设置 `totp_required=true` 的账号强制启用 TOTP。
- 管理员创建用户时前端默认开启 TOTP；数据库默认值为关闭，以兼容存量账号。
- 超级管理员和“管理员”角色可重置他人 TOTP、清除账号登录锁，并设置普通用户是否强制开启 TOTP；不能重置自己的 TOTP。
- “管理员”角色名称不可修改、不可重复创建、不可删除；用户被授予该角色后自动强制 TOTP。
- 只有超级管理员或现有“管理员”角色可以授予或移除“管理员”角色，避免普通账号通过角色分配获得安全管理能力。
- 会话采用 Redis 作为在线状态依据：每次活动将有效期续至当前时间后 24 小时，最多每 10 分钟续期一次。
- 已绑定 TOTP 的用户在会话过期后可只验证 TOTP 恢复会话；未绑定用户须重新输入账号和密码。
- 用户、角色、权限或密码发生安全相关变更时，通过会话版本使旧会话失效。

## 主要代码

- 路由：`config/routes.php`
- 认证：`app/Controller/AuthController.php`、`app/Middleware/JwtAuthMiddleware.php`
- 登录限制和会话：`app/Service/LoginAttemptService.php`、`app/Service/AuthSessionService.php`
- TOTP：`app/Service/TotpService.php`、`app/Service/AuthChallengeService.php`
- 密码规则和日志：`app/Service/PasswordPolicyService.php`、`app/Service/OperationLogService.php`
- 用户和角色：`app/Controller/UserController.php`、`app/Controller/RoleController.php`

## 主要接口

| 方法 | 路径 | 认证 | 用途 |
|---|---|---:|---|
| POST | `/api/login` | 否 | 校验账号密码；直接签发会话或返回 TOTP 挑战 |
| POST | `/api/auth/totp/login-setup` | 挑战令牌 | 获取首次绑定二维码和密钥 |
| POST | `/api/auth/totp/login-bind` | 挑战令牌 | 完成首次绑定并登录 |
| POST | `/api/auth/totp/login-verify` | 挑战令牌 | 校验已绑定账号的 TOTP 并登录 |
| POST | `/api/auth/session/reauth` | 过期令牌 | 已绑定账号通过 TOTP 恢复会话 |
| POST | `/api/logout` | 是 | 注销服务端会话并记录退出用户 |
| GET | `/api/user/info` | 是 | 返回用户、镇街、权限、菜单和安全能力 |
| GET | `/api/user/security` | 是 | 查询本人 TOTP 状态和当前环境校验状态 |
| POST | `/api/user/totp/setup` | 是 | 本人首次绑定前获取二维码和密钥 |
| POST | `/api/user/totp/bind` | 是 | 本人完成首次绑定 |
| POST | `/api/user/change-password` | 是 | 校验原密码并更新本人密码 |
| POST | `/api/users/{id}/totp/reset` | 是 | 超级管理员或“管理员”角色重置他人 TOTP |
| DELETE | `/api/users/{id}/login-lock` | 是 | 超级管理员或“管理员”角色清除账号锁定 |

## 核心边界

- 用户名创建和更新时必须唯一。
- 密码只保存单向哈希；操作日志中的登录密码按已确认规则仅保留首尾各 2 位，中间固定为 `***`，不足 5 位全部记为 `***`。
- TOTP 密钥加密保存且不通过普通用户接口回显。
- 普通用户只有首次绑定入口，不提供关闭或主动重新绑定入口；忘记或更换设备时由具备管理能力的账号重置。
- 重置他人 TOTP 时，生产环境必须先验证操作人的 TOTP；非生产环境不校验验证码。
- `town_id` 为空表示全局账号，非空表示对应镇街数据范围。
- 所有业务接口均以 Redis 中服务端会话为准，不能只依赖前端持有的 JWT。

## 已知边界

- 用户表仍无启停和软删除字段，删除属于直接删除。
- TOTP 不提供恢复码；设备丢失后只能由具备管理能力的账号重置。
- 过期 JWT 的 TOTP 恢复窗口当前为 30 天；主动退出、密码修改、角色变更或会话版本变化后不能用于恢复。
