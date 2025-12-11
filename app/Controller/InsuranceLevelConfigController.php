<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\InsuranceLevelConfig;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Container\ContainerInterface;

class InsuranceLevelConfigController extends AbstractController
{
    public function __construct(ContainerInterface $container, RequestInterface $request, ResponseInterface $response)
    {
        parent::__construct($container, $request, $response);
    }

    /**
     * 获取配置列表
     */
    public function index(RequestInterface $request, ResponseInterface $response)
    {
        $year = (int) $request->input('year', date('Y'));
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 15);
        $paymentCategory = $request->input('payment_category', '');
        $level = $request->input('level', '');

        $query = InsuranceLevelConfig::where('year', $year);
        
        if (!empty($paymentCategory)) {
            $query->where('payment_category', 'like', "%{$paymentCategory}%");
        }
        
        if (!empty($level)) {
            $query->where('level', 'like', "%{$level}%");
        }

        $total = $query->count();
        $data = $query->orderBy('payment_category')
            ->orderBy('level')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->success([
            'list' => $data,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'year' => $year
        ]);
    }

    /**
     * 创建配置
     */
    public function store(RequestInterface $request, ResponseInterface $response)
    {
        $data = $request->all();
        
        // 验证数据
        if (empty($data['year']) || !is_numeric($data['year']) || $data['year'] < 2020 || $data['year'] > 2030) {
            return $this->error('年份必须是2020-2030之间的数字');
        }

        // 处理数组输入并清理字符串
        $data['payment_category'] = is_array($data['payment_category']) 
            ? trim($data['payment_category'][0] ?? '') 
            : trim($data['payment_category'] ?? '');
        
        $data['level'] = is_array($data['level']) 
            ? trim($data['level'][0] ?? '') 
            : trim($data['level'] ?? '');

        if (empty($data['payment_category'])) {
            return $this->error('代缴类别不能为空');
        }
        if (empty($data['level'])) {
            return $this->error('档次不能为空');
        }
        if (!isset($data['subsidy_amount']) || !is_numeric($data['subsidy_amount']) || $data['subsidy_amount'] < 0) {
            return $this->error('资助代缴金额必须是非负数');
        }
        if (!isset($data['personal_amount']) || !is_numeric($data['personal_amount']) || $data['personal_amount'] < 0) {
            return $this->error('个人实缴金额必须是非负数');
        }

        // 检查是否已存在相同年份+代缴类别+档次的配置
        $exists = InsuranceLevelConfig::where('year', $data['year'])
            ->where('payment_category', $data['payment_category'])
            ->where('level', $data['level'])
            ->exists();

        if ($exists) {
            return $this->error('该年份下已存在相同的代缴类别和档次配置');
        }

        $config = InsuranceLevelConfig::create($data);
        return $this->success($config, '创建成功');
    }

    /**
     * 更新配置
     */
    public function update(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $config = InsuranceLevelConfig::find($id);
        if (!$config) {
            return $this->error('配置不存在');
        }

        $data = $request->all();
        
        // 验证数据
        if (empty($data['year']) || !is_numeric($data['year']) || $data['year'] < 2020 || $data['year'] > 2030) {
            return $this->error('年份必须是2020-2030之间的数字');
        }

        // 处理数组输入并清理字符串
        $data['payment_category'] = is_array($data['payment_category']) 
            ? trim($data['payment_category'][0] ?? '') 
            : trim($data['payment_category'] ?? '');
        
        $data['level'] = is_array($data['level']) 
            ? trim($data['level'][0] ?? '') 
            : trim($data['level'] ?? '');

        if (empty($data['payment_category'])) {
            return $this->error('代缴类别不能为空');
        }
        if (empty($data['level'])) {
            return $this->error('档次不能为空');
        }
        if (!isset($data['subsidy_amount']) || !is_numeric($data['subsidy_amount']) || $data['subsidy_amount'] < 0) {
            return $this->error('资助代缴金额必须是非负数');
        }
        if (!isset($data['personal_amount']) || !is_numeric($data['personal_amount']) || $data['personal_amount'] < 0) {
            return $this->error('个人实缴金额必须是非负数');
        }

        // 检查是否与其他记录冲突
        $exists = InsuranceLevelConfig::where('year', $data['year'])
            ->where('payment_category', $data['payment_category'])
            ->where('level', $data['level'])
            ->where('id', '!=', $id)
            ->exists();

        if ($exists) {
            return $this->error('该年份下已存在相同的代缴类别和档次配置');
        }

        $config->update($data);
        return $this->success($config, '更新成功');
    }

    /**
     * 删除配置
     */
    public function destroy(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $config = InsuranceLevelConfig::find($id);
        if (!$config) {
            return $this->error('配置不存在');
        }

        $config->delete();
        return $this->success(null, '删除成功');
    }

    /**
     * 获取配置详情
     */
    public function show(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $config = InsuranceLevelConfig::find($id);
        if (!$config) {
            return $this->error('配置不存在');
        }

        return $this->success($config);
    }

    /**
     * 获取所有年份
     */
    public function getYears(RequestInterface $request, ResponseInterface $response)
    {
        $years = InsuranceLevelConfig::getAllYears();
        return $this->success($years);
    }

    /**
     * 获取指定年份的配置
     */
    public function getByYear(RequestInterface $request, ResponseInterface $response)
    {
        $year = (int) $request->input('year', date('Y'));
        $configs = InsuranceLevelConfig::getByYear($year);
        return $this->success($configs);
    }

    /**
     * 获取所有代缴类别
     */
    public function getPaymentCategories(RequestInterface $request, ResponseInterface $response)
    {
        $categories = InsuranceLevelConfig::getAllPaymentCategories();
        return $this->success($categories);
    }

    /**
     * 获取所有档次
     */
    public function getLevels(RequestInterface $request, ResponseInterface $response)
    {
        $levels = InsuranceLevelConfig::getAllLevels();
        return $this->success($levels);
    }

    /**
     * 获取模板配置（最近年份的配置）
     */
    public function getTemplate(RequestInterface $request, ResponseInterface $response)
    {
        $template = InsuranceLevelConfig::getLatestYearTemplate();
        return $this->success($template);
    }

    /**
     * 批量创建配置
     */
    public function batchCreate(RequestInterface $request, ResponseInterface $response)
    {
        $data = $request->all();
        
        // 验证数据
        if (empty($data['year']) || !is_numeric($data['year']) || $data['year'] < 2020 || $data['year'] > 2030) {
            return $this->error('年份必须是2020-2030之间的数字');
        }
        if (!is_array($data['configs'])) {
            return $this->error('配置数据必须是数组');
        }
        
        $year = (int) $data['year'];
        $configs = $data['configs'];

        // 检查年份是否已存在配置
        if (InsuranceLevelConfig::yearExists($year)) {
            return $this->error('该年份已存在配置，请先删除或更新');
        }

        // 为每个配置添加年份
        foreach ($configs as &$config) {
            $config['year'] = $year;
        }

        $success = InsuranceLevelConfig::batchCreate($configs);
        if ($success) {
            return $this->success(null, '批量创建成功');
        } else {
            return $this->error('批量创建失败');
        }
    }

    /**
     * 删除指定年份的所有配置
     */
    public function deleteByYear(RequestInterface $request, ResponseInterface $response)
    {
        $year = (int) $request->input('year');
        if (!$year) {
            return $this->error('年份不能为空');
        }

        $deleted = InsuranceLevelConfig::deleteByYear($year);
        return $this->success(['deleted_count' => $deleted], '删除成功');
    }

    /**
     * 下载导入模板
     */
    public function downloadTemplate(): PsrResponseInterface
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // 设置表头
            $headers = [
                'A1' => '代缴类别',
                'B1' => '档次',
                'C1' => '资助代缴金额（元）',
                'D1' => '个人实缴金额（元）',
                'E1' => '标准执行起止时间',
                'F1' => '代缴资金支付部门',
                'G1' => '备注',
            ];

            foreach ($headers as $cell => $value) {
                $sheet->setCellValue($cell, $value);
            }

            // 设置列宽
            $sheet->getColumnDimension('A')->setWidth(20);
            $sheet->getColumnDimension('B')->setWidth(15);
            $sheet->getColumnDimension('C')->setWidth(20);
            $sheet->getColumnDimension('D')->setWidth(20);
            $sheet->getColumnDimension('E')->setWidth(25);
            $sheet->getColumnDimension('F')->setWidth(25);
            $sheet->getColumnDimension('G')->setWidth(30);

            // 设置表头样式
            $sheet->getStyle('A1:G1')->getFont()->setBold(true);
            $sheet->getStyle('A1:G1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');

            // 添加示例数据
            $examples = [
                ['民政城乡低保对象', '居民一档', 360.00, 40.00, '2024.10.1-2025.6.30', '区医保局', ''],
                ['民政城乡低保对象', '居民二档', 400.00, 375.00, '2024.10.1-2025.6.30', '区医保局', ''],
                ['民政城乡孤儿', '居民一档', 400.00, 0.00, '2024.10.1-2025.6.30', '区医保局', ''],
                ['民政家庭经济困难大学生', '大学生一档', 380.00, 0.00, '2024.10.1-2025.6.30', '区医保局', ''],
                ['民政家庭经济困难大学生', '大学生二档', 420.00, 0.00, '2024.10.1-2025.6.30', '区医保局', ''],
                ['特困人员', '居民一档', 400.00, 0.00, '2024.10.1-2025.6.30', '区医保局', ''],
                ['事实无人抚养儿童', '居民一档', 400.00, 0.00, '2024.10.1-2025.6.30', '区医保局', ''],
                ['边缘易致贫户', '居民一档', 280.00, 120.00, '2024.10.1-2025.6.30', '区医保局', '']
            ];

            $row = 2;
            foreach ($examples as $example) {
                $sheet->fromArray($example, null, "A{$row}");
                $row++;
            }

            // 生成文件
            $writer = new Xlsx($spreadsheet);
            $filename = '医保参保资助档次配置导入模板.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), 'template');
            $writer->save($tempFile);

            $fileContent = file_get_contents($tempFile);
            unlink($tempFile); // 删除临时文件

            return $this->response->withBody(new SwooleStream($fileContent))
                ->withHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('content-disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('content-length', strlen($fileContent));
        } catch (\Exception $e) {
            return $this->response->json([
                'code' => 500,
                'message' => '下载模板失败：' . $e->getMessage()
            ]);
        }
    }

    /**
     * 验证导入文件
     */
    public function validateImport(RequestInterface $request, ResponseInterface $response)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->error('请上传有效的文件');
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray();

            // 验证表头
            $expectedHeaders = ['代缴类别', '档次', '资助代缴金额（元）', '个人实缴金额（元）', '标准执行起止时间', '代缴资金支付部门'];
            $actualHeaders = $data[0];

            if ($actualHeaders !== $expectedHeaders) {
                return $this->error('文件格式不正确，请使用正确的模板 ，确实表头：' . implode(',', $expectedHeaders));
            }

            // 验证数据
            $validData = [];
            for ($i = 1; $i < count($data); $i++) {
                $row = $data[$i];
                
                // 跳过空行
                if (empty(array_filter($row))) {
                    continue;
                }

                // 验证必填字段
                if (empty($row[0]) || empty($row[1]) || !is_numeric($row[2]) || !is_numeric($row[3])) {
                    return $this->error("第" . ($i + 1) . "行数据格式不正确，代缴类别：" . $row[0] . "，档次：" . $row[1] . "，资助代缴金额：" . $row[2] . "，个人实缴金额：" . $row[3]);
                }

                $validData[] = [
                    'payment_category' => trim($row[0]),
                    'level' => trim($row[1]),
                    'subsidy_amount' => (float)$row[2],
                    'personal_amount' => (float)$row[3],
                    'effective_period' => $row[4] ?? '',
                    'payment_department' => $row[5] ?? '',
                    'remark' => $row[6] ?? '',
                ];
            }

            if (empty($validData)) {
                return $this->error('文件中没有有效数据');
            }

            return $this->success($validData, '文件验证通过');
        } catch (\Exception $e) {
            return $this->error('文件解析失败：' . $e->getMessage());
        }
    }

    /**
     * 导入配置
     */
    public function import(RequestInterface $request, ResponseInterface $response)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->error('请上传有效的文件');
        }

        $year = (int)$request->input('year');
        if ($year < 2020 || $year > 2100) {
            return $this->error('年份必须在2020-2100之间');
        }

        $mode = $request->input('mode', 'append');
        if (!in_array($mode, ['append', 'overwrite'])) {
            return $this->error('导入模式不正确');
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray();

            // 验证表头
            $expectedHeaders = ['代缴类别', '档次', '资助代缴金额（元）', '个人实缴金额（元）', '标准执行起止时间', '代缴资金支付部门'];
            $actualHeaders = $data[0];
            if ($actualHeaders !== $expectedHeaders) {
                return $this->error('文件格式不正确，请使用正确的模板 ,确实表头：' . implode(',', $actualHeaders));
            }

            // 如果是覆盖模式，先删除原有数据
            if ($mode === 'overwrite') {
                InsuranceLevelConfig::where('year', $year)->delete();
            }

            // 处理数据
            $importData = [];
            for ($i = 1; $i < count($data); $i++) {
                $row = $data[$i];
                
                // 跳过空行
                if (empty(array_filter($row))) {
                    continue;
                }

                // 验证必填字段
                if (empty($row[0]) || empty($row[1]) || !is_numeric($row[2]) || !is_numeric($row[3])) {
                    return $this->error("第" . ($i + 1) . "行数据格式不正确");
                }

                $importData[] = [
                    'year' => $year,
                    'payment_category' => trim($row[0]),
                    'level' => trim($row[1]),
                    'subsidy_amount' => (float)$row[2],
                    'personal_amount' => (float)$row[3],
                    'effective_period' => $row[4] ?? '',
                    'payment_department' => $row[5] ?? '',
                    'remark' => $row[6] ?? '',
                ];
            }

            if (empty($importData)) {
                return $this->error('文件中没有有效数据');
            }

            // 批量插入数据
            foreach ($importData as $data) {
                // 检查是否存在相同配置
                $exists = InsuranceLevelConfig::where('year', $data['year'])
                    ->where('payment_category', $data['payment_category'])
                    ->where('level', $data['level'])
                    ->exists();

                if (!$exists) {
                    InsuranceLevelConfig::create($data);
                }
            }

            return $this->success(['imported_count' => count($importData)], '导入成功');
        } catch (\Exception $e) {
            return $this->error('导入失败：' . $e->getMessage());
        }
    }
} 