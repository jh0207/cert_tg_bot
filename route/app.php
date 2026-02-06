<?php

use think\facade\Route;

Route::post('webhook', 'Webhook/handle');
