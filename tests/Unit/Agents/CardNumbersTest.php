<?php

namespace Tests\Unit\Agents;

use App\Support\Agents\CardNumbers;
use PHPUnit\Framework\TestCase;

/** A card number a caller reads out is never kept in a call's words or notes. */
class CardNumbersTest extends TestCase
{
    public function test_a_card_number_becomes_its_last_four_digits(): void
    {
        $this->assertSame('My card is •••• 1111, expiry 12 28.', CardNumbers::mask('My card is 4111 1111 1111 1111, expiry 12 28.'));
        $this->assertSame('رقم البطاقة •••• 0004', CardNumbers::mask('رقم البطاقة 5555-5555-5555-0004'));
        $this->assertSame('Amex •••• 0005', CardNumbers::mask('Amex 3782 822463 10005'));
        $this->assertSame('رقمي •••• 1111', CardNumbers::mask('رقمي ٤١١١١١١١١١١١١١١١'));
    }

    public function test_phone_numbers_and_other_numbers_stay(): void
    {
        foreach (['+20 101 234 5678', '0551234567', '+966 55 123 4567', 'Order 123456', '4111 1111 1111 1112', '2026-11-04'] as $text) {
            $this->assertSame($text, CardNumbers::mask($text), $text);
        }
    }
}
