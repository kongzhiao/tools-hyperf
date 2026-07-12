# 参保台账

## 功能目标

按年度和月度管理特殊对象资助参保数据：先维护身份与金额配置，再导入附件3全量名单及附件4/5/6补充数据，计算身份变化、参保类别和资助结果，并通过下放批次完成镇街复核、收回、缴费核查和导出。

## 业务表

- `enroll_configs`：附件1资助参保身份、附件2医疗救助身份配置。
- `enroll_identity_amount_configs`：附件7身份对应实缴金额。
- `business_filter_options`：未参保原因、居民缴费金额等选项。
- `enroll_ledgers`：年度参保台账主表。
- `enroll_ledger_snapshots`：附件3导入前后月度快照。
- `enroll_import_batches`：导入批次和结果。
- `enroll_review_batches`、`enroll_review_items`：镇街复核下放批次和明细。

## 代码位置

- 控制器：`app/Controller/Enroll/LedgerController.php`、`ConfigController.php`。
- 服务：`app/Service/Enroll/EnrollLedgerService.php`。
- Job：`app/Job/Enroll/Attachment3ImportJob.php`、`SupplementImportJob.php`、`Attachment3ReturnImportJob.php`、`EnrollExportJob.php`。
- 模型：`app/Model/Enroll/`。
- 迁移：根 `migrations/2026_06_04_*` 至 `2026_07_05_*`。

## 配置

- `subsidy`：资助参保身份，优先级越小越优先；包含档次、个人金额和资助金额组合。
- `medical`：医疗救助身份和包含身份。
- 身份对应金额：获得资助身份+居民实缴金额，用于判断符合资助。
- 未参保原因和居民缴费金额来自 `business_filter_options(module=enroll)`。
- 支持 CSV 导入和年度克隆。

## 附件处理

### 附件3全量明细

- 必须有年份和月份，按年份+身份证汇总。
- 身份证或原始身份为空会触发批次安全校验，避免错误取消。
- 同身份证多行合并身份，分别按医疗和资助配置取最小优先级。
- 与导入前基线比较：首次出现新增、身份变化/取消后重现为变更、本月缺失为取消。
- 保存导入前和导入后快照。

### 附件4

只更新当年已有身份证，写居民缴费金额、区外备注和最近月份，并重新计算。

### 附件5

按身份证汇总代缴金额，写获得资助身份、请款批次、第一批金额和最近月份，并重新计算。

### 附件6

写死亡备注，将未参保原因设为死亡、是否参保/符合/获得资助设为否，清空不适用字段。

### 人工调整回导

只更新文件实际包含的缴费时间、未参保原因、区外备注、死亡备注和人工备注，不新增人员。

## 计算规则

- 参保类别：资助参保身份+居民缴费金额+资助金额精确匹配启用的年度资助配置；不匹配为“需核实”。
- 是否参保：居民一档、居民二档、大学生一档、大学生二档、需核实均记为“是”；其他配置结果记“否”。
- 是否符合资助：缴费时间缺失/月份无法解析为待核实；纳入月份与缴费月份不同为否；同月时再匹配附件7身份+金额。
- 是否获得资助：符合资助且资助金额>0为是；是时资助方式为系统资助，否则为事后资助。

## 镇街复核

- 管理员可按镇街、勾选ID或有效筛选条件下放。
- 下放生成唯一批次号，记录写当前批次、待填报、未填报。
- 已在下放中的记录不会重复下放。
- 镇街账号只访问本镇街待填报/已填报记录。
- 镇街保存后重算参保、资助和缴费核查，批次明细同步已填报。
- 管理员可按批次或ID收回，批次状态按明细变为已下放/部分收回/已收回。

## 公开接口

- `GET /api/enroll/ledgers`、`statistics`、`options`、详情。
- `POST` 附件3/4/5/6、人工回导、export、dispatch、recall、缴费确认。
- `PUT/DELETE /api/enroll/ledgers/{id}`。
- `GET /review-batches`、`/review-batches/{id}/items`。
- `GET/POST/PUT/DELETE /api/enroll/configs`，另含 clone/import。
- `GET /api/enroll/import-batches`。

## 已知限制

- `enroll_ledgers(year,id_card)` 只有普通索引，年度人员唯一性依赖应用层。
- 主表没有软删除，删除为直接删除。
- 下放批次与明细用逻辑ID关联，没有数据库外键。
- 配置组合没有数据库唯一约束，重复配置可能导致按优先级/ID取首项。

