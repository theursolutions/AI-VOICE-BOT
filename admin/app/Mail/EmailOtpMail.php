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
            ->subject('Your verification code is ' . $this->code)
            ->markdown('emails.auth.otp', [
                'code' => $this->code,
                'name' => $this->name,
                'ttl'  => $this->ttlMinutes,
            ]);
    }
}
