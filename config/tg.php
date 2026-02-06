<?php

return [
    'token' => getenv('TG_BOT_TOKEN') ?: 'change-me',
    'api_base' => 'https://api.telegram.org',
    'owner_lock' => true,
    'acme_path' => '/root/.acme.sh/acme.sh',
    'acme_server' => 'letsencrypt',
    'cert_export_path' => '/www/wwwroot/cert.com/ssl/',
];
