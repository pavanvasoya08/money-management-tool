<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PasswordHasher;
use App\Services\PasswordPolicy;
use Tests\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function setUp(): void
    {
        $this->freshDatabase();
    }

    public function testRejectsWeakPasswords(): void
    {
        $this->assertTrue(PasswordPolicy::validate('short') !== []);
        $this->assertTrue(PasswordPolicy::validate('alllowercase123!') !== [], 'needs uppercase');
        $this->assertTrue(PasswordPolicy::validate('NoNumbersHere!!') !== [], 'needs number');
        $this->assertTrue(PasswordPolicy::validate('NoSymbols12345') !== [], 'needs symbol');
        $this->assertTrue(PasswordPolicy::validate('Aaaaaaa1!xyz') !== [], 'repeated characters');
    }

    public function testRejectsPersonalInformation(): void
    {
        $problems = PasswordPolicy::validate('Priyanka#2026x', ['name' => 'Priyanka Rao', 'email' => 'pr@example.com']);
        $this->assertTrue(in_array('Do not include your name or email address.', $problems, true));
    }

    public function testAcceptsStrongPassword(): void
    {
        $this->assertSame([], PasswordPolicy::validate('Tr4in-Station!x', ['name' => 'Asha Verma', 'email' => 'asha@example.com']));
    }

    public function testHashingNeverStoresPlaintext(): void
    {
        $hash = PasswordHasher::hash('Tr4in-Station!x');
        $this->assertNotContains('Tr4in-Station!x', $hash);
        $this->assertTrue(PasswordHasher::verify('Tr4in-Station!x', $hash));
        $this->assertFalse(PasswordHasher::verify('wrong', $hash));
        $this->assertFalse(PasswordHasher::needsRehash($hash));
    }
}
