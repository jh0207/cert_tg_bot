<?php

namespace app\controller;

use app\service\AuthService;

class Admin
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function promote(int $operatorId, int $targetId): array
    {
        return $this->auth->promote($operatorId, $targetId);
    }

    public function demote(int $operatorId, int $targetId): array
    {
        return $this->auth->demote($operatorId, $targetId);
    }
}
