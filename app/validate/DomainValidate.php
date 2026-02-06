<?php

namespace app\validate;

use think\Validate;

class DomainValidate extends Validate
{
    protected $rule = [
        'domain' => ['require', 'regex' => '/^(?=.{1,253}$)(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/'],
    ];
}
