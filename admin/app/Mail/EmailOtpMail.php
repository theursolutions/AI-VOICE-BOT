<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmailOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public ?string $name,
        public int $ttlMinutes,
    ) {}

    public function build(): self
    {
        return $this
            // Subject deliberately WITHOUT the code in it: subjects are logged
            // by mail servers and shown in lock-screen previews, so a one-time
            // code in the subject line leaks further than it needs to.
            ->subject('Verify your email address')
            // ->view(), not ->markdown(): emails/auth/otp.blade.php is plain
            // HTML with inline styles (see emails/layout.blade.php), not a
            // Blade-markdown document.
            ->view('emails.auth.otp', [
                'code' => $this->code,
                'name' => $this->name,
                'ttl'  => $this->ttlMinutes,
            ]);
    }
}
