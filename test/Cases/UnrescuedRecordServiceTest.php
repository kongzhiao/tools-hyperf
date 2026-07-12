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

    public function testPriorityRuleMatchesConfiguredDiseaseCodeExactly(): void
    {
        $service = new UnrescuedRecordService();
        $rule = $service->defaultWashRules()[0];
        $record = (object) [
            'medical_category' => '门诊慢特病',
            'disease_code' => ' m00500 ',
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
        ];

        $this->assertTrue($service->matchesPriorityWashRule($record, $rule, ['DB001']));
        $this->assertFalse($service->matchesPriorityWashRule($record, $rule, []));

        $record->medical_category = '普通门诊';
        $this->assertFalse($service->matchesPriorityWashRule($record, $rule, ['DB001']));
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
