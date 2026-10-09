<?php

namespace Tests\Unit;

use App\Support\Phone;
use App\Support\Pin;
use PHPUnit\Framework\TestCase;

class PhoneAndPinTest extends TestCase
{
    public function test_kenyan_mobile_numbers_are_normalised_however_they_are_typed()
    {
        foreach (['0712345678', '0712 345 678', '+254712345678', '254712345678', '+254-712-345-678', '(0712) 345678'] as $typed) {
            $this->assertSame('+254712345678', Phone::normalize($typed), $typed);
        }
        $this->assertSame('+254110345678', Phone::normalize('0110 345 678')); // newer 01xx numbers
    }

    public function test_landlines_short_and_foreign_numbers_are_refused()
    {
        foreach (['020 1234567', '071234567', '07123456789', '+255712345678', 'call me', '', null] as $bad) {
            $this->assertNull(Phone::normalize($bad), var_export($bad, true));
        }
    }

    public function test_easy_pins_are_weak()
    {
        foreach (['0000', '1111', '1234', '4321', '123456', '987654', '3456'] as $pin) {
            $this->assertTrue(Pin::isWeak($pin), $pin);
        }
    }

    public function test_ordinary_pins_are_fine()
    {
        foreach (['2580', '1357', '9021', '480613', '1123'] as $pin) {
            $this->assertFalse(Pin::isWeak($pin), $pin);
        }
    }
}
