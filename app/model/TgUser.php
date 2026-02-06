<?php

namespace app\model;

use think\Model;

class TgUser extends Model
{
    protected $table = 'tg_users';
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';
}
