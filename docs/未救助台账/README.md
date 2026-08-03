# 未救助台账

## 功能目标

将未救助和应补应退 CSV 按清算期导入，完成金额计算、救助对象匹配、规则筛查和排查导出；最终需联系群众的数据通过独立下放通知表完成镇街下放、接收、通知、反馈和报销标记。

## 业务节点

1. `unrescued_records`：附件1未救助明细，附件2补齐对象信息。
2. `unrescued_refund_records`：附件4应补应退明细，附件2独立补齐对象信息。
3. `unrescued_notice_records`：线下核对后的通知明细和完整通知状态机。
4. `unrescued_disease_configs`：重大疾病编码。
5. 筛查配置和日志：`unrescued_wash_configs`、`unrescued_wash_logs`（内部表名保留 wash 以兼容存量数据）。

## 代码位置

- 路由：`config/routes.php` 的 `/api/unrescued`。
- 控制器：`app/Controller/Unrescued/`。
- 服务：`app/Service/Unrescued/UnrescuedRecordService.php`。
- 模型：`app/Model/Unrescued/`。
- Job：`app/Job/Unrescued/`。
- 迁移：根 `migrations/2026_05_24_*`、`2026_06_25_*`、`2026_06_30_*`。

## 导入与匹配

- 清算期经 `normalizePeriod()` 规范为 `YYYYMM`。
- 附件1：缺序号跳过；按清算期+序号更新或新增。
- 附件4：缺序号时使用数据行号；按清算期+序号更新或新增。
- 附件2：按清算期+身份证号更新同一人员的全部就医记录，写镇街、村社、优先身份和已匹配。
- 通知明细：按清算期+序号新增或更新，初始待下放、未报销。
- 所有导入只接受 CSV，编码由 `CsvReaderService` 自动识别。

## 金额与状态

未救助：

```text
进入报销金额 = policy_fee - pool_fund_pay - large_amount_pay - serious_illness_pay
```

应补应退/通知参考金额：

```text
进入报销金额 = policy_fee - pool_fund_pay - large_amount_pay - serious_illness_pay
               - medical_assistance_pay - yukuaibao_pay
```

状态分档：`<=0 无救助金额`、`0~300 拟通知1`、`>300 拟通知2`。

## 筛查

- 配置按 JSON 保存，执行前至少一条规则启用。
- 未救助明细与应补应退明细使用不同 `rule_name` 的独立配置，不能互相复用或覆盖。
- 第一项固定为 `outpatient_major_disease`，但仅进入报销金额 `>300` 的记录参与匹配；医疗类别需命中配置，病种编码需精确命中指定编码或 `status=1` 的重大疾病编码库。
- 状态始终由进入报销金额分档决定，重大疾病规则不得覆盖金额状态。第一项默认 `action=keep`，命中后未剔除并跳过后续规则；改为 `exclude` 时已剔除并同样跳过后续规则。
- 金额 `<=300` 或未命中第一项时继续执行通用规则；重复筛查会按最新规则刷新旧结果。
- 通用规则包括医疗类别保留、机构关键词、三类报销等于政策费用、救助额度已满和身份剔除。
- 应补应退额外启用总费用正负成对抵消、医疗救助金额大于0。
- 筛查不删除记录；结果写 `status`、`exclude_status`、`exclude_rule_code`、`remark` 并记录汇总日志。

## 公开接口

### 未救助 `/api/unrescued/records`

GET列表/统计/筛查配置/筛查选项/筛查状态/详情；POST附件1、附件2、保存规则、执行筛查、导出。兼容原因接口路径仍使用 `/wash-*`。

### 应补应退 `/api/unrescued/refund-records`

GET列表/统计/筛查配置/筛查状态；POST附件4明细、附件2对象、保存规则、执行筛查、导出。兼容原因接口路径仍使用 `/wash-*`。

### 通知 `/api/unrescued/notice-records`

GET列表/统计/接收状态；POST导入、下放、撤销下放、接收、通知、撤销通知、反馈、管理员备注、报销标记、导出。

### 重大疾病 `/api/unrescued/disease-configs`

GET列表；POST新增/导入；PUT编辑；DELETE删除。

控制器中未注册到路由的旧方法不属于当前公开接口。

## 数据范围

- 管理员可按权限访问全局数据。
- 镇街账号通过当前用户 `town_id` 强制限制；通知列表只允许本镇街业务状态数据。
- 镇街无绑定时不能接收或处理其他镇街数据。

## 已知限制

- 清算期+序号仅有普通索引，没有唯一约束，应用层负责先查后写。
- 身份证、电话和银行账号当前为普通字符串字段，数据库未做字段级加密。
- 筛查金额比较在 BCMath 缺失时回退 PHP float，精度风险需在环境部署时关注。
