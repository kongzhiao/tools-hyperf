<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\SettlementConfig;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Psr\Container\ContainerInterface;

class SettlementConfigController extends AbstractController
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
        $category = $request->input('category', '');
        // $level = $request->input('level', '');

        $query = SettlementConfig::where('year', $year);

        if (!empty($category)) {
            $query->where('category', 'like', "%{$category}%");
        }

        // if (!empty($level)) {
        //     $query->where('level', 'like', "%{$level}%");
        // }

        $total = $query->count();
        $data = $query->orderBy('category')
            // ->orderBy('level')
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
        $data['category'] = is_array($data['category'])
            ? trim($data['category'][0] ?? '')
            : trim($data['category'] ?? '');

        $data['level'] = is_array($data['level'])
            ? trim($data['level'][0] ?? '')
            : trim($data['level'] ?? '');

        if (empty($data['category'])) {
            return $this->error('优抚类别不能为空');
        }
        // if (empty($data['level'])) {
        //     return $this->error('档次不能为空');
        // }
        if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] < 0) {
            return $this->error('优抚住院医疗补助金额必须是非负数');
        }

        // 检查是否已存在相同年份+代缴类别+档次的配置
        $exists = SettlementConfig::where('year', $data['year'])
            ->where('category', $data['category'])
            // ->where('level', $data['level'])
            ->exists();

        if ($exists) {
            return $this->error('该年份下已存在相同的优抚类别配置');
        }

        $config = SettlementConfig::create($data);
        return $this->success($config, '创建成功');
    }

    /**
     * 更新配置
     */
    public function update(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $config = SettlementConfig::find($id);
        if (!$config) {
            return $this->error('配置不存在');
        }

        $data = $request->all();

        // 验证数据
        if (empty($data['year']) || !is_numeric($data['year']) || $data['year'] < 2020 || $data['year'] > 2030) {
            return $this->error('年份必须是2020-2030之间的数字');
        }

        // 处理数组输入并清理字符串
        $data['category'] = is_array($data['category'])
            ? trim($data['category'][0] ?? '')
            : trim($data['category'] ?? '');

        // $data['level'] = is_array($data['level'])
        //     ? trim($data['level'][0] ?? '')
        //     : trim($data['level'] ?? '');

        if (empty($data['category'])) {
            return $this->error('优抚类别不能为空');
        }
        // if (empty($data['level'])) {
        //     return $this->error('档次不能为空');
        // }
        if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] < 0) {
            return $this->error('优抚住院医疗补助金额必须是非负数');
        }

        // 检查是否与其他记录冲突
        $exists = SettlementConfig::where('year', $data['year'])
            ->where('category', $data['category'])
            // ->where('level', $data['level'])
            ->where('id', '!=', $id)
            ->exists();

        if ($exists) {
            return $this->error('该年份下已存在相同的优抚类别配置');
        }

        $config->update($data);
        return $this->success($config, '更新成功');
    }

    /**
     * 删除配置
     */
    public function destroy(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $config = SettlementConfig::find($id);
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
        $config = SettlementConfig::find($id);
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
        $years = SettlementConfig::getAllYears();
        return $this->success($years);
    }

    /**
     * 获取指定年份的配置
     */
    public function getByYear(RequestInterface $request, ResponseInterface $response)
    {
        $year = (int) $request->input('year', date('Y'));
        $configs = SettlementConfig::getByYear($year);
        return $this->success($configs);
    }

    /**
     * 获取所有代缴类别
     */
    public function getCategories(RequestInterface $request, ResponseInterface $response)
    {
        $categories = SettlementConfig::getAllCategories();
        return $this->success($categories);
    }

    /**
     * 获取所有档次
     */
    public function getLevels(RequestInterface $request, ResponseInterface $response)
    {
        $levels = SettlementConfig::getAllLevels();
        return $this->success($levels);
    }

    /**
     * 获取模板配置（最近年份的配置）
     */
    public function getTemplate(RequestInterface $request, ResponseInterface $response)
    {
        $template = SettlementConfig::getLatestYearTemplate();
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
            return $this->error('提交的配置数据格式有误');
        }

        $year = (int) $data['year'];
        $configs = $data['configs'];

        // 检查年份是否已存在配置
        if (SettlementConfig::yearExists($year)) {
            return $this->error('该年份已存在配置，请先删除或更新');
        }

        // 为每个配置添加年份
        foreach ($configs as &$config) {
            $config['year'] = $year;
        }

        $success = SettlementConfig::batchCreate($configs);
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

        $deleted = SettlementConfig::deleteByYear($year);
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
                'A1' => '优抚类别',
                'B1' => '优抚住院医疗补助金额（人/元/年）',
                'C1' => '备注',
            ];

            foreach ($headers as $cell => $value) {
                $sheet->setCellValue($cell, $value);
            }

            // 设置列宽
            $sheet->getColumnDimension('A')->setWidth(30);
            $sheet->getColumnDimension('B')->setWidth(30);
            $sheet->getColumnDimension('C')->setWidth(30);

            // 设置表头样式
            $sheet->getStyle('A1:C1')->getFont()->setBold(true);
            $sheet->getStyle('A1:C1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');

            // 添加示例数据
            $examples = [
                ['残疾退役军人 /七级 / 因战', 3000.00, '备注或说明(非必填)'],
                ['带病回乡退役军人', 4000.00, ''],
                ['年满60周岁农村籍退役士兵', 2000.00, '']
            ];

            $row = 2;
            foreach ($examples as $example) {
                $sheet->fromArray($example, null, "A{$row}");
                $row++;
            }

            // 生成文件
            $writer = new Xlsx($spreadsheet);
            $filename = '优抚人员类别额度配置导入模板.xlsx';
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
            $expectedHeaders = ['优抚类别', '优抚住院医疗补助金额（人/元/年）', '备注'];
            $actualHeaders = $data[0];
            var_dump($actualHeaders);

            if ($actualHeaders !== $expectedHeaders) {
                return $this->error('文件格式不正确，请使用正确的模板 ，确认表头：' . implode(',', $expectedHeaders));
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
                if (empty($row[0]) || empty($row[1])) {
                    return $this->error("第" . ($i + 1) . "行数据格式不正确，优抚类别：" . $row[0] . "，优抚住院医疗补助金额（人/元/年）：" . $row[1]);
                }

                $validData[] = [
                    'category' => trim($row[0]),
                    'amount' => (float) $row[1],
                    'remark' => $row[2] ?? '',
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

        $year = (int) $request->input('year');
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
            $expectedHeaders = ['优抚类别', '优抚住院医疗补助金额（人/元/年）', '备注'];
            $actualHeaders = $data[0];
            if ($actualHeaders !== $expectedHeaders) {
                return $this->error('文件格式不正确，请使用正确的模板 ,确实表头：' . implode(',', $actualHeaders));
            }

            // 如果是覆盖模式，先删除原有数据
            if ($mode === 'overwrite') {
                SettlementConfig::where('year', $year)->delete();
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
                if (empty($row[0]) || empty($row[1])) {
                    return $this->error("第" . ($i + 1) . "行数据格式不正确，优抚类别：" . $row[0] . "，优抚住院医疗补助金额（人/元/年）：" . $row[1]);
                }

                $importData[] = [
                    'year' => $year,
                    'category' => trim($row[0]),
                    'amount' => (float) $row[1],
                    'remark' => $row[2] ?? '',
                ];
            }

            if (empty($importData)) {
                return $this->error('文件中没有有效数据');
            }

            // 批量插入数据
            foreach ($importData as $data) {
                // 检查是否存在相同配置
                $exists = SettlementConfig::where('year', $data['year'])
                    ->where('category', $data['category'])
                    ->exists();

                if (!$exists) {
                    SettlementConfig::create($data);
                }
            }

            return $this->success(['imported_count' => count($importData)], '导入成功');
        } catch (\Exception $e) {
            return $this->error('导入失败：' . $e->getMessage());
        }
    }
}