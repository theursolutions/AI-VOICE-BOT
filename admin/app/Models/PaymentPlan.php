<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentPlan extends Model
{
    public $timestamps = true;

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->getTimestamp();
    }
}
