<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;
use App\Core\Logger;
use RuntimeException;

/**
 * Outgoing mail. Drivers:
 *  - log : appends the message to storage/logs/mail.log (development only — it contains reset links)
 *  - mail: PHP mail() via the server MTA (production)
 */
final class Mailer
{
    public function send(string $to, string $subject, string $body): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invalid recipient address.');
        }
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $fromAddress = (string) Config::get('mail.from.address');
        $fromName = str_replace(["\r", "\n", '"'], '', (string) Config::get('mail.from.name'));

        $driver = (string) Config::get('mail.driver', 'log');
        if ($driver === 'mail') {
            $headers = [
                'From' => sprintf('"%s" <%s>', $fromName, $fromAddress),
                'MIME-Version' => '1.0',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Transfer-Encoding' => '8bit',
                'X-Mailer' => 'Business-ERP',
            ];
            $ok = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
            if (!$ok) {
                Logger::error('mail() failed to send message', ['to' => $to, 'subject' => $subject]);
            }
            return $ok;
        }

        $entry = sprintf(
            "==== %s ====\nFrom: %s <%s>\nTo: %s\nSubject: %s\n\n%s\n\n",
            gmdate('Y-m-d H:i:s') . ' UTC', $fromName, $fromAddress, $to, $subject, $body
        );
        return file_put_contents(App::basePath('storage/logs/mail.log'), $entry, FILE_APPEND | LOCK_EX) !== false;
    }
}
