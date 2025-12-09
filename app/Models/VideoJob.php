<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoJob extends Model
{
    protected $fillable = [
        'job_id',
        'store_id',
        'product_id',
        'product_name',
        'product_description',
        'product_image_url',
        'status',
        'video_url',
        'external_video_url',
        'motion_prompt',
        'error_message',
    ];
}
