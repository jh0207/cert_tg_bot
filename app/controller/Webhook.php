<?php

namespace app\controller;

use app\service\TelegramService;
use think\Request;

class Webhook
{
    public function handle(Request $request)
    {
        $payload = $request->getInput();
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            return json(['ok' => false]);
        }

        $bot = new Bot(new TelegramService());
        $bot->handleUpdate($data);

        return json(['ok' => true]);
    }
}
