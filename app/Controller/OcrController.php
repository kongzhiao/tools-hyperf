<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Hyperf\Context\ApplicationContext;

/**
 * @Controller(prefix="/api/ocr")
 */
class OcrController extends AbstractController
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

    /**
     * 上传图片并进行OCR识别
     * @RequestMapping(path="/recognize", methods="post")
     */
    public function recognize(RequestInterface $request, ResponseInterface $response)
    {
        $this->logger->info('开始OCR识别请求');
        
        try {
            // 获取上传的文件
            $file = $request->file('image');
            
            if (!$file || !$file->isValid()) {
                $this->logger->warning('OCR识别失败：无效的文件上传', [
                    'file_exists' => $file ? 'yes' : 'no',
                    'is_valid' => $file ? ($file->isValid() ? 'yes' : 'no') : 'n/a'
                ]);
                
                return $this->error('请上传有效的图片文件', 400);
            }

            $this->logger->info('文件上传验证通过', [
                'filename' => $file->getClientFilename(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType()
            ]);

            // 获取OCR配置
            $config = config('ocr');
            $allowedTypes = $config['upload']['allowed_types'] ?? ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            
            // 检查文件类型
            if (!in_array($file->getMimeType(), $allowedTypes)) {
                $supportedTypes = implode('、', array_map(function($type) {
                    return strtoupper(str_replace('image/', '', $type));
                }, $allowedTypes));
                
                $this->logger->warning('OCR识别失败：不支持的文件类型', [
                    'uploaded_type' => $file->getMimeType(),
                    'allowed_types' => $allowedTypes
                ]);
                
                return $this->error("只支持 {$supportedTypes} 格式的图片", 400);
            }

            // 获取OCR配置
            $config = config('ocr');
            $maxFileSize = $config['upload']['max_file_size'] ?? 10 * 1024 * 1024;
            
            // 检查文件大小
            if ($file->getSize() > $maxFileSize) {
                $maxSizeMB = round($maxFileSize / (1024 * 1024), 1);
                
                $this->logger->warning('OCR识别失败：文件大小超限', [
                    'file_size' => $file->getSize(),
                    'max_size' => $maxFileSize,
                    'max_size_mb' => $maxSizeMB
                ]);
                
                return $this->error("图片大小不能超过{$maxSizeMB}MB", 400);
            }

            // 获取文件路径并创建带扩展名的临时文件
            $tempPath = $file->getRealPath();
            $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
            if (empty($extension)) {
                $extension = 'jpg'; // 默认扩展名
            }
            
            // 创建带扩展名的临时文件
            $tempDir = sys_get_temp_dir();
            $tempFile = $tempDir . '/ocr_' . uniqid() . '.' . $extension;
            
            $this->logger->info('创建临时文件', [
                'original_path' => $tempPath,
                'temp_file' => $tempFile,
                'extension' => $extension
            ]);
            
            copy($tempPath, $tempFile);
            
            // 调用Python OCR服务进行真实识别
            $this->logger->info('开始调用Python OCR服务');
            $result = $this->callPythonOcrService($tempFile);
            
            // 清理临时文件
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            
            if ($result['success']) {
                $this->logger->info('OCR识别成功', [
                    'processing_time' => $result['processing_time'] ?? 'unknown',
                    'confidence' => $result['confidence'] ?? 'unknown'
                ]);
                
                return $this->success($result, '识别成功');
            } else {
                $this->logger->error('OCR识别失败', [
                    'error' => $result['error'] ?? 'unknown error'
                ]);
                
                return $this->error('识别失败: ' . ($result['error'] ?? 'unknown error'), 500);
            }

        } catch (\Exception $e) {
            $this->logger->error('OCR识别过程中发生异常', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->error('识别失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 获取OCR识别历史记录
     * @RequestMapping(path="/history", methods="get")
     */
    public function getHistory(RequestInterface $request)
    {
        $this->logger->info('开始获取OCR识别历史记录');
        
        try {
            // 这里可以从数据库获取历史记录
            // 暂时返回模拟数据
            $history = [
                [
                    'id' => 1,
                    'filename' => '身份证正面.jpg',
                    'recognized_text' => '姓名：张三\n身份证号：110101199001011234',
                    'created_at' => date('Y-m-d H:i:s', time() - 3600),
                    'status' => 'success'
                ],
                [
                    'id' => 2,
                    'filename' => '银行卡.jpg',
                    'recognized_text' => '卡号：6222021234567890123\n银行：中国工商银行',
                    'created_at' => date('Y-m-d H:i:s', time() - 7200),
                    'status' => 'success'
                ]
            ];

            $this->logger->info('成功获取OCR识别历史记录', [
                'count' => count($history)
            ]);

            return $this->success($history, '获取成功');

        } catch (\Exception $e) {
            $this->logger->error('获取OCR识别历史记录失败', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return $this->error('获取历史记录失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 执行OCR识别
     */
    private function performOcr(string $imagePath): array
    {
        // 这里集成本地化的OCR组件
        // 可以使用 Tesseract、PaddleOCR 等开源OCR引擎
        
        // 模拟OCR识别结果
        $recognizedText = $this->simulateOcrRecognition($imagePath);
        
        // 解析识别结果，提取关键信息
        $extractedData = $this->extractKeyInformation($recognizedText);
        
        return [
            'original_text' => $recognizedText,
            'extracted_data' => $extractedData,
            'confidence' => 0.95,
            'processing_time' => rand(100, 500) // 毫秒
        ];
    }

    /**
     * 模拟OCR识别过程
     */
    private function simulateOcrRecognition(string $imagePath): string
    {
        // 这里应该调用实际的OCR引擎
        // 暂时返回模拟数据
        
        $sampleTexts = [
            "姓名：张三\n性别：男\n民族：汉\n出生：1990年1月1日\n住址：北京市朝阳区xxx街道xxx号\n公民身份号码：110101199001011234",
            "中国工商银行\n卡号：6222021234567890123\n有效期：12/25\n持卡人：李四",
            "姓名：王五\n身份证号：320102198505151234\n地址：江苏省南京市xxx区xxx街道",
            "招商银行\n卡号：6225881234567890\n有效期：10/28\n持卡人：赵六"
        ];
        
        // 根据文件名选择不同的模拟数据
        $filename = basename($imagePath);
        $index = intval(substr($filename, 0, 1)) % count($sampleTexts);
        
        return $sampleTexts[$index];
    }

    /**
     * 提取关键信息
     */
    private function extractKeyInformation(string $text): array
    {
        $result = [
            'name' => null,
            'id_card' => null,
            'bank_card' => null,
            'phone' => null,
            'address' => null,
            'bank_name' => null
        ];

        // 提取姓名
        if (preg_match('/姓名[：:]\s*([^\n\r]+)/', $text, $matches)) {
            $result['name'] = trim($matches[1]);
        }

        // 提取身份证号
        if (preg_match('/[身份证号码|公民身份号码][：:]\s*(\d{17}[\dXx])/', $text, $matches)) {
            $result['id_card'] = $matches[1];
        }

        // 提取银行卡号
        if (preg_match('/卡号[：:]\s*(\d{16,19})/', $text, $matches)) {
            $result['bank_card'] = $matches[1];
        }

        // 提取手机号
        if (preg_match('/1[3-9]\d{9}/', $text, $matches)) {
            $result['phone'] = $matches[0];
        }

        // 提取地址
        if (preg_match('/住址[：:]\s*([^\n\r]+)/', $text, $matches)) {
            $result['address'] = trim($matches[1]);
        }

        // 提取银行名称
        if (preg_match('/(中国工商银行|中国建设银行|中国农业银行|中国银行|招商银行|交通银行|中信银行|浦发银行|民生银行|兴业银行|平安银行|华夏银行|广发银行|光大银行|邮储银行)/', $text, $matches)) {
            $result['bank_name'] = $matches[1];
        }

        return $result;
    }

    /**
     * 调用FastAPI OCR服务
     */
    private function callPythonOcrService(string $imagePath): array
    {
        $this->logger->info('开始调用Python OCR服务', [
            'image_path' => $imagePath,
            'file_exists' => file_exists($imagePath) ? 'yes' : 'no'
        ]);
        
        try {
            // 设置脚本执行时间限制
            set_time_limit(300); // 5分钟超时
            
            // 获取OCR服务配置
            $config = config('ocr');
            $serviceConfig = $config['service'];
            $httpConfig = $config['http'];
            
            $this->logger->info('OCR配置信息', [
                'service_url' => $serviceConfig['url'] ?? 'not_set',
                'timeout' => $serviceConfig['timeout'] ?? 'not_set',
                'connect_timeout' => $serviceConfig['connect_timeout'] ?? 'not_set'
            ]);
            
            // 验证配置
            if (empty($serviceConfig['url'])) {
                $this->logger->error('OCR服务配置错误：URL未配置');
                return [
                    'success' => false,
                    'error' => 'OCR服务URL未配置'
                ];
            }
            
            // 检查图片文件是否存在
            if (!file_exists($imagePath)) {
                $this->logger->error('OCR服务调用失败：图片文件不存在', [
                    'image_path' => $imagePath
                ]);
                return [
                    'success' => false,
                    'error' => '图片文件不存在: ' . $imagePath
                ];
            }
            
            // 准备POST数据
            $postData = [
                'image' => new \CURLFile($imagePath, 'image/jpeg', basename($imagePath))
            ];
            
            $this->logger->info('准备OCR请求数据', [
                'filename' => basename($imagePath),
                'file_size' => filesize($imagePath)
            ]);
            
            // 执行OCR请求（支持重试）
            $result = $this->executeOcrRequest($serviceConfig, $httpConfig, $postData);
            
            $this->logger->info('OCR服务调用完成', [
                'success' => $result['success'] ? 'yes' : 'no',
                'error' => $result['error'] ?? 'none'
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error('调用OCR服务时发生异常', [
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => '调用OCR服务时发生异常: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 执行OCR请求（支持重试机制）
     */
    private function executeOcrRequest(array $serviceConfig, array $httpConfig, array $postData): array
    {
        $retryTimes = $serviceConfig['retry_times'] ?? 3;
        $retryInterval = $serviceConfig['retry_interval'] ?? 2;
        
        $this->logger->info('开始执行OCR请求（支持重试）', [
            'retry_times' => $retryTimes,
            'retry_interval' => $retryInterval,
            'service_url' => $serviceConfig['url']
        ]);
        
        for ($attempt = 1; $attempt <= $retryTimes; $attempt++) {
            $this->logger->info("开始第{$attempt}次OCR请求尝试");
            
            $result = $this->makeOcrRequest($serviceConfig, $httpConfig, $postData);
            
            // 如果请求成功，直接返回结果
            if ($result['success']) {
                $this->logger->info("OCR请求成功（第{$attempt}次尝试）", [
                    'processing_time' => $result['processing_time'] ?? 'unknown',
                    'confidence' => $result['confidence'] ?? 'unknown'
                ]);
                return $result;
            }
            
            // 如果是最后一次尝试，返回错误
            if ($attempt >= $retryTimes) {
                $this->logger->error("OCR请求最终失败（已重试{$retryTimes}次）", [
                    'final_error' => $result['error'],
                    'total_attempts' => $retryTimes
                ]);
                return $result;
            }
            
            // 记录重试日志
            $this->logger->warning("OCR请求失败，准备第" . ($attempt + 1) . "次重试", [
                'error' => $result['error'],
                'attempt' => $attempt,
                'max_attempts' => $retryTimes,
                'retry_interval' => $retryInterval
            ]);
            
            // 等待重试间隔
            sleep($retryInterval);
        }
        
        return [
            'success' => false,
            'error' => 'OCR服务请求失败，已重试' . $retryTimes . '次'
        ];
    }
    
    /**
     * 发起单次OCR请求
     */
    private function makeOcrRequest(array $serviceConfig, array $httpConfig, array $postData): array
    {
        $this->logger->info('开始发起单次OCR请求', [
            'url' => $serviceConfig['url'],
            'timeout' => $serviceConfig['timeout'],
            'connect_timeout' => $serviceConfig['connect_timeout']
        ]);
        
        // 初始化cURL
        $ch = curl_init();
        
        // 设置cURL选项
        curl_setopt_array($ch, [
            CURLOPT_URL => $serviceConfig['url'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $serviceConfig['timeout'],
            CURLOPT_CONNECTTIMEOUT => $serviceConfig['connect_timeout'],
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . $httpConfig['user_agent']
            ],
            CURLOPT_SSL_VERIFYPEER => $httpConfig['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => $httpConfig['verify_host'] ? 2 : 0,
        ]);
        
        // 执行请求
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $endTime = microtime(true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $connectTime = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        
        // 关闭cURL
        curl_close($ch);
        
        // 检查cURL错误
        if ($error) {
            $this->logger->error('OCR请求cURL错误', [
                'error' => $error,
                'url' => $serviceConfig['url']
            ]);
            return [
                'success' => false,
                'error' => 'cURL请求失败: ' . $error
            ];
        }
        
        // 检查响应是否为false（cURL失败）
        if ($response === false) {
            $this->logger->error('OCR请求返回false', [
                'url' => $serviceConfig['url']
            ]);
            return [
                'success' => false,
                'error' => 'OCR服务请求失败: 无法获取响应'
            ];
        }
        
        $this->logger->info('OCR请求执行完成', [
            'http_code' => $httpCode,
            'total_time' => round($totalTime * 1000, 2) . 'ms',
            'connect_time' => round($connectTime * 1000, 2) . 'ms',
            'response_size' => strlen($response),
            'has_error' => !empty($error)
        ]);
        
        // 检查HTTP状态码
        if ($httpCode !== 200) {
            $this->logger->error('OCR请求HTTP错误', [
                'http_code' => $httpCode,
                'response' => $response,
                'url' => $serviceConfig['url']
            ]);
            return [
                'success' => false,
                'error' => 'OCR服务返回HTTP错误: ' . $httpCode . ', 响应: ' . $response
            ];
        }
        
        // 检查响应是否为空
        if (empty($response)) {
            $this->logger->error('OCR请求返回空响应', [
                'url' => $serviceConfig['url']
            ]);
            return [
                'success' => false,
                'error' => 'OCR服务没有返回任何响应'
            ];
        }
        
        // 解析JSON响应
        $result = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('OCR请求JSON解析错误', [
                'json_error' => json_last_error_msg(),
                'response' => $response,
                'url' => $serviceConfig['url']
            ]);
            return [
                'success' => false,
                'error' => 'OCR服务返回的JSON格式错误: ' . json_last_error_msg() . ', 响应: ' . $response
            ];
        }
        
        $this->logger->info('OCR请求JSON解析成功', [
            'response_keys' => array_keys($result),
            'has_success_field' => isset($result['success'])
        ]);
        
        return $result;
    }
    

    
    /**
     * 保存识别记录
     */
    private function saveRecognitionRecord(array $data): void
    {
        // 这里可以保存到数据库
        // 暂时不实现
    }
} 