<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class NationalDictMapping extends Model
{
    protected ?string $table = 'national_dict_mappings';
    
    protected array $fillable = [
        'category_conversion_id',
        'national_dict_value'
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