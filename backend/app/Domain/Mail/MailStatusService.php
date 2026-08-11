<?php

namespace App\Domain\Mail;

use App\Models\SystemSetting;
use Symfony\Component\Mailer\Transport;

/**
 * Backs the admin-login warning and the password-reset gate from briefing
 * 12.2: while the mail server is unreachable or misconfigured, admins see a
 * red warning on login and password reset stays disabled for everyone.
 */
class MailStatusService
{
    public function isConfigured(): bool
    {
        return filled(SystemSetting::get('mail.host')) && filled(SystemSetting::get('mail.from_address'));
    }

    /** Attempts a real SMTP connection using the configured settings (briefing 12.2). */
    public function isReachable(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $host = SystemSetting::get('mail.host');
        $port = SystemSetting::get('mail.port', 587);
        $encryption = SystemSetting::get('mail.encryption', 'starttls');

        $scheme = match ($encryption) {
            'ssl_tls' => 'smtps',
            default => 'smtp',
        };

        try {
            $transport = Transport::fromDsn("{$scheme}://{$host}:{$port}");
            $transport->start();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isHealthy(): bool
    {
        return $this->isConfigured() && $this->isReachable();
    }
}
