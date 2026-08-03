<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Service\Unrescued\UnrescuedRecordService;
use PHPUnit\Framework\TestCase;

class UnrescuedRecordServiceTest extends TestCase
{
    public function testPriorityRuleIsFirstForBothLedgers(): void
    {
        $service = new UnrescuedRecordService();

        $unrescuedRule = $service->defaultWashRules()[0];
        $refundRule = $service->refundWashRules()[0];

        $this->assertSame(UnrescuedRecordService::PRIORITY_WASH_RULE_CODE, $unrescuedRule['code']);
        $this->assertSame(UnrescuedRecordService::PRIORITY_WASH_RULE_CODE, $refundRule['code']);
        $this->assertSame('keep', $unrescuedRule['action']);
        $this->assertTrue($unrescuedRule['enabled']);
        $this->assertSame(['门诊慢特病', '造口袋门诊'], $unrescuedRule['medical_categories']);
        $this->assertSame(['M00500'], $unrescuedRule['disease_codes']);
    }

    public function testPriorityRuleNormalizesLegacyCopy(): void
    {
        $service = new UnrescuedRecordService();
        $rule = $service->defaultWashRules()[0];
        $rule['remark'] = '门诊重大疾病匹配，标记为拟通知2';
        $rule['condition_text'] = '医疗类别命中配置，且病种编码命中指定编码或已启用的重大疾病编码库';

        $normalized = $service->normalizeWashRules([$rule]);

        $this->assertSame('门诊重大疾病匹配', $normalized[0]['remark']);
        $this->assertSame('进入报销金额 > 300，且医疗类别和病种编码命中配置', $normalized[0]['condition_text']);
    }

    public function testPriorityRuleMatchesConfiguredDiseaseCodeExactly(): void
    {
        $service = new UnrescuedRecordService();
        $rule = $service->defaultWashRules()[0];
        $record = (object) [
            'medical_category' => '门诊慢特病',
            'disease_code' => ' m00500 ',
            'calc_reimbursement_amount' => '300.01',
        ];

        $this->assertTrue($service->matchesPriorityWashRule($record, $rule, []));

        $record->disease_code = 'M005001';
        $this->assertFalse($service->matchesPriorityWashRule($record, $rule, []));
    }

    public function testPriorityRuleMatchesOnlyEnabledLibraryCodesPassedByCaller(): void
    {
        $service = new UnrescuedRecordService();
        $rule = $service->defaultWashRules()[0];
        $record = (object) [
            'medical_category' => '造口袋门诊',
            'disease_code' => 'db001',
            'calc_reimbursement_amount' => '300.01',
        ];

        $this->assertTrue($service->matchesPriorityWashRule($record, $rule, ['DB001']));
        $this->assertFalse($service->matchesPriorityWashRule($record, $rule, []));

        $record->medical_category = '普通门诊';
        $this->assertFalse($service->matchesPriorityWashRule($record, $rule, ['DB001']));
    }

    public function testPriorityRuleRequiresReimbursementAmountAboveThreeHundred(): void
    {
        $service = new UnrescuedRecordService();
        $rule = $service->defaultWashRules()[0];
        $record = (object) [
            'medical_category' => '门诊慢特病',
            'disease_code' => 'M00500',
            'calc_reimbursement_amount' => '0.00',
        ];

        foreach (['-9.00', '0.00', '0.01', '300.00'] as $amount) {
            $record->calc_reimbursement_amount = $amount;
            $this->assertFalse($service->matchesPriorityWashRule($record, $rule, []), '金额 ' . $amount . ' 不应参与重大疾病匹配');
        }

        $record->calc_reimbursement_amount = '300.01';
        $this->assertTrue($service->matchesPriorityWashRule($record, $rule, []));
    }

    public function testDecideStatusUsesExactAmountBoundaries(): void
    {
        $service = new UnrescuedRecordService();

        $this->assertSame(UnrescuedRecordService::STATUS_NO_AMOUNT, $service->decideStatus('-0.01'));
        $this->assertSame(UnrescuedRecordService::STATUS_NO_AMOUNT, $service->decideStatus('0.00'));
        $this->assertSame(UnrescuedRecordService::STATUS_NOTICE_1, $service->decideStatus('0.01'));
        $this->assertSame(UnrescuedRecordService::STATUS_NOTICE_1, $service->decideStatus('300.00'));
        $this->assertSame(UnrescuedRecordService::STATUS_NOTICE_2, $service->decideStatus('300.01'));
    }

    public function testScreeningStatusRestoresAmountStatusButKeepsWorkflowStatus(): void
    {
        $service = new UnrescuedRecordService();

        $this->assertSame(
            UnrescuedRecordService::STATUS_NOTICE_1,
            $service->screeningStatus((object) ['status' => UnrescuedRecordService::STATUS_NOTICE_2, 'calc_reimbursement_amount' => '300.00'])
        );
        $this->assertSame(
            UnrescuedRecordService::STATUS_NOTIFIED,
            $service->screeningStatus((object) ['status' => UnrescuedRecordService::STATUS_NOTIFIED, 'calc_reimbursement_amount' => '0.00'])
        );
    }
}
