# 未救助台账功能开发交接文档

更新时间：2026-05-28

## 一、当前功能范围

本次新增“未救助台账”业务，覆盖未救助明细导入、救助对象名单匹配、清洗规则配置与执行、镇街下放/接收/通知、账户回填、报销标记、统计筛选、导出和重大疾病编码配置。

当前业务主入口：

- 前端页面：`tools-react-yf/src/pages/Unrescued/Records/index.tsx`
- 前端接口：`tools-react-yf/src/services/unrescued.ts`
- 后端控制器：`app/Controller/Unrescued/RecordController.php`
- 后端业务服务：`app/Service/Unrescued/UnrescuedRecordService.php`
- 运行日志：`runtime/logs/hyperf.log`

## 二、迁移文件整理结果

本次业务相关 migration 已整理为一次到位版本，生产首次执行时只需要保留以下文件：

- `2026_05_24_100001_create_towns_table.php`：镇街表。
- `2026_05_24_100002_add_town_id_to_users_table.php`：用户绑定镇街。
- `2026_05_24_100003_create_operation_logs_table.php`：操作日志表。
- `2026_05_24_100004_create_unrescued_records_table.php`：未救助主表。
- `2026_05_24_100005_create_unrescued_disease_configs_table.php`：重大疾病编码配置表。
- `2026_05_24_100006_create_unrescued_wash_configs_table.php`：清洗规则配置表。
- `2026_05_24_100007_create_unrescued_wash_logs_table.php`：清洗执行日志表。
- `2026_05_24_100008_create_unrescued_supplement_records_table.php`：应退应补排查记录表。
- `2026_05_24_100009_add_status_to_permissions_table.php`：权限表补状态字段。
- `2026_05_24_100010_seed_unrescued_permissions.php`：未救助菜单及按钮权限节点。
- `2026_05_24_100011_create_business_filter_options_table.php`：业务筛选项缓存表。

已经不需要保留的修正类 migration：

- `2026_05_24_100010_patch_unrescued_stage_four_to_seven_fields.php`
- `2026_05_25_231200_patch_unrescued_wash_config_rule_name.php`
- `2026_05_27_100001_patch_unrescued_wash_config_rule_type.php`

注意：`unrescued_wash_configs` 当前不再使用 `rule_type` 字段。清洗规则按 `data` JSON 保存，名称字段为 `name` 和 `rule_name`。

## 三、核心表说明

### 1. `unrescued_records`

未救助明细主表，数据主要来自附件1，附件2导入后按清算期和身份证号匹配补充镇街、村社、身份类别等信息。

核心字段：

- `settlement_period`：清算期，格式如 `202602`。
- `sequence_no`：附件1序号，同一清算期内作为附件1更新匹配依据。
- `name`、`id_card`：姓名、身份证号。
- `medical_category`、`hospital_name`、`priority_identity`：医疗类别、机构名称、身份类别，已作为筛选和清洗条件。
- `calc_reimbursement_amount`：进入报销金额，按政策范围费用扣减统筹、大额、大病报销计算。
- `town_id`、`street_town`、`village`：附件2匹配后回填的镇街信息。
- `status`：流程状态，包含 `待处理`、`无救助金额`、`不通知`、`拟通知`、`已下放`、`已接收`、`已通知`。
- `exclude_status`：清洗剔除状态，包含 `未剔除`、`已剔除`。
- `exclude_rule_code`：命中的清洗规则编码。
- `reimbursement_status`：报销状态，包含 `未报销`、`已报销`。
- `bank_name`、`bank_account_name`、`bank_account_no`：镇街回填账户信息。

主表已在建表 migration 中补齐索引：

- `idx_period_sequence(settlement_period, sequence_no)`
- `idx_period_id_card(settlement_period, id_card)`
- `idx_period_town_status(settlement_period, town_id, status)`
- `idx_period_status(settlement_period, status)`
- `idx_period_medical_category(settlement_period, medical_category)`
- `idx_period_priority_identity(settlement_period, priority_identity)`
- `idx_period_hospital_name(settlement_period, hospital_name)`
- `idx_period_exclude_rule(settlement_period, exclude_rule_code)`
- `idx_exclude_status(exclude_status)`
- `idx_reimbursement_status(reimbursement_status)`

如果本地或测试库已经提前建过表但没有这些索引，可手动执行：

```sql
ALTER TABLE `unrescued_records`
  ADD INDEX `idx_period_medical_category` (`settlement_period`, `medical_category`),
  ADD INDEX `idx_period_priority_identity` (`settlement_period`, `priority_identity`),
  ADD INDEX `idx_period_hospital_name` (`settlement_period`, `hospital_name`),
  ADD INDEX `idx_period_exclude_rule` (`settlement_period`, `exclude_rule_code`);
```

### 2. `unrescued_wash_configs`

保存清洗规则配置。默认规则在代码中生成，不再依赖额外 seed 数据。

默认规则包括：

- 医疗类别保留：`medical_category_keep`
- 机构名称关键字剔除：`hospital_keyword_exclude`
- 统筹报销等于政策范围费用：`pool_equals_policy`
- 大额报销等于政策范围费用：`large_equals_policy`
- 大病报销等于政策范围费用：`serious_equals_policy`
- 普通住院救助额度已满：`normal_rescue_limit`
- 重特大疾病救助额度已满：`major_rescue_limit`
- 大额费用住院救助额度已满：`large_fee_rescue_limit`
- 身份类别剔除：`identity_exclude`

### 3. `business_filter_options`

用于缓存动态筛选项：

- 附件1导入后保存医疗类别：`business=unrescued`、`option_type=medical_category`
- 附件2导入后保存身份类别：`business=unrescued`、`option_type=priority_identity`

## 四、权限与菜单

菜单和按钮节点由 `2026_05_24_100010_seed_unrescued_permissions.php` 创建。

主要节点：

- 未救助台账
- 未救助明细
- 重大疾病编码
- 未救助明细的导入、清洗、下放、通知、账户回填、报销标记、导出等按钮权限

前端权限判断在 `tools-react-yf/src/access.ts` 中，关键 access 包括：

- `canAccessUnrescued`
- `canReadUnrescuedRecords`
- `canImportUnrescuedRecords`
- `canWashUnrescuedRecords`
- `canDistributeUnrescuedRecords`
- `canNotifyUnrescuedRecords`
- `canFillUnrescuedAccounts`
- `canMarkUnrescuedReimbursement`
- `canExportUnrescuedRecords`

镇街账号由用户 `town_id` 判断。镇街账号不能导入、执行清洗、配置清洗规则、下放、标记报销和导出。

## 五、后端接口

接口前缀：`/api/unrescued/records`

- `GET /`：未救助明细分页列表。
- `GET /statistics`：统计数据。
- `POST /import-attachment1`：导入附件1未救助明细，仅支持 CSV。
- `POST /import-attachment2`：导入附件2救助对象名单，仅支持 CSV。
- `GET /wash-config`：获取当前启用清洗规则。
- `POST /wash-config`：保存清洗规则。
- `GET /wash-options`：获取清洗和筛选下拉项。
- `POST /wash/execute`：提交清洗任务。
- `GET /wash/status`：查询当前清算期是否存在执行中的清洗任务。
- `POST /distribute`：按清算期和镇街下放数据。
- `POST /receive`：镇街确认接收。
- `POST /notify`：标记已通知。
- `POST /unnotify`：撤销通知。
- `POST /accounts`：回填账户信息。
- `POST /reimbursement`：标记报销状态。
- `POST /export`：提交导出任务。
- `GET /{id}`：查看单条记录。

## 六、导入逻辑

### 1. 附件1导入

任务类：`app/Job/Unrescued/Attachment1ImportJob.php`

处理方式：

- 按用户上传的清算期导入。
- 以 `settlement_period + sequence_no` 判断是否已存在。
- 已存在则批量更新，未存在则批量插入。
- 已进入镇街流程的状态不被附件1重新计算覆盖，即 `已下放`、`已接收`、`已通知` 会保留。
- 新记录按进入报销金额自动判定：
  - `<= 0`：`无救助金额`
  - `<= 300`：`不通知`
  - `> 300`：`拟通知`
- 导入过程按批次处理，并写入任务进度和后端日志。

性能优化点：

- CSV 读取后按 500 行聚合写库。
- 一次查询当前批次已存在序号。
- 插入使用批量 insert。
- 更新使用 CASE 批量 update，异常时回退到逐条保存。
- 医疗类别筛选项在导入完成后去重保存。

### 2. 附件2导入

任务类：`app/Job/Unrescued/Attachment2ImportJob.php`

处理方式：

- 按 `settlement_period + id_card` 匹配附件1主表。
- 匹配成功后回填 `town_id`、`street_town`、`village`、`priority_identity`。
- 镇街匹配支持镇街名称、编码及简单名称归一化。
- 如果姓名为空，会用附件2姓名补齐主表姓名。
- 匹配后重新计算待处理类状态，但不覆盖 `已下放`、`已接收`、`已通知`。
- 身份类别筛选项在导入完成后去重保存。

性能优化点：

- 启动时一次性加载镇街映射。
- 每 1000 行聚合处理。
- 每批只按身份证集合查询一次匹配数量。
- 任务进度和后端日志会持续记录。

## 七、清洗规则

清洗规则执行已改为异步任务。

任务类：`app/Job/Unrescued/WashExecuteJob.php`

执行逻辑：

- 按清算期执行，可选镇街范围。
- 每 1000 条记录分批扫描。
- 命中清洗规则的记录更新为 `已剔除`，并写入 `exclude_rule_code`、`remark`。
- 未命中记录更新为 `未剔除`，并清空命中规则和备注。
- 每批使用批量 update，减少逐条保存。
- 执行完成后写入 `unrescued_wash_logs`，记录总数、剔除数、保留数和规则命中汇总。

前端交互：

- 点击执行清洗前有二次确认。
- 清洗任务提交后按钮不可重复点击。
- 页面展示执行进度。
- 页面刷新或切换回来后，会通过本地缓存的任务 uuid 和 `/wash/status` 恢复执行中状态。
- 清洗执行中每 2 秒同步任务进度，同时刷新统计数据，让“已剔除”等统计肉眼可见变化。
- 任务完成或失败后会清除本地任务缓存；任务中心和后端任务表仍保留历史。

日志关键字：

- `Unrescued wash execute start.`
- `Unrescued wash execute progress.`
- `Unrescued wash execute success.`
- `Unrescued wash execute failed`

## 八、筛选与统计

后端统一由 `UnrescuedRecordService::applyFilters()` 处理筛选。

当前支持筛选：

- 清算期：`settlement_period`
- 关键字：姓名、身份证号、序号
- 镇街：`town_id`
- 医疗类别：`medical_category`
- 身份类别：`priority_identity`
- 机构名称：`hospital_name`，模糊查询
- 状态：`status`
- 剔除状态：`exclude_status`
- 命中规则：`exclude_rule_code`
- 报销状态：`reimbursement_status`

镇街账号数据范围：

- 自动限制为当前用户绑定的 `town_id`。
- 只可见 `已下放`、`已接收`、`已通知` 状态。
- 顶部筛选只保留基础项：清算期、所属镇街、关键字、状态、查询、刷新、重置。
- 所属镇街为当前人员绑定镇街，不可编辑。

普通管理账号：

- 顶部默认展示常用筛选。
- 更多筛选中包含镇街、机构名称、状态、剔除、命中规则、报销等条件。
- 重置按钮会清除其他筛选，但不会清除清算期。
- 清算期切换会立即刷新列表、统计、筛选项和清洗任务状态，并缓存到浏览器本地。

## 九、下放、接收、通知、账户、报销

### 1. 下放

接口：`POST /api/unrescued/records/distribute`

后台按清算期和镇街下放：

- 只处理该镇街下 `未剔除` 且 `拟通知` 的记录。
- 更新为 `已下放` 并写入 `distributed_at`。
- 没有匹配到镇街的记录不能下放。

### 2. 接收

接口：`POST /api/unrescued/records/receive`

镇街账号进入页面后，如果当前清算期存在 `已下放` 待接收记录，会弹窗要求确认接收。确认后更新为 `已接收` 并写入 `received_at`。

### 3. 通知与撤销通知

接口：

- `POST /api/unrescued/records/notify`
- `POST /api/unrescued/records/unnotify`

镇街可对 `已接收` 记录标记为 `已通知`，也可将 `已通知` 撤销回 `已接收`。

### 4. 账户回填

接口：`POST /api/unrescued/records/accounts`

允许对 `已接收`、`已通知` 记录回填开户行、户名、银行账号。

### 5. 报销标记

接口：`POST /api/unrescued/records/reimbursement`

仅管理账号可操作，用于标记 `已报销` 或恢复 `未报销`。

## 十、导出口径

导出入口：`POST /api/unrescued/records/export`

导出任务类：`app/Job/Unrescued/UnrescuedExportJob.php`

导出类型：

- `attachment1`：医疗救助未救助排查明细。
- `attachment2`：医疗救助未报销台账。
- `attachment3`：医疗救助未救助通知名单。
- `attachment4`：医疗救助应补应退排查记录。

当前口径：

- 导出排查明细：导出当前筛选条件下的所有主表记录，首列已增加 `清算期`。
- 导出未报销台账：导出当前筛选条件下所有 `未剔除` 记录。
- 导出通知名单：导出当前筛选条件下所有 `未剔除` 记录。
- 未匹配到镇街的记录也会导出，由人工后续处理；但这类记录不能下放给镇街。
- 应退应补排查记录按应退应补表和筛选条件导出。

注意：附件2和附件3导出不再强制要求已匹配救助对象或已匹配镇街，避免页面显示未剔除 273 条但导出只有 260 条的问题。

日志关键字：

- `Unrescued export submit start.`
- `Unrescued export submit success.`
- `Unrescued export start.`
- `Unrescued export records counted.`
- `Unrescued export progress.`
- `Unrescued export success.`
- `Unrescued export failed`

## 十一、前端页面交互

页面文件：`tools-react-yf/src/pages/Unrescued/Records/index.tsx`

主要交互：

- 清算期默认取浏览器本地缓存；没有缓存时取当前年月。
- 用户手动切换清算期后会写入 localStorage，刷新页面仍保留上一次选择。
- 切换清算期后立即重新加载列表、统计、清洗选项和清洗任务状态。
- 支持导入附件1、附件2，并提供 CSV 模板下载入口。
- 清洗规则支持查看、编辑、保存、执行。
- 清洗执行中显示进度，不允许重复提交。
- 筛选支持查询、刷新、重置，重置不会清除清算期。
- 管理账号可使用更多筛选；镇街账号只保留基础筛选。
- 镇街账号进入页面时，如果存在待接收记录，会提示确认接收。

## 十二、日志和排错

导入、导出、清洗三个耗时链路均已记录开始、进度、成功、失败日志，便于通过 `runtime/logs/hyperf.log` 观察。

常用排查关键字：

- 附件1导入：`Unrescued attachment1 import`
- 附件2导入：`Unrescued attachment2 import`
- 清洗执行：`Unrescued wash execute`
- 导出：`Unrescued export`
- 任务重复提交：`task:lock`

任务状态以任务中心为准。前端页面的进度展示来自任务进度接口和 `/wash/status` 的组合查询。

## 十三、部署和本地执行注意

1. 当前 vendor 要求 PHP `>= 8.2.0`。如果命令行 PHP 仍是 8.1，会导致 `php bin/hyperf.php migrate` 无法执行。
2. 生产首次部署建议从干净业务库执行上述整理后的 migrations，不再执行已删除的 patch migration。
3. 如果本地已经手动改过表结构，建议对照 `database/migrations/2026_05_24_100004_create_unrescued_records_table.php` 核对字段和索引。
4. 如果权限菜单重新执行后没有出现，优先检查 `permissions` 表中 `2026_05_24_100010_seed_unrescued_permissions.php` 创建的未救助节点是否已插入，以及用户角色是否绑定了这些权限。
5. 如果附件2匹配后 `town_id=0`，通常是附件2镇街名称与 `towns` 表名称或编码无法归一匹配，需要维护镇街基础数据或检查附件2镇街列名/内容。

## 十四、已知业务口径

- 附件1按 `清算期 + 序号` 更新或新增。
- 附件2按 `清算期 + 身份证号` 匹配主表，可能一行附件2匹配多条同身份证就医记录。
- 清洗只负责剔除标记，不删除记录。
- 已进入镇街流程的记录，附件1/附件2再次导入时不覆盖流程状态。
- 镇街账号只处理已下放到自己的记录。
- 导出附件2/附件3包含未匹配镇街但未剔除的数据，后续人工处理；未匹配镇街的数据不能执行下放。
