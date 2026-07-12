# AGENTS.md

## 项目定位与文档入口

本项目 `tools-hyperf-yf` 是共享救助信息服务平台后端，基于 Hyperf 3，主要处理医保业务 CSV 的导入、匹配、筛查、统计、导出、异步任务，以及后台系统账号与 RBAC 权限。

进入项目后先读本文件，再按任务读取 `docs/share/` 和对应功能文档。当前代码、路由和有效迁移是事实来源；帮助页用于补充用户操作，不能覆盖代码事实。

### 必读文档

- `docs/README.md`：文档入口。
- `docs/share/项目概览.md`：项目定位、模块与数据流。
- `docs/share/开发规范.md`：分层、CSV、任务、数据库和安全规则。
- `docs/share/接口规范.md`：当前响应、认证、分页和异步任务协议。
- `docs/share/文档规范.md`：功能文档维护要求。
- `docs/系统用户管理/`：后台账号、角色、权限和镇街绑定。
- `docs/未救助台账/`：未救助、应补应退、通知和筛查模型。
- `docs/参保台账/`：年度配置、月度导入、计算和镇街复核。

## 中文协作要求

- 默认使用简体中文沟通和编写业务说明、文档及注释。
- 类名、方法名、表名、字段、接口路径和命令保留英文。
- 状态和医保业务词沿用当前代码，不擅自合并或改名。

## 技术栈

- PHP 7.3+，Hyperf 3 / Swoole。
- MySQL、Redis、JWT。
- Hyperf Async Queue，当前默认 Redis 队列驱动。
- OpenSpout 流式读取 CSV，PhpSpreadsheet/OpenSpout 支撑表格处理。
- Migration、PHPUnit、PHPStan、PHP CS Fixer。

## 核心架构

```text
Request -> JwtAuthMiddleware -> Controller -> Service/Model -> DB/Redis
                                      |
                                      +-> TaskService -> AsyncQueue Job -> 文件/数据/任务状态
```

- `app/Controller/`：接收参数、权限上下文、调用服务和响应。
- `app/Service/`：匹配、计算、筛选、任务投递等可复用业务逻辑。
- `app/Model/`：数据模型、关联和类型转换。
- `app/Job/`：CSV 导入、筛查、导出等长任务（存量类名仍使用 Wash）。
- `config/routes.php`：实际 HTTP 路由来源。
- `migrations/`：Hyperf 默认执行的增量迁移目录。
- `database/migrations/`：存量镜像/参考目录，不得误认为默认执行目录。

## 当前模块事实

- 系统用户是后台登录账号，`users.town_id` 为空为全局账号，非空为镇街账号。
- 未救助台账实际公开流程分为 `/unrescued/records`、`/refund-records`、`/notice-records` 和 `/disease-configs`。
- 参保台账使用 `/enroll/ledgers`、`/configs` 和 `/import-batches`。
- 导入只允许 `.csv`，支持 UTF-8、GBK、GB18030 自动识别。
- 多数业务接口成功码为 `0`，任务接口成功码为 `200`；不能照搬其他项目的成功码。

## Controller 与 Service

- Controller 做参数读取、当前用户/镇街范围、任务提交和响应；复杂匹配与计算放 Service/Job。
- 新列表接口必须限制分页、允许排序字段并避免无条件大表扫描。
- 金额使用数据库 `decimal(14,2)`；计算优先 BCMath，缺失时当前代码回退两位小数格式化。
- 身份证、金额、状态匹配键和镇街范围必须在服务端校验，不能依赖前端。
- 异常响应不得暴露 SQL、堆栈、文件路径或完整敏感数据。

## CSV 与异步任务

- 扩展名必须是 `csv`，表头通过兼容别名读取；新增别名需记录在功能文档。
- `CsvReaderService` 流式读取并规范编码、BOM、零宽字符和空行。
- 大任务通过 `TaskService::dispatchTask()` 投递，任务锁默认 2 小时，防止同类重复提交。
- Job 必须更新 pending/running/completed/failed 状态，失败保存面向用户的截断原因，完整异常只进服务端日志。
- 临时文件在成功或失败后清理；导出文件通过 UUID 下载中转，不暴露真实路径。
- 任务提交成功不代表业务完成，接口需返回 UUID 供前端跟踪。

## 数据库规范

- 表结构变更必须新增 `migrations/` 迁移，不直接修改 SQL 快照代替迁移。
- 明确字段类型、长度/精度、可空、默认值、注释、索引和回滚逻辑。
- 组合匹配键应评估唯一约束；当前没有唯一约束的存量表必须如实记录，不能在文档中写成数据库保证。
- 台账原始记录原则上不因筛查删除；使用状态和软删除策略以具体模型为准。
- 新迁移如需要同步镜像目录，必须保持内容一致；默认执行来源仍是根 `migrations/`。

## 认证、权限与数据范围

- JWT 从 `Authorization: Bearer <token>` 读取；查询参数 Token 是现有本地测试兼容，不应扩散到新接口。
- `JwtAuthMiddleware` 注入 `userId`、`user`、`username`。
- 超级管理员 `users.id = 1` 取得全部权限；普通账号通过角色关联启用权限。
- 镇街数据范围必须由后端根据当前用户绑定强制追加，忽略调用方伪造的其他镇街范围。
- 身份证、联系电话、银行账户、Token、密码哈希和环境口令不得输出到文档或日志。

## 文档维护

- 复杂功能变更前补齐 README、流程和数据模型；实现后追加业务变更增量。
- 两个台账的用户说明可参考 `helps/` 下 2026-07-05 HTML；代码与帮助页冲突时以代码为准。
- 接口、状态、字段、索引或 CSV 表头变化时同步前后端对应文档。

## 验证与 Git 边界

- 只改任务所需文件，不覆盖用户未提交变更，不顺手格式化无关代码。
- 未经明确要求不修改业务代码、配置、依赖、迁移，不提交、不推送、不改写历史。
- 纯文档检查结构、链接、表格和 Mermaid；结构变化后执行 `codegraph sync`。
- 代码任务按范围执行 PHP 语法、相关测试、静态分析或迁移检查，并如实报告。

## CodeGraph

结构性查询优先使用 CodeGraph，精确文本使用 `rg`。修改完成并核对后执行 `codegraph sync`，确认索引成功再交付。
