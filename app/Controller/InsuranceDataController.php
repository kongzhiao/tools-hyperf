<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\InsuranceData;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\DbConnection\Db;
use App\Model\InsuranceLevelConfig;
use App\Service\InsuranceLevelConfigCache;
use PhpOffice\PhpSpreadsheet\IOFactory;

class InsuranceDataController extends AbstractController
{

    /**
     * 获取参保数据列表
     */
    public function index(RequestInterface $request, ResponseInterface $response)
    {
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 15);
        
        // 搜索条件
        $filters = [
            'year' => $request->input('year', ''),
            'street_town' => $request->input('street_town', ''),
            'name' => $request->input('name', ''),
            'id_number' => $request->input('id_number', ''),
            'payment_category' => $request->input('payment_category', ''),
            'level' => $request->input('level', ''),
            'medical_assistance_category' => $request->input('medical_assistance_category', ''),
            'level_match_status' => $request->input('level_match_status', ''),
            'assistance_identity_match_status' => $request->input('assistance_identity_match_status', ''),
            'street_town_match_status' => $request->input('street_town_match_status', ''),
            'match_status' => $request->input('match_status', ''),
        ];

        $result = InsuranceData::search($filters, $page, $pageSize);

        return $this->success($result);
    }

    /**
     * 获取参保数据详情
     */
    public function show(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $data = InsuranceData::find($id);
        if (!$data) {
            return $this->error('数据不存在');
        }

        return $this->success($data);
    }

    /**
     * 更新参保数据
     */
    public function update(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $data = InsuranceData::find($id);
        if (!$data) {
            return $this->error('数据不存在');
        }

        $updateData = $request->all();
        
        // 验证数据
        if (isset($updateData['payment_amount']) && (!is_numeric($updateData['payment_amount']) || $updateData['payment_amount'] < 0)) {
            return $this->error('代缴金额必须是非负数');
        }
        
        if (isset($updateData['personal_amount']) && (!is_numeric($updateData['personal_amount']) || $updateData['personal_amount'] < 0)) {
            return $this->error('个人实缴金额必须是非负数');
        }

        // 验证匹配状态字段
        $matchStatusFields = [
            'level_match_status',
            'assistance_identity_match_status', 
            'street_town_match_status',
            'match_status'
        ];
        
        foreach ($matchStatusFields as $field) {
            if (isset($updateData[$field]) && !in_array($updateData[$field], ['matched', 'unmatched', ''], true)) {
                return $this->error("{$field} 字段值无效，只能是 'matched' 或 'unmatched'");
            }
        }

        $data->update($updateData);
        return $this->success($data, '更新成功');
    }

    /**
     * 删除参保数据
     */
    public function destroy(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $data = InsuranceData::find($id);
        if (!$data) {
            return $this->error('数据不存在');
        }

        $data->delete();
        return $this->success(null, '删除成功');
    }

    /**
     * 获取所有年份
     */
    public function getYears(RequestInterface $request, ResponseInterface $response)
    {
        $data = InsuranceData::getAllYears();
        return $this->success($data);
    }

    /**
     * 获取所有街道乡镇
     */
    public function getStreetTowns(RequestInterface $request, ResponseInterface $response)
    {
        $data = InsuranceData::getAllStreetTowns();
        return $this->success($data);
    }

    /**
     * 获取所有代缴类别
     */
    public function getPaymentCategories(RequestInterface $request, ResponseInterface $response)
    {
        $data = InsuranceData::getAllPaymentCategories();
        return $this->success($data);
    }

    /**
     * 获取所有档次
     */
    public function getLevels(RequestInterface $request, ResponseInterface $response)
    {
        $data = InsuranceData::getAllLevels();
        return $this->success($data);
    }

    /**
     * 获取所有医疗救助类别
     */
    public function getMedicalAssistanceCategories(RequestInterface $request, ResponseInterface $response)
    {
        $data = InsuranceData::getAllMedicalAssistanceCategories();
        return $this->success($data);
    }

    /**
     * 获取统计数据
     */
    public function getStatistics(RequestInterface $request, ResponseInterface $response)
    {
        $year = $request->input('year', null);
        if ($year) {
            $year = (int) $year;
        }
        $data = InsuranceData::getStatistics($year);
        return $this->success($data);
    }

    /**
     * 批量更新数据
     */
    public function batchUpdate(RequestInterface $request, ResponseInterface $response)
    {
        $data = $request->all();
        
        if (empty($data['ids']) || !is_array($data['ids'])) {
            return $this->error('请选择要更新的数据');
        }

        $updateData = $data['update_data'] ?? [];
        if (empty($updateData)) {
            return $this->error('请提供要更新的数据');
        }

        // 验证数据
        if (isset($updateData['payment_amount']) && (!is_numeric($updateData['payment_amount']) || $updateData['payment_amount'] < 0)) {
            return $this->error('代缴金额必须是非负数');
        }
        
        if (isset($updateData['personal_amount']) && (!is_numeric($updateData['personal_amount']) || $updateData['personal_amount'] < 0)) {
            return $this->error('个人实缴金额必须是非负数');
        }

        $count = InsuranceData::whereIn('id', $data['ids'])->update($updateData);
        
        return $this->success(['updated_count' => $count], "成功更新 {$count} 条数据");
    }

    /**
     * 创建新年份的数据
     */
    public function createYear(RequestInterface $request, ResponseInterface $response)
    {
        $year = $request->input('year');
        if (!$year || !is_numeric($year)) {
            return $this->error('请提供有效的年份');
        }

        $year = (int) $year;
        
        // 检查年份是否已经在管理表中存在
        if (\App\Model\InsuranceYear::yearExists($year)) {
            return $this->error("{$year}年已经存在");
        }
        
        // 创建新年份
        $success = \App\Model\InsuranceYear::createYear($year, "{$year}年度参保数据");
        if (!$success) {
            return $this->error("创建{$year}年失败，请检查日志");
        }

        return $this->success(['year' => $year], "{$year}年创建成功，可以开始导入数据");
    }

    /**
     * 获取年份管理列表
     */
    public function getYearList(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $years = \App\Model\InsuranceYear::all();
            $yearList = [];
            
            foreach ($years as $year) {
                $dataCount = InsuranceData::where('year', $year->year)->count();
                $yearList[] = [
                    'id' => $year->id,
                    'year' => $year->year,
                    'description' => $year->description,
                    'is_active' => $year->is_active,
                    'data_count' => $dataCount,
                    'created_at' => $year->created_at,
                    'updated_at' => $year->updated_at,
                ];
            }
            
            return $this->success($yearList);
        } catch (\Exception $e) {
            return $this->error('获取年份列表失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新年份信息
     */
    public function updateYear(RequestInterface $request, ResponseInterface $response, int $id)
    {
        try {
            $year = \App\Model\InsuranceYear::find($id);
            if (!$year) {
                return $this->error('年份不存在');
            }

            $data = $request->all();
            $updateData = [];
            
            if (isset($data['description'])) {
                $updateData['description'] = $data['description'];
            }
            
            if (isset($data['is_active'])) {
                $updateData['is_active'] = (bool) $data['is_active'];
            }

            $year->update($updateData);
            
            return $this->success(['year' => $year], '年份更新成功');
        } catch (\Exception $e) {
            return $this->error('更新年份失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除年份
     */
    public function deleteYear(RequestInterface $request, ResponseInterface $response, int $id)
    {
        try {
            $year = \App\Model\InsuranceYear::find($id);
            if (!$year) {
                return $this->error('年份不存在');
            }

            // 检查是否有数据
            $dataCount = InsuranceData::where('year', $year->year)->count();
            if ($dataCount > 0) {
                return $this->error("无法删除{$year->year}年，该年份下有{$dataCount}条数据记录");
            }

            $year->delete();
            
            return $this->success([], "{$year->year}年删除成功");
        } catch (\Exception $e) {
            return $this->error('删除年份失败: ' . $e->getMessage());
        }
    }

    /**
     * 清空年份数据
     */
    public function clearYearData(RequestInterface $request, ResponseInterface $response, int $id)
    {
        try {
            $year = \App\Model\InsuranceYear::find($id);
            if (!$year) {
                return $this->error('年份不存在');
            }

            // 删除该年份的所有数据
            $deletedCount = InsuranceData::where('year', $year->year)->delete();
            
            return $this->success([
                'year' => $year->year,
                'deleted_count' => $deletedCount
            ], "{$year->year}年数据清空成功，共删除{$deletedCount}条记录");
        } catch (\Exception $e) {
            return $this->error('清空年份数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 按年份导入数据
     */
    public function importByYear(RequestInterface $request, ResponseInterface $response)
    {
        $year = $request->input('year');
        $mode = $request->input('mode', 'incremental');
        $file = $request->file('file');
        
        if (!$year || !is_numeric($year)) {
            return $this->error('请提供有效的年份');
        }

        if (!in_array($mode, ['incremental', 'full'])) {
            return $this->error('导入模式必须是 incremental 或 full');
        }
        
        // 检查是否上传了文件
        if (!$file) {
            return $this->error('请选择要导入的Excel文件');
        }
        
        // 检查文件是否有效
        if (!$file->isValid()) {
            $error = $file->getError();
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制',
                UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
                UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                UPLOAD_ERR_NO_FILE => '没有文件被上传',
                UPLOAD_ERR_NO_TMP_DIR => '找不到临时文件夹',
                UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                UPLOAD_ERR_EXTENSION => '文件上传被扩展程序中断',
            ];
            $errorMessage = $errorMessages[$error] ?? '文件上传失败，错误代码：' . $error;
            return $this->error($errorMessage);
        }
        
        // 检查文件类型
        $allowedTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $allowedTypes)) {
            return $this->error('请上传Excel文件（.xlsx或.xls格式），当前文件类型：' . $mimeType);
        }

        $year = (int) $year;
        
        // 检查年份是否存在
        if (!\App\Model\InsuranceYear::yearExists($year)) {
            return $this->error("{$year}年不存在，请先创建该年份");
        }
        
        // 检查是否已有数据
        $existingCount = InsuranceData::where('year', $year)->count();
        if ($existingCount > 0 && $mode === 'incremental') {
            // 增量模式：允许有数据
        } elseif ($existingCount > 0 && $mode === 'full') {
            // 全量模式：允许有数据，会覆盖
        }
        
        try {
            // 保存上传的文件
            $uploadDir = BASE_PATH . '/storage/uploads/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    return $this->error('创建上传目录失败');
                }
            }
            
            $fileName = 'insurance_data_' . $year . '_' . time() . '.' . $file->getExtension();
            $filePath = $uploadDir . $fileName;
            
            // 尝试移动文件
            $moveResult = $file->moveTo($filePath);
            if (!$moveResult) {
                // 检查文件是否已经存在
                if (!file_exists($filePath)) {
                    return $this->error('文件保存失败，请检查目录权限');
                }
            }
            
            // 验证文件是否真的保存了
            if (!file_exists($filePath)) {
                return $this->error('文件保存验证失败');
            }
            
            // 执行导入命令，传入文件路径
            $command = "php bin/hyperf.php import:insurance-data --year={$year} --mode={$mode} --file={$filePath}";
            $output = [];
            $returnCode = 0;
            
            exec($command . " 2>&1", $output, $returnCode);
            
            // 删除临时文件
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            if ($returnCode === 0) {
                // 获取导入后的数据条数
                $importedCount = InsuranceData::where('year', $year)->count();
                $modeText = $mode === 'incremental' ? '增量导入' : '全量覆盖';
                return $this->success([
                    'year' => $year,
                    'imported_count' => $importedCount,
                    'mode' => $mode
                ], "{$year}年数据{$modeText}成功，共{$importedCount}条记录");
            } else {
                $errorMessage = implode("\n", $output);
                return $this->error("导入失败: " . $errorMessage);
            }
        } catch (\Exception $e) {
            return $this->error('导入失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出参保数据
     */
    public function export(RequestInterface $request, ResponseInterface $response)
    {
        return $this->exportCsv($request, $response);
    }

    /**
     * 获取导出数据统计信息
     */
    public function getExportInfo(RequestInterface $request, ResponseInterface $response)
    {
        $year = (int) $request->input('year', date('Y'));
        
        // 搜索条件
        $filters = [
            'year' => $request->input('year', ''),
            'street_town' => $request->input('street_town', ''),
            'name' => $request->input('name', ''),
            'id_number' => $request->input('id_number', ''),
            'payment_category' => $request->input('payment_category', ''),
            'level' => $request->input('level', ''),
            'medical_assistance_category' => $request->input('medical_assistance_category', ''),
        ];

        try {
            // 构建查询
            $query = InsuranceData::query();
            
            // 应用搜索条件
            if (!empty($filters['year'])) {
                $query->where('year', $filters['year']);
            }
            if (!empty($filters['street_town'])) {
                $query->where('street_town', 'like', "%{$filters['street_town']}%");
            }
            if (!empty($filters['name'])) {
                $query->where('name', 'like', "%{$filters['name']}%");
            }
            if (!empty($filters['id_number'])) {
                $query->where('id_number', 'like', "%{$filters['id_number']}%");
            }
            if (!empty($filters['payment_category'])) {
                $query->where('payment_category', $filters['payment_category']);
            }
            if (!empty($filters['level'])) {
                $query->where('level', $filters['level']);
            }
            if (!empty($filters['medical_assistance_category'])) {
                $query->where('medical_assistance_category', $filters['medical_assistance_category']);
            }

            // 获取总记录数
            $totalCount = $query->count();
            
            return $this->success([
                'total_count' => $totalCount,
                'can_export' => $totalCount <= 100000,
                'suggested_format' => 'csv'
            ]);
            
        } catch (\Exception $e) {
            return $this->error('获取导出信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出Excel格式
     */






    /**
     * 导出CSV文件
     */
    private function exportCsv(RequestInterface $request, ResponseInterface $response)
    {
        // 设置脚本执行时间限制
        set_time_limit(1800); // 30分钟超时
        
        $year = (int) $request->input('year', date('Y'));
        
        // 搜索条件
        $filters = [
            'year' => $request->input('year', ''),
            'street_town' => $request->input('street_town', ''),
            'name' => $request->input('name', ''),
            'id_number' => $request->input('id_number', ''),
            'payment_category' => $request->input('payment_category', ''),
            'level' => $request->input('level', ''),
            'medical_assistance_category' => $request->input('medical_assistance_category', ''),
            'level_match_status' => $request->input('level_match_status', ''),
            'assistance_identity_match_status' => $request->input('assistance_identity_match_status', ''),
            'street_town_match_status' => $request->input('street_town_match_status', ''),
            'match_status' => $request->input('match_status', ''),
        ];

        try {
            // 构建查询
            $query = InsuranceData::query();
            
            // 应用搜索条件
            if (!empty($filters['year'])) {
                $query->where('year', $filters['year']);
            }
            if (!empty($filters['street_town'])) {
                $query->where('street_town', 'like', "%{$filters['street_town']}%");
            }
            if (!empty($filters['name'])) {
                $query->where('name', 'like', "%{$filters['name']}%");
            }
            if (!empty($filters['id_number'])) {
                $query->where('id_number', 'like', "%{$filters['id_number']}%");
            }
            if (!empty($filters['payment_category'])) {
                $query->where('payment_category', $filters['payment_category']);
            }
            if (!empty($filters['level'])) {
                $query->where('level', $filters['level']);
            }
            if (!empty($filters['medical_assistance_category'])) {
                $query->where('medical_assistance_category', $filters['medical_assistance_category']);
            }
            if (!empty($filters['level_match_status'])) {
                $query->where('level_match_status', $filters['level_match_status']);
            }
            if (!empty($filters['assistance_identity_match_status'])) {
                $query->where('assistance_identity_match_status', $filters['assistance_identity_match_status']);
            }
            if (!empty($filters['street_town_match_status'])) {
                $query->where('street_town_match_status', $filters['street_town_match_status']);
            }
            if (!empty($filters['match_status'])) {
                $query->where('match_status', $filters['match_status']);
            }

            // 获取总记录数
            $totalCount = $query->count();
            
            // 如果数据量太大，建议分批处理或限制导出
            if ($totalCount > 100000) {
                return $this->error('数据量过大（超过10万条），请缩小搜索范围后重试');
            }

            // 创建CSV文件
            $filename = $year . '年参保数据.csv';
            $tempFile = tempnam(sys_get_temp_dir(), 'insurance_data_csv_');
            
            $handle = fopen($tempFile, 'w');
            
            // 写入BOM，确保Excel正确识别中文
            fwrite($handle, "\xEF\xBB\xBF");
            
            // 写入表头（只包含核心字段）
            $headers = [
                '序号',
                '街道乡镇',
                '姓名',
                '身份证件号码',
                '代缴类别',
                '代缴金额',
                '档次',
                '档次匹配状态',
                '医疗救助匹配状态',
                '认定区匹配状态',
                '匹配状态'
            ];
            fputcsv($handle, $headers);

            // 分批处理数据，减小批次大小以提高稳定性
            $batchSize = 500; // 减小批次大小
            $index = 0;
            $startTime = time();
            
            $query->orderBy('id', 'asc')->chunk($batchSize, function ($items) use ($handle, &$index, $startTime) {
                // 检查是否超时
                if (time() - $startTime > 1500) { // 25分钟超时保护
                    throw new \Exception('导出操作超时，请缩小数据范围后重试');
                }
                
                foreach ($items as $item) {
                    $row = [
                        $index + 1,
                        $item->street_town,
                        $item->name,
                        $item->id_number,
                        $item->payment_category,
                        $item->payment_amount,
                        $item->level,
                        $this->convertMatchStatusToChinese($item->level_match_status, 'default'),
                        $this->convertMatchStatusToChinese($item->assistance_identity_match_status, 'default'),
                        $this->convertMatchStatusToChinese($item->street_town_match_status, 'default'),
                        $this->convertMatchStatusToChinese($item->match_status, 'ms')
                    ];
                    fputcsv($handle, $row);
                    $index++;
                }
            });
            
            fclose($handle);
            
            // 使用文件流输出，避免内存问题
            return $response->download($tempFile, $filename);
            
        } catch (\Exception $e) {
            return $this->error('导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 验证保险数据文件格式
     * @RequestMapping(path="validate", methods="post")
     */
    public function validateFile()
    {
        try {
            $file = $this->request->file('file');
            $year = $this->request->input('year');

            if (!$file || !$file->isValid()) {
                return $this->error('请上传有效的文件');
            }

            if (!in_array($file->getExtension(), ['xlsx', 'xls'])) {
                return $this->error('文件格式不正确，只支持 .xlsx 和 .xls 格式');
            }

            // 读取Excel文件
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            
            // 获取第一行所有列的值
            $headerRow = $worksheet->getRowIterator(1)->current();
            $cellIterator = $headerRow->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(true); // 只遍历非空单元格
            
            // 收集表头
            $headers = [];
            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                if (!empty($value)) { // 只添加非空值
                    $headers[] = trim((string)$value);
                }
            }

            // 必要的字段列表
            $requiredFields = [
                '序号',
                '姓名',
                '身份证号',
                '街道乡镇',
                '代缴类别',
                '代缴金额'
            ];

            // 检查必要字段是否都存在
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (!in_array($field, $headers)) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                return $this->error('表格第一行缺少必要字段：' . implode('、', $missingFields));
            }

            return $this->success([
                'headers' => $headers,
                'message' => '文件格式验证通过'
            ]);
        } catch (\Exception $e) {
            return $this->error('验证文件时发生错误：' . $e->getMessage());
        }
    }

    /**
     * 验证导入参保档次匹配数据
     */
    public function validateImportLevelMatch()
    {
        try {
            $file = $this->request->file('file');
            $year = $this->request->input('year');

            if (!$file || !$file->isValid()) {
                return $this->error('请上传有效的文件');
            }

            if (!in_array($file->getExtension(), ['xlsx', 'xls'])) {
                return $this->error('文件格式不正确，只支持 .xlsx 和 .xls 格式');
            }

            // 读取Excel文件
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            
            // 获取第一行所有列的值
            $headerRow = $worksheet->getRowIterator(1)->current();
            $cellIterator = $headerRow->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(true); // 只遍历非空单元格
            
            // 收集表头
            $headers = [];
            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                if (!empty($value)) { // 只添加非空值
                    $headers[] = trim((string)$value);
                }
            }

            // 必要的字段列表
            $requiredFields = [
                '身份证号',
                '个人实缴金额'
            ];

            // 检查必要字段是否都存在
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (!in_array($field, $headers)) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                return $this->error('表格第一行缺少必要字段：' . implode('、', $missingFields));
            }

            return $this->success([
                'headers' => $headers,
                'message' => '文件格式验证通过'
            ]);
        } catch (\Exception $e) {
            return $this->error('验证文件时发生错误：' . $e->getMessage());
        }
    }


    /**
     * 验证导入认定区数据
     */

     public function validateImportStreetTown()
     {
         try {
             $file = $this->request->file('file');
             $year = $this->request->input('year');
 
             if (!$file || !$file->isValid()) {
                 return $this->error('请上传有效的文件');
             }
 
             if (!in_array($file->getExtension(), ['xlsx', 'xls'])) {
                 return $this->error('文件格式不正确，只支持 .xlsx 和 .xls 格式');
             }
 
             // 读取Excel文件
             $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
             $reader->setReadDataOnly(true);
             $spreadsheet = $reader->load($file->getRealPath());
             $worksheet = $spreadsheet->getActiveSheet();
             
             // 获取第一行所有列的值
             $headerRow = $worksheet->getRowIterator(1)->current();
             $cellIterator = $headerRow->getCellIterator();
             $cellIterator->setIterateOnlyExistingCells(true); // 只遍历非空单元格
             
             // 收集表头
             $headers = [];
             foreach ($cellIterator as $cell) {
                 $value = $cell->getValue();
                 if (!empty($value)) { // 只添加非空值
                     $headers[] = trim((string)$value);
                 }
             }
 
             // 必要的字段列表
             $requiredFields = [
                 '身份证号',
                 '认定区',
                 '资助身份'
             ];
 
             // 检查必要字段是否都存在
             $missingFields = [];
             foreach ($requiredFields as $field) {
                 if (!in_array($field, $headers)) {
                     $missingFields[] = $field;
                 }
             }
 
             if (!empty($missingFields)) {
                 return $this->error('表格第一行缺少必要字段：' . implode('、', $missingFields));
             }
 
             return $this->success([
                 'headers' => $headers,
                 'message' => '文件格式验证通过'
             ]);
         } catch (\Exception $e) {
             return $this->error('验证文件时发生错误：' . $e->getMessage());
         }
     }


    /**
     * 获取字段映射关系
     */
    private function getFieldMapping($headers)
    {
        // 定义固定的字段名称
        $fixedFields = [
            '序号',
            '姓名',
            '身份证号',
            '街道乡镇',
            '代缴类别',
            '代缴金额',
            '档次',
            '个人实缴金额',
            '资助身份',
            '认定地'
        ];

        $columnMap = [
            'name' => null,
            'id_number' => null,
            'street_town' => null,
            'payment_category' => null,
            'payment_amount' => null,
            'level' => null,
            'personal_amount'=> null,
            'assistance_identity' => null,
            'street_town_name' => null
        ];


        // 遍历表头，查找对应的字段
        foreach ($headers as $column => $header) {
            $header = trim((string)$header);
            switch ($header) {
                case '姓名':
                    $columnMap['name'] = $column;
                    break;
                case '身份证号':
                case '身份证件号码':
                    $columnMap['id_number'] = $column;
                    break;
                case '身份证件类型':
                    $columnMap['id_type'] = $column;
                    break;
                case '街道乡镇':
                    $columnMap['street_town'] = $column;
                    break;
                case '代缴类别':
                    $columnMap['payment_category'] = $column;
                    break;
                case '代缴金额':
                    $columnMap['payment_amount'] = $column;
                    break;
                case '档次':
                    $columnMap['level'] = $column;
                    break;
                case '认定区':
                    $columnMap['street_town_name'] = $column;
                    break;
                case '个人实缴金额':
                    $columnMap['personal_amount'] = $column;
                    break;
                case '资助身份':
                    $columnMap['assistance_identity'] = $column;
                    break;
            }
        }

        return $columnMap;
    }

    /**
     * 使用批量插入优化处理数据批次
     */
    private function processBatchWithCoroutine($worksheet, $startRow, $endRow, $columnMap, $year, $importType)
    {
        $result = [
            'imported_count' => 0,
            'skipped_count' => 0,
            'error_rows' => [],
            'debug_info' => [
                'total_rows' => $endRow - $startRow + 1,
                'column_map' => $columnMap,
                'start_row' => $startRow,
                'end_row' => $endRow
            ],
            'performance' => [
                'duplicate_check_time' => 0,
                'data_creation_time' => 0,
                'level_matching_time' => 0,
                'batch_insert_time' => 0,
                'batch_count' => 0
            ]
        ];
        
        $duplicateCheckTime = 0.0;
        $dataCreationTime = 0.0;
        $levelMatchingTime = 0.0;
        $batchInsertTime = 0.0;
        $batchCount = 0;
        
        // 批量插入配置
        $batchSize = 100; // 每批次处理100条记录
        $validData = []; // 存储有效数据用于批量插入
        $duplicateIds = []; // 存储重复的身份证号

        // 第一步：批量检查重复记录（仅增量导入）
        if ($importType === 'increment') {
            $checkStart = microtime(true);
            $idNumbers = [];
            
            // 收集所有身份证号
            for ($row = $startRow; $row <= $endRow; $row++) {
                $idCard = isset($columnMap['id_number']) ? 
                    trim((string)$worksheet->getCell($columnMap['id_number'] . $row)->getValue()) : null;
                if (!empty($idCard)) {
                    $idNumbers[] = $idCard;
                }
            }
            
            // 批量查询已存在的记录
            if (!empty($idNumbers)) {
                $existingIds = InsuranceData::where('year', $year)
                    ->whereIn('id_number', $idNumbers)
                    ->pluck('id_number')
                    ->toArray();
                $duplicateIds = array_flip($existingIds);
            }
            
            $duplicateCheckTime = microtime(true) - $checkStart;
        }

        // 第二步：处理数据并收集有效记录
        $dataCreationStart = microtime(true);
        
        for ($row = $startRow; $row <= $endRow; $row++) {
            try {
                // 获取身份证号用于查重
                $idCard = isset($columnMap['id_number']) ? 
                    trim((string)$worksheet->getCell($columnMap['id_number'] . $row)->getValue()) : null;
                
                if (empty($idCard)) {
                    $result['skipped_count']++;
                    $result['error_rows'][] = [
                        'row' => $row,
                        'reason' => '身份证号为空'
                    ];
                    continue;
                }

                // 检查是否重复（使用预查询的结果）
                if ($importType === 'increment' && isset($duplicateIds[$idCard])) {
                    $result['skipped_count']++;
                    continue;
                }

                // 创建新记录
                $data = [
                    'year' => $year,
                    'id_type' => '居民身份证', // 设置默认的证件类型
                ];
                
                foreach ($columnMap as $field => $col) {
                    if ($col !== null) {  // 只处理存在的列
                        try {
                            $cellValue = $worksheet->getCell($col . $row)->getValue();
                            // 对金额字段进行特殊处理
                            if (in_array($field, ['payment_amount'])) {
                                $value = is_numeric($cellValue) ? floatval($cellValue) : 0;
                            } else {
                                $value = $cellValue !== null ? trim((string)$cellValue) : '';
                            }
                            $data[$field] = $value;
                        } catch (\Exception $e) {
                            throw new \Exception("读取单元格 {$col}{$row} 时出错：" . $e->getMessage());
                        }
                    }
                }

                // 验证必填字段
                $requiredFields = [
                    'name' => '姓名',
                    'id_number' => '身份证号',
                    'street_town' => '街道乡镇',
                    'payment_category' => '代缴类别',
                    'payment_amount' => '代缴金额'
                ];
                
                $missingFields = [];
                foreach ($requiredFields as $field => $label) {
                    if (empty($data[$field])) {
                        $missingFields[] = $label;
                    }
                }
                
                if (!empty($missingFields)) {
                    $result['skipped_count']++;
                    $result['error_rows'][] = [
                        'row' => $row,
                        'reason' => '必填字段不能为空：' . implode('、', $missingFields),
                        'data' => $data
                    ];
                    continue;
                }

                // 根据代缴类别和金额匹配档次（使用缓存）
                $levelMatchingStart = microtime(true);
                $levelConfigs = InsuranceLevelConfigCache::findMatchingConfigs(
                    $year, 
                    $data['payment_category'], 
                    $data['payment_amount']
                );
                $levelMatchingTime += microtime(true) - $levelMatchingStart;

                // 记录匹配过程的详细信息（使用缓存）
                $availableConfigs = InsuranceLevelConfigCache::getAvailableConfigs(
                    $year, 
                    $data['payment_category']
                );
                
                $matchLog = [
                    'year' => $year,
                    'payment_category' => $data['payment_category'],
                    'payment_amount' => $data['payment_amount'],
                    'matched_count' => $levelConfigs->count(),
                    'available_configs' => $availableConfigs->map(function($config) {
                        return [
                            'level' => $config->level,
                            'subsidy_amount' => $config->subsidy_amount,
                            'personal_amount' => $config->personal_amount
                        ];
                    })->all()
                ];

                if ($levelConfigs->count() === 1) {
                    // 只有一条匹配记录时，进行完整匹配
                    $levelConfig = $levelConfigs->first();
                    $data['level'] = $levelConfig->level;
                    $data['level_match_status'] = 'matched';
                    $data['personal_amount'] = $levelConfig->personal_amount;
                } else if ($levelConfigs->count() > 1) {
                    // 有多条匹配记录时，不进行匹配
                    $data['level'] = '';  // 改为空字符串而不是 null
                    $data['level_match_status'] = 'unmatched';
                    
                    // 记录匹配到多条的情况
                    $result['error_rows'][] = [
                        'row' => $row,
                        'reason' => '找到多条匹配的档次配置，跳过匹配',
                        'match_log' => $matchLog,
                        'data' => [
                            'payment_category' => $data['payment_category'],
                            'payment_amount' => $data['payment_amount'],
                            'matched_levels' => $levelConfigs->pluck('level')->toArray()
                        ]
                    ];
                } else {
                    // 没有匹配记录
                    $data['level'] = '';  // 改为空字符串而不是 null
                    $data['level_match_status'] = 'unmatched';
                    
                    // 记录未匹配的情况
                    $result['error_rows'][] = [
                        'row' => $row,
                        'reason' => '找不到匹配的档次配置',
                        'match_log' => $matchLog,
                        'data' => [
                            'payment_category' => $data['payment_category'],
                            'payment_amount' => $data['payment_amount']
                        ]
                    ];
                }

                // 添加到批量插入数组
                $validData[] = $data;
                
            } catch (\Exception $e) {
                $result['error_rows'][] = [
                    'row' => $row,
                    'reason' => '数据格式错误：' . $e->getMessage()
                ];
                $result['skipped_count']++;
            }
        }
        
        $dataCreationTime = microtime(true) - $dataCreationStart;

        // 第三步：批量插入数据
        if (!empty($validData)) {
            $batchInsertStart = microtime(true);
            
            // 分批插入，避免单次插入过多数据
            $chunks = array_chunk($validData, $batchSize);
            foreach ($chunks as $chunk) {
                try {
                    InsuranceData::insert($chunk);
                    $result['imported_count'] += count($chunk);
                    $batchCount++;
                } catch (\Exception $e) {
                    // 如果批量插入失败，尝试逐条插入以确定具体错误
                    foreach ($chunk as $index => $data) {
                        try {
                            InsuranceData::create($data);
                            $result['imported_count']++;
                        } catch (\Exception $e2) {
                            $result['error_rows'][] = [
                                'row' => $startRow + $index, // 估算行号
                                'reason' => '数据保存失败：' . $e2->getMessage(),
                                'data' => $data
                            ];
                            $result['skipped_count']++;
                        }
                    }
                }
            }
            
            $batchInsertTime = microtime(true) - $batchInsertStart;
        }

        // 更新性能统计
        $result['performance'] = [
            'duplicate_check_time' => $duplicateCheckTime,
            'data_creation_time' => $dataCreationTime,
            'level_matching_time' => $levelMatchingTime,
            'batch_insert_time' => $batchInsertTime,
            'batch_count' => $batchCount,
            'total_processing_time' => $duplicateCheckTime + $dataCreationTime + $levelMatchingTime + $batchInsertTime
        ];

        return $result;
    }

    /**
     * 导入保险数据
     * @RequestMapping(path="import", methods="post")
     */
    public function importData()
    {
        try {
            $file = $this->request->file('file');
            $year = $this->request->input('year');
            $importType = $this->request->input('import_type', 'increment');

            if (!$file || !$file->isValid()) {
                return $this->error('请上传有效的文件');
            }

            if (!$year) {
                return $this->error('请指定导入年份');
            }

            // 将年份转换为整数类型
            $year = (int) $year;

            // 读取Excel文件 - 性能监控
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            
            // 获取表头
            $headerRow = $worksheet->getRowIterator(1)->current();
            $cellIterator = $headerRow->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(true);
            
            $headers = [];
            $column = 'A';
            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                if (!empty($value)) {
                    $headers[$column] = trim((string)$value);
                }
                $column++;
            }

            // 获取字段映射 
            $columnMap = $this->getFieldMapping($headers);

            // 验证必要字段是否都存在
            $requiredFields = ['name', 'id_number', 'street_town', 'payment_category', 'payment_amount'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if ($columnMap[$field] === null) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                $fieldNames = [
                    'name' => '姓名',
                    'id_number' => '身份证号',
                    'street_town' => '街道乡镇',
                    'payment_category' => '代缴类别',
                    'payment_amount' => '代缴金额'
                ];
                $missingFieldNames = array_map(function($field) use ($fieldNames) {
                    return $fieldNames[$field];
                }, $missingFields);
                return $this->error('Excel文件缺少必要字段：' . implode('、', $missingFieldNames));
            }



            $highestRow = $worksheet->getHighestRow();
 
            // 初始化档次配置缓存
            InsuranceLevelConfigCache::loadConfigsForYear($year);

            // 如果是全量导入，先删除该年份的所有数据
            if ($importType === 'full') {
                $deleteStart = microtime(true);
                $deletedCount = InsuranceData::where('year', $year)->delete();
            }

            // 分批事务处理 - 性能监控
            $transactionStart = microtime(as_float: true);
            $totalImported = 0;
            $totalSkipped = 0;
            $allErrorRows = [];
            $batchSize = 100; // 每批次处理100条记录
            $totalRows = $highestRow - 1;
            $batchCount = 0;
            
            // 分批处理数据
            for ($startRow = 2; $startRow <= $highestRow; $startRow += $batchSize) {
                $endRow = min($startRow + $batchSize - 1, $highestRow);
                $batchCount++;
                
                // 开启小事务
                Db::beginTransaction();
                try {
                    $processingStart = microtime(true);
                    $batchResult = $this->processBatchWithCoroutine($worksheet, $startRow, $endRow, $columnMap, $year, $importType);
                    $processingTime = microtime(true) - $processingStart;
                    
                    // 累计结果
                    $totalImported += $batchResult['imported_count'];
                    $totalSkipped += $batchResult['skipped_count'];
                    $allErrorRows = array_merge($allErrorRows, $batchResult['error_rows']);
                    
                    Db::commit();
                    
                    // 记录批次性能
                    error_log("批次 {$batchCount}: 行 {$startRow}-{$endRow}, 导入 {$batchResult['imported_count']} 条, 跳过 {$batchResult['skipped_count']} 条, 耗时 " . round($processingTime, 3) . " 秒");
                    
                } catch (\Exception $e) {
                    Db::rollBack();
                    // 记录批次失败
                    error_log("批次 {$batchCount} 失败: " . $e->getMessage());
                    $allErrorRows[] = [
                        'row' => "批次 {$batchCount} ({$startRow}-{$endRow})",
                        'reason' => '批次处理失败：' . $e->getMessage()
                    ];
                }
            }
            
            // 合并结果
            $result = [
                'imported_count' => $totalImported,
                'skipped_count' => $totalSkipped,
                'error_rows' => $allErrorRows,
                'debug_info' => [
                    'total_rows' => $totalRows,
                    'batch_count' => $batchCount,
                    'batch_size' => $batchSize
                ]
            ];


            if ($result['imported_count'] > 0) {
                return $this->success($result, '导入成功');
            } else {
                // 即使没有导入成功，也返回详细信息
                $result['total_rows'] = $highestRow - 1;
                return $this->error('导入失败：没有有效数据被导入', $result);
            }
        } catch (\Exception $e) {
            // 记录异常时的性能信息
            return $this->error('导入数据时发生错误：' . $e->getMessage());
        }
    }

    /**
     * 导入保险数据 - 普通导入版本
     */
    public function importDataStream(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $file = $request->file('file');
            $year = $request->input('year');
            $importType = $request->input('import_type', 'increment');

            if (!$file || !$file->isValid()) {
                return $this->error('请上传有效的文件');
            }

            // 验证文件格式
            $allowedExtensions = ['xlsx', 'xls'];
            $fileExtension = strtolower($file->getExtension());
            if (!in_array($fileExtension, $allowedExtensions)) {
                return $this->error('只支持 Excel 文件格式 (.xlsx, .xls)');
            }

            // 验证文件大小（最大 10MB）
            $maxSize = 10 * 1024 * 1024; // 10MB
            if ($file->getSize() > $maxSize) {
                return $this->error('文件大小不能超过 10MB');
            }

            if (!$year) {
                return $this->error('请指定导入年份');
            }

            // 将年份转换为整数类型
            $year = (int) $year;

            // 开启事务
            Db::beginTransaction();
            try {
                // 如果是全量导入，先删除该年份的所有数据
                if ($importType === 'full') {
                    InsuranceData::where('year', $year)->delete();
                }

                // 初始化档次配置缓存
                InsuranceLevelConfigCache::loadConfigsForYear($year);

                // 读取Excel文件
                try {
                    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                    $reader->setReadDataOnly(true);
                    $spreadsheet = $reader->load($file->getRealPath());
                    $worksheet = $spreadsheet->getActiveSheet();
                } catch (\Exception $e) {
                    return $this->error('Excel文件读取失败：' . $e->getMessage());
                }
                
                // 获取表头（从第3行开始，因为前两行是标题和说明）
                $headerRow = $worksheet->getRowIterator(3)->current();
                $cellIterator = $headerRow->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(true);
                
                $headers = [];
                $column = 'A';
                foreach ($cellIterator as $cell) {
                    $value = $cell->getValue();
                    if (!empty($value)) {
                        $headers[$column] = trim((string)$value);
                    }
                    $column++;
                }

                // 获取字段映射
                $columnMap = $this->getFieldMapping($headers);

                // 验证必要字段是否都存在
                $requiredFields = ['name', 'id_number', 'street_town', 'payment_category', 'payment_amount'];
                $missingFields = [];
                foreach ($requiredFields as $field) {
                    if ($columnMap[$field] === null) {
                        $missingFields[] = $field;
                    }
                }

                if (!empty($missingFields)) {
                    $fieldNames = [
                        'name' => '姓名',
                        'id_number' => '身份证号',
                        'street_town' => '街道乡镇',
                        'payment_category' => '代缴类别',
                        'payment_amount' => '代缴金额'
                    ];
                    $missingFieldNames = array_map(function($field) use ($fieldNames) {
                        return $fieldNames[$field];
                    }, $missingFields);
                    
                    return $this->error('Excel文件缺少必要字段：' . implode('、', $missingFieldNames));
                }

                $highestRow = $worksheet->getHighestRow();
                $totalRows = $highestRow - 3; // 减去标题行、说明行和表头行

                // 处理数据
                $result = $this->processBatchWithCoroutine($worksheet, 4, $highestRow, $columnMap, $year, $importType);

                Db::commit();

                return $this->success($result, "导入完成，成功{$result['imported_count']}条，跳过{$result['skipped_count']}条");

            } catch (\Exception $e) {
                Db::rollBack();
                return $this->error('导入失败：' . $e->getMessage());
            }
        } catch (\Exception $e) {
            return $this->error('导入数据时发生错误：' . $e->getMessage());
        }
    }





    /**
     * 下载导入模板
     */
    public function downloadTemplate()
    {
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // 设置表头
            $headers = [
                'A1' => '序号',
                'B1' => '姓名',
                'C1' => '身份证号',
                'D1' => '街道乡镇',
                'E1' => '代缴类别',
                'F1' => '代缴金额',
                'G1' => '档次'
            ];

            foreach ($headers as $cell => $value) {
                $sheet->setCellValue($cell, $value);
            }

            // 添加示例数据
            $examples = [
                ['1', '张三', '510000199001011234', '某街道', '民政城乡低保对象', '360.00', '居民一档'],
                ['2', '李四', '510000199001021234', '某乡镇', '民政城乡低保对象', '400.00', '居民二档'],
                ['3', '王五', '510000199001031234', '某街道', '民政城乡孤儿', '400.00', '居民一档']
            ];

            $row = 2;
            foreach ($examples as $example) {
                $sheet->fromArray($example, null, "A{$row}");
                $row++;
            }

            // 设置列宽
            $sheet->getColumnDimension('A')->setWidth(10);
            $sheet->getColumnDimension('B')->setWidth(15);
            $sheet->getColumnDimension('C')->setWidth(20);
            $sheet->getColumnDimension('D')->setWidth(20);
            $sheet->getColumnDimension('E')->setWidth(25);
            $sheet->getColumnDimension('F')->setWidth(15);
            $sheet->getColumnDimension('G')->setWidth(15);

            // 生成文件
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $filename = '参保数据导入模板.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), 'template');
            $writer->save($tempFile);

            $fileContent = file_get_contents($tempFile);
            unlink($tempFile); // 删除临时文件

            return (new \Hyperf\HttpServer\Response())->withHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('content-disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('content-length', strlen($fileContent))
                ->withBody(new \Hyperf\HttpMessage\Stream\SwooleStream($fileContent));
        } catch (\Exception $e) {
            return $this->error('下载模板失败: ' . $e->getMessage());
        }
    }

    /**
     * 认定区认证身份匹配数据
     * @param RequestInterface $request
     * @return array
     */
    public function importStreetTown(RequestInterface $request)
    {
        try {
            $file = $request->file('file');
            if (!$file || !$file->isValid()) {
                return $this->error('请上传有效的文件');
            }

            $year = $request->input('year', date('Y'));
            
            // 读取Excel文件
            $spreadsheet = IOFactory::load($file->getPath() . '/' . $file->getFilename());
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();

            // 获取表头
            $headerRow = $worksheet->getRowIterator(1)->current();
            $cellIterator = $headerRow->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(true);
            $headers = [];
            $column = 'A';
            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                if (!empty($value)) {
                    $headers[$column] = trim((string)$value);
                }
                $column++;
            }
            // 获取字段映射 
            $columnMap = $this->getFieldMapping($headers);

            // 验证必要字段是否都存在
            $requiredFields = ['id_number', 'assistance_identity','street_town_name'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if ($columnMap[$field] === null) {
                    $missingFields[] = $field;
                }
            }
            if (!empty($missingFields)) {
                $fieldNames = [
                    'id_number' => '身份证号',
                    'assistance_identity' => '资助身份',
                    'street_town_name' => '认定地'
                ];
                $missingFieldNames = array_map(function($field) use ($fieldNames) {
                    return $fieldNames[$field];
                }, $missingFields);
                return $this->error('Excel文件缺少必要字段：' . implode('、', $missingFieldNames));
            }
  
            
            // 查询category_conversions表中所有数据，使用自己的Model
            $categoryConversions = \App\Model\CategoryConversion::query()->get()->toArray();

            // 移除表头
            array_shift($data);
            

            $assistanceSuccessCount = 0;
            $streetTownSuccessCount = 0;
            $assistanceFailCount = 0;
            $streetTownFailCount = 0;
            $failCount = 0;
            $errors = [];
            $totalRows = count($data);

            foreach ($data as $index => $row) {
                try {
                    // 根据字段映射自动获取对应列的数据
                    $idCol = $columnMap['id_number'];
                    $assistanceIdentityCol = $columnMap['assistance_identity'];
                    $streetTownNameCol = $columnMap['street_town_name'];
                    $idNumber = $worksheet->getCell($idCol . $index)->getValue();
                    $assistanceIdentity = $worksheet->getCell($assistanceIdentityCol . $index)->getValue();
                    $streetTownName = $worksheet->getCell($streetTownNameCol . $index)->getValue();
   

                    if (empty($idNumber)) {
                        $failCount++;
                        $errors[] = "第" . ($index + 2) . "行：身份证号为空，已跳过";
                        continue;
                    }

                    // 查找对应的参保数据
                    $insuranceData = InsuranceData::where('id_number', $idNumber)
                        ->where('year', $year)
                        ->first();

                    if (!$insuranceData) {
                        $errors[] = "第" . ($index + 2) . "行：未找到身份证号为 {$idNumber} 的参保数据";
                        $failCount++;
                        continue;
                    }

                    // 根据医疗救助匹配身份
                    if(!empty($assistanceIdentity)){
                        // 判断$assistanceIdentity是否在$categoryConversions的任一项中
                        $isMatched = false;
                        foreach ($categoryConversions as $conversion) {
                            if (
                                (isset($conversion['name']) && $conversion['name'] == $assistanceIdentity) ||
                                (isset($conversion['tax_standard']) && $conversion['tax_standard'] == $assistanceIdentity)
                                /*||
                                (isset($conversion['medical_export_standard']) && $conversion['medical_export_standard'] == $assistanceIdentity) ||
                                (isset($conversion['national_dict_name']) && $conversion['national_dict_name'] == $assistanceIdentity)
                                 */
                                ) {
                                $isMatched = true;
                                break;
                            }
                        }
                        if ($isMatched) {
                            $insuranceData->assistance_identity_match_status = 'matched';
                        } else {
                            $insuranceData->assistance_identity_match_status = 'unmatched';
                        }

                        $insuranceData->assistance_identity = $assistanceIdentity;
                        $insuranceData->save();
                        $assistanceSuccessCount++;
      
                    }else{
                        $errors[] = "第" . ($index + 2) . "行：资助身份为空, 已跳过";
                        $assistanceFailCount++;
                    }

                    // 根据认定区匹配档次
                    if(!empty($streetTownName) && $streetTownName == "江津区"){
                        $insuranceData->street_town_name = $streetTownName;
                        $insuranceData->street_town_match_status = 'matched';
                        $insuranceData->save();
                        $streetTownSuccessCount++;
                    }else{
                        $insuranceData->street_town_name = $streetTownName;
                        $insuranceData->street_town_match_status = 'unmatched';
                        $insuranceData->save();
                        $errors[] = "第" . ($index + 2) . "行：认定区不匹配";
                        $streetTownFailCount++;
                    }

                    if($insuranceData->street_town_match_status == 'matched' && 
                        $insuranceData->assistance_identity_match_status == 'matched' && 
                        $insuranceData->level_match_status == 'matched'
                    ){
                        $insuranceData->match_status = 'matched';
                        $insuranceData->save();
                    }else{
                        $insuranceData->match_status = 'unmatched';
                        $insuranceData->save();
                        $failCount++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "第" . ($index + 2) . "行：处理失败 - " . $e->getMessage();
                    $failCount++;
                }
            }

            return $this->success([
                'assistance_success_count' => $assistanceSuccessCount,
                'street_town_success_count' => $streetTownSuccessCount,
                'assistance_fail_count' => $assistanceFailCount,
                'street_town_fail_count' => $streetTownFailCount,
                'fail_count' => $failCount,
                'total_rows' => $totalRows,
                'errors' => $errors
            ], "总匹配{$totalRows}条，已匹配上{$assistanceSuccessCount}条，已忽略{$assistanceFailCount}条");
        } catch (\Exception $e) {
            return $this->error('导入失败：' . $e->getMessage());
        }
    }

       /**
     * 导入参保档次匹配数据
     * @param RequestInterface $request
     * @return array
     */
    public function importLevelMatch(RequestInterface $request)
    {
        try {
            $file = $request->file('file');
            if (!$file || !$file->isValid()) {
                return $this->error('请上传有效的文件');
            }

            $year = $request->input('year', date('Y'));
            
            // 读取Excel文件
            $spreadsheet = IOFactory::load($file->getPath() . '/' . $file->getFilename());
            $worksheet = $spreadsheet->getActiveSheet();
            $data = $worksheet->toArray();
            
            // 获取表头
            $headerRow = $worksheet->getRowIterator(1)->current();
            $cellIterator = $headerRow->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(true);
            $headers = [];
            $column = 'A';
            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                if (!empty($value)) {
                    $headers[$column] = trim((string)$value);
                }
                $column++;
            }
            // 获取字段映射 
            $columnMap = $this->getFieldMapping($headers);
            // 验证必要字段是否都存在
            $requiredFields = ['id_number', 'personal_amount'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if ($columnMap[$field] === null) {
                    $missingFields[] = $field;
                }
            }
            if (!empty($missingFields)) {
                $fieldNames = [
                    'id_number' => '身份证号',
                    'personal_amount' => '个人实缴金额'
                ];
                $missingFieldNames = array_map(function($field) use ($fieldNames) {
                    return $fieldNames[$field];
                }, $missingFields);
                return $this->error('Excel文件缺少必要字段：' . implode('、', $missingFieldNames));
            }

            $highestRow = $worksheet->getHighestRow();

            // 移除表头
            array_shift($data);
            
            $successCount = 0;
            $failCount = 0;
            $errors = [];
            $totalRows = count($data);

            // 获取参保档次配置
            $levelConfigs = InsuranceLevelConfig::where('year', $year)
                ->get()
                ->groupBy('payment_category')
                ->toArray();

                
            foreach ($data as $index => $row) {
                try {
                    // 根据字段映射自动获取对应列的数据
                    $idCol = $columnMap['id_number'];
                    $personalAmountCol = $columnMap['personal_amount'];
                    $idNumber = $worksheet->getCell($idCol . $index)->getValue();
                    $personalPayment = $worksheet->getCell($personalAmountCol . $index)->getValue();

                    if (empty($idNumber)) {
                        $failCount++;
                        $errors[] = "第" . ($index + 2) . "行：身份证号为空，已跳过";
                        continue;
                    }

                    // 查找对应的参保数据
                    $insuranceData = InsuranceData::where('id_number', $idNumber)
                        ->where('year', $year)
                        ->first();

                    if (!$insuranceData) {
                        $errors[] = "第" . ($index + 2) . "行：未找到身份证号为 {$idNumber} 的参保数据";
                        $failCount++;
                        continue;
                    }

                    // 根据代缴类别和个人实缴金额匹配档次
                    $paymentCategory = $insuranceData->payment_category;
                    $matchedLevel = null;

                    if (isset($levelConfigs[$paymentCategory])) {
                        foreach ($levelConfigs[$paymentCategory] as $config) {
                            if ($personalPayment == $config['personal_payment']) {
                                $matchedLevel = $config['level'];
                                break;
                            }
                        }
                    }

                    if ($matchedLevel === null) {
                        $errors[] = "第" . ($index + 2) . "行：无法匹配档次，代缴类别：{$paymentCategory}，个人实缴金额：{$personalPayment}";
                        $failCount++;
                        continue;
                    }

                    // 更新参保数据
                    $insuranceData->level = $matchedLevel;
                    $insuranceData->level_match_status = 'matched';
                    $insuranceData->personal_amount = floatval($personalPayment);
                    $insuranceData->save();



                    if($insuranceData->street_town_match_status == 'matched' && 
                        $insuranceData->assistance_identity_match_status == 'matched' && 
                        $insuranceData->level_match_status == 'matched'
                    ){
                        $insuranceData->match_status = 'matched';
                        $insuranceData->save();
                        continue;
                    }

                    $successCount++;
                } catch (\Exception $e) {
                    $errors[] = "第" . ($index + 2) . "行：处理失败 - " . $e->getMessage();
                    $failCount++;
                }
            }

            return $this->success([
                'success_count' => $successCount,
                'fail_count' => $failCount,
                'total_rows' => $totalRows,
                'errors' => $errors
            ], "总匹配{$totalRows}条，已匹配上{$successCount}条，已忽略{$failCount}条");
        } catch (\Exception $e) {
            return $this->error('导入失败：' . $e->getMessage());
        }
    }

    /**
     * 将匹配状态转换为中文显示
     * 
     * @param string|null $status
     * @return string
     */
    private function convertMatchStatusToChinese($status, $type = 'ms'): string
    {
        if($type === 'ms'){
            switch ($status) {
                case 'matched':
                    return '正常数据';
                case 'unmatched':
                    return '疑点数据';
                case null:
                case '':
                    default:
                        return '未处理';
                }
        }else{
            switch ($status) {
                case 'matched':
                    return '已匹配';
                case 'unmatched':
                    return '未匹配';
                case null:
                case '':
                    return '未处理';
            }
        }
    }
} 