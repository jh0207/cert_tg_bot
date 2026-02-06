<?php

namespace app\model;

use think\Model;

class CertOrder extends Model
{
    protected $table = 'cert_orders';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
