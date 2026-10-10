<?php

namespace Tests\Feature;

use App\Services\Telephony\TelephonyNumbers;
use Tests\TestCase;

/**
 * Asterisk numbers arrive exactly as dialed — an extension like 1122, a
 * local DID like 0424432233. Running them through the Twilio E.164
 * normaliser turned 1122 into +1122 and 0424432233 into +424432233, so
 * the dialed number never matched and every call was rejected.
 */
class TelephonyNumbersTest extends TestCase
{
    public function test_asterisk_numbers_are_kept_exactly_as_dialed(): void
    {
        $this->assertSame('1122', TelephonyNumbers::normalize('1122', TelephonyNumbers::ASTERISK));
        $this->assertSame('0424432233', TelephonyNumbers::normalize('042 4432233', TelephonyNumbers::ASTERISK));
    }

    public function test_twilio_numbers_are_still_e164(): void
    {
        $this->assertSame('+12346352160', TelephonyNumbers::normalize('12346352160', TelephonyNumbers::TWILIO));
        $this->assertSame('+12346352160', TelephonyNumbers::normalize('+1 234 635 2160', TelephonyNumbers::TWILIO));
    }

    public function test_entries_saved_before_providers_existed_are_twilio(): void
    {
        $this->assertSame(TelephonyNumbers::TWILIO, TelephonyNumbers::providerOf(['phone_number' => '+12346352160']));
        $this->assertSame(TelephonyNumbers::ASTERISK, TelephonyNumbers::providerOf(['provider' => 'asterisk']));
        $this->assertSame(TelephonyNumbers::TWILIO, TelephonyNumbers::providerOf(null));
    }
}
