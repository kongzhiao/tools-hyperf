<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class MedicalExportMapping extends Model
{
    protected ?string $table = 'medical_export_mappings';
    
    protected array $fillable = [
        'category_conversion_id',
        'medical_export_value'
    ];

    protected array $casts = [
        'created_at' => 'datetime'
    ];

    // 关联类别转换
    public function categoryConversion()
    {
        return $this->belongsTo(CategoryConversion::class, 'category_conversion_id', 'id');
    }
} 