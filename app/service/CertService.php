<?php

namespace app\service;

use app\model\ActionLog;
use app\model\CertOrder;
use app\model\TgUser;
use app\validate\DomainValidate;

class CertService
{
    private AcmeService $acme;
    private DnsService $dns;

    public function __construct(AcmeService $acme, DnsService $dns)
    {
        $this->acme = $acme;
        $this->dns = $dns;
    }

    public function createOrder(array $from, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $validator = new DomainValidate();
        if (!$validator->check(['domain' => $domain])) {
            return ['success' => false, 'message' => '域名格式错误'];
        }

        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '请先发送 /start 绑定账号'];
        }

        $existing = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $user['id'])
            ->where('status', '<>', 'issued')
            ->find();
        if ($existing) {
            if ($existing['status'] !== 'created') {
                return ['success' => false, 'message' => '当前订单状态不可重复生成 TXT'];
            }

            return ['success' => false, 'message' => '该域名已有进行中的订单'];
        }

        $order = CertOrder::create([
            'tg_user_id' => $user['id'],
            'domain' => $domain,
            'status' => 'created',
        ]);

        return $this->issueOrder($user, $order);
    }

    public function startOrder(array $from): array
    {
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '请先发送 /start 绑定账号'];
        }

        $existing = CertOrder::where('tg_user_id', $user['id'])
            ->where('status', 'created')
            ->where('domain', '')
            ->find();
        if ($existing) {
            return ['success' => true, 'order' => $existing];
        }

        $order = CertOrder::create([
            'tg_user_id' => $user['id'],
            'domain' => '',
            'status' => 'created',
        ]);

        return ['success' => true, 'order' => $order];
    }

    public function setOrderType(int $userId, int $orderId, string $certType): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        if ($order['status'] !== 'created') {
            return ['success' => false, 'message' => '当前状态不可选择类型'];
        }

        if (!in_array($certType, ['root', 'wildcard'], true)) {
            return ['success' => false, 'message' => '证书类型不合法'];
        }

        $order->save(['cert_type' => $certType]);

        $user = TgUser::where('id', $userId)->find();
        if ($user) {
            $user->save([
                'pending_action' => 'await_domain',
                'pending_order_id' => $orderId,
            ]);
        }

        return ['success' => true, 'order' => $order];
    }

    public function submitDomain(int $userId, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $validator = new DomainValidate();
        if (!$validator->check(['domain' => $domain])) {
            return ['success' => false, 'message' => '域名格式错误'];
        }

        $user = TgUser::where('id', $userId)->find();
        if (!$user) {
            return ['success' => false, 'message' => '用户不存在'];
        }

        if (!$user['pending_order_id']) {
            return ['success' => false, 'message' => '没有待处理的订单'];
        }

        $order = CertOrder::where('id', $user['pending_order_id'])
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        if ($order['status'] !== 'created') {
            return ['success' => false, 'message' => '当前订单状态不可提交域名'];
        }

        if ($order['domain'] !== '') {
            return ['success' => false, 'message' => '该订单已提交域名'];
        }

        $duplicate = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $userId)
            ->where('status', '<>', 'issued')
            ->find();
        if ($duplicate) {
            return ['success' => false, 'message' => '该域名已有进行中的订单'];
        }

        $order->save(['domain' => $domain]);
        $user->save(['pending_action' => '', 'pending_order_id' => 0]);

        return $this->issueOrder($user, $order);
    }

    public function verifyOrderById(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        return $this->verifyOrderByOrder($order);
    }

    public function getCertificateInfo(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        if ($order['status'] !== 'issued') {
            return ['success' => false, 'message' => '证书尚未签发'];
        }

        $info = $this->readCertificateInfo($order['cert_path']);
        $typeText = $this->formatCertType($order['cert_type']);
        $message = "证书类型：{$typeText}";
        if ($info['expires_at']) {
            $message .= "\n有效期至：{$info['expires_at']}";
        }

        return ['success' => true, 'message' => $message];
    }

    public function getDownloadInfo(int $userId, int $orderId): array
    {
        $order = CertOrder::where('id', $orderId)
            ->where('tg_user_id', $userId)
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        if ($order['status'] !== 'issued') {
            return ['success' => false, 'message' => '证书尚未签发'];
        }

        $message = "证书已导出至服务器目录：\n{$this->getOrderExportPath($order)}\n\n";
        $message .= "文件：\ncert.pem\nfullchain.pem\nprivkey.pem";
        return ['success' => true, 'message' => $message];
    }

    private function issueOrder($user, CertOrder $order): array
    {
        if ($order['status'] !== 'created') {
            return ['success' => false, 'message' => '当前订单状态不可生成 TXT'];
        }

        if ($order['domain'] === '') {
            return ['success' => false, 'message' => '请先提交域名'];
        }

        $domain = $order['domain'];
        $domains = $this->getAcmeDomains($order);
        $dryRun = $this->acme->issueDryRun($domains);
        $this->log($user['id'], 'acme_issue_dry_run', $dryRun['output']);
        if (!$dryRun['success']) {
            $order->save(['status' => 'created', 'acme_output' => $dryRun['output']]);
            return ['success' => false, 'message' => 'acme.sh dry-run 失败：' . $dryRun['output']];
        }

        $txt = $this->dns->parseTxtRecord($dryRun['output']);
        $order->save([
            'status' => 'dns_wait',
            'txt_host' => $txt['name'] ?? '',
            'txt_value' => $txt['value'] ?? '',
            'acme_output' => $dryRun['output'],
        ]);

        $message = "请添加 TXT 记录后点击「我已完成解析」按钮进行验证。\n";
        if ($txt) {
            $message .= "<pre>";
            $message .= "域名 | 主机记录 | 类型 | 记录值\n";
            $message .= "{$domain} | {$txt['name']} | TXT | {$txt['value']}";
            $message .= "</pre>";
        } else {
            $message .= "无法解析 TXT 记录，请查看输出：\n" . $dryRun['output'];
        }

        $this->log($user['id'], 'order_create', $domain);

        return ['success' => true, 'message' => $message, 'order' => $order, 'txt' => $txt];
    }

    public function verifyOrder(array $from, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '请先发送 /start 绑定账号'];
        }

        $order = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $user['id'])
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        return $this->verifyOrderByOrder($order);
    }

    private function verifyOrderByOrder(CertOrder $order): array
    {
        $userId = $order['tg_user_id'];
        if ($order['status'] !== 'dns_wait') {
            return ['success' => false, 'message' => '当前状态不可验证'];
        }

        if ($order['txt_host'] && $order['txt_value']) {
            if (!$this->dns->verifyTxt($order['txt_host'], $order['txt_value'])) {
                return [
                    'success' => false,
                    'message' => '当前未检测到 TXT 记录，DNS 可能仍在生效中。通常需要 1~10 分钟，部分 DNS 更久。',
                ];
            }
        }

        $order->save(['status' => 'dns_verified']);

        $domains = $this->getAcmeDomains($order);
        $renew = $this->acme->renew($domains);
        $this->log($userId, 'acme_renew', $renew['output']);
        if (!$renew['success']) {
            return ['success' => false, 'message' => '证书签发失败：' . $renew['output']];
        }

        $install = $this->acme->installCert($order['domain']);
        $this->log($userId, 'acme_install_cert', $install['output']);
        if (!$install['success']) {
            return ['success' => false, 'message' => '证书导出失败：' . $install['output']];
        }

        $exportPath = $this->getOrderExportPath($order);

        $order->save([
            'status' => 'issued',
            'cert_path' => $exportPath . 'cert.pem',
            'key_path' => $exportPath . 'privkey.pem',
            'fullchain_path' => $exportPath . 'fullchain.pem',
        ]);

        $this->log($userId, 'order_issued', $order['domain']);

        $info = $this->readCertificateInfo($exportPath . 'cert.pem');
        $typeText = $this->formatCertType($order['cert_type']);
        $message = "证书签发成功（{$typeText}），已导出到：{$exportPath}";
        if ($info['expires_at']) {
            $message .= "\n有效期至：{$info['expires_at']}";
        }

        return ['success' => true, 'message' => $message, 'order' => $order];
    }

    public function status(array $from, string $domain): array
    {
        $domain = strtolower(trim($domain));
        $user = TgUser::where('tg_id', $from['id'])->find();
        if (!$user) {
            return ['success' => false, 'message' => '请先发送 /start 绑定账号'];
        }

        $order = CertOrder::where('domain', $domain)
            ->where('tg_user_id', $user['id'])
            ->find();
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        return ['success' => true, 'message' => '当前状态：' . $order['status']];
    }

    public function statusByDomain(string $domain): array
    {
        $order = CertOrder::where('domain', $domain)->find();
        if (!$order) {
            return ['success' => false, 'message' => '订单不存在'];
        }

        return ['success' => true, 'message' => '当前状态：' . $order['status']];
    }

    private function log(int $userId, string $action, string $detail): void
    {
        ActionLog::create([
            'tg_user_id' => $userId,
            'action' => $action,
            'detail' => $detail,
        ]);
    }

    private function formatCertType(string $type): string
    {
        return $type === 'wildcard' ? '通配符证书' : '根域名证书';
    }

    private function getAcmeDomains(CertOrder $order): array
    {
        if ($order['cert_type'] === 'wildcard') {
            return [$order['domain'], '*.' . $order['domain']];
        }

        return [$order['domain']];
    }

    private function getOrderExportPath(CertOrder $order): string
    {
        $config = config('tg');
        return rtrim($config['cert_export_path'], '/') . '/' . $order['domain'] . '/';
    }

    private function readCertificateInfo(string $certPath): array
    {
        if (!is_file($certPath)) {
            return ['expires_at' => null];
        }

        $certContent = file_get_contents($certPath);
        if ($certContent === false) {
            return ['expires_at' => null];
        }

        $certData = openssl_x509_parse($certContent);
        if (!$certData || !isset($certData['validTo_time_t'])) {
            return ['expires_at' => null];
        }

        return ['expires_at' => date('Y-m-d H:i:s', $certData['validTo_time_t'])];
    }
}
