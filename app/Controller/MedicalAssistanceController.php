<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\DbConnection\Db;
use App\Constants\ErrorCode;
use App\Exception\BusinessException;
use App\Model\MedPersonInfo;
use App\Model\MedMedicalRecord;
use App\Model\MedReimbursementDetail;
use Psr\Log\LoggerInterface;
use Hyperf\Context\ApplicationContext;

/**
 * 救助报销控制器
 * @Controller(prefix="/api/medical-assistance")
 */
class MedicalAssistanceController extends AbstractController
{
    /**
     * @Inject
     */
    protected LoggerInterface $logger;

    public function __construct()
    {
        parent::__construct();
        
        // 确保 logger 被正确初始化
        if (!isset($this->logger)) {
            try {
                $container = ApplicationContext::getContainer();
                $this->logger = $container->get(LoggerInterface::class);
            } catch (\Exception $e) {
                // 如果无法获取 logger，创建一个简单的错误日志记录器
                $this->logger = new class implements LoggerInterface {
                    public function emergency($message, array $context = []): void { error_log("[EMERGENCY] $message"); }
                    public function alert($message, array $context = []): void { error_log("[ALERT] $message"); }
                    public function critical($message, array $context = []): void { error_log("[CRITICAL] $message"); }
                    public function error($message, array $context = []): void { error_log("[ERROR] $message"); }
                    public function warning($message, array $context = []): void { error_log("[WARNING] $message"); }
                    public function notice($message, array $context = []): void { error_log("[NOTICE] $message"); }
                    public function info($message, array $context = []): void { error_log("[INFO] $message"); }
                    public function debug($message, array $context = []): void { error_log("[DEBUG] $message"); }
                    public function log($level, $message, array $context = []): void { error_log("[$level] $message"); }
                };
            }
        }
    }

    // ==================== 患者管理相关接口 ====================

    /**
     * 获取患者列表
     * @RequestMapping(path="/patients", methods="get")
     */
    public function getPatients(RequestInterface $request)
    {
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 15);
        
        $filters = [
            'name' => $request->input('name', ''),
            'id_card' => $request->input('id_card', ''),
            'insurance_area' => $request->input('insurance_area', ''),
        ];

        $result = MedPersonInfo::search($filters, $page, $pageSize);
        return $this->success($result);
    }

    /**
     * 创建患者信息
     * @RequestMapping(path="/patients", methods="post")
     */
    public function createPatient(RequestInterface $request)
    {
        $data = $request->all();
        
        // 验证必填字段
        if (empty($data['name']) || empty($data['id_card'])) {
            return $this->error('姓名和身份证号不能为空');
        }

        // 检查身份证号是否已存在
        if (MedPersonInfo::findByIdCard($data['id_card'])) {
            return $this->error('该身份证号已存在');
        }

        $patient = MedPersonInfo::create($data);
        return $this->success($patient, '患者信息创建成功');
    }

    /**
     * 获取患者详情
     * @RequestMapping(path="/patients/{id}", methods="get")
     */
    public function getPatient(int $id)
    {
        $patient = MedPersonInfo::with(['medicalRecords', 'reimbursementDetails'])->find($id);
        
        if (!$patient) {
            return $this->error('患者信息不存在');
        }

        return $this->success($patient);
    }

    /**
     * 更新患者信息
     * @RequestMapping(path="/patients/{id}", methods="put")
     */
    public function updatePatient(RequestInterface $request, int $id)
    {
        $patient = MedPersonInfo::find($id);
        if (!$patient) {
            return $this->error('患者信息不存在');
        }

        $data = $request->all();
        
        // 如果更新身份证号，检查是否与其他患者重复
        if (isset($data['id_card']) && $data['id_card'] !== $patient->id_card) {
            if (MedPersonInfo::findByIdCard($data['id_card'])) {
                return $this->error('该身份证号已被其他患者使用');
            }
        }

        $patient->update($data);
        return $this->success($patient, '患者信息更新成功');
    }

    /**
     * 删除患者信息
     * @param int $id 患者ID
     * @param bool $cascade 是否级联删除关联数据
     * @return array
     */
    public function deletePatient(int $id, bool $cascade = false)
    {
        $patient = MedPersonInfo::find($id);
        if (!$patient) {
            return $this->error('患者信息不存在');
        }

        // 检查是否有关联的受理记录
        $hasReimbursementRecords = $patient->reimbursementDetails()->count() > 0;
        
        // 只有存在受理记录时才需要级联删除
        if ($hasReimbursementRecords && !$cascade) {
            return $this->error('该患者存在关联的受理记录，如需删除请使用级联删除选项');
        }

        try {
            Db::beginTransaction();
            
            if ($cascade) {
                // 删除关联的受理记录
                $patient->reimbursementDetails()->delete();
                
                // 删除关联的就诊记录
                $patient->medicalRecords()->delete();
            } else {
                // 即使不级联删除，也要删除就诊记录（因为就诊记录不应该独立存在）
                $patient->medicalRecords()->delete();
            }
            
            // 删除患者信息
            $patient->delete();
            
            Db::commit();
            return $this->success(null, '患者信息删除成功');
            
        } catch (\Exception $e) {
            Db::rollBack();
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    /**
     * 批量删除患者
     * @param RequestInterface $request
     * @return mixed
     */
    public function batchDeletePatients(RequestInterface $request)
    {
        try {
            $ids = $request->input('ids', []);
            $cascade = $request->input('cascade', false);

            if (empty($ids)) {
                throw new BusinessException(ErrorCode::PARAMETER_ERROR, '请选择要删除的患者');
            }

            // 开启事务
            Db::beginTransaction();
            try {
                // 检查是否有患者存在受理记录
                $patientsWithReimbursementRecords = MedPersonInfo::query()
                    ->whereIn('id', $ids)
                    ->whereHas('reimbursementDetails')
                    ->get();

                if ($patientsWithReimbursementRecords->isNotEmpty() && !$cascade) {
                    $patientNames = $patientsWithReimbursementRecords->pluck('name')->implode('、');
                    throw new BusinessException(
                        ErrorCode::PARAMETER_ERROR,
                        "患者 {$patientNames} 存在关联的受理记录，如需删除请使用级联删除选项"
                    );
                }

                if ($cascade) {
                    // 删除关联的受理记录
                    MedReimbursementDetail::whereIn('person_id', $ids)->delete();
                    
                    // 删除关联的就诊记录
                    MedMedicalRecord::whereIn('person_id', $ids)->delete();
                } else {
                    // 即使不级联删除，也要删除就诊记录（因为就诊记录不应该独立存在）
                    MedMedicalRecord::whereIn('person_id', $ids)->delete();
                }

                // 删除患者
                $deletedCount = MedPersonInfo::query()
                    ->whereIn('id', $ids)
                    ->delete();

                Db::commit();
                return $this->success(
                    ['deleted_count' => $deletedCount],
                    "成功删除 {$deletedCount} 个患者" . ($cascade ? '及其关联记录' : '')
                );
            } catch (\Exception $e) {
                Db::rollBack();
                throw $e;
            }
        } catch (BusinessException $e) {
            return $this->error($e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
            // 记录错误日志
            $this->logger->error(sprintf(
                '批量删除患者失败: %s, IDs: %s, Cascade: %s',
                $e->getMessage(),
                json_encode($ids),
                $cascade ? 'true' : 'false'
            ), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('批量删除患者失败', ErrorCode::SERVER_ERROR);
        }
    }

    /**
     * 获取所有参保地区
     * @RequestMapping(path="/patients/insurance-areas", methods="get")
     */
    public function getInsuranceAreas()
    {
        $areas = MedPersonInfo::getAllInsuranceAreas();
        return $this->success($areas);
    }

    // ==================== 就诊记录相关接口 ====================

    /**
     * 获取就诊记录列表
     * @RequestMapping(path="/medical-records", methods="get")
     */
    public function getMedicalRecords(RequestInterface $request)
    {
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 15);
        
        $filters = [
            'person_id' => $request->input('person_id', ''),
            'hospital_name' => $request->input('hospital_name', ''),
            'visit_type' => $request->input('visit_type', ''),
            'processing_status' => $request->input('processing_status', ''),
            'admission_date_start' => $request->input('admission_date_start', ''),
            'admission_date_end' => $request->input('admission_date_end', ''),
        ];

        $result = MedMedicalRecord::search($filters, $page, $pageSize);
        return $this->success($result);
    }

    /**
     * 根据身份证号获取就诊记录列表
     * @RequestMapping(path="/medical-records/by-id-card", methods="get")
     */
    public function getMedicalRecordsByIdCard(RequestInterface $request)
    {
        $idCard = $request->input('id_card', '');
        
        if (empty($idCard)) {
            return $this->error('身份证号不能为空');
        }

        // 先查找患者信息
        $patient = MedPersonInfo::findByIdCard($idCard);
        if (!$patient) {
            return $this->error('未找到该身份证号对应的患者信息');
        }

        // 获取该患者的所有就诊记录（所有状态都可以选择）
        $records = MedMedicalRecord::where('person_id', $patient->id)
            ->with('personInfo')
            ->orderBy('admission_date', 'desc')
            ->get();

        return $this->success([
            'patient' => $patient,
            'records' => $records
        ]);
    }

    /**
     * 创建就诊记录
     * @RequestMapping(path="/medical-records", methods="post")
     */
    public function createMedicalRecord(RequestInterface $request)
    {
        $data = $request->all();
        
        // 验证必填字段
        if (empty($data['person_id']) || empty($data['hospital_name']) || empty($data['visit_type'])) {
            return $this->error('患者ID、医院名称和就诊类别不能为空');
        }

        // 检查患者是否存在
        if (!MedPersonInfo::find($data['person_id'])) {
            return $this->error('患者信息不存在');
        }

        $record = MedMedicalRecord::create($data);
        return $this->success($record, '就诊记录创建成功');
    }

    /**
     * 获取就诊记录详情
     * @RequestMapping(path="/medical-records/{id}", methods="get")
     */
    public function getMedicalRecord(int $id)
    {
        $record = MedMedicalRecord::with(['personInfo', 'reimbursementDetail'])->find($id);
        
        if (!$record) {
            return $this->error('就诊记录不存在');
        }

        return $this->success($record);
    }

    /**
     * 更新就诊记录
     * @RequestMapping(path="/medical-records/{id}", methods="put")
     */
    public function updateMedicalRecord(RequestInterface $request, int $id)
    {
        $record = MedMedicalRecord::find($id);
        if (!$record) {
            return $this->error('就诊记录不存在');
        }

        $data = $request->all();
        
        // 如果更新患者ID，检查患者是否存在
        if (isset($data['person_id']) && $data['person_id'] !== $record->person_id) {
            if (!MedPersonInfo::find($data['person_id'])) {
                return $this->error('患者信息不存在');
            }
        }

        $record->update($data);
        return $this->success($record, '就诊记录更新成功');
    }

    /**
     * 删除就诊记录
     * @RequestMapping(path="/medical-records/{id}", methods="delete")
     */
    public function deleteMedicalRecord(int $id)
    {
        $record = MedMedicalRecord::find($id);
        if (!$record) {
            return $this->error('就诊记录不存在');
        }

        try {
            Db::beginTransaction();
            
            // 查找包含该就诊记录的受理记录
            $reimbursementDetails = MedReimbursementDetail::where(function ($query) use ($id) {
                $query->whereJsonContains('medical_record_ids', $id);
            })->get();

            foreach ($reimbursementDetails as $reimbursement) {
                $recordIds = is_array($reimbursement->medical_record_ids) 
                    ? $reimbursement->medical_record_ids 
                    : [$reimbursement->medical_record_ids];
                
                // 从受理记录中移除该就诊记录ID
                $updatedRecordIds = array_filter($recordIds, function($recordId) use ($id) {
                    return $recordId != $id;
                });
                
                if (empty($updatedRecordIds)) {
                    // 如果受理记录中只有这一个就诊记录，删除整个受理记录
                    $reimbursement->delete();
                } else {
                    // 否则更新受理记录，移除该就诊记录ID并重新计算金额
                    $remainingRecords = MedMedicalRecord::whereIn('id', $updatedRecordIds)->get();
                    
                    $totalAmount = $remainingRecords->sum('total_cost');
                    $policyCoveredAmount = $remainingRecords->sum('policy_covered_cost');
                    $poolReimbursementAmount = $remainingRecords->sum('pool_reimbursement_amount');
                    $largeAmountReimbursementAmount = $remainingRecords->sum('large_amount_reimbursement_amount');
                    $criticalIllnessReimbursementAmount = $remainingRecords->sum('critical_illness_reimbursement_amount');
                    
                    $reimbursement->update([
                        'medical_record_ids' => array_values($updatedRecordIds),
                        'total_amount' => $totalAmount,
                        'policy_covered_amount' => $policyCoveredAmount,
                        'pool_reimbursement_amount' => $poolReimbursementAmount,
                        'large_amount_reimbursement_amount' => $largeAmountReimbursementAmount,
                        'critical_illness_reimbursement_amount' => $criticalIllnessReimbursementAmount,
                        'pool_reimbursement_ratio' => $totalAmount > 0 ? round(($poolReimbursementAmount / $totalAmount) * 100, 2) : 0,
                        'large_amount_reimbursement_ratio' => $totalAmount > 0 ? round(($largeAmountReimbursementAmount / $totalAmount) * 100, 2) : 0,
                        'critical_illness_reimbursement_ratio' => $totalAmount > 0 ? round(($criticalIllnessReimbursementAmount / $totalAmount) * 100, 2) : 0,
                    ]);
                }
            }
            
            // 删除就诊记录
            $record->delete();
            
            Db::commit();
            return $this->success(null, '就诊记录删除成功');
            
        } catch (\Exception $e) {
            Db::rollBack();
            $this->logger->error('删除就诊记录失败: ' . $e->getMessage(), [
                'record_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    /**
     * 批量删除就诊记录
     * @RequestMapping(path="/medical-records/batch-delete", methods="post")
     */
    public function batchDeleteMedicalRecords(RequestInterface $request)
    {
        try {
            $ids = $request->input('ids', []);
            $force = $request->input('force', false); // 是否强制删除

            if (empty($ids)) {
                throw new BusinessException(ErrorCode::PARAMETER_ERROR, '请选择要删除的就诊记录');
            }

            // 开启事务
            Db::beginTransaction();
            try {
                $deletedRecords = [];
                $deletedReimbursements = [];
                $updatedReimbursements = [];
                
                foreach ($ids as $id) {
                    $record = MedMedicalRecord::find($id);
                    if (!$record) {
                        continue; // 跳过不存在的记录
                    }
                    
                    // 查找包含该就诊记录的受理记录
                    $reimbursementDetails = MedReimbursementDetail::where(function ($query) use ($id) {
                        $query->whereJsonContains('medical_record_ids', $id);
                    })->get();

                    foreach ($reimbursementDetails as $reimbursement) {
                        $recordIds = is_array($reimbursement->medical_record_ids) 
                            ? $reimbursement->medical_record_ids 
                            : [$reimbursement->medical_record_ids];
                        
                        // 从受理记录中移除该就诊记录ID
                        $updatedRecordIds = array_filter($recordIds, function($recordId) use ($id) {
                            return $recordId != $id;
                        });
                        
                        if (empty($updatedRecordIds)) {
                            // 如果受理记录中只有这一个就诊记录，删除整个受理记录
                            $deletedReimbursements[] = $reimbursement->id;
                            $reimbursement->delete();
                        } else {
                            // 否则更新受理记录，移除该就诊记录ID并重新计算金额
                            $remainingRecords = MedMedicalRecord::whereIn('id', $updatedRecordIds)->get();
                            
                            $totalAmount = $remainingRecords->sum('total_cost');
                            $policyCoveredAmount = $remainingRecords->sum('policy_covered_cost');
                            $poolReimbursementAmount = $remainingRecords->sum('pool_reimbursement_amount');
                            $largeAmountReimbursementAmount = $remainingRecords->sum('large_amount_reimbursement_amount');
                            $criticalIllnessReimbursementAmount = $remainingRecords->sum('critical_illness_reimbursement_amount');
                            
                            $reimbursement->update([
                                'medical_record_ids' => array_values($updatedRecordIds),
                                'total_amount' => $totalAmount,
                                'policy_covered_amount' => $policyCoveredAmount,
                                'pool_reimbursement_amount' => $poolReimbursementAmount,
                                'large_amount_reimbursement_amount' => $largeAmountReimbursementAmount,
                                'critical_illness_reimbursement_amount' => $criticalIllnessReimbursementAmount,
                                'pool_reimbursement_ratio' => $totalAmount > 0 ? round(($poolReimbursementAmount / $totalAmount) * 100, 2) : 0,
                                'large_amount_reimbursement_ratio' => $totalAmount > 0 ? round(($largeAmountReimbursementAmount / $totalAmount) * 100, 2) : 0,
                                'critical_illness_reimbursement_ratio' => $totalAmount > 0 ? round(($criticalIllnessReimbursementAmount / $totalAmount) * 100, 2) : 0,
                            ]);
                            
                            $updatedReimbursements[] = $reimbursement->id;
                        }
                    }
                    
                    // 删除就诊记录
                    $record->delete();
                    $deletedRecords[] = $id;
                }

                Db::commit();
                
                return $this->success([
                    'deleted_medical_records' => $deletedRecords,
                    'deleted_reimbursements' => $deletedReimbursements,
                    'updated_reimbursements' => $updatedReimbursements,
                    'deleted_count' => count($deletedRecords)
                ], "成功删除 " . count($deletedRecords) . " 条就诊记录" . 
                   (count($deletedReimbursements) > 0 ? "，同时删除了 " . count($deletedReimbursements) . " 条相关受理记录" : "") .
                   (count($updatedReimbursements) > 0 ? "，更新了 " . count($updatedReimbursements) . " 条相关受理记录" : ""));
                
            } catch (\Exception $e) {
                Db::rollBack();
                throw $e;
            }
        } catch (BusinessException $e) {
            return $this->error($e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
            // 记录错误日志
            $this->logger->error(sprintf(
                '批量删除就诊记录失败: %s, IDs: %s',
                $e->getMessage(),
                json_encode($ids ?? [])
            ), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('批量删除就诊记录失败', ErrorCode::SERVER_ERROR);
        }
    }

    /**
     * 获取所有就诊类别
     * @RequestMapping(path="/medical-records/visit-types", methods="get")
     */
    public function getVisitTypes()
    {
        $types = MedMedicalRecord::getAllVisitTypes();
        return $this->success($types);
    }

    /**
     * 获取所有医院名称
     * @RequestMapping(path="/medical-records/hospitals", methods="get")
     */
    public function getHospitals()
    {
        $hospitals = MedMedicalRecord::getAllHospitalNames();
        return $this->success($hospitals);
    }

    /**
     * 获取所有处理状态
     * @RequestMapping(path="/medical-records/processing-statuses", methods="get")
     */
    public function getProcessingStatuses()
    {
        $statuses = MedMedicalRecord::getAllProcessingStatuses();
        return $this->success($statuses);
    }

    // ==================== 报销管理相关接口 ====================

    /**
     * 获取报销明细列表
     * @RequestMapping(path="/reimbursements", methods="get")
     */
    public function getReimbursements(RequestInterface $request)
    {
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 15);
        
        $filters = [
            'person_id' => $request->input('person_id', ''),
            'medical_record_id' => $request->input('medical_record_id', ''),
            'bank_name' => $request->input('bank_name', ''),
            'reimbursement_status' => $request->input('reimbursement_status', ''),
            'account_name' => $request->input('account_name', ''),
        ];

        $result = MedReimbursementDetail::search($filters, $page, $pageSize);
        return $this->success($result);
    }

    /**
     * 创建报销明细
     * @RequestMapping(path="/reimbursements", methods="post")
     */
    public function createReimbursement(RequestInterface $request)
    {
        $data = $request->all();
        
        // 验证必填字段
        if (empty($data['person_id']) || empty($data['medical_record_ids']) || empty($data['bank_name'])) {
            return $this->error('患者ID、就诊记录ID列表和银行名称不能为空');
        }

        // 检查患者是否存在
        if (!MedPersonInfo::find($data['person_id'])) {
            return $this->error('患者信息不存在');
        }

        // 确保 medical_record_ids 是数组
        if (!is_array($data['medical_record_ids'])) {
            $data['medical_record_ids'] = [$data['medical_record_ids']];
        }

        // 检查就诊记录是否存在且状态为未报销
        $medicalRecords = MedMedicalRecord::whereIn('id', $data['medical_record_ids'])
            ->where('processing_status', '!=', 'reimbursed')
            ->get();
        if ($medicalRecords->count() !== count($data['medical_record_ids'])) {
            return $this->error('部分就诊记录不存在或已报销，无法重复报销');
        }

        // 检查是否已存在这些就诊记录的报销明细
        $existingReimbursements = MedReimbursementDetail::where(function ($query) use ($data) {
            foreach ($data['medical_record_ids'] as $recordId) {
                $query->orWhereJsonContains('medical_record_ids', $recordId);
            }
        })->get();

        if ($existingReimbursements->count() > 0) {
            return $this->error('部分就诊记录已存在报销明细');
        }

        $reimbursement = MedReimbursementDetail::create($data);
        return $this->success($reimbursement, '报销明细创建成功');
    }

    /**
     * 获取报销明细详情
     * @RequestMapping(path="/reimbursements/{id}", methods="get")
     */
    public function getReimbursement(int $id)
    {
        $reimbursement = MedReimbursementDetail::with(['personInfo'])->find($id);
        
        if (!$reimbursement) {
            return $this->error('报销明细不存在');
        }

        // 加载关联的就诊记录
        $reimbursement->medical_records = $reimbursement->getMedicalRecords();

        return $this->success($reimbursement);
    }

    /**
     * 更新报销明细
     * @RequestMapping(path="/reimbursements/{id}", methods="put")
     */
    public function updateReimbursement(RequestInterface $request, int $id)
    {
        $reimbursement = MedReimbursementDetail::find($id);
        if (!$reimbursement) {
            return $this->error('报销明细不存在');
        }

        // 检查报销明细是否已作废，已作废的记录不能编辑
        if ($reimbursement->reimbursement_status === 'void') {
            return $this->error('已作废的报销明细不能编辑');
        }

        $data = $request->all();
        
        // 如果更新患者ID，检查患者是否存在
        if (isset($data['person_id']) && $data['person_id'] !== $reimbursement->person_id) {
            if (!MedPersonInfo::find($data['person_id'])) {
                return $this->error('患者信息不存在');
            }
        }

        // 获取原来的就诊记录ID列表
        $originalRecordIds = is_array($reimbursement->medical_record_ids) 
            ? $reimbursement->medical_record_ids 
            : [$reimbursement->medical_record_ids];
        
        // 处理提交的就诊记录ID列表
        $newRecordIds = [];
        if (isset($data['medical_record_ids'])) {
            if (!is_array($data['medical_record_ids'])) {
                $newRecordIds = [$data['medical_record_ids']];
            } else {
                $newRecordIds = $data['medical_record_ids'];
            }
        }
        
        // 检查就诊记录ID是否真的发生了变化
        $recordIdsChanged = false;
        if (count($originalRecordIds) !== count($newRecordIds)) {
            $recordIdsChanged = true;
        } else {
            // 比较数组内容是否相同（忽略顺序）
            sort($originalRecordIds);
            sort($newRecordIds);
            $recordIdsChanged = ($originalRecordIds !== $newRecordIds);
        }
        
        // 如果就诊记录ID发生了变化，需要处理状态更新和金额重新计算
        if ($recordIdsChanged) {
            $medicalRecords = MedMedicalRecord::whereIn('id', $newRecordIds)->get();
            if ($medicalRecords->count() !== count($newRecordIds)) {
                return $this->error('部分就诊记录不存在');
            }

            // 检查新的就诊记录是否已存在其他报销明细（排除当前报销明细）
            $existingReimbursements = MedReimbursementDetail::where('id', '!=', $id)
                ->where(function ($query) use ($newRecordIds) {
                    foreach ($newRecordIds as $recordId) {
                        $query->orWhereJsonContains('medical_record_ids', $recordId);
                    }
                })->get();

            if ($existingReimbursements->count() > 0) {
                return $this->error('部分就诊记录已存在其他报销明细');
            }
            
            // 找出被移除的就诊记录ID
            $removedRecordIds = array_diff($originalRecordIds, $newRecordIds);
            
            // 找出新增的就诊记录ID
            $addedRecordIds = array_diff($newRecordIds, $originalRecordIds);

            // 使用数据库事务确保数据一致性
            Db::beginTransaction();
            try {
                // 将被移除的就诊记录状态改为未报销
                if (!empty($removedRecordIds)) {
                    MedMedicalRecord::whereIn('id', $removedRecordIds)->update(['processing_status' => 'unreimbursed']);
                }

                // 将新增的就诊记录状态改为已报销
                if (!empty($addedRecordIds)) {
                    MedMedicalRecord::whereIn('id', $addedRecordIds)->update(['processing_status' => 'reimbursed']);
                }

                // 重新计算报销明细的金额
                $totalAmount = $medicalRecords->sum('total_cost');
                $policyCoveredAmount = $medicalRecords->sum('policy_covered_cost');
                $poolReimbursementAmount = $medicalRecords->sum('pool_reimbursement_amount');
                $largeAmountReimbursementAmount = $medicalRecords->sum('large_amount_reimbursement_amount');
                $criticalIllnessReimbursementAmount = $medicalRecords->sum('critical_illness_reimbursement_amount');

                // 更新金额相关字段
                $data['total_amount'] = $totalAmount;
                $data['policy_covered_amount'] = $policyCoveredAmount;
                $data['pool_reimbursement_amount'] = $poolReimbursementAmount;
                $data['large_amount_reimbursement_amount'] = $largeAmountReimbursementAmount;
                $data['critical_illness_reimbursement_amount'] = $criticalIllnessReimbursementAmount;
                $data['pool_reimbursement_ratio'] = $totalAmount > 0 ? round(($poolReimbursementAmount / $totalAmount) * 100, 2) : 0;
                $data['large_amount_reimbursement_ratio'] = $totalAmount > 0 ? round(($largeAmountReimbursementAmount / $totalAmount) * 100, 2) : 0;
                $data['critical_illness_reimbursement_ratio'] = $totalAmount > 0 ? round(($criticalIllnessReimbursementAmount / $totalAmount) * 100, 2) : 0;

                $reimbursement->update($data);
                
                Db::commit();
                return $this->success($reimbursement, '报销明细更新成功');
            } catch (\Exception $e) {
                Db::rollBack();
                error_log('更新报销明细失败: ' . $e->getMessage());
                return $this->error('更新报销明细失败: ' . $e->getMessage());
            }
        } else {
            // 如果就诊记录ID没有变化，直接更新其他字段
            // 但如果状态更新为作废，需要将对应的就诊记录状态改为未报销
            if (isset($data['reimbursement_status']) && $data['reimbursement_status'] === 'void') {
                try {
                    Db::beginTransaction();
                    
                    // 将关联的就诊记录状态改为未报销
                    if (!empty($reimbursement->medical_record_ids)) {
                        $recordIds = is_array($reimbursement->medical_record_ids) 
                            ? $reimbursement->medical_record_ids 
                            : [$reimbursement->medical_record_ids];
                        MedMedicalRecord::whereIn('id', $recordIds)
                            ->update(['processing_status' => 'unreimbursed']);
                    }
                    
                    $reimbursement->update($data);
                    
                    Db::commit();
                    return $this->success($reimbursement, '报销明细更新成功');
                } catch (\Exception $e) {
                    Db::rollBack();
                    return $this->error('更新失败：' . $e->getMessage());
                }
            } else {
                $reimbursement->update($data);
                return $this->success($reimbursement, '报销明细更新成功');
            }
        }
    }

    /**
     * 删除报销明细
     * @RequestMapping(path="/reimbursements/{id}", methods="delete")
     */
    public function deleteReimbursement(int $id)
    {
        $reimbursement = MedReimbursementDetail::find($id);
        if (!$reimbursement) {
            return $this->error('报销明细不存在');
        }

        // 检查受理状态，已受理的不允许删除
        if ($reimbursement->reimbursement_status === 'processed') {
            return $this->error('已受理的报销明细不允许删除');
        }

        try {
            Db::beginTransaction();
            
            // 将关联的就诊记录状态改回未报销
            if (!empty($reimbursement->medical_record_ids)) {
                MedMedicalRecord::whereIn('id', $reimbursement->medical_record_ids)
                    ->update(['processing_status' => 'unreimbursed']);
            }
            
            // 删除报销明细
            $reimbursement->delete();
            
            Db::commit();
            return $this->success(null, '报销明细删除成功');
        } catch (\Exception $e) {
            Db::rollBack();
            return $this->error('删除失败：' . $e->getMessage());
        }
    }

    /**
     * 获取所有银行名称
     * @RequestMapping(path="/reimbursements/banks", methods="get")
     */
    public function getBanks()
    {
        $banks = MedReimbursementDetail::getAllBankNames();
        return $this->success($banks);
    }

    /**
     * 获取所有报销状态
     * @RequestMapping(path="/reimbursements/statuses", methods="get")
     */
    public function getReimbursementStatuses()
    {
        $statuses = MedReimbursementDetail::getAllReimbursementStatuses();
        return $this->success($statuses);
    }

    /**
     * 获取报销统计信息
     * @RequestMapping(path="/reimbursements/statistics", methods="get")
     */
    public function getReimbursementStatistics()
    {
        $statistics = MedReimbursementDetail::getStatistics();
        return $this->success($statistics);
    }

    /**
     * 批量创建报销明细
     * @RequestMapping(path="/reimbursements/batch-create", methods="post")
     */
    public function batchCreateReimbursements(RequestInterface $request)
    {
        try {
            // 添加调试信息
            error_log('开始处理批量创建报销明细请求');
            
            $data = $request->all();
            error_log('接收到的数据: ' . json_encode($data));
            
            // 验证必填字段
            if (empty($data['person_id']) || empty($data['medical_record_ids']) || empty($data['bank_name']) || empty($data['bank_account']) || empty($data['account_name'])) {
                return $this->error('患者ID、就诊记录ID列表、银行信息不能为空');
            }

            // 检查患者是否存在
            if (!MedPersonInfo::find($data['person_id'])) {
                return $this->error('患者信息不存在');
            }

            $medicalRecordIds = $data['medical_record_ids'];
            if (!is_array($medicalRecordIds)) {
                return $this->error('就诊记录ID列表格式错误');
            }

            // 检查就诊记录是否存在且属于该患者且状态为未报销
            $medicalRecords = MedMedicalRecord::whereIn('id', $medicalRecordIds)
                ->where('person_id', $data['person_id'])
                ->where('processing_status', '!=', 'reimbursed')
                ->get();

            if ($medicalRecords->count() !== count($medicalRecordIds)) {
                return $this->error('部分就诊记录不存在或已报销，无法重复报销');
            }

            Db::beginTransaction();
            try {
                // 计算总金额
                $totalAmount = $medicalRecords->sum('total_cost');
                $policyCoveredAmount = $medicalRecords->sum('policy_covered_cost');
                $poolReimbursementAmount = $medicalRecords->sum('pool_reimbursement_amount');
                $largeAmountReimbursementAmount = $medicalRecords->sum('large_amount_reimbursement_amount');
                $criticalIllnessReimbursementAmount = $medicalRecords->sum('critical_illness_reimbursement_amount');

                // 创建单个受理记录，包含所有就诊记录
                $reimbursementData = [
                    'person_id' => $data['person_id'],
                    'medical_record_ids' => $medicalRecordIds,
                    'bank_name' => $data['bank_name'],
                    'bank_account' => $data['bank_account'],
                    'account_name' => $data['account_name'],
                    'total_amount' => $totalAmount,
                    'policy_covered_amount' => $policyCoveredAmount,
                    'pool_reimbursement_amount' => $poolReimbursementAmount,
                    'large_amount_reimbursement_amount' => $largeAmountReimbursementAmount,
                    'critical_illness_reimbursement_amount' => $criticalIllnessReimbursementAmount,
                    'pool_reimbursement_ratio' => $totalAmount > 0 ? round(($poolReimbursementAmount / $totalAmount) * 100, 2) : 0,
                    'large_amount_reimbursement_ratio' => $totalAmount > 0 ? round(($largeAmountReimbursementAmount / $totalAmount) * 100, 2) : 0,
                    'critical_illness_reimbursement_ratio' => $totalAmount > 0 ? round(($criticalIllnessReimbursementAmount / $totalAmount) * 100, 2) : 0,
                    'reimbursement_status' => $data['reimbursement_status'] ?? 'processed', // 使用前端传递的受理状态
                ];

                $reimbursement = MedReimbursementDetail::create($reimbursementData);

                // 更新所有就诊记录状态为已报销
                MedMedicalRecord::whereIn('id', $medicalRecordIds)->update(['processing_status' => 'reimbursed']);

                Db::commit();
                return $this->success($reimbursement, '批量创建报销明细成功');
            } catch (\Exception $e) {
                Db::rollBack();
                error_log('批量创建报销明细失败: ' . $e->getMessage());
                return $this->error('批量创建报销明细失败: ' . $e->getMessage());
            }
        } catch (\Exception $e) {
            error_log('批量创建报销明细外层异常: ' . $e->getMessage());
            return $this->error('批量创建报销明细失败: ' . $e->getMessage());
        }
    }

    // ==================== 综合查询接口 ====================

    /**
     * 获取患者完整的医疗救助信息
     * @RequestMapping(path="/patients/{id}/complete-info", methods="get")
     */
    public function getPatientCompleteInfo(int $id)
    {
        $patient = MedPersonInfo::with([
            'medicalRecords' => function ($query) {
                $query->orderBy('admission_date', 'desc');
            },
            'reimbursementDetails'
        ])->find($id);

        if (!$patient) {
            return $this->error('患者信息不存在');
        }

        // 为每个受理记录加载关联的就诊记录
        $patient->reimbursementDetails->each(function ($reimbursement) {
            $reimbursement->medical_records = $reimbursement->getMedicalRecords();
        });

        return $this->success($patient);
    }

    /**
     * 批量更新就诊记录状态
     * @RequestMapping(path="/medical-records/batch-update-status", methods="post")
     */
    public function batchUpdateMedicalRecordStatus(RequestInterface $request)
    {
        $data = $request->all();
        
        if (empty($data['ids']) || !is_array($data['ids'])) {
            return $this->error('请选择要更新的就诊记录');
        }

        if (empty($data['processing_status'])) {
            return $this->error('请提供要更新的处理状态');
        }

        $validStatuses = ['unreimbursed', 'reimbursed', 'returned'];
        if (!in_array($data['processing_status'], $validStatuses)) {
            return $this->error('无效的处理状态');
        }

        $count = MedMedicalRecord::whereIn('id', $data['ids'])->update([
            'processing_status' => $data['processing_status']
        ]);

        return $this->success(['updated_count' => $count], "成功更新 {$count} 条就诊记录状态");
    }

    /**
     * 批量更新报销明细状态
     * @RequestMapping(path="/reimbursements/batch-update-status", methods="post")
     */
    public function batchUpdateReimbursementStatus(RequestInterface $request)
    {
        $data = $request->all();
        
        if (empty($data['ids']) || !is_array($data['ids'])) {
            return $this->error('请选择要更新的报销明细');
        }

        if (empty($data['reimbursement_status'])) {
            return $this->error('请提供要更新的报销状态');
        }

        $validStatuses = ['pending', 'processed', 'void'];
        if (!in_array($data['reimbursement_status'], $validStatuses)) {
            return $this->error('无效的报销状态');
        }

        try {
            Db::beginTransaction();
            
            // 如果状态更新为作废，需要将对应的就诊记录状态改为未报销
            if ($data['reimbursement_status'] === 'void') {
                $reimbursements = MedReimbursementDetail::whereIn('id', $data['ids'])->get();
                $allRecordIds = [];
                
                foreach ($reimbursements as $reimbursement) {
                    if (!empty($reimbursement->medical_record_ids)) {
                        $recordIds = is_array($reimbursement->medical_record_ids) 
                            ? $reimbursement->medical_record_ids 
                            : [$reimbursement->medical_record_ids];
                        $allRecordIds = array_merge($allRecordIds, $recordIds);
                    }
                }
                
                // 将关联的就诊记录状态改为未报销
                if (!empty($allRecordIds)) {
                    MedMedicalRecord::whereIn('id', $allRecordIds)
                        ->update(['processing_status' => 'unreimbursed']);
                }
            }

            $count = MedReimbursementDetail::whereIn('id', $data['ids'])->update([
                'reimbursement_status' => $data['reimbursement_status']
            ]);
            
            Db::commit();
            return $this->success(['updated_count' => $count], "成功更新 {$count} 条报销明细状态");
        } catch (\Exception $e) {
            Db::rollBack();
            return $this->error('更新失败：' . $e->getMessage());
        }
    }

    // ==================== Excel导入接口 ====================

    /**
     * 导入Excel文件
     * @RequestMapping(path="/import-excel", methods="post")
     */
    public function importExcel(RequestInterface $request)
    {
        try {
            // 检查是否有文件上传
            $file = $request->file('excel_file');
            if (!$file || !$file->isValid()) {
                return $this->error('请上传有效的Excel文件');
            }

            // 检查文件类型
            $allowedTypes = ['xlsx', 'xls'];
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, $allowedTypes)) {
                return $this->error('只支持.xlsx和.xls格式的Excel文件');
            }

            // 检查文件大小（限制为10MB）
            if ($file->getSize() > 10 * 1024 * 1024) {
                return $this->error('文件大小不能超过10MB');
            }

            // 使用事务确保数据一致性
            Db::beginTransaction();
            
            try {
                $importResult = $this->processExcelFile($file);
                Db::commit();
                
                return $this->success($importResult, 'Excel文件导入成功');
            } catch (\Exception $e) {
                Db::rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            return $this->error('导入失败：' . $e->getMessage());
        }
    }

    /**
     * 处理Excel文件
     */
    private function processExcelFile($file)
    {
        $result = [
            'patients' => ['imported' => 0, 'skipped' => 0, 'errors' => []],
            'medical_records' => ['imported' => 0, 'skipped' => 0, 'errors' => []],
            'summary' => []
        ];

        // 读取Excel文件
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getPathname());
        
        // 处理第一个工作表（包含患者信息和就诊记录）
        $this->processMainSheet($spreadsheet->getSheet(0), $result);

        // 生成汇总信息
        $result['summary'] = [
            'total_patients' => $result['patients']['imported'] + $result['patients']['skipped'],
            'total_medical_records' => $result['medical_records']['imported'] + $result['medical_records']['skipped'],
            'success_rate' => $this->calculateSuccessRate($result)
        ];

        return $result;
    }

    /**
     * 处理主工作表（包含患者信息和就诊记录）
     */
    private function processMainSheet($sheet, &$result)
    {
        $rows = $sheet->toArray();
        $headers = array_shift($rows); // 移除标题行
        
        foreach ($rows as $rowIndex => $row) {
            try {
                // 跳过空行
                if (empty(array_filter($row))) {
                    continue;
                }

                // 提取患者信息
                $patientData = $this->extractPatientData($row, $headers);
                
                // 检查身份证号是否已存在，如果不存在则创建患者
                $existingPatient = MedPersonInfo::findByIdCard($patientData['id_card']);
                if (!$existingPatient) {
                    $patient = MedPersonInfo::create($patientData);
                    $result['patients']['imported']++;
                } else {
                    $patient = $existingPatient;
                    $result['patients']['skipped']++;
                }

                // 提取就诊记录信息
                $recordData = $this->extractMedicalRecordData($row, $headers);
                
                // 设置患者ID
                $recordData['person_id'] = $patient->id;
                
                // 创建就诊记录
                MedMedicalRecord::create($recordData);
                $result['medical_records']['imported']++;
                
            } catch (\Exception $e) {
                $result['patients']['errors'][] = "第{$rowIndex}行：" . $e->getMessage();
            }
        }
    }



    /**
     * 提取患者数据
     */
    private function extractPatientData($row, $headers)
    {
        // 根据Excel列标题映射字段（支持多种可能的标题名称）
        $headerMappings = [
            'name' => ['姓名', '患者姓名', '姓名（必填）'],
            'id_card' => ['身份证号', '身份证号码', '身份证号（必填）'],
            'insurance_area' => ['参保地', '参保地区', '参保所属地区']
        ];

        $data = [];
        foreach ($headers as $index => $header) {
            if (!isset($header) || !is_string($header)) {
                continue;
            }
            $header = trim($header);
            foreach ($headerMappings as $field => $possibleHeaders) {
                if (in_array($header, $possibleHeaders)) {
                    $value = isset($row[$index]) ? (string)$row[$index] : '';
                    $data[$field] = trim($value);
                    break;
                }
            }
        }

        // 验证必填字段
        $requiredFields = ['name', 'id_card'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("必填字段 {$field} 不能为空");
            }
        }

        // 记录详细的导入信息
        $this->logImportInfo('Patient Headers', $headers);
        $this->logImportInfo('Patient Row Data', $row);
        $this->logImportInfo('Extracted Patient Data', $data);

        return $data;
    }

    /**
     * 提取就诊记录数据
     */
    private function extractMedicalRecordData($row, $headers)
    {
        // 支持多种可能的标题名称
        $headerMappings = [
            'hospital_name' => ['就诊医疗机构名称', '医疗机构名称', '医院名称'],
            'visit_type' => ['医保就诊类别', '就诊类别', '医保类别'],
            'admission_date' => ['入院时间', '入院日期'],
            'discharge_date' => ['出院时间', '出院日期'],
            'settlement_date' => ['结算时间', '结算日期'],
            'total_cost' => ['总费用', '医疗总费用'],
            'policy_covered_cost' => ['医保政策范围内费用', '政策范围内费用'],
            'pool_reimbursement_amount' => ['统筹报销金额', '统筹基金支付'],
            'large_amount_reimbursement_amount' => ['大额报销金额', '大额医疗费用补助'],
            'critical_illness_reimbursement_amount' => ['大病报销金额', '大病保险支付'],
            'medical_assistance_amount' => ['医疗救助金额', '医疗救助支付'],
            'excess_reimbursement_amount' => ['渝快保报销金额', '渝快保支付']
        ];

        $data = [];
        foreach ($headers as $index => $header) {
            if (!isset($header) || !is_string($header)) {
                continue;
            }
            $header = trim($header);
            foreach ($headerMappings as $field => $possibleHeaders) {
                if (in_array($header, $possibleHeaders)) {
                    $value = isset($row[$index]) ? (string)$row[$index] : '';
                    $value = trim($value);
                    
                    if (!empty($value)) {
                        // 处理特殊字段
                        if (in_array($field, ['admission_date', 'discharge_date', 'settlement_date'])) {
                            try {
                                $data[$field] = $this->parseDate($value);
                            } catch (\Exception $e) {
                                $this->logImportInfo('Date Parse Error', [
                                    'field' => $field,
                                    'value' => $value,
                                    'error' => $e->getMessage()
                                ]);
                                $data[$field] = null;
                            }
                        } elseif (in_array($field, ['total_cost', 'policy_covered_cost', 'pool_reimbursement_amount', 
                                                   'large_amount_reimbursement_amount', 'critical_illness_reimbursement_amount', 
                                                   'medical_assistance_amount', 'excess_reimbursement_amount'])) {
                            try {
                                $data[$field] = $this->parseAmount($value);
                            } catch (\Exception $e) {
                                $this->logImportInfo('Amount Parse Error', [
                                    'field' => $field,
                                    'value' => $value,
                                    'error' => $e->getMessage()
                                ]);
                                $data[$field] = 0;
                            }
                        } else {
                            $data[$field] = $value;
                        }
                    } else {
                        // 设置空值的默认值
                        if (in_array($field, ['total_cost', 'policy_covered_cost', 'pool_reimbursement_amount', 
                                            'large_amount_reimbursement_amount', 'critical_illness_reimbursement_amount', 
                                            'medical_assistance_amount', 'excess_reimbursement_amount'])) {
                            $data[$field] = 0;
                        } else {
                            $data[$field] = null;
                        }
                    }
                    break;
                }
            }
        }

        // 验证必填字段
        $requiredFields = ['hospital_name', 'visit_type'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("必填字段 {$field} 不能为空");
            }
        }

        // 记录详细的导入信息
        $this->logImportInfo('Medical Record Headers', $headers);
        $this->logImportInfo('Medical Record Row Data', $row);
        $this->logImportInfo('Extracted Medical Record Data', $data);

        // 设置默认值
        $data['processing_status'] = 'unreimbursed';

        return $data;
    }

    /**
     * 解析日期字符串
     */
    private function parseDate($value)
    {
        if (empty($value)) {
            return null;
        }

        // 尝试多种日期格式
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d',
            'Y/m/d',
            'Y年m月d日',
            'd/m/Y',
            'm/d/Y'
        ];

        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        throw new \InvalidArgumentException("无效的日期格式: {$value}");
    }

    /**
     * 解析金额字符串
     */
    private function parseAmount($value)
    {
        if (empty($value)) {
            return 0;
        }

        // 移除所有非数字字符（保留小数点和负号）
        $value = preg_replace('/[^-0-9.]/', '', $value);
        
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException("无效的金额格式: {$value}");
        }

        return round((float)$value, 2);
    }

    /**
     * 计算成功率
     */
    private function calculateSuccessRate($result)
    {
        $totalPatients = $result['patients']['imported'] + $result['patients']['skipped'];
        $totalMedicalRecords = $result['medical_records']['imported'] + $result['medical_records']['skipped'];

        $totalRecords = $totalPatients + $totalMedicalRecords;
        if ($totalRecords === 0) {
            return 0;
        }

        $successfulRecords = $result['patients']['imported'] + $result['medical_records']['imported'];
        
        return round(($successfulRecords / $totalRecords) * 100, 2);
    }

    /**
     * 记录导入信息
     */
    private function logImportInfo($title, $data)
    {
        $logData = [
            'title' => $title,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // 使用 Hyperf 的日志系统记录信息
        $logger = \Hyperf\Context\ApplicationContext::getContainer()
            ->get(\Psr\Log\LoggerInterface::class);
        $logger->info('Excel Import: ' . $title, $logData);
    }

    /**
     * 导出受理台账
     * @RequestMapping(path="/reimbursements/export-ledger", methods="get")
     */
    public function exportReimbursementLedger(RequestInterface $request, ResponseInterface $response)
    {
        try {
            // 获取筛选条件
            $filters = [
                'person_id' => $request->input('person_id', ''),
                'medical_record_id' => $request->input('medical_record_id', ''),
                'bank_name' => $request->input('bank_name', ''),
                'reimbursement_status' => $request->input('reimbursement_status', ''),
                'account_name' => $request->input('account_name', ''),
            ];

            // 构建查询条件
            $query = MedReimbursementDetail::with(['personInfo']);

            if (!empty($filters['person_id'])) {
                $query->where('person_id', $filters['person_id']);
            }

            if (!empty($filters['medical_record_id'])) {
                $query->whereJsonContains('medical_record_ids', $filters['medical_record_id']);
            }

            if (!empty($filters['medical_record_ids'])) {
                $query->whereJsonContains('medical_record_ids', $filters['medical_record_ids']);
            }

            if (!empty($filters['bank_name'])) {
                $query->where('bank_name', 'like', "%{$filters['bank_name']}%");
            }

            if (!empty($filters['reimbursement_status'])) {
                $query->where('reimbursement_status', $filters['reimbursement_status']);
            }

            if (!empty($filters['account_name'])) {
                $query->where('account_name', 'like', "%{$filters['account_name']}%");
            }

            // 获取筛选后的受理记录
            $reimbursements = $query->orderBy('created_at', 'desc')->get();

            // 创建Excel文件
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            
            // 创建第一个工作表：受理记录
            $sheet1 = $spreadsheet->getActiveSheet();
            $sheet1->setTitle('受理记录');
            
            // 设置受理记录表头
            $headers1 = [
                'A1' => '受理记录ID',
                'B1' => '患者姓名',
                'C1' => '身份证号',
                'D1' => '银行名称',
                'E1' => '银行账号',
                'F1' => '户名',
                'G1' => '总金额',
                'H1' => '政策内金额',
                'I1' => '统筹报销金额',
                'J1' => '大额报销金额',
                'K1' => '重疾报销金额',
                'L1' => '统筹报销比例(%)',
                'M1' => '大额报销比例(%)',
                'N1' => '重疾报销比例(%)',
                'O1' => '受理状态',
                'P1' => '创建时间',
                'Q1' => '更新时间'
            ];
            
            foreach ($headers1 as $cell => $value) {
                $sheet1->setCellValue($cell, $value);
            }
            
            // 填充受理记录数据
            $row1 = 2;
            foreach ($reimbursements as $reimbursement) {
                $patient = $reimbursement->personInfo;
                $sheet1->setCellValue('A' . $row1, $reimbursement->id);
                $sheet1->setCellValue('B' . $row1, $patient ? $patient->name : '');
                $sheet1->setCellValueExplicit('C' . $row1, $patient ? $patient->id_card : '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet1->setCellValue('D' . $row1, $reimbursement->bank_name);
                $sheet1->setCellValueExplicit('E' . $row1, $reimbursement->bank_account, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                $sheet1->setCellValue('F' . $row1, $reimbursement->account_name);
                $sheet1->setCellValue('G' . $row1, $reimbursement->total_amount);
                $sheet1->setCellValue('H' . $row1, $reimbursement->policy_covered_amount);
                $sheet1->setCellValue('I' . $row1, $reimbursement->pool_reimbursement_amount);
                $sheet1->setCellValue('J' . $row1, $reimbursement->large_amount_reimbursement_amount);
                $sheet1->setCellValue('K' . $row1, $reimbursement->critical_illness_reimbursement_amount);
                $sheet1->setCellValue('L' . $row1, $reimbursement->pool_reimbursement_ratio);
                $sheet1->setCellValue('M' . $row1, $reimbursement->large_amount_reimbursement_ratio);
                $sheet1->setCellValue('N' . $row1, $reimbursement->critical_illness_reimbursement_ratio);
                $sheet1->setCellValue('O' . $row1, $this->getStatusText($reimbursement->reimbursement_status));
                $sheet1->setCellValue('P' . $row1, $reimbursement->created_at ? $reimbursement->created_at->format('Y-m-d H:i:s') : '');
                $sheet1->setCellValue('Q' . $row1, $reimbursement->updated_at ? $reimbursement->updated_at->format('Y-m-d H:i:s') : '');
                $row1++;
            }
            
            // 设置受理记录工作表样式
            $this->setExcelStyles($sheet1, $row1 - 1);
            
            // 创建第二个工作表：受理明细
            $sheet2 = $spreadsheet->createSheet();
            $sheet2->setTitle('受理明细');
            
            // 设置受理明细表头
            $headers2 = [
                'A1' => '受理记录ID',
                'B1' => '就诊记录ID',
                'C1' => '患者姓名',
                'D1' => '身份证号',
                'E1' => '医院名称',
                'F1' => '就诊类别',
                'G1' => '入院时间',
                'H1' => '出院时间',
                'I1' => '结算时间',
                'J1' => '总费用',
                'K1' => '政策内费用',
                'L1' => '统筹报销金额',
                'M1' => '大额报销金额',
                'N1' => '重疾报销金额',
                'O1' => '医疗救助金额',
                'P1' => '渝快保报销金额',
                'Q1' => '处理状态',
                'R1' => '创建时间'
            ];
            
            foreach ($headers2 as $cell => $value) {
                $sheet2->setCellValue($cell, $value);
            }
            
            // 填充受理明细数据
            $row2 = 2;
            foreach ($reimbursements as $reimbursement) {
                $patient = $reimbursement->personInfo;
                $medicalRecords = $reimbursement->getMedicalRecords();
                
                if ($medicalRecords->isEmpty()) {
                    // 如果没有就诊记录，仍然显示受理记录信息
                    $sheet2->setCellValue('A' . $row2, $reimbursement->id);
                    $sheet2->setCellValue('B' . $row2, '');
                    $sheet2->setCellValue('C' . $row2, $patient ? $patient->name : '');
                    $sheet2->setCellValueExplicit('D' . $row2, $patient ? $patient->id_card : '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet2->setCellValue('E' . $row2, '');
                    $sheet2->setCellValue('F' . $row2, '');
                    $sheet2->setCellValue('G' . $row2, '');
                    $sheet2->setCellValue('H' . $row2, '');
                    $sheet2->setCellValue('I' . $row2, '');
                    $sheet2->setCellValue('J' . $row2, '');
                    $sheet2->setCellValue('K' . $row2, '');
                    $sheet2->setCellValue('L' . $row2, '');
                    $sheet2->setCellValue('M' . $row2, '');
                    $sheet2->setCellValue('N' . $row2, '');
                    $sheet2->setCellValue('O' . $row2, '');
                    $sheet2->setCellValue('P' . $row2, '');
                    $sheet2->setCellValue('Q' . $row2, '');
                    $sheet2->setCellValue('R' . $row2, '');
                    $row2++;
                } else {
                    // 为每个就诊记录创建一行
                    foreach ($medicalRecords as $record) {
                        $sheet2->setCellValue('A' . $row2, $reimbursement->id);
                        $sheet2->setCellValue('B' . $row2, $record->id);
                        $sheet2->setCellValue('C' . $row2, $patient ? $patient->name : '');
                        $sheet2->setCellValueExplicit('D' . $row2, $patient ? $patient->id_card : '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        $sheet2->setCellValue('E' . $row2, $record->hospital_name);
                        $sheet2->setCellValue('F' . $row2, $record->visit_type);
                        $sheet2->setCellValue('G' . $row2, $record->admission_date ? $record->admission_date->format('Y-m-d') : '');
                        $sheet2->setCellValue('H' . $row2, $record->discharge_date ? $record->discharge_date->format('Y-m-d') : '');
                        $sheet2->setCellValue('I' . $row2, $record->settlement_date ? $record->settlement_date->format('Y-m-d') : '');
                        $sheet2->setCellValue('J' . $row2, $record->total_cost);
                        $sheet2->setCellValue('K' . $row2, $record->policy_covered_cost);
                        $sheet2->setCellValue('L' . $row2, $record->pool_reimbursement_amount);
                        $sheet2->setCellValue('M' . $row2, $record->large_amount_reimbursement_amount);
                        $sheet2->setCellValue('N' . $row2, $record->critical_illness_reimbursement_amount);
                        $sheet2->setCellValue('O' . $row2, $record->medical_assistance_amount);
                        $sheet2->setCellValue('P' . $row2, $record->excess_reimbursement_amount);
                        $sheet2->setCellValue('Q' . $row2, $this->getProcessingStatusText($record->processing_status));
                        $sheet2->setCellValue('R' . $row2, $record->created_at ? $record->created_at->format('Y-m-d H:i:s') : '');
                        $row2++;
                    }
                }
            }
            
            // 设置受理明细工作表样式
            $this->setExcelStyles($sheet2, $row2 - 1);
            
            // 输出文件
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $filename = '受理台账_' . date('YmdHis') . '.xlsx';
            
            // 输出到临时文件
            $tempFile = tempnam(sys_get_temp_dir(), 'reimbursement_ledger_');
            $writer->save($tempFile);
            
            $content = file_get_contents($tempFile);
            unlink($tempFile);
            
            return $response->json([
                'code' => 0,
                'message' => '受理台账导出成功',
                'data' => [
                    'filename' => $filename,
                    'content' => base64_encode($content),
                    'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ]
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('导出受理台账失败: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->error('导出受理台账失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取受理状态文本
     */
    private function getStatusText($status)
    {
        $statusMap = [
            'pending' => '未申请',
            'processed' => '已受理',
            'void' => '作废',
        ];
        return $statusMap[$status] ?? $status;
    }

    /**
     * 获取处理状态文本
     */
    private function getProcessingStatusText($status)
    {
        $statusMap = [
            'unreimbursed' => '未报销',
            'reimbursed' => '已报销',
            'returned' => '已退回',
        ];
        return $statusMap[$status] ?? $status;
    }

    /**
     * 设置Excel样式
     */
    private function setExcelStyles($sheet, $lastRow)
    {
        // 设置表头样式
        $headerRange = 'A1:' . $sheet->getHighestColumn() . '1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFE6E6FA');
        
        // 设置边框
        $allRange = 'A1:' . $sheet->getHighestColumn() . $lastRow;
        $sheet->getStyle($allRange)->getBorders()->getAllBorders()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        
        // 自动调整列宽
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
} 