<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\InsuranceData;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\DbConnection\Db;
use App\Model\InsuranceLevelConfig;
use App\Service\InsuranceLevelConfigCache;
use App\Job\InsuranceDataExportJob;
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

        $count = InsuranceData::whereIn('id', $data['ids'])->update(
            (new InsuranceData())->prepareAttributesForStorage($updateData)
        );

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
        $allowedTypes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
            'text/plain'
        ];
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, $allowedTypes)) {
            return $this->error('请上传有效的文件（.xlsx, .xls 或 .csv 格式），当前文件类型：' . $mimeType);
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
     * 导出参保数据匹配结果（异步任务）
     */
    public function export(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $userId = (int) $request->getAttribute('userId', 0);
            $username = $request->getAttribute('username', 'System');
            $uid = $userId ?: 0;

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

            // 预检查数据量
            $query = InsuranceData::query();
            if (!empty($filters['year'])) {
                $query->where('year', $filters['year']);
            }
            if (!empty($filters['street_town'])) {
                $query->where('street_town', $filters['street_town']);
            }
            if (!empty($filters['name'])) {
                $query->whereBlind('name', (string) $filters['name']);
            }
            if (!empty($filters['id_number'])) {
                $query->whereBlind('id_number', (string) $filters['id_number']);
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

            $count = $query->count();
            if ($count === 0) {
                return $this->error('当前筛选条件下没有可导出的数据');
            }

            $params = [
                'uid' => $uid,
                'filters' => $filters,
            ];

            // 使用 TaskService 创建任务并投递队列
            $lockKey = sprintf('task:lock:%d:exportInsuranceData', $uid);
            $uuid = \App\Service\TaskService::instance()->dispatchTask(
                '参保数据_导出_匹配结果_',
                $uid,
                $username,
                InsuranceDataExportJob::class,
                [$params, '%UUID%'],
                $lockKey
            );

            if ($uuid === false) {
                return $this->error('任务正在执行中，请在任务中心查看进度');
            }

            return $this->success([
                'uuid' => $uuid
            ], '导出任务已提交，请在任务中心查看进度');

        } catch (\Exception $e) {
            return $this->error('导出失败: ' . $e->getMessage());
        }
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
                $query->whereBlind('name', (string) $filters['name']);
            }
            if (!empty($filters['id_number'])) {
                $query->whereBlind('id_number', (string) $filters['id_number']);
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

            if ($file->getExtension() !== 'csv') {
                return $this->error('文件格式不正确，只支持 .csv 格式');
            }

            // 使用 CsvReaderService 读取表头
            $csvReader = new \App\Service\CsvReaderService();
            $tempFile = $file->getPathname();
            $headers = $csvReader->getHeaders($tempFile);

            // 必要的字段列表
            $requiredFields = [
                '序号',
                '姓名',
                '身份证号',
                '街道乡镇',
                '代缴类别',
                '代缴金额'
            ];

            // 检查必要字段是否都存在（模糊匹配）
            $missingFields = [];
            foreach ($requiredFields as $field) {
                $found = false;
                foreach ($headers as $header) {
                    if (mb_strpos($header, $field) !== false || mb_strpos($field, $header) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                $debugHeaders = !empty($headers) ? implode('、', $headers) : '[未识别到表头]';
                return $this->error('表格第一行缺少必要字段：' . implode('、', $missingFields) . '。当前识别到的表头为：' . $debugHeaders);
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

            if ($file->getExtension() !== 'csv') {
                return $this->error('文件格式不正确，只支持 .csv 格式');
            }

            // 使用 CsvReaderService 读取表头
            $csvReader = new \App\Service\CsvReaderService();
            $tempFile = $file->getPathname();
            $headers = $csvReader->getHeaders($tempFile);

            // 必要的字段列表
            $requiredFields = [
                '身份证号',
                '个人实缴金额'
            ];

            // 检查必要字段是否都存在（模糊匹配）
            $missingFields = [];
            foreach ($requiredFields as $field) {
                $found = false;
                foreach ($headers as $header) {
                    if (mb_strpos($header, $field) !== false || mb_strpos($field, $header) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                $debugHeaders = !empty($headers) ? implode('、', $headers) : '[未识别到表头]';
                return $this->error('表格第一行缺少必要字段：' . implode('、', $missingFields) . '。当前识别到的表头为：' . $debugHeaders);
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

            if ($file->getExtension() !== 'csv') {
                return $this->error('文件格式不正确，只支持 .csv 格式');
            }

            // 使用 CsvReaderService 读取表头
            $csvReader = new \App\Service\CsvReaderService();
            $tempFile = $file->getPathname();
            $headers = $csvReader->getHeaders($tempFile);

            // 必要的字段列表
            $requiredFields = [
                '身份证号',
                '认定区',
                '资助身份'
            ];

            // 检查必要字段是否都存在（模糊匹配）
            $missingFields = [];
            foreach ($requiredFields as $field) {
                $found = false;
                foreach ($headers as $header) {
                    if (mb_strpos($header, $field) !== false || mb_strpos($field, $header) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $missingFields[] = $field;
                }
            }

            if (!empty($missingFields)) {
                $debugHeaders = !empty($headers) ? implode('、', $headers) : '[未识别到表头]';
                return $this->error('表格第一行缺少必要字段：' . implode('、', $missingFields) . '。当前识别到的表头为：' . $debugHeaders);
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
            'personal_amount' => null,
            'assistance_identity' => null,
            'street_town_name' => null
        ];


        // 遍历表头，查找对应的字段
        foreach ($headers as $column => $header) {
            $header = trim((string) $header);
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
    protected function processBatchWithCoroutine($worksheet, int $startRow, int $endRow, array $columnMap, int $year, string $importType): array
    {
        $result = [
            'imported_count' => 0,
            'skipped_count' => 0,
            'error_rows' => [],
            'debug_info' => [
                'total_rows' => $endRow - $startRow + 1,
            ],
            'performance' => []
        ];

        $duplicateCheckTime = 0;
        $dataCreationTime = 0;
        $levelMatchingTime = 0;
        $batchInsertTime = 0;
        $batchCount = 0;

        $validData = []; // 存储有效数据用于批量插入
        $duplicateIds = []; // 存储重复的身份证号
        $batchSize = 500;

        if ($worksheet instanceof \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet) {
            // ============ Excel 分支 ============
            // 第一步：预查重（仅针对增量导入）
            if ($importType === 'increment') {
                $checkStart = microtime(true);
                $idNumbers = [];
                for ($rowIdx = $startRow; $rowIdx <= $endRow; $rowIdx++) {
                    $idCard = isset($columnMap['id_number']) ?
                        trim((string) $worksheet->getCell($columnMap['id_number'] . $rowIdx)->getValue()) : null;
                    if (!empty($idCard)) {
                        $idNumbers[] = $idCard;
                    }
                }
                if (!empty($idNumbers)) {
                    $existingIds = \App\Model\InsuranceData::where('year', $year)
                        ->whereBlindIn('id_number', $idNumbers)
                        ->get(['id_number'])
                        ->pluck('id_number')
                        ->toArray();
                    $duplicateIds = array_flip($existingIds);
                }
                $duplicateCheckTime = microtime(true) - $checkStart;
            }

            $dataCreationStart = microtime(true);
            for ($rowIdx = $startRow; $rowIdx <= $endRow; $rowIdx++) { // 使用 $rowIdx 避免混淆
                try {
                    $idCard = isset($columnMap['id_number']) ? trim((string) $worksheet->getCell($columnMap['id_number'] . $rowIdx)->getValue()) : null;
                    if (empty($idCard)) {
                        $result['skipped_count']++;
                        $result['error_rows'][] = ['row' => $rowIdx, 'reason' => '身份证号为空'];
                        continue;
                    }
                    if ($importType === 'increment' && isset($duplicateIds[$idCard])) {
                        $result['skipped_count']++;
                        continue;
                    }

                    $data = ['year' => $year, 'id_type' => '居民身份证'];
                    foreach ($columnMap as $field => $col) {
                        if ($col !== null) {
                            $cellValue = $worksheet->getCell($col . $rowIdx)->getValue();
                            if (in_array($field, ['payment_amount'])) {
                                $value = is_numeric($cellValue) ? floatval($cellValue) : 0;
                            } else {
                                $value = $cellValue !== null ? trim((string) $cellValue) : '';
                            }
                            $data[$field] = $value;
                        }
                    }

                    // 调用统一的后续处理逻辑
                    $this->handleRowPostProcessing($data, $rowIdx, $result, $validData, $year, $levelMatchingTime);

                } catch (\Exception $e) {
                    $result['error_rows'][] = ['row' => $rowIdx, 'reason' => '数据处理异常：' . $e->getMessage()];
                    $result['skipped_count']++;
                }
            }
        } else {
            // ============ CSV 分支 ============
            // 第一步：预查重
            if ($importType === 'increment') {
                $checkStart = microtime(true);
                $idNumbers = [];
                foreach ($worksheet as $item) {
                    $row = $item['row_data'];
                    $idCard = isset($columnMap['id_number']) ? trim((string) ($row[$columnMap['id_number']] ?? '')) : null;
                    if (!empty($idCard)) {
                        $idNumbers[] = $idCard;
                    }
                }
                if (!empty($idNumbers)) {
                    $existingIds = \App\Model\InsuranceData::where('year', $year)
                        ->whereBlindIn('id_number', $idNumbers)
                        ->get(['id_number'])
                        ->pluck('id_number')
                        ->toArray();
                    $duplicateIds = array_flip($existingIds);
                }
                $duplicateCheckTime = microtime(true) - $checkStart;
            }

            $dataCreationStart = microtime(true);
            foreach ($worksheet as $item) {
                $row = $item['row_data'];
                $rowIdx = $item['row_idx'];
                try {
                    $idCard = isset($columnMap['id_number']) ? trim((string) ($row[$columnMap['id_number']] ?? '')) : null;
                    if (empty($idCard)) {
                        $result['skipped_count']++;
                        $result['error_rows'][] = ['row' => $rowIdx, 'reason' => '身份证号为空'];
                        continue;
                    }
                    if ($importType === 'increment' && isset($duplicateIds[$idCard])) {
                        $result['skipped_count']++;
                        continue;
                    }

                    $data = ['year' => $year, 'id_type' => '居民身份证'];
                    foreach ($columnMap as $field => $colIdx) {
                        if ($colIdx !== null) {
                            $cellValue = $row[$colIdx] ?? '';
                            if (in_array($field, ['payment_amount'])) {
                                $value = is_numeric($cellValue) ? floatval($cellValue) : 0;
                            } else {
                                $value = trim((string) $cellValue);
                            }
                            $data[$field] = $value;
                        }
                    }

                    // 调用统一的后续处理逻辑
                    $this->handleRowPostProcessing($data, $rowIdx, $result, $validData, $year, $levelMatchingTime);

                } catch (\Exception $e) {
                    $result['error_rows'][] = ['row' => $rowIdx, 'reason' => '数据处理异常：' . $e->getMessage()];
                    $result['skipped_count']++;
                }
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
                    $encryptedChunk = array_map(static function (array $data): array {
                        $model = new InsuranceData($data);
                        $attributes = $model->getAttributes();
                        $now = date('Y-m-d H:i:s');
                        $attributes['created_at'] = $attributes['created_at'] ?? $now;
                        $attributes['updated_at'] = $attributes['updated_at'] ?? $now;
                        return $attributes;
                    }, $chunk);
                    InsuranceData::insert($encryptedChunk);
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
     * 统一处理行数据的验证、匹配和加入待插入队列
     */
    protected function handleRowPostProcessing(array &$data, $rowIdx, array &$result, array &$validData, int $year, &$levelMatchingTime)
    {
        // 验证必填字段
        $requiredFields = [
            'name' => '姓名',
            'id_number' => '身份证号',
            'street_town' => '街道乡镇',
            'payment_category' => '代缴类别',
            'payment_amount' => '代缴金额'
        ];
        foreach ($requiredFields as $field => $label) {
            $val = $data[$field] ?? '';
            if ($val === '' || $val === null) {
                $result['skipped_count']++;
                $result['error_rows'][] = ['row' => $rowIdx, 'reason' => "必填字段 {$label} 不能为空"];
                return;
            }
        }

        // 根据代缴类别和金额匹配档次（使用缓存）
        $levelMatchingStart = microtime(true);
        $levelConfigs = InsuranceLevelConfigCache::findMatchingConfigs(
            $year,
            $data['payment_category'],
            $data['payment_amount']
        );
        $levelMatchingTime += microtime(true) - $levelMatchingStart;

        if ($levelConfigs->count() === 1) {
            $levelConfig = $levelConfigs->first();
            $data['level'] = $levelConfig->level;
            $data['level_match_status'] = 'matched';
            $data['personal_amount'] = $levelConfig->personal_amount;
        } else {
            $data['level'] = '';
            $data['level_match_status'] = 'unmatched';
        }

        $validData[] = $data;
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

            // 获取文件信息
            $filePath = $file->getRealPath();
            $fileExtension = strtolower($file->getExtension());

            // 检查是否是 CSV
            $isCsv = $fileExtension === 'csv' || $file->getMimeType() === 'text/csv' || $file->getMimeType() === 'text/plain';

            $columnMap = [];
            $headers = [];

            if ($isCsv) {
                $csvReader = new \App\Service\CsvReaderService();
                $headers = $csvReader->getHeaders($filePath);
                $columnMap = $this->getFieldMapping($headers);
            } else {
                // 读取Excel文件 - 性能监控
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();

                // 获取表头
                $headerRow = $worksheet->getRowIterator(1)->current();
                $cellIterator = $headerRow->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(true);

                $column = 0;
                foreach ($cellIterator as $cell) {
                    $value = $cell->getValue();
                    if (!empty($value)) {
                        $headers[$column] = trim((string) $value);
                    }
                    $column++;
                }

                // 获取字段映射 
                $columnMap = $this->getFieldMapping($headers);
            }

            // 验证必要字段是否都存在
            $requiredFields = ['name', 'id_number', 'street_town', 'payment_category', 'payment_amount'];
            $missingFields = [];
            foreach ($requiredFields as $field) {
                if (($columnMap[$field] ?? null) === null) {
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
                $missingFieldNames = array_map(function ($field) use ($fieldNames) {
                    return $fieldNames[$field];
                }, $missingFields);
                return $this->error('文件缺少必要字段：' . implode('、', $missingFieldNames));
            }

            // 初始化档次配置缓存
            InsuranceLevelConfigCache::loadConfigsForYear((int) $year);

            // 如果是全量导入，先删除该年份的所有数据
            if ($importType === 'full') {
                InsuranceData::where('year', (int) $year)->delete();
            }

            $totalImported = 0;
            $totalSkipped = 0;
            $allErrorRows = [];
            $batchSize = 100;
            $totalRows = 0;

            // 初始化档次配置缓存
            InsuranceLevelConfigCache::loadConfigsForYear((int) $year);

            // 保存文件供异步 Job 处理
            $uploadDir = BASE_PATH . '/storage/uploads/';
            if (!is_dir($uploadDir))
                mkdir($uploadDir, 0755, true);
            $newFileName = 'insurance_import_' . time() . '_' . uniqid() . '.' . $fileExtension;
            $finalPath = $uploadDir . $newFileName;

            // 如果是流上传，可能没有 getRealPath
            if (method_exists($file, 'moveTo')) {
                $file->moveTo($finalPath);
            } else {
                file_put_contents($finalPath, file_get_contents($filePath));
            }

            // 投递异步任务
            $userId = (int) $this->request->getAttribute('userId', 0);
            $username = (string) $this->request->getAttribute('username', 'System');

            $lockKey = sprintf('task:lock:%d:importInsuranceData', $userId);
            $uuid = \App\Service\TaskService::instance()->dispatchTask(
                '参保数据导入_',
                $userId,
                $username,
                \App\Job\InsuranceDataImportJob::class,
                [
                    [], // 基础参数
                    $finalPath,
                    (int) $year,
                    (string) $importType,
                    $columnMap,
                    1
                ],
                $lockKey
            );

            if ($uuid === false) {
                return $this->error('导入任务正在执行中，请在任务中心查看进度');
            }

            return $this->success([
                'uuid' => $uuid
            ], '导入任务已提交，请在任务中心查看进度');
        } catch (\Exception $e) {
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
            $fileExtension = strtolower($file->getExtension());
            $isCsv = $fileExtension === 'csv' || $file->getMimeType() === 'text/csv' || $file->getMimeType() === 'text/plain';

            if (!$isCsv && !in_array($fileExtension, ['xlsx', 'xls'])) {
                return $this->error('只支持 Excel 或 CSV 文件格式');
            }

            // 验证文件大小（最大 128MB）
            $maxSize = 128 * 1024 * 1024;
            if ($file->getSize() > $maxSize) {
                return $this->error('文件大小不能超过 128MB');
            }

            $year = (int) $year;

            // 初始化档次配置缓存
            InsuranceLevelConfigCache::loadConfigsForYear($year);

            if ($importType === 'full') {
                InsuranceData::where('year', $year)->delete();
            }

            $columnMap = [];
            $headers = [];
            $filePath = $file->getRealPath();

            if ($isCsv) {
                $csvReader = new \App\Service\CsvReaderService();
                $headers = $csvReader->getHeaders($filePath);
                $columnMap = $this->getFieldMapping($headers);
            } else {
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                $reader->setReadDataOnly(true);
                $spreadsheet = $reader->load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();

                // 获取表头（从第3行开始）
                $headerRow = $worksheet->getRowIterator(3)->current();
                $cellIterator = $headerRow->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(true);
                $column = 0;
                foreach ($cellIterator as $cell) {
                    $value = $cell->getValue();
                    if (!empty($value)) {
                        $headers[$column] = trim((string) $value);
                    }
                    $column++;
                }
                $columnMap = $this->getFieldMapping($headers);
            }

            if (empty($columnMap['id_number'])) {
                return $this->error('文件缺少必要字段：身份证号');
            }

            // 保存文件供异步 Job 处理
            $uploadDir = BASE_PATH . '/storage/uploads/';
            if (!is_dir($uploadDir))
                mkdir($uploadDir, 0755, true);
            $newFileName = 'insurance_stream_import_' . time() . '_' . uniqid() . '.' . $fileExtension;
            $finalPath = $uploadDir . $newFileName;

            if (method_exists($file, 'moveTo')) {
                $file->moveTo($finalPath);
            } else {
                file_put_contents($finalPath, file_get_contents($filePath));
            }

            // 投递异步任务
            $userId = (int) $this->request->getAttribute('userId', 0);
            $username = (string) $this->request->getAttribute('username', 'System');

            $lockKey = sprintf('task:lock:%d:importInsuranceData', $userId);
            $uuid = \App\Service\TaskService::instance()->dispatchTask(
                '参保数据导入_',
                $userId,
                $username,
                \App\Job\InsuranceDataImportJob::class,
                [
                    [], // 基础参数
                    $finalPath,
                    (int) $year,
                    (string) $importType,
                    $columnMap,
                    $isCsv ? 1 : 3
                ],
                $lockKey
            );

            if ($uuid === false) {
                return $this->error('导入任务正在执行中，请在任务中心查看进度');
            }

            return $this->success([
                'uuid' => $uuid
            ], '导入任务已提交，请在任务中心查看进度');
        } catch (\Exception $e) {
            return $this->error('导入失败：' . $e->getMessage());
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

            // 使用 CsvReaderService 读取数据
            $csvReader = new \App\Service\CsvReaderService();
            $tempFile = $file->getPathname();
            $headers = $csvReader->getHeaders($tempFile);

            // 获取字段映射 
            $columnMap = $this->getFieldMapping($headers);

            // 验证必要字段是否都存在
            $requiredFields = ['id_number', 'assistance_identity', 'street_town_name'];
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
                $missingFieldNames = array_map(function ($field) use ($fieldNames) {
                    return $fieldNames[$field] ?? $field;
                }, $missingFields);
                return $this->error('CSV文件缺少必要字段：' . implode('、', $missingFieldNames));
            }

            // 保存文件供异步 Job 处理
            $uploadDir = BASE_PATH . '/storage/uploads/';
            if (!is_dir($uploadDir))
                mkdir($uploadDir, 0755, true);
            $newFileName = 'insurance_street_town_' . time() . '_' . uniqid() . '.csv';
            $finalPath = $uploadDir . $newFileName;
            $file->moveTo($finalPath);

            // 投递异步任务
            $userId = (int) $this->request->getAttribute('userId', 0);
            $username = (string) $this->request->getAttribute('username', 'System');

            $lockKey = sprintf('task:lock:%d:importStreetTown', $userId);
            $uuid = \App\Service\TaskService::instance()->dispatchTask(
                '区划身份匹配_',
                $userId,
                $username,
                \App\Job\InsuranceDataUpdateJob::class,
                [
                    [], // 基础参数
                    $finalPath,
                    (int) $year,
                    $columnMap,
                    'street_town'
                ],
                $lockKey
            );

            if ($uuid === false) {
                return $this->error('匹配任务正在执行中，请在任务中心查看进度');
            }

            return $this->success([
                'uuid' => $uuid
            ], '匹配任务已提交，请在任务中心查看进度');
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

            // 使用 CsvReaderService 读取数据
            $csvReader = new \App\Service\CsvReaderService();
            $tempFile = $file->getPathname();
            $headers = $csvReader->getHeaders($tempFile);

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
                $missingFieldNames = array_map(function ($field) use ($fieldNames) {
                    return $fieldNames[$field] ?? $field;
                }, $missingFields);
                return $this->error('CSV文件缺少必要字段：' . implode('、', $missingFieldNames));
            }

            // 保存文件供异步 Job 处理
            $uploadDir = BASE_PATH . '/storage/uploads/';
            if (!is_dir($uploadDir))
                mkdir($uploadDir, 0755, true);
            $newFileName = 'insurance_level_match_' . time() . '_' . uniqid() . '.csv';
            $finalPath = $uploadDir . $newFileName;
            $file->moveTo($finalPath);

            // 投递异步任务
            $userId = (int) $this->request->getAttribute('userId', 0);
            $username = (string) $this->request->getAttribute('username', 'System');

            $lockKey = sprintf('task:lock:%d:importLevelMatch', $userId);
            $uuid = \App\Service\TaskService::instance()->dispatchTask(
                '档次金额匹配_',
                $userId,
                $username,
                \App\Job\InsuranceDataUpdateJob::class,
                [
                    [], // 基础参数
                    $finalPath,
                    (int) $year,
                    $columnMap,
                    'level_match'
                ],
                $lockKey
            );

            if ($uuid === false) {
                return $this->error('匹配任务正在执行中，请在任务中心查看进度');
            }

            return $this->success([
                'uuid' => $uuid
            ], '匹配任务已提交，请在任务中心查看进度');
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
        if ($type === 'ms') {
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
        } else {
            switch ($status) {
                case 'matched':
                    return '已匹配';
                case 'unmatched':
                    return '未匹配';
                case null:
                case '':
                    return '未处理';
                default:
                    return '未处理';
            }
        }
    }
}
