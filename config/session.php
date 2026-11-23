<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Env;

return [
    'name' => (string) Env::get('SESSION_NAME', 'erp_session'),
    // Absolute session lifetime (minutes). The idle timeout is configurable in Settings.
    'lifetime' => (int) Env::get('SESSION_LIFETIME', 480),
    'secure' => Env::get('SESSION_SECURE_COOKIE', true) === true,
    'same_site' => in_array(Env::get('SESSION_SAME_SITE', 'Lax'), ['Lax', 'Strict'], true) ? Env::get('SESSION_SAME_SITE', 'Lax') : 'Lax',
    'path' => App::basePath('storage/sessions'),
];
