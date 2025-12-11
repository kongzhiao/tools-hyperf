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
     * 预览导入数据
     */
    public function previewImport(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $uploadedFile = $request->file('file');
            
            if (!$uploadedFile || !$uploadedFile->isValid()) {
                return $this->error('请上传有效的Excel文件');
            }

            $tempFile = $uploadedFile->getPathname();
            $result = $this->importService->parseExcelFile($tempFile);

            return $this->success($result);
        } catch (\Exception $e) {
            return $this->error('文件解析失败: ' . $e->getMessage());
        }
    }

    /**
     * 确认导入数据
     */
    public function confirmImport(RequestInterface $request, ResponseInterface $response)
    {
        try {
            $data = $request->input('data', []);
            
            if (empty($data) || !is_array($data)) {
                return $this->error('导入数据不能为空');
            }

            $result = $this->importService->batchImport($data);

            return $this->success($result, '导入完成');
        } catch (\Exception $e) {
            return $this->error('导入失败: ' . $e->getMessage());
        }
    }
} 