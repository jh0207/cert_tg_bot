<?php

namespace app\model;

use think\Model;

class ActionLog extends Model
{
    protected $table = 'action_logs';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = false;
}
