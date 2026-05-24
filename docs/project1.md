# 共享救助信息服务平台现状梳理

> 梳理时间：2026-05-24  
> 范围：后端 `tools-hyperf-yf`、配套前端 `tools-react-yf`、当前 MySQL 实库与 SQL dump。  
> 说明：本次按要求不参考 `database/migrations` 目录，数据库结构以当前实库和 `database/hyperfp10916*.sql` 为准。

## 1. 总体架构

平台是一个前后端分离的救助/参保/优抚业务系统。

- 前端：`tools-react-yf`，React 18 + Umi Max + Ant Design Pro，运行时从后端拉取用户信息与菜单权限。
- 后端：`tools-hyperf-yf`，Hyperf 3.x + Swoole，提供 `/api` REST 接口。
- 数据库：MySQL，主库名当前为 `tools_yf`。
- 缓存：Redis，用于登录用户缓存、任务锁、异步队列。
- 队列：Hyperf Async Queue，当前配置为 Redis Driver，导入导出任务通过 `task` 表跟踪进度。
- 认证：JWT，登录后发放 token，后续请求经过 `JwtAuthMiddleware`。
- 文件：导入文件进入 `storage/uploads`，导出文件进入 `runtime/export`，通过 `/api/download` 中转下载。

## 2. 前端架构

前端项目位于同级目录 `/Users/macbook/Documents/codes/tools-react-yf`。

主要技术与配置：

- `@umijs/max` 作为应用框架，`.umirc.ts` 配置路由、代理、布局。
- `antd` 和 `@ant-design/pro-components` 作为后台管理 UI。
- 开发环境 `/api` 代理到 `http://localhost:9502`，测试环境代理到 `http://47.109.34.185:9510`。
- `src/app.ts` 在启动时读取 `/api/user/info` 与 `/api/permissions/user/menus`，将后端权限菜单转换为 Umi Layout 菜单。
- `src/access.ts` 以权限名称做前端访问控制，例如 `账户管理:查看`、`参保数据管理:导入`。

现有前端页面：

- 登录：`/login`
- 移动端登录与受理：`/m/login`、`/m/medical/reimbursement`
- 仪表板：`/dashboard`
- 用户管理：账户、角色、权限
- 业务配置：类别转换配置、参保档次配置、类别额度配置
- 数据核实：参保数据管理、税务数据汇总、参保数据汇总
- 统计汇总：`/statistics-summary`
- 救助报销：受理记录、就诊记录、患者管理
- 优抚联网结算：`/yf/settlement-online`

注意：前端 `.umirc.ts` 中部分静态路由路径带 `config/` 前缀，而当前数据库权限菜单中是 `/business-config/category-conversion`、`/business-config/insurance-level-config`。实际运行以动态菜单为主，后续新增菜单时应保证数据库 `permissions.path/component` 与前端页面路径一致。

## 3. 后端架构

后端采用比较传统的 Controller + Service + Model + Job 分层。

- `app/Controller`：路由入口，负责参数读取、响应封装、任务提交。
- `app/Service`：业务计算、导入解析、任务服务、缓存服务。
- `app/Model`：Hyperf ORM 模型。
- `app/Job`：异步导入导出任务。
- `app/Middleware`：JWT、权限、跨域中间件。
- `config/routes.php`：集中注册路由，绝大部分业务路由挂在 `/api` 并加 JWT 中间件。

统一响应由 `AbstractController::success/error` 输出：

- 成功：`{ code: 0, message: "...", data: ... }`
- 失败：`{ code: 非0, message: "...", data: null }`

## 4. 用户、角色、权限

认证流程：

1. `POST /api/login` 使用用户名和密码登录。
2. 登录成功后后端签发 JWT，payload 包含 `user_id`、`username`、`iat`、`exp`。
3. 用户信息通过 `User::toJwtArray()` 写入 Redis，key 为 `user:cache:{id}`。
4. 受保护接口经过 `JwtAuthMiddleware`，从 JWT 和 Redis 恢复用户。
5. 前端启动时调用 `/api/user/info` 和 `/api/permissions/user/menus`。

权限模型：

- `users`：用户。
- `roles`：角色。
- `role_user`：用户角色关系。
- `permissions`：菜单和操作权限，`type` 为 `menu` 或 `operation`。
- `role_permissions`：角色权限关系。

当前实库角色包括：

- 超级管理员
- 管理员
- 一般成员
- 若干测试角色，如优抚测试角色

当前实库用户包括：

- `admin`：超级管理员。
- `zoe`、`user1`、`test` 等普通/测试用户。
- 多个镇街账号雏形，如 `baisha`、`bailin`、`caijia`、`jijiang` 等；当前 `users` 表没有镇街字段，尚不能从数据层直接表达“账号归属镇街”。

现有菜单权限顶层：

- 仪表板
- 用户管理
- 业务配置
- 数据核实
- 统计汇总
- 救助报销
- 联网结算

## 5. 已有业务功能

### 5.1 参保台账/数据核实

核心接口：

- `/api/insurance-data`
- `/api/insurance-level-configs`
- `/api/tax-summary/data`
- `/api/insurance-summary/data`

核心表：

- `insurance_data`：参保对象数据，含年度、姓名、身份证、镇街、医保类别、资助身份、档次、代缴金额、个人实缴金额、匹配状态等。
- `insurance_level_configs`：年度资助参保档次配置，含代缴类别、档次、补助金额、个人金额。
- `insurance_years`：年度启用配置。
- `category_conversions`：类别口径转换配置，打通税务、医保导出、国家字典口径。

主要能力：

- 导入参保对象、困难人员、死亡人员、身份映射等资料。
- 对资助身份、参保档次、镇街进行匹配。
- 汇总税务与参保数据，导出核实结果。

### 5.2 医疗救助受理

核心接口：

- `/api/medical-assistance/patients`
- `/api/medical-assistance/medical-records`
- `/api/medical-assistance/reimbursements`

核心表：

- `med_person_info`：患者/人员基础信息。
- `med_medical_record`：就诊记录，含医疗机构、就诊类别、入出院结算日期、费用与各类报销金额、处理状态。
- `med_reimbursement_detail`：受理/报销记录，含银行、账户、户名、合计金额、报销状态等。

主要能力：

- 患者管理。
- 就诊记录导入、维护、导出。
- 医疗救助受理台账维护、导出、状态标记。
- 移动端已有受理相关页面。

### 5.3 统计汇总

核心接口：

- `/api/statistics/projects`
- `/api/statistics/list`
- `/api/statistics/import`
- `/api/statistics/*-statistics`
- `/api/statistics/export-*`

核心表：

- `projects`：项目。
- `statistics_data`：统计明细，支持区内明细、跨区明细、手工明细，字段覆盖人员、身份、就诊机构、医保类别、病种、费用、医疗救助、倾斜救助、渝快保、个人支付等。
- `statistics_summery`：历史汇总表，命名存在 `summery` 拼写。

主要能力：

- 按项目导入统计明细。
- 人次统计、报销统计、倾斜救助统计。
- 导出统计明细和多类统计汇总。

### 5.4 优抚联网结算

核心接口：

- `/api/yf-settlements`
- `/api/yf-category-quotas`

核心表：

- `yf_settlements`：优抚联网结算明细，含人员、优抚类别、医保类别、机构、病种、清算期、费用、医保/救助/渝快保支付、年度限额、已用金额、本次补助、剩余额度、支付状态。
- `yf_category_quotas`：优抚类别年度补助限额。

主要能力：

- 导入联网结算数据。
- 按优抚类别和年度限额计算本次补助。
- 标记支付、批量支付、重算、导出明细和台账。

### 5.5 通用任务中心

核心表：

- `task`：异步任务记录，含 uuid、标题、发起人、进度、导出文件地址、文件大小、状态。

核心服务：

- `TaskService::dispatchTask()`：创建任务、加锁、投递 Job。
- `AbstractJob`：统一更新任务进度、完成状态、失败状态和释放锁。

## 6. 现有数据模型简表

| 表名 | 说明 | 主要字段 |
| :--- | :--- | :--- |
| `users` | 用户 | username, password, nickname |
| `roles` | 角色 | name, description |
| `permissions` | 菜单/操作权限 | name, type, parent_id, path, component, icon, sort |
| `role_user` | 用户角色关系 | user_id, role_id |
| `role_permissions` | 角色权限关系 | role_id, permission_id |
| `category_conversions` | 类别口径转换 | tax_standard, medical_export_standard, national_dict_name |
| `insurance_data` | 参保数据 | year, name, id_number, street_town, level, payment_amount, match_status |
| `insurance_level_configs` | 参保档次配置 | year, payment_category, level, subsidy_amount, personal_amount |
| `insurance_years` | 年度配置 | year, is_active, description |
| `med_person_info` | 救助患者 | name, id_card, insurance_area |
| `med_medical_record` | 就诊记录 | person_id, hospital_name, visit_type, dates, costs, processing_status |
| `med_reimbursement_detail` | 受理报销 | person_id, medical_record_ids, bank info, amount, reimbursement_status |
| `projects` | 统计项目 | code, dec |
| `statistics_data` | 统计明细 | project_id, import_type, settlement_period, id_number, amounts |
| `statistics_summery` | 统计汇总 | project_code, data_type, street_town, person/payment fields |
| `task` | 任务中心 | uuid, title, uid, progress, file_url, status |
| `yf_category_quotas` | 优抚年度限额 | year, category, quota_amount |
| `yf_settlements` | 优抚结算 | id_card, category, period_belong, amounts, pay_status |

## 7. 对新“未救助明细台账”模块的边界判断

`docs/未救助明细台账_md` 描述的是一个新业务模块。它只复用平台底座能力：

- 登录认证、用户角色权限。
- 动态菜单。
- 导入/导出文件处理。
- 任务中心。
- 全平台操作日志。
- 通用响应、日志、Redis 队列。

它不应复用现有参保、医疗救助受理、统计汇总、优抚联网结算的业务表作为主数据源。原因是：

- 新模块的导入附件、状态流转、清洗规则、镇街下放、银行回填和导出格式独立。
- 附件4“应补应退排查记录”虽然字段类似 `statistics_data` 和 `yf_settlements`，但需求明确是独立排查结果，每月名单一人可多条；本期不新增附件4导入模板。
- 现有 `users` 表没有镇街归属字段；若新模块需要镇街账号数据权限，需要扩展用户-镇街关系或新增镇街组织表。
- 附件2救助对象名单不单独落表，导入后直接补全当月未救助主表；附件4身份、镇街匹配也依据附件2导入后沉淀在主表中的对象信息处理。
