<?php

namespace app\controller;

use app\service\AuthService;
use app\service\TelegramService;
use app\service\AcmeService;
use app\service\DnsService;
use app\service\CertService;

class Bot
{
    private TelegramService $telegram;
    private AuthService $auth;
    private CertService $certService;

    public function __construct(TelegramService $telegram)
    {
        $this->telegram = $telegram;
        $this->auth = new AuthService();
        $this->certService = new CertService(new AcmeService(), new DnsService());
    }

    public function handleUpdate(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');
        if (!$chatId || $text === '') {
            return;
        }

        $user = $this->auth->startUser($message['from']);
        if ($user['pending_action'] === 'await_domain' && strpos($text, '/') !== 0) {
            $result = $this->certService->submitDomain($user['id'], $text);
            if ($result['success'] && isset($result['order'])) {
                $keyboard = $this->buildDnsKeyboard($result['order']['id']);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            } else {
                $this->telegram->sendMessage($chatId, $result['message']);
            }
            return;
        }

        if (strpos($text, '/start') === 0) {
            $role = $user['role'];
            $this->telegram->sendMessage($chatId, "欢迎使用证书机器人！当前角色：{$role}。发送 /help 查看指令。");
            return;
        }

        if (strpos($text, '/help') === 0) {
            $help = implode("\n", [
                '/new 申请证书',
                '/domain example.com 申请证书',
                '/verify example.com DNS 解析完成后验证并签发',
                '/status example.com 查看订单状态',
            ]);
            $this->telegram->sendMessage($chatId, $help);
            return;
        }

        if (strpos($text, '/new') === 0 || strpos($text, '/domain') === 0) {
            $result = $this->certService->startOrder($message['from']);
            if (!$result['success']) {
                $this->telegram->sendMessage($chatId, $result['message']);
                return;
            }

            $orderId = $result['order']['id'];
            $keyboard = $this->buildTypeKeyboard($orderId);
            $messageText = "你正在申请 SSL 证书，请选择证书类型。\n";
            $messageText .= "根域名证书：仅保护 example.com，不包含子域名。\n";
            $messageText .= "通配符证书：保护 *.example.com，并同时包含 example.com。\n";
            $messageText .= "通配符证书必须使用 DNS TXT 验证，当前系统仅支持 DNS 手动解析。";
            $this->telegram->sendMessage($chatId, $messageText, $keyboard);
            return;
        }

        if (strpos($text, '/verify') === 0) {
            $domain = trim(str_replace('/verify', '', $text));
            $result = $this->certService->verifyOrder($message['from'], $domain);
            if (($result['success'] ?? false) && isset($result['order'])) {
                $keyboard = $this->buildIssuedKeyboard($result['order']['id']);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            } else {
                $this->telegram->sendMessage($chatId, $result['message']);
            }
            return;
        }

        if (strpos($text, '/status') === 0) {
            $domain = trim(str_replace('/status', '', $text));
            $result = $this->certService->status($message['from'], $domain);
            $this->telegram->sendMessage($chatId, $result['message']);
            return;
        }

        $this->telegram->sendMessage($chatId, '未知指令，发送 /help 查看指令。');
    }

    private function handleCallback(array $callback): void
    {
        $data = $callback['data'] ?? '';
        $from = $callback['from'] ?? [];
        $chatId = $callback['message']['chat']['id'] ?? null;
        $callbackId = $callback['id'] ?? '';

        if (!$chatId || $data === '') {
            return;
        }

        $parts = explode(':', $data);
        $action = $parts[0] ?? '';
        $orderId = isset($parts[2]) ? (int) $parts[2] : (isset($parts[1]) ? (int) $parts[1] : 0);

        if ($action === 'type') {
            $type = $parts[1] ?? 'root';
            $result = $this->certService->setOrderType($from['id'], $orderId, $type);
            $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
            if ($result['success']) {
                $prompt = "请输入主域名，例如 example.com。\n不要输入 http:// 或 https://\n不要输入 *.example.com";
                $this->telegram->sendMessage($chatId, $prompt);
            } else {
                $this->telegram->sendMessage($chatId, $result['message']);
            }
            return;
        }

        if ($action === 'verify') {
            $result = $this->certService->verifyOrderById($from['id'], $orderId);
            $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
            if (($result['success'] ?? false) && isset($result['order'])) {
                $keyboard = $this->buildIssuedKeyboard($result['order']['id']);
                $this->telegram->sendMessage($chatId, $result['message'], $keyboard);
            } else {
                $this->telegram->sendMessage($chatId, $result['message']);
            }
            return;
        }

        if ($action === 'later') {
            $this->telegram->answerCallbackQuery($callbackId, '已记录，你可稍后再验证。');
            $this->telegram->sendMessage($chatId, '好的，稍后完成解析后再点击验证即可。');
            return;
        }

        if ($action === 'download') {
            $result = $this->certService->getDownloadInfo($from['id'], $orderId);
            $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
            $this->telegram->sendMessage($chatId, $result['message']);
            return;
        }

        if ($action === 'info') {
            $result = $this->certService->getCertificateInfo($from['id'], $orderId);
            $this->telegram->answerCallbackQuery($callbackId, $result['message'] ?? '');
            $this->telegram->sendMessage($chatId, $result['message']);
            return;
        }
    }

    private function buildTypeKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '仅根域名证书（example.com）', 'callback_data' => "type:root:{$orderId}"],
            ],
            [
                ['text' => '通配符证书（*.example.com，包含根域名）', 'callback_data' => "type:wildcard:{$orderId}"],
            ],
        ];
    }

    private function buildDnsKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '我已完成解析', 'callback_data' => "verify:{$orderId}"],
                ['text' => '稍后再说', 'callback_data' => "later:{$orderId}"],
            ],
        ];
    }

    private function buildIssuedKeyboard(int $orderId): array
    {
        return [
            [
                ['text' => '下载证书', 'callback_data' => "download:{$orderId}"],
                ['text' => '查看证书信息', 'callback_data' => "info:{$orderId}"],
            ],
        ];
    }
}
