<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Hyperf\HttpServer\Router\Router;

// ==================== 基础路由 ====================
Router::addRoute(['GET', 'POST'], '/', 'App\Controller\IndexController::index');
Router::get('/health', 'App\Controller\IndexController::health');
// Router::get('/favicon.ico', function () {
//     return '';
// });

// 兼容 hyperf/swagger 组件未内置控制器的情况，直接读取 swagger.json
// Router::get('/swagger', function () {
//     $file = BASE_PATH . '/storage/swagger/swagger.json';
//     if (file_exists($file)) {
//         return json_decode(file_get_contents($file), true);
//     }
//     return ['error' => 'swagger.json not found'];
// });

// ==================== 认证相关路由组 ====================
Router::addGroup('/api', function () {
    // 认证路由
    Router::post('/login', [App\Controller\AuthController::class, 'login']);
    Router::post('/logout', [App\Controller\AuthController::class, 'logout']);
});

// ==================== 需要认证的通用业务路由组 ====================
Router::addGroup('/api', function () {
    Router::get('/user/info', [App\Controller\AuthController::class, 'info']);

    // 任务管理路由
    Router::get('/tasks', [App\Controller\TaskController::class, 'index']);
    Router::get('/tasks/count', [App\Controller\TaskController::class, 'count']);
    Router::get('/tasks/progress', [App\Controller\TaskController::class, 'show']);

    // 用户管理路由
    Router::get('/users', [App\Controller\UserController::class, 'index']);
    Router::post('/users', [App\Controller\UserController::class, 'store']);
    Router::get('/users/roles', [App\Controller\UserController::class, 'getRoles']);
    Router::get('/users/{id}', [App\Controller\UserController::class, 'show']);
    Router::put('/users/{id}', [App\Controller\UserController::class, 'update']);
    Router::delete('/users/{id}', [App\Controller\UserController::class, 'destroy']);
    Router::post('/users/{id}/roles', [App\Controller\UserController::class, 'assignRoles']);

    // 角色管理路由
    Router::get('/roles', [App\Controller\RoleController::class, 'index']);
    Router::post('/roles', [App\Controller\RoleController::class, 'store']);
    Router::get('/roles/{id}', [App\Controller\RoleController::class, 'show']);
    Router::put('/roles/{id}', [App\Controller\RoleController::class, 'update']);
    Router::delete('/roles/{id}', [App\Controller\RoleController::class, 'destroy']);
    Router::post('/roles/{id}/permissions', [App\Controller\RoleController::class, 'assignPermissions']);
    Router::get('/roles/{id}/permissions', [App\Controller\RoleController::class, 'getPermissions']);

    // 权限管理路由
    Router::get('/permissions', [App\Controller\PermissionController::class, 'index']);
    Router::post('/permissions', [App\Controller\PermissionController::class, 'store']);
    Router::get('/permissions/menus', [App\Controller\PermissionController::class, 'getMenus']);
    Router::get('/permissions/operations', [App\Controller\PermissionController::class, 'getOperations']);
    Router::get('/permissions/user/menus', [App\Controller\PermissionController::class, 'getUserMenus']);
    Router::post('/permissions/validate', [App\Controller\PermissionController::class, 'validatePermissions']);
    Router::post('/permissions/generate', [App\Controller\PermissionController::class, 'generateMissingPermissions']);
    Router::get('/permissions/{id}', [App\Controller\PermissionController::class, 'show']);
    Router::put('/permissions/{id}', [App\Controller\PermissionController::class, 'update']);
    Router::delete('/permissions/{id}', [App\Controller\PermissionController::class, 'destroy']);
}, ['middleware' => [App\Middleware\JwtAuthMiddleware::class]]);

// ==================== 类别转换管理路由组 ====================
Router::addGroup('/api/category-conversions', function () {
    // 具体路径要在参数路径之前
    Router::get('', [App\Controller\CategoryConversionController::class, 'index']);
    Router::post('', [App\Controller\CategoryConversionController::class, 'store']);
    Router::get('/tax-standards', [App\Controller\CategoryConversionController::class, 'getTaxStandards']);
    Router::get('/by-tax-standard', [App\Controller\CategoryConversionController::class, 'getByTaxStandard']);
    Router::post('/convert', [App\Controller\CategoryConversionController::class, 'convert']);
    Router::post('/batch-convert', [App\Controller\CategoryConversionController::class, 'batchConvert']);
    Router::get('/template', [App\Controller\CategoryConversionController::class, 'downloadTemplate']);
    Router::post('/import/preview', [App\Controller\CategoryConversionController::class, 'previewImport']);
    Router::post('/import/confirm', [App\Controller\CategoryConversionController::class, 'confirmImport']);

    // 通配符路由放在最后
    Router::get('/{id}', [App\Controller\CategoryConversionController::class, 'show']);
    Router::put('/{id}', [App\Controller\CategoryConversionController::class, 'update']);
    Router::delete('/{id}', [App\Controller\CategoryConversionController::class, 'destroy']);
}, ['middleware' => [App\Middleware\JwtAuthMiddleware::class]]);

// ==================== 业务汇总路由汇总 ====================
Router::addGroup('/api', function () {
    // 参保资助档次配置
    Router::addGroup('/insurance-level-configs', function () {
        Router::get('', [App\Controller\InsuranceLevelConfigController::class, 'index']);
        Router::post('', [App\Controller\InsuranceLevelConfigController::class, 'store']);
        Router::get('/years', [App\Controller\InsuranceLevelConfigController::class, 'getYears']);
        Router::get('/by-year', [App\Controller\InsuranceLevelConfigController::class, 'getByYear']);
        Router::get('/payment-categories', [App\Controller\InsuranceLevelConfigController::class, 'getPaymentCategories']);
        Router::get('/levels', [App\Controller\InsuranceLevelConfigController::class, 'getLevels']);
        Router::get('/template', [App\Controller\InsuranceLevelConfigController::class, 'getTemplate']);
        Router::post('/batch-create', [App\Controller\InsuranceLevelConfigController::class, 'batchCreate']);
        Router::delete('/by-year', [App\Controller\InsuranceLevelConfigController::class, 'deleteByYear']);
        Router::get('/template/download', [App\Controller\InsuranceLevelConfigController::class, 'downloadTemplate']);
        Router::post('/validate', [App\Controller\InsuranceLevelConfigController::class, 'validateImport']);
        Router::post('/import', [App\Controller\InsuranceLevelConfigController::class, 'import']);
        Router::get('/{id}', [App\Controller\InsuranceLevelConfigController::class, 'show']);
        Router::put('/{id}', [App\Controller\InsuranceLevelConfigController::class, 'update']);
        Router::delete('/{id}', [App\Controller\InsuranceLevelConfigController::class, 'destroy']);
    });

    // 参保数据管理
    Router::addGroup('/insurance-data', function () {
        Router::get('', [App\Controller\InsuranceDataController::class, 'index']);
        Router::get('/years', [App\Controller\InsuranceDataController::class, 'getYears']);
        Router::get('/street-towns', [App\Controller\InsuranceDataController::class, 'getStreetTowns']);
        Router::get('/payment-categories', [App\Controller\InsuranceDataController::class, 'getPaymentCategories']);
        Router::get('/levels', [App\Controller\InsuranceDataController::class, 'getLevels']);
        Router::get('/medical-assistance-categories', [App\Controller\InsuranceDataController::class, 'getMedicalAssistanceCategories']);
        Router::get('/statistics', [App\Controller\InsuranceDataController::class, 'getStatistics']);
        Router::get('/export', [App\Controller\InsuranceDataController::class, 'export']);
        Router::get('/export-info', [App\Controller\InsuranceDataController::class, 'getExportInfo']);
        Router::get('/template', [App\Controller\InsuranceDataController::class, 'downloadTemplate']);
        Router::post('/batch-update', [App\Controller\InsuranceDataController::class, 'batchUpdate']);
        Router::post('/create-year', [App\Controller\InsuranceDataController::class, 'createYear']);
        Router::post('/import-by-year', [App\Controller\InsuranceDataController::class, 'importByYear']);
        Router::post('/validate', [App\Controller\InsuranceDataController::class, 'validateFile']);
        Router::post('/validate-import-level-match', [App\Controller\InsuranceDataController::class, 'validateImportLevelMatch']);
        Router::post('/validate-import-street-town', [App\Controller\InsuranceDataController::class, 'validateImportStreetTown']);
        Router::post('/import-street-town', [App\Controller\InsuranceDataController::class, 'importStreetTown']);
        Router::post('/import', [App\Controller\InsuranceDataController::class, 'importData']);
        Router::get('/year-list', [App\Controller\InsuranceDataController::class, 'getYearList']);
        Router::post('/import-level-match', [App\Controller\InsuranceDataController::class, 'importLevelMatch']);
        Router::put('/years/{id}', [App\Controller\InsuranceDataController::class, 'updateYear']);
        Router::delete('/years/{id}/data', [App\Controller\InsuranceDataController::class, 'clearYearData']);
        Router::delete('/years/{id}', [App\Controller\InsuranceDataController::class, 'deleteYear']);
        Router::get('/{id}', [App\Controller\InsuranceDataController::class, 'show']);
        Router::put('/{id}', [App\Controller\InsuranceDataController::class, 'update']);
        Router::delete('/{id}', [App\Controller\InsuranceDataController::class, 'destroy']);
    });

    // 税务与参保汇总
    Router::get('/tax-summary/data', [App\Controller\TaxSummaryController::class, 'getData']);
    Router::get('/tax-summary/export', [App\Controller\TaxSummaryController::class, 'export']);
    Router::get('/insurance-summary/data', [App\Controller\InsuranceSummaryController::class, 'getData']);
    Router::get('/insurance-summary/export', [App\Controller\InsuranceSummaryController::class, 'export']);

    // 统计汇总
    Router::addGroup('/statistics', function () {
        Router::get('/projects', [App\Controller\StatisticsSummaryController::class, 'getProjects']);
        Router::post('/projects', [App\Controller\StatisticsSummaryController::class, 'createProject']);
        Router::put('/projects/{id:\d+}', [App\Controller\StatisticsSummaryController::class, 'updateProject']);
        Router::delete('/projects/{id:\d+}', [App\Controller\StatisticsSummaryController::class, 'deleteProject']);
        Router::delete('/projects/{id:\d+}/data', [App\Controller\StatisticsSummaryController::class, 'clearProjectData']);
        Router::get('/list', [App\Controller\StatisticsSummaryController::class, 'getStatisticsList']);
        Router::post('/import', [App\Controller\StatisticsSummaryController::class, 'importStatistics']);
        Router::get('/data-type-options', [App\Controller\StatisticsSummaryController::class, 'getDataTypeOptions']);
        Router::post('/person-time-statistics', [App\Controller\StatisticsSummaryController::class, 'getPersonTimeStatistics']);
        Router::post('/reimbursement-statistics', [App\Controller\StatisticsSummaryController::class, 'getReimbursementStatistics']);
        Router::post('/tilt-assistance-statistics', [App\Controller\StatisticsSummaryController::class, 'getTiltAssistanceStatistics']);
        Router::post('/export-person-time-statistics', [App\Controller\StatisticsSummaryController::class, 'exportPersonTimeStatistics']);
        Router::post('/export-reimbursement-statistics', [App\Controller\StatisticsSummaryController::class, 'exportReimbursementStatistics']);
        Router::post('/export-tilt-assistance-statistics', [App\Controller\StatisticsSummaryController::class, 'exportTiltAssistanceStatistics']);
        Router::post('/export-detail-statistics', [App\Controller\StatisticsSummaryController::class, 'exportDetailStatistics']);
        // Task progress endpoint moved to public group
    });

    // 医疗救助
    Router::addGroup('/medical-assistance', function () {
        Router::get('/patients', [App\Controller\MedicalAssistanceController::class, 'getPatients']);
        Router::post('/patients', [App\Controller\MedicalAssistanceController::class, 'createPatient']);
        Router::get('/patients/insurance-areas', [App\Controller\MedicalAssistanceController::class, 'getInsuranceAreas']);
        Router::post('/patients/batch-delete', [App\Controller\MedicalAssistanceController::class, 'batchDeletePatients']);
        Router::get('/patients/{id:\d+}', [App\Controller\MedicalAssistanceController::class, 'getPatient']);
        Router::put('/patients/{id:\d+}', [App\Controller\MedicalAssistanceController::class, 'updatePatient']);
        Router::delete('/patients/{id:\d+}', [App\Controller\MedicalAssistanceController::class, 'deletePatient']);
        Router::get('/patients/{id:\d+}/complete-info', [App\Controller\MedicalAssistanceController::class, 'getPatientCompleteInfo']);
        Router::get('/medical-records', [App\Controller\MedicalAssistanceController::class, 'getMedicalRecords']);
        Router::get('/medical-records/by-id-card', [App\Controller\MedicalAssistanceController::class, 'getMedicalRecordsByIdCard']);
        Router::post('/medical-records', [App\Controller\MedicalAssistanceController::class, 'createMedicalRecord']);
        Router::get('/medical-records/visit-types', [App\Controller\MedicalAssistanceController::class, 'getVisitTypes']);
        Router::get('/medical-records/hospitals', [App\Controller\MedicalAssistanceController::class, 'getHospitals']);
        Router::get('/medical-records/processing-statuses', [App\Controller\MedicalAssistanceController::class, 'getProcessingStatuses']);
        Router::post('/medical-records/batch-update-status', [App\Controller\MedicalAssistanceController::class, 'batchUpdateMedicalRecordStatus']);
        Router::post('/medical-records/batch-delete', [App\Controller\MedicalAssistanceController::class, 'batchDeleteMedicalRecords']);
        Router::get('/medical-records/{id:\d+}', [App\Controller\MedicalAssistanceController::class, 'getMedicalRecord']);
        Router::put('/medical-records/{id:\d+}', [App\Controller\MedicalAssistanceController::class, 'updateMedicalRecord']);
        Router::delete('/medical-records/{id:\d+}', [App\Controller\MedicalAssistanceController::class, 'deleteMedicalRecord']);
        Router::get('/reimbursements', [App\Controller\MedicalAssistanceController::class, 'getReimbursements']);
        Router::post('/reimbursements', [App\Controller\MedicalAssistanceController::class, 'createReimbursement']);
        Router::post('/reimbursements/batch-create', [App\Controller\MedicalAssistanceController::class, 'batchCreateReimbursements']);
        Router::get('/reimbursements/banks', [App\Controller\MedicalAssistanceController::class, 'getBanks']);
        Router::get('/reimbursements/statuses', [App\Controller\MedicalAssistanceController::class, 'getReimbursementStatuses']);
        Router::get('/reimbursements/statistics', [App\Controller\MedicalAssistanceController::class, 'getReimbursementStatistics']);
        Router::get('/reimbursements/export-ledger', [App\Controller\MedicalAssistanceController::class, 'exportReimbursementLedger']);
        Router::post('/reimbursements/batch-update-status', [App\Controller\MedicalAssistanceController::class, 'batchUpdateReimbursementStatus']);
        Router::get('/reimbursements/{id:\d+}', [App\Controller\MedicalAssistanceController::class, 'getReimbursement']);
        Router::put('/reimbursements/{id:\d+}', [App\Controller\MedicalAssistanceController::class, 'updateReimbursement']);
        Router::delete('/reimbursements/{id:\d+}', [App\Controller\MedicalAssistanceController::class, 'deleteReimbursement']);
        Router::post('/import-excel', [App\Controller\MedicalAssistanceController::class, 'importExcel']);
    });

    // 优抚救助
    Router::addGroup('/settlement-config', function () {
        Router::get('', [App\Controller\SettlementConfigController::class, 'index']);
        Router::post('', [App\Controller\SettlementConfigController::class, 'store']);
        Router::get('/years', [App\Controller\SettlementConfigController::class, 'getYears']);
        Router::get('/by-year', [App\Controller\SettlementConfigController::class, 'getByYear']);
        Router::get('/categories', [App\Controller\SettlementConfigController::class, 'getCategories']);
        Router::get('/levels', [App\Controller\SettlementConfigController::class, 'getLevels']);
        Router::get('/template', [App\Controller\SettlementConfigController::class, 'getTemplate']);
        Router::post('/batch-create', [App\Controller\SettlementConfigController::class, 'batchCreate']);
        Router::delete('/by-year', [App\Controller\SettlementConfigController::class, 'deleteByYear']);
        Router::get('/template/download', [App\Controller\SettlementConfigController::class, 'downloadTemplate']);
        Router::post('/validate', [App\Controller\SettlementConfigController::class, 'validateImport']);
        Router::post('/import', [App\Controller\SettlementConfigController::class, 'import']);
        Router::get('/{id}', [App\Controller\SettlementConfigController::class, 'show']);
        Router::put('/{id}', [App\Controller\SettlementConfigController::class, 'update']);
        Router::delete('/{id}', [App\Controller\SettlementConfigController::class, 'destroy']);
    });

    // 仪表盘
    Router::get('/dashboard/stats', [App\Controller\DashboardController::class, 'getStats']);

    // 身份验证
    Router::addGroup('/identity-verification', function () {
        Router::post('/verify', [App\Controller\IdentityVerificationController::class, 'verify']);
        Router::get('/history', [App\Controller\IdentityVerificationController::class, 'getHistory']);
        Router::get('/template', [App\Controller\IdentityVerificationController::class, 'downloadTemplate']);
    });

    // OCR识别
    Router::addGroup('/ocr', function () {
        Router::post('/recognize', [App\Controller\OcrController::class, 'recognize']);
        Router::get('/history', [App\Controller\OcrController::class, 'getHistory']);
    });
}, ['middleware' => [App\Middleware\JwtAuthMiddleware::class]]);