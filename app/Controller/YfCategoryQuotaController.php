<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\YfCategoryQuota;
use App\Service\CsvReaderService;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Container\ContainerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class YfCategoryQuotaController extends AbstractController
{
    public function __construct(ContainerInterface $container, RequestInterface $request, ResponseInterface $response)
    {
        parent::__construct($container, $request, $response);
    }

    /**
     * 获取配置列表
     */
    public function index(RequestInterface $request)
    {
        $year = (int) $request->input('year', date('Y'));
        $page = (int) $request->input('page', 1);
        $pageSize = (int) $request->input('page_size', 15);
        $category = (string) $request->input('category', '');

        $query = YfCategoryQuota::where('year', $year)
            ->orderBy('id', 'asc');

        if (!empty($category)) {
            $query->where('category', 'like', "%{$category}%");
        }

        $total = $query->count();
        $data = $query->orderBy('category')
            ->offset(($page - 1) * (int) $pageSize)
            ->limit((int) $pageSize)
            ->get();

        return $this->success([
            'list' => $data,
            'total' => $total,
            'page' => (int) $page,
            'page_size' => (int) $pageSize,
            'year' => $year
        ]);
    }

    /**
     * 创建配置
     */
    public function store(RequestInterface $request)
    {
        $data = $request->all();

        if (empty($data['year']) || !is_numeric($data['year'])) {
            return $this->error('年份不能为空');
        }

        $category = trim((string) ($data['category'] ?? ''));
        if (empty($category)) {
            return $this->error('优抚类别不能为空');
        }

        if (!isset($data['quota_amount']) || !is_numeric($data['quota_amount'])) {
            return $this->error('补助金额格式不正确');
        }

        // 检查重复
        $exists = YfCategoryQuota::where('year', (int) $data['year'])
            ->where('category', $category)
            ->exists();

        if ($exists) {
            return $this->error('该年份下已存在相同的优抚类别配置');
        }

        $quota = YfCategoryQuota::create([
            'year' => (int) $data['year'],
            'category' => $category,
            'quota_amount' => (float) $data['quota_amount'],
            'remark' => $data['remark'] ?? '',
        ]);

        return $this->success($quota, '创建成功');
    }

    /**
     * 更新配置
     */
    public function update(RequestInterface $request, int $id)
    {
        $quota = YfCategoryQuota::find($id);
        if (!$quota) {
            return $this->error('配置不存在');
        }

        $data = $request->all();
        if (isset($data['quota_amount']) && is_numeric($data['quota_amount'])) {
            $quota->quota_amount = (float) $data['quota_amount'];
        }
        if (isset($data['remark'])) {
            $quota->remark = (string) $data['remark'];
        }
        if (isset($data['category'])) {
            $quota->category = trim((string) $data['category']);
        }

        $quota->save();

        return $this->success($quota, '更新成功');
    }

    /**
     * 删除配置
     */
    public function destroy(int $id)
    {
        $quota = YfCategoryQuota::find($id);
        if (!$quota) {
            return $this->error('配置不存在');
        }

        $quota->delete();
        return $this->success(null, '删除成功');
    }

    /**
     * 获取所有年份
     */
    public function getYears()
    {
        $years = YfCategoryQuota::distinct()->pluck('year')->sort()->values()->toArray();
        return $this->success($years);
    }

    /**
     * 根据年份获取已配置的优抚类别
     */
    public function getCategoriesByYear(RequestInterface $request)
    {
        $year = (int) $request->input('year', (string) date('Y'));
        $categories = YfCategoryQuota::where('year', $year)
            ->distinct()
            ->pluck('category')
            ->toArray();
        return $this->success($categories);
    }

    /**
     * 克隆年度数据
     */
    public function cloneYear(RequestInterface $request)
    {
        $fromYear = (int) $request->input('from_year', 0);
        $toYear = (int) $request->input('to_year', 0);

        if ($fromYear === $toYear) {
            return $this->error('来源年份和目标年份不能相同');
        }

        if (YfCategoryQuota::where('year', $toYear)->exists()) {
            return $this->error('目标年份已存在配置，请先删除或直接修改');
        }

        $sources = YfCategoryQuota::where('year', $fromYear)->get();
        if ($sources->isEmpty()) {
            return $this->error('来源年份没有数据可克隆');
        }

        foreach ($sources as $source) {
            YfCategoryQuota::create([
                'year' => $toYear,
                'category' => $source->category,
                'quota_amount' => $source->quota_amount,
                'remark' => $source->remark,
            ]);
        }

        return $this->success(null, '克隆成功');
    }

    /**
     * 导入 CSV
     */
    public function import(RequestInterface $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return $this->error('无效的文件');
        }

        $year = (int) $request->input('year', 0);
        if ($year < 2020) {
            return $this->error('请选择有效的年度');
        }

        $mode = (string) $request->input('mode', 'append');

        try {
            $csvReader = $this->container->get(CsvReaderService::class);

            if ($mode === 'overwrite') {
                YfCategoryQuota::where('year', $year)->delete();
            }

            $result = [
                'imported' => 0,
                'skipped' => 0,
                'errors' => []
            ];

            $mappings = [
                'category' => ['优抚类别', '类别'],
                'quota_amount' => ['优抚住院医疗补助金额（人/元/年）', '补助金额', '金额', '限额'],
                'remark' => ['备注']
            ];

            $csvReader->read(
                $file->getPathname(),
                function ($rowData, $rowIndex) use (&$result, $mappings, $year) {
                    try {
                        $data = ['year' => $year];
                        foreach ($mappings as $field => $possibleHeaders) {
                            foreach ($rowData as $csvHeader => $value) {
                                $csvHeaderTrim = trim((string) $csvHeader);
                                foreach ($possibleHeaders as $expected) {
                                    if (mb_strpos($csvHeaderTrim, $expected) !== false) {
                                        $data[$field] = trim((string) $value);
                                        break 2;
                                    }
                                }
                            }
                        }

                        if (empty($data['category'])) {
                            return; // 跳过空行或无用行
                        }

                        $amount = (float) ($data['quota_amount'] ?? 0);

                        $exists = YfCategoryQuota::where('year', $year)
                            ->where('category', $data['category'])
                            ->exists();

                        if ($exists) {
                            $result['skipped']++;
                        } else {
                            YfCategoryQuota::create([
                                'year' => $year,
                                'category' => $data['category'],
                                'quota_amount' => $amount,
                                'remark' => $data['remark'] ?? '',
                            ]);
                            $result['imported']++;
                        }
                    } catch (\Throwable $e) {
                        $result['errors'][] = "第" . ($rowIndex + 1) . "行：" . $e->getMessage();
                    }
                }
            );

            return $this->success([
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
                'error_count' => count($result['errors']),
                'errors' => array_slice($result['errors'], 0, 10),
            ], '导入完成');

        } catch (\Exception $e) {
            return $this->error('导入发生错误：' . $e->getMessage());
        }
    }

    /**
     * 下载导入模板
     */
    public function downloadTemplate()
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // 表头
            $headers = ['优抚类别', '优抚住院医疗补助金额（人/元/年）', '备注'];
            foreach ($headers as $index => $header) {
                $sheet->setCellValueByColumnAndRow($index + 1, 1, $header);
            }

            // 示例数据
            $examples = [
                ['带病回乡退役军人', 4000.00, '年度补助限额示例'],
                ['参战退役军官', 5000.00, ''],
            ];
            foreach ($examples as $rowIndex => $rowData) {
                foreach ($rowData as $colIndex => $value) {
                    $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 2, $value);
                }
            }

            $writer = new Xlsx($spreadsheet);
            $filename = '优抚人员类别额度配置模板.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), 'template');
            $writer->save($tempFile);

            $content = file_get_contents($tempFile);
            unlink($tempFile);

            return $this->response->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('Content-Disposition', 'attachment; filename="' . urlencode($filename) . '"')
                ->setBody(new SwooleStream($content));
        } catch (\Exception $e) {
            return $this->error('生成模板失败：' . $e->getMessage());
        }
    }
}
