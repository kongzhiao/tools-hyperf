<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\CategoryConversion;
use App\Service\CategoryConversionImportService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Di\Annotation\Inject;

class CategoryConversionController extends AbstractController
{
    #[Inject]
    protected CategoryConversionImportService $importService;
    /**
     * 获取类别转换列表
     */
    public function index(RequestInterface $request, ResponseInterface $response)
    {
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 15);
        $taxStandard = $request->input('tax_standard', '');
        $medicalExportStandard = $request->input('medical_export_standard', '');
        $nationalDictName = $request->input('national_dict_name', '');

        $query = CategoryConversion::query();

        // 筛选条件
        if (!empty($taxStandard)) {
            $query->where('tax_standard', 'like', "%{$taxStandard}%");
        }
        if (!empty($medicalExportStandard)) {
            $query->where('medical_export_standard', 'like', "%{$medicalExportStandard}%");
        }
        if (!empty($nationalDictName)) {
            $query->where('national_dict_name', 'like', "%{$nationalDictName}%");
        }

        $total = $query->count();
        $data = $query->orderBy('id', 'desc')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->success([
            'list' => $data,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 创建类别转换
     */
    public function store(RequestInterface $request, ResponseInterface $response)
    {
        $data = $request->all();

        // 验证数据
        if (empty($data['tax_standard'])) {
            return $this->error('税务代缴数据口径不能为空');
        }

        // 检查是否已存在相同的记录
        $exists = CategoryConversion::where('tax_standard', $data['tax_standard'])
            ->where('medical_export_standard', $data['medical_export_standard'] ?? null)
            ->where('national_dict_name', $data['national_dict_name'] ?? null)
            ->exists();

        if ($exists) {
            return $this->error('该转换规则已存在');
        }

        $categoryConversion = CategoryConversion::create($data);

        return $this->success($categoryConversion, '创建成功');
    }

    /**
     * 更新类别转换
     */
    public function update(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $data = $request->all();

        $categoryConversion = CategoryConversion::find($id);
        if (!$categoryConversion) {
            return $this->error('记录不存在');
        }

        // 检查是否已存在相同的记录（排除当前记录）
        $exists = CategoryConversion::where('id', '!=', $id)
            ->where('tax_standard', $data['tax_standard'])
            ->where('medical_export_standard', $data['medical_export_standard'] ?? null)
            ->where('national_dict_name', $data['national_dict_name'] ?? null)
            ->exists();

        if ($exists) {
            return $this->error('该转换规则已存在');
        }

        $categoryConversion->update($data);

        return $this->success($categoryConversion, '更新成功');
    }

    /**
     * 删除类别转换
     */
    public function destroy(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $categoryConversion = CategoryConversion::find($id);
        if (!$categoryConversion) {
            return $this->error('记录不存在');
        }

        $categoryConversion->delete();

        return $this->success(null, '删除成功');
    }

    /**
     * 获取类别转换详情
     */
    public function show(RequestInterface $request, ResponseInterface $response, int $id)
    {
        $categoryConversion = CategoryConversion::find($id);
        if (!$categoryConversion) {
            return $this->error('记录不存在');
        }

        return $this->success($categoryConversion);
    }

    /**
     * 获取所有税务代缴数据口径
     */
    public function getTaxStandards(RequestInterface $request, ResponseInterface $response)
    {
        $taxStandards = CategoryConversion::getAllTaxStandards();
        return $this->success($taxStandards);
    }

    /**
     * 根据税务代缴数据口径获取相关记录
     */
    public function getByTaxStandard(RequestInterface $request, ResponseInterface $response)
    {
        $taxStandard = $request->input('tax_standard', '');
        if (empty($taxStandard)) {
            return $this->error('税务代缴数据口径不能为空');
        }

        $data = CategoryConversion::getByTaxStandard($taxStandard);
        return $this->success($data);
    }

    /**
     * 转换值：根据医保数据导出对象口径或国家字典值名称获取税务代缴数据口径
     */
    public function convert(RequestInterface $request, ResponseInterface $response)
    {
        $value = $request->input('value', '');
        if (empty($value)) {
            return $this->error('转换值不能为空');
        }

        $conversion = CategoryConversion::findByAnyValue($value);
        if ($conversion) {
            return $this->success([
                'original_value' => $value,
                'converted_value' => $conversion->tax_standard,
                'conversion_type' => $conversion->medical_export_standard === $value ? 'medical_export' : 'national_dict'
            ]);
        }

        return $this->success([
            'original_value' => $value,
            'converted_value' => $value,
            'conversion_type' => 'no_match'
        ], '未找到匹配的转换规则，返回原值');
    }

    /**
     * 批量转换
     */
    public function batchConvert(RequestInterface $request, ResponseInterface $response)
    {
        $values = $request->input('values', []);
        if (empty($values) || !is_array($values)) {
            return $this->error('转换值列表不能为空');
        }

        $results = [];
        foreach ($values as $value) {
            $conversion = CategoryConversion::findByAnyValue($value);
            $results[] = [
                'original_value' => $value,
                'converted_value' => $conversion ? $conversion->tax_standard : $value,
                'conversion_type' => $conversion ?
                    ($conversion->medical_export_standard === $value ? 'medical_export' : 'national_dict') :
                    'no_match'
            ];
        }

        return $this->success($results);
    }

    /**
     * 下载导入模板
     */
    public function downloadTemplate(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $tempFile = $this->importService->generateTemplate();

            $content = file_get_contents($tempFile);
            unlink($tempFile); // 删除临时文件

            return $this->response->raw($content)
                ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="类别转换导入模板.xlsx"')
                ->withHeader('Cache-Control', 'no-cache');
        } catch (\Exception $e) {
            return $this->error('模板生成失败: ' . $e->getMessage());
        }
    }

    /**
     * 上传并导入数据（同步处理）
     */
    public function uploadImport(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $uploadedFile = $request->file('file');

            if (!$uploadedFile || !$uploadedFile->isValid()) {
                return $this->error('请上传有效的 CSV 文件');
            }

            // 验证文件类型
            $extension = strtolower($uploadedFile->getExtension());
            if ($extension !== 'csv') {
                return $this->error('仅支持 CSV 格式文件');
            }

            $tempFile = $uploadedFile->getPathname();

            // 使用 CsvReaderService 读取并导入
            $csvReader = new \App\Service\CsvReaderService();
            $result = [
                'imported' => 0,
                'skipped' => 0,
                'errors' => []
            ];

            // 表头字段映射
            $mappings = [
                'tax_standard' => ['税务代缴数据口径', '税务口径', '税务代缴口径'],
                'medical_export_standard' => ['医保数据导出对象口径', '医保口径', '医保导出口径'],
                'national_dict_name' => ['国家字典值名称', '国家字典', '字典名称']
            ];

            $logger = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Hyperf\Logger\LoggerFactory::class)
                ->get('default');

            // 逐行处理数据
            $csvReader->read(
                $tempFile,
                function ($rowData, $rowIndex, $headers) use (&$result, $mappings, $logger) {
                    try {
                        // 调试：打印第一行数据
                        if ($rowIndex <= 2) {
                            $logger->info("CSV Row {$rowIndex} headers: " . json_encode(array_keys($rowData), JSON_UNESCAPED_UNICODE));
                            $logger->info("CSV Row {$rowIndex} data: " . json_encode($rowData, JSON_UNESCAPED_UNICODE));
                        }

                        // 提取数据 - 使用 mb_strpos 进行模糊匹配
                        $data = [];
                        foreach ($mappings as $field => $possibleHeaders) {
                            foreach ($rowData as $csvHeader => $value) {
                                $csvHeaderTrimmed = trim((string) $csvHeader);
                                foreach ($possibleHeaders as $expectedHeader) {
                                    // 精确匹配或包含匹配
                                    if (
                                        $csvHeaderTrimmed === $expectedHeader ||
                                        mb_strpos($csvHeaderTrimmed, $expectedHeader) !== false ||
                                        mb_strpos($expectedHeader, $csvHeaderTrimmed) !== false
                                    ) {
                                        if ($value !== null && $value !== '') {
                                            $data[$field] = trim((string) $value);
                                        }
                                        break 2;
                                    }
                                }
                            }
                        }

                        // 验证必填字段
                        if (empty($data['tax_standard'])) {
                            throw new \Exception('税务代缴数据口径不能为空');
                        }

                        // 验证至少有一个映射字段
                        if (empty($data['medical_export_standard']) && empty($data['national_dict_name'])) {
                            throw new \Exception('医保数据导出对象口径和国家字典值名称至少填写一项');
                        }

                        // 检查是否已存在
                        $existing = CategoryConversion::where('tax_standard', $data['tax_standard'])
                            ->where('medical_export_standard', $data['medical_export_standard'] ?? '')
                            ->where('national_dict_name', $data['national_dict_name'] ?? '')
                            ->first();

                        if ($existing) {
                            $result['skipped']++;
                        } else {
                            CategoryConversion::create([
                                'tax_standard' => $data['tax_standard'],
                                'medical_export_standard' => $data['medical_export_standard'] ?? null,
                                'national_dict_name' => $data['national_dict_name'] ?? null
                            ]);
                            $result['imported']++;
                        }

                    } catch (\Throwable $e) {
                        $result['errors'][] = "第" . ($rowIndex + 1) . "行：" . $e->getMessage();
                    }
                },
                true,
                null
            );

            return $this->success([
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'error_count' => count($result['errors']),
                'errors' => array_slice($result['errors'], 0, 10), // 最多返回10条错误
                'message' => sprintf(
                    '导入完成：成功 %d 条，跳过 %d 条，失败 %d 条',
                    $result['imported'],
                    $result['skipped'],
                    count($result['errors'])
                )
            ]);
        } catch (\Exception $e) {
            return $this->error('导入失败: ' . $e->getMessage());
        }
    }
}