<?php

namespace app\controller;

use app\service\AcmeService;
use app\service\CertService;
use app\service\DnsService;

class Cert
{
    private CertService $service;

    public function __construct()
    {
        $this->service = new CertService(new AcmeService(), new DnsService());
    }

    public function status(string $domain): array
    {
        return $this->service->statusByDomain($domain);
    }
}
