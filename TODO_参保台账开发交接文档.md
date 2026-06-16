# 参保台账开发交接文档

更新时间：2026-06-16

本文按当前真实代码与已确认业务口径重新整理，用于后续开发、排查和交接。旧版讨论中的不确定 TODO 已清理；仍需客户确认的内容统一放在“已知口径与注意事项”。

## 一、当前功能范围

参保台账模块包含三类页面：

| 页面 | 前端路径 | 主要用途 |
| :--- | :--- | :--- |
| 参保台账明细 | `tools-react-yf/src/pages/Enroll/Ledgers/index.tsx` | 年度台账查询、统计、导入附件3/4/5/6、导出附件1/2/3 |
| 参保配置 | `tools-react-yf/src/pages/Enroll/Configs/index.tsx` | 维护附件1、附件2、附件7配置，支持导入、增删改、年度克隆 |
| 参保导入记录 | `tools-react-yf/src/pages/Enroll/ImportBatches/index.tsx` | 查看附件3/4/5/6导入批次、总行数、成功/失败行数和结果消息 |

后端主要入口：

| 类型 | 文件 |
| :--- | :--- |
| 明细控制器 | `app/Controller/Enroll/LedgerController.php` |
| 配置控制器 | `app/Controller/Enroll/ConfigController.php` |
| 业务服务 | `app/Service/Enroll/EnrollLedgerService.php` |
| 附件3导入任务 | `app/Job/Enroll/Attachment3ImportJob.php` |
| 附件4/5/6导入任务 | `app/Job/Enroll/SupplementImportJob.php` |
| 导出任务 | `app/Job/Enroll/EnrollExportJob.php` |
| CSV读取 | `app/Service/CsvReaderService.php` |

## 二、核心业务口径

1. 参保台账按“年份 + 身份证号码”作为程序层主匹配规则，年度内同一身份证只保留一条主台账记录。
2. 数据库不使用唯一索引兜底，新增或更新由程序判断处理。
3. 附件3是按月导入的“当月全量名单”。用户选择年份和月份后，后端周期统一为 `YYYY-MM`。
4. 增量形式只有 `新增`、`变更`、`取消`，没有“正常”状态。
5. 同月重复导入时，若当前优先医疗救助身份未变化，保留原来的增量形式。
6. 本年度已取消人员在后续月份重新出现时，标记为 `变更`。
7. 镇街账号按账号绑定镇街限制 `town_name` 数据范围。

## 三、数据库表

| 表名 | 中文说明 | 主要用途 |
| :--- | :--- | :--- |
| `enroll_configs` | 参保身份配置表 | 保存附件1资助参保身份配置、附件2医疗救助身份配置 |
| `enroll_identity_amount_configs` | 身份实缴金额配置表 | 保存附件7身份对应明细，用于判断是否符合资助 |
| `enroll_ledgers` | 参保台账主表 | 保存年度人员主台账 |
| `enroll_ledger_snapshots` | 月度快照表 | 保存附件3导入前、导入后的快照，便于追溯 |
| `enroll_import_batches` | 导入批次表 | 保存附件3/4/5/6导入批次、行数、状态和结果消息 |
| `tasks` | 任务中心表 | 保存附件3/4/5/6导入、导出任务进度、结果文件、失败原因 |
| `operation_logs` | 全局操作记录表 | 保存导入、导出、配置编辑、克隆等操作记录 |
| `business_filter_options` | 业务筛选项表 | 保存附件3导入时产生的镇街、原始身份等筛选项 |

## 四、权限和镇街账号

菜单权限由 `database/migrations/2026_06_04_100002_seed_enroll_permissions.php` 和 `app/Command/InitMenuPermissionsCommand.php` 整理。

主要权限：

| 权限 | 用途 |
| :--- | :--- |
| `参保台账明细:查看` | 查看参保台账明细 |
| `参保台账明细:导入` | 导入附件3/4/5/6 |
| `参保台账明细:导出` | 导出附件1/2/3 |
| `参保台账明细:编辑` | 编辑人工字段 |
| `参保配置:查看` | 查看配置 |
| `参保配置:导入` | 导入附件1/2/7配置 |
| `参保配置:创建` | 新增配置、克隆年度配置 |
| `参保配置:编辑` | 编辑配置 |
| `参保配置:删除` | 删除配置 |
| `参保导入记录:查看` | 查看导入批次 |

镇街账号当前口径：

1. 明细列表、统计、筛选项、单条详情、编辑、导出均自动限制为当前账号所属镇街。
2. 后端禁止镇街账号导入附件3/4/5/6，接口返回 403。
3. 镇街账号如果拥有导出权限，只导出本镇街范围的数据。
4. 参保配置是全局配置，建议不要给镇街角色开放。
5. 参保导入记录目前不是按镇街隔离，建议不要给镇街角色开放。

## 五、CSV读取与导入文件校验

CSV 统一由 `CsvReaderService` 处理。

当前能力：

1. 支持 `.csv` 文件。
2. 自动识别 UTF-8、GBK、GB18030、GB2312、BIG5。
3. 读取前 1MB 用于编码检测，避免表头纯 ASCII 时误判 GBK 文件为 UTF-8。
4. 所有表头和单元格会统一规范为合法 UTF-8，再去除 BOM、零宽字符。
5. 配置导入、附件3、附件4、附件5、附件6都会校验关键表头；缺表头会直接失败。

注意：前端提示“UTF-8/GBK”，后端实际已兼容 GB18030。

## 六、参保配置

入口：`参保台账 -> 参保配置`

年份仅展示近三年。切换年份自动请求接口；切换配置类型时保留当前年份。

### 1. 附件1：资助参保身份配置

配置类型：`subsidy`

写入表：`enroll_configs`

程序层新增/更新规则：`year + config_type + identity_name + insurance_level`

导入模板列：

| 导入列 | 字段 | 说明 |
| :--- | :--- | :--- |
| 优先级/排序 | `priority` | 值越小优先级越高 |
| 资助参保身份 | `identity_name` | 生成资助参保身份记录、当前优先资助参保身份 |
| 资助档次/参保档次/档次 | `insurance_level` | 用于计算参保类别 |
| 资助标准 | `subsidy_standard` | 配置留存 |
| 个人实缴金额/居民医保缴费金额 | `personal_amount` | 计算参保类别时匹配 |
| 资助代缴金额/资助金额 | `subsidy_amount` | 计算参保类别时匹配 |

页面新增/编辑额外支持 `included_identities`（包含参保身份），用于别名匹配，不要求导入模板包含该列。

### 2. 附件2：医疗救助身份配置

配置类型：`medical`

写入表：`enroll_configs`

程序层新增/更新规则：`year + config_type + identity_name`

导入模板列：

| 导入列 | 字段 | 说明 |
| :--- | :--- | :--- |
| 优先级/排序 | `priority` | 值越小优先级越高 |
| 医疗救助身份 | `identity_name` | 生成医疗救助身份记录、当前优先医疗救助身份 |
| 包含参保身份 | `included_identities` | 多个值可用顿号、逗号、分号等分隔 |

医疗救助身份配置不使用资助档次、资助标准、个人实缴金额、资助代缴金额。

### 3. 附件7：身份对应明细配置

配置类型：`identity_amount`

写入表：`enroll_identity_amount_configs`

程序层新增/更新规则：`year + special_identity + paid_amount`

导入模板列：

| 导入列 | 字段 | 说明 |
| :--- | :--- | :--- |
| 特殊人员身份/身份类别 | `special_identity` | 判断是否符合资助时匹配 |
| 实缴金额/个人实缴金额 | `paid_amount` | 判断是否符合资助时匹配居民医保缴费金额 |

页面新增/编辑额外支持 `included_identities`，用于别名匹配，不要求导入模板包含该列。

### 4. 配置导入结果口径

配置导入同步处理，返回：

| 字段 | 说明 |
| :--- | :--- |
| `total` | 文件总记录数 |
| `success` | 成功新增或更新入库数 |
| `skipped` | 与库内一致，无需写入数 |
| `failed` | 失败行数 |
| `errors` | 最多记录前 20 条错误 |

前端提示：`总 xx 条，成功入库 xx 条，跳过 xx 条，失败 xx 条`。

### 5. 年度克隆

可从来源年份克隆到目标年份，可勾选：

- 资助参保身份
- 医疗救助身份
- 身份对应明细

支持“覆盖目标年份已有配置”。不覆盖时按程序层规则新增或更新；覆盖时先清理目标年份中被勾选类型的数据，再复制。

## 七、参保台账明细

入口：`参保台账 -> 参保台账明细`

### 1. 筛选和统计

筛选项：

| 筛选项 | 字段 |
| :--- | :--- |
| 年份 | `year` |
| 关键词 | `name`、`id_card`、`village_name` 模糊查询 |
| 镇街 | `town_name` |
| 身份变更 | `change_status` |

统计项：

| 统计项 | 规则 |
| :--- | :--- |
| 当前记录 | 当前筛选条件下总数 |
| 新增 | `change_status = 新增` |
| 变更 | `change_status = 变更` |
| 取消 | `change_status = 取消` |
| 已参保 | `is_insured = 是` |
| 符合资助 | `is_eligible_for_subsidy = 是` |

### 2. 列表交互

1. 姓名、身份证号码、镇街默认固定左侧。
2. 支持列显示配置。
3. 支持按金额、日期等字段排序：`resident_payment_amount`、`subsidy_amount`、`tax_first_request_amount`、`included_month`、`cancel_month`、`payment_time`、`updated_at`。
4. 部分长文本字段支持省略显示和复制。

### 3. 可人工编辑字段

接口只允许更新以下字段：

| 字段 | 中文说明 |
| :--- | :--- |
| `payment_time` | 缴费时间 |
| `uninsured_reason` | 未参保原因 |
| `insurance_place_remark` | 资助地或参保地（区外备注） |
| `death_remark` | 备注（卫健委死亡时间） |
| `manual_remark` | 人工备注 |

## 八、附件3导入：资助参保对象全量明细

入口：`参保台账明细 -> 导入 -> 导入 附件3全量明细`

处理方式：异步任务，提交后到任务中心查看进度。

写入表：

- `enroll_ledgers`
- `enroll_import_batches`
- `enroll_ledger_snapshots`
- `business_filter_options`

必需表头：

| 必需组 | 支持别名 |
| :--- | :--- |
| 身份证号码 | 身份证号码、身份证号、公民身份号码、身份证、id_card |
| 医疗救助身份 | 医疗救助身份、特殊人员身份、身份类别、救助身份、身份、raw_identity |

导入字段：

| 导入列 | 台账字段 | 说明 |
| :--- | :--- | :--- |
| 身份证号码 | `id_card` | 年度主匹配字段 |
| 姓名 | `name` | 人员姓名 |
| 镇（街） | `town_name` | 镇街 |
| 村（居） | `village_name` | 村居 |
| 纳入资助时间 | `included_month` | 标准化为日期文本；空值取导入月份 |
| 缴费时间 | `payment_time` | 标准化为日期文本 |
| 医疗救助身份 | `raw_identity` | 原始身份，可拆分多个身份 |

保护规则：

1. 缺必需表头则失败。
2. 有身份证号码为空或医疗救助身份为空的错误行，则整批失败，避免误判取消人员。
3. 没有有效附件3数据时失败，不执行取消逻辑。

### 身份匹配

医疗救助身份：

1. 使用附件3原始身份匹配当年 `medical` 配置。
2. 可匹配 `identity_name`，也可匹配 `included_identities`。
3. 支持中英文括号、顿号、空格、`1-2级` 等归一化。
4. 命中记录按优先级升序存入 `medical_identity_records`。
5. `medical_identity` 取优先级最高的一项。
6. 未命中配置时仍保留原身份，优先级记为 `9999`，便于排查配置缺漏。

资助参保身份：

1. 使用附件3原始身份匹配当年 `subsidy` 配置。
2. 可匹配 `identity_name`，也可匹配页面维护的 `included_identities`。
3. 命中记录按优先级升序存入 `subsidy_identity_records`。
4. `subsidy_identity` 取优先级最高的一项。

同一身份证在同月文件出现多行时，会合并身份记录并保留优先级最高身份。

### 新增、变更、取消

附件3导入前先保存导入前快照，再按本年度已有台账与本月全量名单比对：

| 情况 | `change_status` |
| :--- | :--- |
| 本年度该身份证不存在 | 新增 |
| 本年度该身份证存在，且之前是取消，本月重新出现 | 变更 |
| 本年度该身份证存在，当前优先医疗救助身份发生变化 | 变更 |
| 本年度该身份证存在，当前优先医疗救助身份未变化 | 保留原状态 |
| 本年度之前存在，但本月附件3没有该身份证 | 取消 |

取消时写入：

| 字段 | 值 |
| :--- | :--- |
| `change_status` | 取消 |
| `cancel_month` | 当前导入月份 |
| `last_attachment3_period` | 当前导入月份 |

### 附件3后立即计算的字段

附件3导入后会调用 `calculateInsuranceFields()`，但部分结果依赖附件4、附件5、附件7。

| 字段 | 规则 |
| :--- | :--- |
| `is_eligible_for_subsidy` 是否符合资助 | 没有缴费时间为 `待核实`；纳入资助月份与缴费月份不同为 `否`；同月时用 `year + subsidy_identity_obtained + resident_payment_amount` 匹配附件7，命中为 `是`，否则为 `否` |
| `is_subsidy_obtained` 是否获得资助 | 待核实时为 `待核实`；符合资助且资助金额大于 0 为 `是`，否则为 `否` |
| `subsidy_method` 资助方式 | 获得资助为 `是` 时为 `系统资助`，否则为 `事后资助`；待核实时为空 |
| `is_insured` 是否参保 | 依赖参保类别；参保类别为居民一档、居民二档、大学生一档、大学生二档、需核实时为 `是` |
| `uninsured_reason` 未参保原因 | 是否参保为 `是` 时写 `无`，否则为空 |

## 九、附件4导入：困难人员参保核实

入口：`参保台账明细 -> 导入 -> 导入 附件4参保核实`

处理方式：异步任务。

必需表头：

| 必需组 | 支持别名 |
| :--- | :--- |
| 身份证号码 | 身份证号、身份证号码、公民身份号码、身份证 |
| 个人缴费金额 | 个人缴费金额、居民医保缴费金额 |

匹配规则：按 `year + id_card` 找既有台账，只更新，不新增人员。

字段写入：

| 附件4列 | 台账字段 | 说明 |
| :--- | :--- | :--- |
| 个人缴费金额 | `resident_payment_amount` | 居民医保缴费金额 |
| 居民医保所属区划、职工医保所属区划 | `insurance_place_remark` | 非江津区划拼为区外备注 |
| 当前导入月份 | `last_attachment4_period` | 最近附件4月份 |

导入后重新计算：参保类别、是否参保、未参保原因、是否符合资助、是否获得资助、资助方式。

如果整份文件没有匹配到任何可更新台账，任务失败并写入失败原因。

## 十、附件5导入：税务请款明细

入口：`参保台账明细 -> 导入 -> 导入 附件5税务请款`

处理方式：异步任务。

必需表头：

| 必需组 | 支持别名 |
| :--- | :--- |
| 身份证号码 | 身份证号码、身份证号、公民身份号码、身份证 |
| 代缴金额 | 代缴金额、资助金额 |
| 代缴类别 | 代缴类别、获得资助身份类别、身份类别 |

匹配规则：按 `year + id_card` 找既有台账，只更新，不新增人员。同一身份证多行时会合并金额。

字段写入：

| 附件5列 | 台账字段 | 说明 |
| :--- | :--- | :--- |
| 代缴金额 | `subsidy_amount` | 资助金额，同人多行累加 |
| 代缴类别 | `subsidy_identity_obtained` | 获得资助身份类别 |
| 请款批次 | `tax_request_batch` | 税务请款批次 |
| 请款批次包含“第一”时的代缴金额 | `tax_first_request_amount` | 税务第一批请款金额，同人多行累加 |
| 当前导入月份 | `last_attachment5_period` | 最近附件5月份 |

导入后重新计算：参保类别、是否参保、未参保原因、是否符合资助、是否获得资助、资助方式。

参保类别计算：

1. 取台账 `subsidy_identity + resident_payment_amount + subsidy_amount`。
2. 匹配当年附件1配置 `identity_name/included_identities + personal_amount + subsidy_amount`。
3. 命中后取配置的 `insurance_level`。
4. 未命中时写 `需核实`。

## 十一、附件6导入：死亡人员名单

入口：`参保台账明细 -> 导入 -> 导入 附件6死亡名单`

处理方式：异步任务。

必需表头：

| 必需组 | 支持别名 |
| :--- | :--- |
| 身份证号码 | 身份证号码、身份证号、公民身份号码、身份证 |
| 死亡时间 | 死亡时间、death_time |

匹配规则：按 `year + id_card` 找既有台账，只更新，不新增人员。

字段写入：

| 附件6列 | 台账字段 |
| :--- | :--- |
| 死亡时间 | `death_remark` |
| 当前导入月份 | `last_attachment6_period` |

## 十二、导入记录和任务中心

附件3/4/5/6导入会同时写入：

1. `tasks`：任务进度、成功/失败、失败原因、结果文件。
2. `enroll_import_batches`：业务批次、总行数、成功行数、失败行数、结果消息。
3. 后端日志：`runtime/logs/hyperf.log`。

导入失败时，用户应优先看任务中心和参保导入记录的失败原因。

## 十三、导出

入口：`参保台账明细 -> 导出`

导出是异步任务，结果到任务中心下载。导出格式为 CSV，带 UTF-8 BOM。

### 1. 附件1《资助参保对象-汇总名单》

建议导出时机：完成附件1、附件2、附件3后。

列：

| 列 | 来源 |
| :--- | :--- |
| 姓名 | `name` |
| 身份证号码 | `id_card` |
| 镇（街） | `town_name` |
| 村（居） | `village_name` |
| 资助参保身份 | `subsidy_identity` |
| 动态身份列 | 当前年份附件1配置，按优先级去重生成 |

动态身份列规则：读取 `subsidy_identity_records`，命中该列身份填 `√`，否则留空。

### 2. 附件2《资助参保对象-对比结果》

建议导出时机：完成附件3当月全量导入后。

列：

| 列 | 来源 |
| :--- | :--- |
| 增量形式 | `change_status` |
| 姓名 | `name` |
| 身份证号码 | `id_card` |
| 医疗救助身份 | `medical_identity` |
| 纳入资助时间 | `included_month` |
| 身份取消时间 | `cancel_month` |

导出时只导出 `新增`、`变更`、`取消` 三类记录。

### 3. 附件3《特殊对象资助参保台账》

建议导出时机：附件3、附件4、附件5、附件6都完成后。

列：

| 列 | 来源 |
| :--- | :--- |
| 序号 | 导出时生成 |
| 纳入资助时间 | `included_month` |
| 身份取消时间 | `cancel_month` |
| 身份变更情况 | `change_status` |
| 镇（街） | `town_name` |
| 村（居） | `village_name` |
| 姓名 | `name` |
| 身份证号码 | `id_card` |
| 医疗救助身份 | `medical_identity` |
| 资助参保身份 | `subsidy_identity` |
| 是否参保 | `is_insured` |
| 参保类别 | `insurance_category` |
| 居民医保缴费金额 | `resident_payment_amount` |
| 缴费时间 | `payment_time` |
| 是否符合资助 | `is_eligible_for_subsidy` |
| 是否获得资助 | `is_subsidy_obtained` |
| 获得资助身份类别 | `subsidy_identity_obtained` |
| 资助方式 | `subsidy_method` |
| 税务第一批请款税务清款情况 | `tax_first_request_amount` |
| 资助金额 | `subsidy_amount` |
| 资助地或参保地（区外备注） | `insurance_place_remark` |
| 备注（卫健委死亡时间） | `death_remark` |
| 未参保原因 | `uninsured_reason` |

## 十四、接口清单

参保配置：

- `GET /api/enroll/configs`
- `POST /api/enroll/configs`
- `PUT /api/enroll/configs/{id}`
- `DELETE /api/enroll/configs/{id}`
- `POST /api/enroll/configs/import`
- `POST /api/enroll/configs/clone`

参保台账明细：

- `GET /api/enroll/ledgers`
- `GET /api/enroll/ledgers/statistics`
- `GET /api/enroll/ledgers/options`
- `GET /api/enroll/ledgers/{id}`
- `PUT /api/enroll/ledgers/{id}`
- `POST /api/enroll/ledgers/import-attachment3`
- `POST /api/enroll/ledgers/import-attachment4`
- `POST /api/enroll/ledgers/import-attachment5`
- `POST /api/enroll/ledgers/import-attachment6`
- `POST /api/enroll/ledgers/export`
- `GET /api/enroll/import-batches`

## 十五、开发和排查重点

1. 附件3全量名单导入必须谨慎，不能在文件错误时执行取消逻辑。
2. 变更判断基于当前优先医疗救助身份 `medical_identity`，不是资助参保身份。
3. 附件4/5/6只更新既有台账，不新增人员；所以必须先有附件3数据。
4. `subsidy_identity_records` 中出现 `priority=9999` 表示配置未命中，需要补配置或别名。
5. 参保类别依赖附件1配置、附件4缴费金额、附件5资助金额。
6. 是否符合资助依赖附件7配置、缴费时间、获得资助身份类别、居民医保缴费金额。
7. 导入/导出任务失败原因应写入任务中心和导入批次，避免只能查日志。
8. 镇街账号不能导入附件3/4/5/6，导出也应自动限制本镇街数据。

## 十六、已知口径与注意事项

1. 附件4区外备注当前按“居民医保所属区划/职工医保所属区划非江津”拼接。
2. 附件5税务第一批请款当前按“请款批次包含第一”时写入对应代缴金额。
3. 特殊对象资助参保台账导出后回导更新人工调整内容，目前未实现独立回导接口。
4. 前端参保导入记录目前不是按镇街隔离，镇街角色不建议开放。
