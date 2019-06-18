<?php

namespace timramseyjr\Ebay\Models;

use Illuminate\Database\Eloquent\Model;

class EbaySettings extends Model
{
    protected $casts = [
        'value' => 'array'
    ];
}
