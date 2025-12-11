<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\CategoryConversion;
use App\Model\InsuranceData;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class IdentityVerificationController extends AbstractController
{
    /**
     * 下载身份验证模板
     */
    public function downloadTemplate(RequestInterface $request, ResponseInterface $response): PsrResponseInterface
    {
        try {
            $templatePath = BASE_PATH . '/../doc/template/医疗救助对象资助参保名单汇总.xlsx';
            
            if (!file_exists($templatePath)) {
                return $this->error('模板文件不存在');
            }

            $fileName = '身份验证模板_' . date('Y-m-d') . '.xlsx';
            $fileContent = file_get_contents($templatePath);
            
            return $this->response->withBody(new SwooleStream($fileContent))
                ->withHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
                ->withHeader('content-disposition', 'attachment; filename="' . $fileName . '"')
                ->withHeader('content-length', strlen($fileContent));
        } catch (\Exception $e) {
            return $this->error('下载模板失败: ' . $e->getMessage());
        }
    }

    /**
     * 身份类别验证
     */
    public function verify(RequestInterface $request, ResponseInterface $response)
    {
        $year = $request->input('year');
        $file = $request->file('file');

        if (!$year) {
            return $this->error('请选择年份');
        }

        if (!$file) {
            return $this->error('请选择要验证的Excel文件');
        }

        if (!$file->isValid()) {
            $error = $file->getError();
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => '文件大小超过php.ini中upload_max_filesize限制',
                UPLOAD_ERR_FORM_SIZE => '文件大小超过表单中MAX_FILE_SIZE限制',
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

        try {
            // 保存上传文件
            $uploadDir = BASE_PATH . '/storage/uploads/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true)) {
                    return $this->error('创建上传目录失败');
                }
            }
            $fileName = 'identity_verification_' . $year . '_' . time() . '.' . $file->getExtension();
            $filePath = $uploadDir . $fileName;

            $moveResult = $file->moveTo($filePath);
            if (!$moveResult) {
                if (!file_exists($filePath)) {
                    return $this->error('文件保存失败，请检查目录权限');
                }
            }
            if (!file_exists($filePath)) {
                return $this->error('文件保存验证失败');
            }

            // 执行验证命令
            $command = "php bin/hyperf.php verify:identity --year={$year} --file={$filePath}";
            $output = [];
            $returnCode = 0;

            exec($command . " 2>&1", $output, $returnCode);

            // 删除临时文件
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            if ($returnCode === 0) {
                // 解析输出结果
                $result = $this->parseVerificationOutput($output);
                return $this->success($result);
            } else {
                $errorMessage = implode("\n", $output);
                return $this->error('验证失败: ' . $errorMessage);
            }

        } catch (\Exception $e) {
            return $this->error('验证失败: ' . $e->getMessage());
        }
    }

    /**
     * 解析验证输出结果
     */
    private function parseVerificationOutput(array $output): array
    {
        $results = [];
        $summary = [
            'total' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'error' => 0,
        ];

        foreach ($output as $line) {
            if (strpos($line, 'RESULT:') === 0) {
                $data = json_decode(substr($line, 7), true);
                if ($data) {
                    $results[] = $data;
                    $summary['total']++;
                    
                    switch ($data['status']) {
                        case 'matched':
                            $summary['matched']++;
                            break;
                        case 'unmatched':
                            $summary['unmatched']++;
                            break;
                        case 'error':
                            $summary['error']++;
                            break;
                    }
                }
            }
        }

        return [
            'results' => $results,
            'summary' => $summary,
        ];
    }

    /**
     * 获取验证历史
     */
    public function getHistory(RequestInterface $request, ResponseInterface $response)
    {
        $year = $request->input('year');
        $page = (int)$request->input('page', 1);
        $pageSize = (int)$request->input('page_size', 20);

        if (!$year) {
            return $this->error('请选择年份');
        }

        try {
            // 这里可以从验证历史表中获取数据
            // 暂时返回空数据
            return $this->success([
                'list' => [],
                'total' => 0,
                'page' => $page,
                'page_size' => $pageSize,
            ]);
        } catch (\Exception $e) {
            return $this->error('获取验证历史失败: ' . $e->getMessage());
        }
    }
} 