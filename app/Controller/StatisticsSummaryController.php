<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\BusinessException;
use App\Model\Project;
use App\Model\StatisticsData;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

class StatisticsSummaryController extends AbstractController
{
    /**
     * 获取项目列表
     */
    public function getProjects(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $projects = Project::orderBy('created_at', 'desc')->get();
            
            return $response->json([
                'code' => 200,
                'message' => '获取成功',
                'data' => $projects
            ]);
        } catch (\Exception $e) {
            throw new BusinessException(500, '获取项目列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 创建项目
     */
    public function createProject(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $data = $request->all();
            
            // 简单验证
            if (empty($data['code'])) {
                throw new BusinessException(400, '项目代码不能为空');
            }
            
            if (empty($data['dec'])) {
                throw new BusinessException(400, '项目备注名称不能为空');
            }

            // 检查项目代码是否已存在
            $existingProject = Project::where('code', $data['code'])->first();
            if ($existingProject) {
                throw new BusinessException(400, '项目代码已存在');
            }

            // 创建项目
            $project = Project::create([
                'code' => $data['code'],
                'dec' => $data['dec']
            ]);

            return $response->json([
                'code' => 200,
                'message' => '项目创建成功',
                'data' => $project
            ]);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '创建项目失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新项目
     */
    public function updateProject(RequestInterface $request, ResponseInterface $response, int $id)
    {
        try {
            $data = $request->all();
            
            // 简单验证
            if (empty($data['code'])) {
                throw new BusinessException(400, '项目代码不能为空');
            }
            
            if (empty($data['dec'])) {
                throw new BusinessException(400, '项目备注名称不能为空');
            }

            $project = Project::find($id);
            if (!$project) {
                throw new BusinessException(404, '项目不存在');
            }

            // 检查项目代码是否已存在（排除当前项目）
            $existingProject = Project::where('code', $data['code'])
                ->where('id', '!=', $id)
                ->first();
            if ($existingProject) {
                throw new BusinessException(400, '项目代码已存在');
            }

            // 更新项目
            $project->update([
                'code' => $data['code'],
                'dec' => $data['dec']
            ]);

            return $response->json([
                'code' => 200,
                'message' => '项目更新成功',
                'data' => $project
            ]);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '更新项目失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除项目
     */
    public function deleteProject(RequestInterface $request, ResponseInterface $response, int $id)
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                throw new BusinessException(404, '项目不存在');
            }

            // 先删除该项目的所有统计数据
            $deletedCount = StatisticsData::where('project_id', $id)->delete();

            // 然后删除项目
            $project->delete();

            return $response->json([
                'code' => 200,
                'message' => '项目删除成功',
                'data' => [
                    'deleted_statistics_count' => $deletedCount
                ]
            ]);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '删除项目失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取统计数据列表
     */
    public function getStatisticsList(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $params = $request->all();
            $page = (int)($params['page'] ?? 1);
            $pageSize = (int)($params['pageSize'] ?? 10);
            
            $query = StatisticsData::with('project');
            
            // 添加筛选条件
            if (!empty($params['project_id'])) {
                $query->where('project_id', $params['project_id']);
            } elseif (!empty($params['project_code'])) {
                $query->whereHas('project', function ($q) use ($params) {
                    $q->where('code', $params['project_code']);
                });
            }

            if (!empty($params['street_town'])) {
                $query->where('street_town', 'like', '%' . $params['street_town'] . '%');
            }
            
            if (!empty($params['data_type'])) {
                $query->where('import_type', $params['data_type']);
            }
            
            if (!empty($params['name'])) {
                $query->where('name', 'like', '%' . $params['name'] . '%');
            }
            
            if (!empty($params['id_number'])) {
                $query->where('id_number', 'like', '%' . $params['id_number'] . '%');
            }
            
            $total = $query->count();
            $data = $query->offset(($page - 1) * $pageSize)
                         ->limit($pageSize)
                         ->orderBy('created_at', 'desc')
                         ->get();
            
            return $response->json([
                'code' => 200,
                'message' => '获取成功',
                'data' => [
                    'data' => $data,
                    'total' => $total,
                    'per_page' => $pageSize,
                    'current_page' => $page
                ]
            ]);
        } catch (\Exception $e) {
            throw new BusinessException(500, '获取统计数据列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 导入统计数据
     */
    public function importStatistics(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $data = $request->all();
            
            // 验证项目ID
            if (empty($data['project_id']) || !is_numeric($data['project_id'])) {
                throw new BusinessException(400, '项目ID不能为空且必须是数字');
            }
            
            // 验证导入类型
            if (empty($data['import_type'])) {
                throw new BusinessException(400, '导入类型不能为空');
            }
            
            // 验证文件 - 使用 Hyperf 的文件上传方式
            $uploadedFiles = $request->getUploadedFiles();
            if (empty($uploadedFiles) || !isset($uploadedFiles['file'])) {
                throw new BusinessException(400, '请上传文件');
            }
            
            $file = $uploadedFiles['file'];
            if ($file->getError() !== UPLOAD_ERR_OK) {
                throw new BusinessException(400, '文件上传失败: ' . $file->getError());
            }
            
            // 验证文件类型
            $allowedTypes = [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
                'application/octet-stream' // 某些情况下 Excel 文件可能被识别为此类型
            ];
            
            $fileType = $file->getClientMediaType();
            if (!in_array($fileType, $allowedTypes)) {
                throw new BusinessException(400, '只支持 Excel 文件格式，当前文件类型: ' . $fileType);
            }
            
            // 保存临时文件
            $tempFile = tempnam(sys_get_temp_dir(), 'excel_import_');
            $file->moveTo($tempFile);
            
            try {
                // 读取 Excel 文件
                $spreadsheet = IOFactory::load($tempFile);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                
                // 移除表头，从第二行开始处理数据
                $headers = array_shift($rows);
                $dataRows = $rows;
                
                if (empty($dataRows)) {
                    throw new BusinessException(400, '文件中没有数据');
                }
                
                // 处理数据行并保存到数据库
                $processedData = [];
                $importBatch = date('YmdHis');
                
                foreach ($dataRows as $rowIndex => $row) {
                    if (empty(array_filter($row))) {
                        continue; // 跳过空行
                    }
                    
                    $rowData = [];
                    foreach ($headers as $index => $header) {
                        $rowData[$header] = $row[$index] ?? '';
                    }
                    
                    // 映射字段到数据库字段
                    $dbData = [
                        'project_id' => $data['project_id'],
                        'import_type' => $data['import_type'],
                        'import_batch' => $importBatch,
                        'medical_category' => $rowData['医保分类'] ?? '',
                        'settlement_period' => $rowData['清算期'] ?? '',
                        'fee_period' => $rowData['费款所属期'] ?? '',
                        'settlement_id' => $rowData['结算id'] ?? '',
                        'certification_place' => $rowData['认定地'] ?? '',
                        'street_town' => $rowData['镇街'] ?? '',
                        'insurance_place' => $rowData['参保地'] ?? '',
                        'insurance_category' => $rowData['参保类别'] ?? '',
                        'id_number' => $rowData['身份证号'] ?? '',
                        'name' => $rowData['姓名'] ?? '',
                        'assistance_identity' => $rowData['救助身份'] ?? '',
                        'visit_place' => $rowData['就诊地'] ?? '',
                        'medical_institution' => $rowData['就诊医疗机构名称'] ?? '',
                        'medical_visit_category' => $rowData['医保就诊类别'] ?? '',
                        'medical_assistance_category' => $rowData['医疗救助类别'] ?? '',
                        'disease_code' => $rowData['病种编码'] ?? '',
                        'disease_name' => $rowData['病种名称'] ?? '',
                        'admission_date' => $rowData['入院日期'] ?? '',
                        'discharge_date' => $rowData['出院日期'] ?? '',
                        'settlement_date' => $rowData['结算日期'] ?? '',
                        'total_cost' => $this->parseAmount($rowData['费用总额'] ?? 0),
                        'eligible_reimbursement' => $this->parseAmount($rowData['符合医保报销金额'] ?? 0),
                        'basic_medical_reimbursement' => $this->parseAmount($rowData['基本医疗保险报销金额'] ?? 0),
                        'serious_illness_reimbursement' => $this->parseAmount($rowData['大病报销金额'] ?? 0),
                        'large_amount_reimbursement' => $this->parseAmount($rowData['大额报销金额'] ?? 0),
                        'medical_assistance_amount' => $this->parseAmount($rowData['进入医疗救助金额'] ?? 0),
                        'medical_assistance' => $this->parseAmount($rowData['医疗救助'] ?? 0),
                        'tilt_assistance' => $this->parseAmount($rowData['倾斜救助'] ?? 0),
                        'poverty_relief_amount' => $this->parseAmount($rowData['扶贫济困金额（元）'] ?? 0),
                        'yukuaibao_amount' => $this->parseAmount($rowData['渝快保支出金额（元）'] ?? 0),
                        'personal_account_amount' => $this->parseAmount($rowData['个人账户支付金额（元）'] ?? 0),
                        'personal_cash_amount' => $this->parseAmount($rowData['个人现金支付金额（元）'] ?? 0),
                    ];
                    
                    // 保存到数据库
                    StatisticsData::create($dbData);
                    $processedData[] = $dbData;
                }
                
                if (empty($processedData)) {
                    throw new BusinessException(400, '没有有效的数据行');
                }
                
                return $response->json([
                    'code' => 200,
                    'message' => '数据导入成功',
                    'data' => [
                        'imported_count' => count($processedData),
                        'sample_data' => array_slice($processedData, 0, 3) // 返回前3行作为示例
                    ]
                ]);
                
            } finally {
                // 清理临时文件
                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }
            
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '导入统计数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 解析金额字段
     */
    private function parseAmount($value): float
    {
        if (empty($value)) {
            return 0.0;
        }
        
        // 移除可能的货币符号和空格
        $value = str_replace(['¥', '￥', ' ', ','], '', $value);
        
        // 转换为浮点数
        $amount = (float)$value;
        
        return round($amount, 2);
    }

    /**
     * 获取数据类型选项
     */
    public function getDataTypeOptions(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $options = [
                ['value' => 'population', 'label' => '人口统计'],
                ['value' => 'income', 'label' => '收入统计'],
                ['value' => 'education', 'label' => '教育统计'],
                ['value' => 'health', 'label' => '健康统计'],
            ];
            
            return $response->json([
                'code' => 200,
                'message' => '获取成功',
                'data' => $options
            ]);
        } catch (\Exception $e) {
            throw new BusinessException(500, '获取数据类型选项失败: ' . $e->getMessage());
        }
    }

    /**
     * 人次统计
     */
    public function getPersonTimeStatistics(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $params = $request->all();
            
            // 验证项目ID列表
            if (empty($params['project_ids']) || !is_array($params['project_ids'])) {
                throw new BusinessException(400, '项目ID列表不能为空且必须是数组');
            }
            
            $projectIds = $params['project_ids'];
            $statisticsResults = [];
            
            foreach ($projectIds as $projectId) {
                // 获取项目信息
                $project = Project::find($projectId);
                if (!$project) {
                    continue; // 跳过不存在的项目
                }
                
                // 获取项目数据
                $projectData = StatisticsData::where('project_id', $projectId)->get();
                
                // 按import_type分组
                $groupedByDataType = [
                    '区内明细' => $projectData->where('import_type', '区内明细'),
                    '跨区明细' => $projectData->where('import_type', '跨区明细'),
                    '手工明细' => $projectData->where('import_type', '手工明细')
                ];
                
                // 统计结果
                $projectStats = [
                    'project_name' => $project->dec,
                    'project_code' => $project->code,
                    'import_types' => [],
                    'summary' => [
                        'total_outpatient_count' => 0,
                        'total_outpatient_amount' => 0,
                        'total_inpatient_count' => 0,
                        'total_inpatient_amount' => 0
                    ]
                ];
                
                // 按import_type统计
                foreach ($groupedByDataType as $dataType => $data) {
                    // 按medical_category分组
                    $outpatientData = $data->where('medical_category', '门诊');
                    $inpatientData = $data->where('medical_category', '住院');
                    
                    $outpatientCount = $outpatientData->count();
                    $outpatientAmount = $outpatientData->sum('total_cost');
                    $inpatientCount = $inpatientData->count();
                    $inpatientAmount = $inpatientData->sum('total_cost');
                    
                    $projectStats['import_types'][$dataType] = [
                        'outpatient_count' => $outpatientCount,
                        'outpatient_amount' => round($outpatientAmount, 2),
                        'inpatient_count' => $inpatientCount,
                        'inpatient_amount' => round($inpatientAmount, 2),
                        'total_amount' => round($outpatientAmount + $inpatientAmount, 2)
                    ];
                    
                    // 累计到总计
                    $projectStats['summary']['total_outpatient_count'] += $outpatientCount;
                    $projectStats['summary']['total_outpatient_amount'] += $outpatientAmount;
                    $projectStats['summary']['total_inpatient_count'] += $inpatientCount;
                    $projectStats['summary']['total_inpatient_amount'] += $inpatientAmount;
                }
                
                // 格式化总计金额
                $projectStats['summary']['total_outpatient_amount'] = round($projectStats['summary']['total_outpatient_amount'], 2);
                $projectStats['summary']['total_inpatient_amount'] = round($projectStats['summary']['total_inpatient_amount'], 2);
                
                $statisticsResults[] = $projectStats;
            }
            
            return $response->json([
                'code' => 200,
                'message' => '人次统计成功',
                'data' => $statisticsResults
            ]);
            
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '人次统计失败: ' . $e->getMessage());
        }
    }

    /**
     * 报销统计
     */
    public function getReimbursementStatistics(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $params = $request->all();
            
            // 验证项目ID列表
            if (empty($params['project_ids']) || !is_array($params['project_ids'])) {
                throw new BusinessException(400, '项目ID列表不能为空且必须是数组');
            }
            
            $projectIds = $params['project_ids'];
            $statisticsResults = [];
            
            foreach ($projectIds as $projectId) {
                // 获取项目信息
                $project = Project::find($projectId);
                if (!$project) {
                    continue; // 跳过不存在的项目
                }
                
                // 获取项目数据
                $projectData = StatisticsData::where('project_id', $projectId)->get();
                
                // 按import_type分组
                $groupedByDataType = [
                    '区内明细' => $projectData->where('import_type', '区内明细'),
                    '跨区明细' => $projectData->where('import_type', '跨区明细'),
                    '手工明细' => $projectData->where('import_type', '手工明细')
                ];
                
                // 统计结果
                $projectStats = [
                    'project_name' => $project->dec,
                    'project_code' => $project->code,
                    'import_types' => [],
                    'summary' => [
                        'total_cost' => 0,
                        'eligible_reimbursement' => 0,
                        'basic_medical_reimbursement' => 0,
                        'serious_illness_reimbursement' => 0,
                        'large_amount_reimbursement' => 0,
                        'medical_assistance_amount' => 0,
                        'tilt_assistance' => 0
                    ]
                ];
                
                // 按import_type统计
                foreach ($groupedByDataType as $dataType => $data) {
                    $totalCost = $data->sum('total_cost');
                    $eligibleReimbursement = $data->sum('eligible_reimbursement');
                    $basicMedicalReimbursement = $data->sum('basic_medical_reimbursement');
                    $seriousIllnessReimbursement = $data->sum('serious_illness_reimbursement');
                    $largeAmountReimbursement = $data->sum('large_amount_reimbursement');
                    $medicalAssistanceAmount = $data->sum('medical_assistance_amount');
                    $tiltAssistance = $data->sum('tilt_assistance');
                    
                    $projectStats['import_types'][$dataType] = [
                        'total_cost' => round($totalCost, 2),
                        'eligible_reimbursement' => round($eligibleReimbursement, 2),
                        'basic_medical_reimbursement' => round($basicMedicalReimbursement, 2),
                        'serious_illness_reimbursement' => round($seriousIllnessReimbursement, 2),
                        'large_amount_reimbursement' => round($largeAmountReimbursement, 2),
                        'medical_assistance_amount' => round($medicalAssistanceAmount, 2),
                        'tilt_assistance' => round($tiltAssistance, 2)
                    ];
                    
                    // 累计到总计
                    $projectStats['summary']['total_cost'] += $totalCost;
                    $projectStats['summary']['eligible_reimbursement'] += $eligibleReimbursement;
                    $projectStats['summary']['basic_medical_reimbursement'] += $basicMedicalReimbursement;
                    $projectStats['summary']['serious_illness_reimbursement'] += $seriousIllnessReimbursement;
                    $projectStats['summary']['large_amount_reimbursement'] += $largeAmountReimbursement;
                    $projectStats['summary']['medical_assistance_amount'] += $medicalAssistanceAmount;
                    $projectStats['summary']['tilt_assistance'] += $tiltAssistance;
                }
                
                // 格式化总计金额
                $projectStats['summary']['total_cost'] = round($projectStats['summary']['total_cost'], 2);
                $projectStats['summary']['eligible_reimbursement'] = round($projectStats['summary']['eligible_reimbursement'], 2);
                $projectStats['summary']['basic_medical_reimbursement'] = round($projectStats['summary']['basic_medical_reimbursement'], 2);
                $projectStats['summary']['serious_illness_reimbursement'] = round($projectStats['summary']['serious_illness_reimbursement'], 2);
                $projectStats['summary']['large_amount_reimbursement'] = round($projectStats['summary']['large_amount_reimbursement'], 2);
                $projectStats['summary']['medical_assistance_amount'] = round($projectStats['summary']['medical_assistance_amount'], 2);
                $projectStats['summary']['tilt_assistance'] = round($projectStats['summary']['tilt_assistance'], 2);
                
                $statisticsResults[] = $projectStats;
            }
            
            return $response->json([
                'code' => 200,
                'message' => '报销统计成功',
                'data' => $statisticsResults
            ]);
            
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '报销统计失败: ' . $e->getMessage());
        }
    }

    /**
     * 倾斜救助统计
     */
    public function getTiltAssistanceStatistics(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $params = $request->all();
            
            // 验证项目ID列表
            if (empty($params['project_ids']) || !is_array($params['project_ids'])) {
                throw new BusinessException(400, '项目ID列表不能为空且必须是数组');
            }
            
            $projectIds = $params['project_ids'];
            $statisticsResults = [];
            
            foreach ($projectIds as $projectId) {
                // 获取项目信息
                $project = Project::find($projectId);
                if (!$project) {
                    continue; // 跳过不存在的项目
                }
                
                // 获取项目数据
                $projectData = StatisticsData::where('project_id', $projectId)->get();
                
                // 按import_type分组
                $groupedByDataType = [
                    '区内明细' => $projectData->where('import_type', '区内明细'),
                    '跨区明细' => $projectData->where('import_type', '跨区明细'),
                    '手工明细' => $projectData->where('import_type', '手工明细')
                ];
                
                // 统计结果
                $projectStats = [
                    'project_name' => $project->dec,
                    'project_code' => $project->code,
                    'import_types' => [],
                    'summary' => [
                        'total_count' => 0,
                        'total_tilt_assistance' => 0
                    ]
                ];
                
                // 按import_type统计
                foreach ($groupedByDataType as $dataType => $data) {
                    $count = $data->count();
                    $tiltAssistance = $data->sum('tilt_assistance');
                    
                    $projectStats['import_types'][$dataType] = [
                        'count' => $count,
                        'tilt_assistance' => round($tiltAssistance, 2)
                    ];
                    
                    // 累计到总计
                    $projectStats['summary']['total_count'] += $count;
                    $projectStats['summary']['total_tilt_assistance'] += $tiltAssistance;
                }
                
                // 格式化总计金额
                $projectStats['summary']['total_tilt_assistance'] = round($projectStats['summary']['total_tilt_assistance'], 2);
                
                $statisticsResults[] = $projectStats;
            }
            
            return $response->json([
                'code' => 200,
                'message' => '倾斜救助统计成功',
                'data' => $statisticsResults
            ]);
            
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '倾斜救助统计失败: ' . $e->getMessage());
        }
    }

    /**
     * 人次统计导出
     */
    public function exportPersonTimeStatistics(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $params = $request->all();
            
            // 验证项目ID列表
            if (empty($params['project_ids']) || !is_array($params['project_ids'])) {
                throw new BusinessException(400, '项目ID列表不能为空且必须是数组');
            }
            
            $projectIds = $params['project_ids'];
            $statisticsResults = [];
            
            foreach ($projectIds as $projectId) {
                // 获取项目信息
                $project = Project::find($projectId);
                if (!$project) {
                    continue; // 跳过不存在的项目
                }
                
                // 获取项目数据
                $projectData = StatisticsData::where('project_id', $projectId)->get();
                
                // 按import_type分组
                $groupedByDataType = [
                    '区内明细' => $projectData->where('import_type', '区内明细'),
                    '跨区明细' => $projectData->where('import_type', '跨区明细'),
                    '手工明细' => $projectData->where('import_type', '手工明细')
                ];
                
                // 统计结果
                $projectStats = [
                    'project_name' => $project->dec,
                    'project_code' => $project->code,
                    'import_types' => [],
                    'summary' => [
                        'total_outpatient_count' => 0,
                        'total_outpatient_amount' => 0,
                        'total_inpatient_count' => 0,
                        'total_inpatient_amount' => 0
                    ]
                ];
                
                // 按import_type统计
                foreach ($groupedByDataType as $dataType => $data) {
                    // 按medical_category分组
                    $outpatientData = $data->where('medical_category', '门诊');
                    $inpatientData = $data->where('medical_category', '住院');
                    
                    $outpatientCount = $outpatientData->count();
                    $outpatientAmount = $outpatientData->sum('total_cost');
                    $inpatientCount = $inpatientData->count();
                    $inpatientAmount = $inpatientData->sum('total_cost');
                    
                    $projectStats['import_types'][$dataType] = [
                        'outpatient_count' => $outpatientCount,
                        'outpatient_amount' => round($outpatientAmount, 2),
                        'inpatient_count' => $inpatientCount,
                        'inpatient_amount' => round($inpatientAmount, 2),
                        'total_amount' => round($outpatientAmount + $inpatientAmount, 2)
                    ];
                    
                    // 累计到总计
                    $projectStats['summary']['total_outpatient_count'] += $outpatientCount;
                    $projectStats['summary']['total_outpatient_amount'] += $outpatientAmount;
                    $projectStats['summary']['total_inpatient_count'] += $inpatientCount;
                    $projectStats['summary']['total_inpatient_amount'] += $inpatientAmount;
                }
                
                // 格式化总计金额
                $projectStats['summary']['total_outpatient_amount'] = round($projectStats['summary']['total_outpatient_amount'], 2);
                $projectStats['summary']['total_inpatient_amount'] = round($projectStats['summary']['total_inpatient_amount'], 2);
                
                $statisticsResults[] = $projectStats;
            }
            
            // 创建Excel文件
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // 设置标题
            $sheet->setCellValue('A1', '人次统计汇总表');
            $sheet->mergeCells('A1:H1');
            
            // 设置表头
            $headers = [
                'A3' => '项目名称',
                'B3' => '项目代码',
                'C3' => '数据类型',
                'D3' => '门诊人数',
                'E3' => '门诊金额（元）',
                'F3' => '住院人数',
                'G3' => '住院金额（元）',
                'H3' => '总金额（元）'
            ];
            
            foreach ($headers as $cell => $value) {
                $sheet->setCellValue($cell, $value);
            }
            
            // 填充数据
            $row = 4;
            foreach ($statisticsResults as $projectStats) {
                foreach ($projectStats['import_types'] as $dataType => $typeStats) {
                    $sheet->setCellValue('A' . $row, $projectStats['project_name']);
                    $sheet->setCellValue('B' . $row, $projectStats['project_code']);
                    $sheet->setCellValue('C' . $row, $dataType);
                    $sheet->setCellValue('D' . $row, $typeStats['outpatient_count']);
                    $sheet->setCellValue('E' . $row, $typeStats['outpatient_amount']);
                    $sheet->setCellValue('F' . $row, $typeStats['inpatient_count']);
                    $sheet->setCellValue('G' . $row, $typeStats['inpatient_amount']);
                    $sheet->setCellValue('H' . $row, $typeStats['total_amount']);
                    $row++;
                }
                
                // 添加项目汇总行
                $sheet->setCellValue('A' . $row, $projectStats['project_name']);
                $sheet->setCellValue('B' . $row, $projectStats['project_code']);
                $sheet->setCellValue('C' . $row, '项目汇总');
                $sheet->setCellValue('D' . $row, $projectStats['summary']['total_outpatient_count']);
                $sheet->setCellValue('E' . $row, $projectStats['summary']['total_outpatient_amount']);
                $sheet->setCellValue('F' . $row, $projectStats['summary']['total_inpatient_count']);
                $sheet->setCellValue('G' . $row, $projectStats['summary']['total_inpatient_amount']);
                $sheet->setCellValue('H' . $row, $projectStats['summary']['total_outpatient_amount'] + $projectStats['summary']['total_inpatient_amount']);
                $row++;
            }
            
            // 设置样式
            $this->setExcelStyles($sheet, $row - 1);
            
            // 输出文件
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $filename = '人次统计汇总表_' . date('YmdHis') . '.xlsx';
            
            // 输出到临时文件
            $tempFile = tempnam(sys_get_temp_dir(), 'person_time_statistics_');
            $writer->save($tempFile);
            
            $content = file_get_contents($tempFile);
            unlink($tempFile);
            
            return $response->json([
                'code' => 200,
                'message' => '人次统计导出成功',
                'data' => [
                    'filename' => $filename,
                    'content' => base64_encode($content),
                    'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ]
            ]);
            
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '人次统计导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 报销统计导出
     */
    public function exportReimbursementStatistics(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $params = $request->all();
            
            // 验证项目ID列表
            if (empty($params['project_ids']) || !is_array($params['project_ids'])) {
                throw new BusinessException(400, '项目ID列表不能为空且必须是数组');
            }
            
            $projectIds = $params['project_ids'];
            $statisticsResults = [];
            
            foreach ($projectIds as $projectId) {
                // 获取项目信息
                $project = Project::find($projectId);
                if (!$project) {
                    continue; // 跳过不存在的项目
                }
                
                // 获取项目数据
                $projectData = StatisticsData::where('project_id', $projectId)->get();
                
                // 按import_type分组
                $groupedByDataType = [
                    '区内明细' => $projectData->where('import_type', '区内明细'),
                    '跨区明细' => $projectData->where('import_type', '跨区明细'),
                    '手工明细' => $projectData->where('import_type', '手工明细')
                ];
                
                // 统计结果
                $projectStats = [
                    'project_name' => $project->dec,
                    'project_code' => $project->code,
                    'import_types' => [],
                    'summary' => [
                        'total_cost' => 0,
                        'eligible_reimbursement' => 0,
                        'basic_medical_reimbursement' => 0,
                        'serious_illness_reimbursement' => 0,
                        'large_amount_reimbursement' => 0,
                        'medical_assistance_amount' => 0,
                        'tilt_assistance' => 0
                    ]
                ];
                
                // 按import_type统计
                foreach ($groupedByDataType as $dataType => $data) {
                    $totalCost = $data->sum('total_cost');
                    $eligibleReimbursement = $data->sum('eligible_reimbursement');
                    $basicMedicalReimbursement = $data->sum('basic_medical_reimbursement');
                    $seriousIllnessReimbursement = $data->sum('serious_illness_reimbursement');
                    $largeAmountReimbursement = $data->sum('large_amount_reimbursement');
                    $medicalAssistanceAmount = $data->sum('medical_assistance_amount');
                    $tiltAssistance = $data->sum('tilt_assistance');
                    
                    $projectStats['import_types'][$dataType] = [
                        'total_cost' => round($totalCost, 2),
                        'eligible_reimbursement' => round($eligibleReimbursement, 2),
                        'basic_medical_reimbursement' => round($basicMedicalReimbursement, 2),
                        'serious_illness_reimbursement' => round($seriousIllnessReimbursement, 2),
                        'large_amount_reimbursement' => round($largeAmountReimbursement, 2),
                        'medical_assistance_amount' => round($medicalAssistanceAmount, 2),
                        'tilt_assistance' => round($tiltAssistance, 2)
                    ];
                    
                    // 累计到总计
                    $projectStats['summary']['total_cost'] += $totalCost;
                    $projectStats['summary']['eligible_reimbursement'] += $eligibleReimbursement;
                    $projectStats['summary']['basic_medical_reimbursement'] += $basicMedicalReimbursement;
                    $projectStats['summary']['serious_illness_reimbursement'] += $seriousIllnessReimbursement;
                    $projectStats['summary']['large_amount_reimbursement'] += $largeAmountReimbursement;
                    $projectStats['summary']['medical_assistance_amount'] += $medicalAssistanceAmount;
                    $projectStats['summary']['tilt_assistance'] += $tiltAssistance;
                }
                
                // 格式化总计金额
                $projectStats['summary']['total_cost'] = round($projectStats['summary']['total_cost'], 2);
                $projectStats['summary']['eligible_reimbursement'] = round($projectStats['summary']['eligible_reimbursement'], 2);
                $projectStats['summary']['basic_medical_reimbursement'] = round($projectStats['summary']['basic_medical_reimbursement'], 2);
                $projectStats['summary']['serious_illness_reimbursement'] = round($projectStats['summary']['serious_illness_reimbursement'], 2);
                $projectStats['summary']['large_amount_reimbursement'] = round($projectStats['summary']['large_amount_reimbursement'], 2);
                $projectStats['summary']['medical_assistance_amount'] = round($projectStats['summary']['medical_assistance_amount'], 2);
                $projectStats['summary']['tilt_assistance'] = round($projectStats['summary']['tilt_assistance'], 2);
                
                $statisticsResults[] = $projectStats;
            }
            
            // 创建Excel文件
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // 设置标题
            $sheet->setCellValue('A1', '报销统计汇总表');
            $sheet->mergeCells('A1:I1');
            
            // 设置表头
            $headers = [
                'A3' => '项目名称',
                'B3' => '项目代码',
                'C3' => '数据类型',
                'D3' => '费用总额（元）',
                'E3' => '符合医保报销金额（元）',
                'F3' => '基本医疗保险报销金额（元）',
                'G3' => '大病报销金额（元）',
                'H3' => '大额报销金额（元）',
                'I3' => '进入医疗救助金额（元）',
                'J3' => '倾斜救助（元）'
            ];
            
            foreach ($headers as $cell => $value) {
                $sheet->setCellValue($cell, $value);
            }
            
            // 填充数据
            $row = 4;
            foreach ($statisticsResults as $projectStats) {
                foreach ($projectStats['import_types'] as $dataType => $typeStats) {
                    $sheet->setCellValue('A' . $row, $projectStats['project_name']);
                    $sheet->setCellValue('B' . $row, $projectStats['project_code']);
                    $sheet->setCellValue('C' . $row, $dataType);
                    $sheet->setCellValue('D' . $row, $typeStats['total_cost']);
                    $sheet->setCellValue('E' . $row, $typeStats['eligible_reimbursement']);
                    $sheet->setCellValue('F' . $row, $typeStats['basic_medical_reimbursement']);
                    $sheet->setCellValue('G' . $row, $typeStats['serious_illness_reimbursement']);
                    $sheet->setCellValue('H' . $row, $typeStats['large_amount_reimbursement']);
                    $sheet->setCellValue('I' . $row, $typeStats['medical_assistance_amount']);
                    $sheet->setCellValue('J' . $row, $typeStats['tilt_assistance']);
                    $row++;
                }
                
                // 添加项目汇总行
                $sheet->setCellValue('A' . $row, $projectStats['project_name']);
                $sheet->setCellValue('B' . $row, $projectStats['project_code']);
                $sheet->setCellValue('C' . $row, '项目汇总');
                $sheet->setCellValue('D' . $row, $projectStats['summary']['total_cost']);
                $sheet->setCellValue('E' . $row, $projectStats['summary']['eligible_reimbursement']);
                $sheet->setCellValue('F' . $row, $projectStats['summary']['basic_medical_reimbursement']);
                $sheet->setCellValue('G' . $row, $projectStats['summary']['serious_illness_reimbursement']);
                $sheet->setCellValue('H' . $row, $projectStats['summary']['large_amount_reimbursement']);
                $sheet->setCellValue('I' . $row, $projectStats['summary']['medical_assistance_amount']);
                $sheet->setCellValue('J' . $row, $projectStats['summary']['tilt_assistance']);
                $row++;
            }
            
            // 设置样式
            $this->setExcelStyles($sheet, $row - 1);
            
            // 输出文件
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $filename = '报销统计汇总表_' . date('YmdHis') . '.xlsx';
            
            // 输出到临时文件
            $tempFile = tempnam(sys_get_temp_dir(), 'reimbursement_statistics_');
            $writer->save($tempFile);
            
            $content = file_get_contents($tempFile);
            unlink($tempFile);
            
            return $response->json([
                'code' => 200,
                'message' => '报销统计导出成功',
                'data' => [
                    'filename' => $filename,
                    'content' => base64_encode($content),
                    'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ]
            ]);
            
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '报销统计导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 倾斜救助统计导出
     */
    public function exportTiltAssistanceStatistics(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $params = $request->all();
            
            // 验证项目ID列表
            if (empty($params['project_ids']) || !is_array($params['project_ids'])) {
                throw new BusinessException(400, '项目ID列表不能为空且必须是数组');
            }
            
            $projectIds = $params['project_ids'];
            $statisticsResults = [];
            
            foreach ($projectIds as $projectId) {
                // 获取项目信息
                $project = Project::find($projectId);
                if (!$project) {
                    continue; // 跳过不存在的项目
                }
                
                // 获取项目数据
                $projectData = StatisticsData::where('project_id', $projectId)->get();
                
                // 按import_type分组
                $groupedByDataType = [
                    '区内明细' => $projectData->where('import_type', '区内明细'),
                    '跨区明细' => $projectData->where('import_type', '跨区明细'),
                    '手工明细' => $projectData->where('import_type', '手工明细')
                ];
                
                // 统计结果
                $projectStats = [
                    'project_name' => $project->dec,
                    'project_code' => $project->code,
                    'import_types' => [],
                    'summary' => [
                        'total_count' => 0,
                        'total_tilt_assistance' => 0
                    ]
                ];
                
                // 按import_type统计
                foreach ($groupedByDataType as $dataType => $data) {
                    $count = $data->count();
                    $tiltAssistance = $data->sum('tilt_assistance');
                    
                    $projectStats['import_types'][$dataType] = [
                        'count' => $count,
                        'tilt_assistance' => round($tiltAssistance, 2)
                    ];
                    
                    // 累计到总计
                    $projectStats['summary']['total_count'] += $count;
                    $projectStats['summary']['total_tilt_assistance'] += $tiltAssistance;
                }
                
                // 格式化总计金额
                $projectStats['summary']['total_tilt_assistance'] = round($projectStats['summary']['total_tilt_assistance'], 2);
                
                $statisticsResults[] = $projectStats;
            }
            
            // 创建Excel文件
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // 设置标题
            $sheet->setCellValue('A1', '倾斜救助统计汇总表');
            $sheet->mergeCells('A1:D1');
            
            // 设置表头
            $headers = [
                'A3' => '项目名称',
                'B3' => '项目代码',
                'C3' => '数据类型',
                'D3' => '人数',
                'E3' => '倾斜救助金额（元）'
            ];
            
            foreach ($headers as $cell => $value) {
                $sheet->setCellValue($cell, $value);
            }
            
            // 填充数据
            $row = 4;
            foreach ($statisticsResults as $projectStats) {
                foreach ($projectStats['import_types'] as $dataType => $typeStats) {
                    $sheet->setCellValue('A' . $row, $projectStats['project_name']);
                    $sheet->setCellValue('B' . $row, $projectStats['project_code']);
                    $sheet->setCellValue('C' . $row, $dataType);
                    $sheet->setCellValue('D' . $row, $typeStats['count']);
                    $sheet->setCellValue('E' . $row, $typeStats['tilt_assistance']);
                    $row++;
                }
                
                // 添加项目汇总行
                $sheet->setCellValue('A' . $row, $projectStats['project_name']);
                $sheet->setCellValue('B' . $row, $projectStats['project_code']);
                $sheet->setCellValue('C' . $row, '项目汇总');
                $sheet->setCellValue('D' . $row, $projectStats['summary']['total_count']);
                $sheet->setCellValue('E' . $row, $projectStats['summary']['total_tilt_assistance']);
                $row++;
            }
            
            // 设置样式
            $this->setExcelStyles($sheet, $row - 1);
            
            // 输出文件
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $filename = '倾斜救助统计汇总表_' . date('YmdHis') . '.xlsx';
            
            // 输出到临时文件
            $tempFile = tempnam(sys_get_temp_dir(), 'tilt_assistance_statistics_');
            $writer->save($tempFile);
            
            $content = file_get_contents($tempFile);
            unlink($tempFile);
            
            return $response->json([
                'code' => 200,
                'message' => '倾斜救助统计导出成功',
                'data' => [
                    'filename' => $filename,
                    'content' => base64_encode($content),
                    'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ]
            ]);
            
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '倾斜救助统计导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 清空项目数据
     */
    public function clearProjectData(RequestInterface $request, ResponseInterface $response, int $id)
    {
        try {
            $project = Project::find($id);
            if (!$project) {
                throw new BusinessException(404, '项目不存在');
            }

            // 删除该项目的所有统计数据
            $deletedCount = StatisticsData::where('project_id', $id)->delete();

            return $response->json([
                'code' => 200,
                'message' => '数据清空成功',
                'data' => [
                    'deleted_count' => $deletedCount
                ]
            ]);
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '清空项目数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出明细统计
     */
    public function exportDetailStatistics(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $params = $request->all();
            
            // 验证项目ID列表
            if (empty($params['project_ids']) || !is_array($params['project_ids'])) {
                throw new BusinessException(400, '项目ID列表不能为空且必须是数组');
            }
            
            $projectIds = $params['project_ids'];
            
            // 创建Excel文件
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            
            // 定义明细类型
            $detailTypes = [
                '区内明细' => '区内明细',
                '跨区明细' => '跨区明细', 
                '手工明细' => '手工明细'
            ];
            
            // 定义表头
            $headers = [
                'A' => '年月',
                'B' => '医保分类',
                'C' => '清算期',
                'D' => '费款所属期',
                'E' => '结算id',
                'F' => '认定地',
                'G' => '镇街',
                'H' => '参保地',
                'I' => '参保类别',
                'J' => '身份证号',
                'K' => '姓名',
                'L' => '救助身份',
                'M' => '就诊地',
                'N' => '就诊医疗机构名称',
                'O' => '医保就诊类别',
                'P' => '医疗救助类别',
                'Q' => '病种编码',
                'R' => '病种名称',
                'S' => '入院日期',
                'T' => '出院日期',
                'U' => '结算日期',
                'V' => '费用总额',
                'W' => '符合医保报销金额',
                'X' => '基本医疗保险报销金额',
                'Y' => '大病报销金额',
                'Z' => '大额报销金额',
                'AA' => '进入医疗救助金额',
                'AB' => '医疗救助',
                'AC' => '倾斜救助',
                'AD' => '扶贫济困金额（元）',
                'AE' => '渝快保支出金额（元）',
                'AF' => '个人账户支付金额（元）',
                'AG' => '个人现金支付金额（元）'
            ];
            
            $sheetIndex = 0;
            foreach ($detailTypes as $typeName => $importType) {
                // 创建新的工作表
                if ($sheetIndex === 0) {
                    $sheet = $spreadsheet->getActiveSheet();
                } else {
                    $sheet = $spreadsheet->createSheet();
                }
                $sheet->setTitle($typeName);
                
                // 设置标题
                $sheet->setCellValue('A1', $typeName . '明细统计');
                $sheet->mergeCells('A1:AG1');
                
                // 设置表头
                $row = 3;
                foreach ($headers as $column => $header) {
                    $sheet->setCellValue($column . $row, $header);
                }
                
                // 获取数据
                $data = StatisticsData::whereIn('project_id', $projectIds)
                    ->where('import_type', $importType)
                    ->with('project')
                    ->get();
                
                // 填充数据
                $row = 4;
                foreach ($data as $item) {
                    $sheet->setCellValue('A' . $row, $item->project->code ?? '');
                    $sheet->setCellValue('B' . $row, $item->medical_category);
                    $sheet->setCellValue('C' . $row, $item->settlement_period);
                    $sheet->setCellValue('D' . $row, $item->fee_period);
                    $sheet->setCellValueExplicit('E' . $row, $item->settlement_id, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValue('F' . $row, $item->certification_place);
                    $sheet->setCellValue('G' . $row, $item->street_town);
                    $sheet->setCellValue('H' . $row, $item->insurance_place);
                    $sheet->setCellValue('I' . $row, $item->insurance_category);
                    $sheet->setCellValueExplicit('J' . $row, $item->id_number, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    $sheet->setCellValue('K' . $row, $item->name);
                    $sheet->setCellValue('L' . $row, $item->assistance_identity);
                    $sheet->setCellValue('M' . $row, $item->visit_place);
                    $sheet->setCellValue('N' . $row, $item->medical_institution);
                    $sheet->setCellValue('O' . $row, $item->medical_visit_category);
                    $sheet->setCellValue('P' . $row, $item->medical_assistance_category);
                    $sheet->setCellValue('Q' . $row, $item->disease_code);
                    $sheet->setCellValue('R' . $row, $item->disease_name);
                    $sheet->setCellValue('S' . $row, $item->admission_date);
                    $sheet->setCellValue('T' . $row, $item->discharge_date);
                    $sheet->setCellValue('U' . $row, $item->settlement_date);
                    $sheet->setCellValue('V' . $row, $item->total_cost);
                    $sheet->setCellValue('W' . $row, $item->eligible_reimbursement);
                    $sheet->setCellValue('X' . $row, $item->basic_medical_reimbursement);
                    $sheet->setCellValue('Y' . $row, $item->serious_illness_reimbursement);
                    $sheet->setCellValue('Z' . $row, $item->large_amount_reimbursement);
                    $sheet->setCellValue('AA' . $row, $item->medical_assistance_amount);
                    $sheet->setCellValue('AB' . $row, $item->medical_assistance);
                    $sheet->setCellValue('AC' . $row, $item->tilt_assistance);
                    $sheet->setCellValue('AD' . $row, $item->poverty_relief_amount);
                    $sheet->setCellValue('AE' . $row, $item->yukuaibao_amount);
                    $sheet->setCellValue('AF' . $row, $item->personal_account_amount);
                    $sheet->setCellValue('AG' . $row, $item->personal_cash_amount);
                    $row++;
                }
                
                // 设置样式
                $this->setDetailExcelStyles($sheet, $row - 1);
                
                $sheetIndex++;
            }
            
            // 输出文件
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $filename = '明细统计汇总表_' . date('YmdHis') . '.xlsx';
            
            // 输出到临时文件
            $tempFile = tempnam(sys_get_temp_dir(), 'detail_statistics_');
            $writer->save($tempFile);
            
            $content = file_get_contents($tempFile);
            unlink($tempFile);
            
            return $response->json([
                'code' => 200,
                'message' => '明细统计导出成功',
                'data' => [
                    'filename' => $filename,
                    'content' => base64_encode($content),
                    'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                ]
            ]);
            
        } catch (BusinessException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BusinessException(500, '明细统计导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 设置明细Excel样式
     */
    private function setDetailExcelStyles($sheet, $lastRow): void
    {
        // 标题样式
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ]
        ]);
        
        // 表头样式
        $headerRange = 'A3:AF3';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'E0E0E0'
                ]
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
            ]
        ]);
        
        // 设置边框
        $dataRange = 'A1:AF' . $lastRow;
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
                ]
            ]
        ]);
        
        // 设置列宽
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z', 'AA', 'AB', 'AC', 'AD', 'AE', 'AF'];
        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setWidth(15);
        }
        
        // 设置行高
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(3)->setRowHeight(25);
    }

    /**
     * 设置Excel样式
     */
    private function setExcelStyles($sheet, $lastRow): void
    {
        // 标题样式
        $sheet->getStyle('A1')->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 16
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ]
        ]);
        
        // 表头样式
        $headerRange = 'A3:' . $sheet->getHighestColumn() . '3';
        $sheet->getStyle($headerRange)->applyFromArray([
            'font' => [
                'bold' => true
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'E0E0E0'
                ]
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
            ]
        ]);
        
        // 设置边框
        $dataRange = 'A1:' . $sheet->getHighestColumn() . $lastRow;
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
                ]
            ]
        ]);
        
        // 设置列宽
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex((string)$col);
            $sheet->getColumnDimension($columnLetter)->setWidth(15);
        }
        
        // 设置行高
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(3)->setRowHeight(25);
    }
} 