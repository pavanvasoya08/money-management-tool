<?php

declare(strict_types=1);

use App\Core\Env;

return [
    // log  : writes messages to storage/logs/mail.log (development)
    // mail : PHP mail() through the server's MTA (sendmail/postfix)
    'driver' => (string) Env::get('MAIL_DRIVER', 'log'),
    'from' => [
        'address' => (string) Env::get('MAIL_FROM_ADDRESS', 'no-reply@example.com'),
        'name' => (string) Env::get('MAIL_FROM_NAME', 'Business ERP'),
    ],
];
