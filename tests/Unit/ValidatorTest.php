<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use Tests\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredAndEmail(): void
    {
        $v = new Validator(['email' => 'not-an-email', 'name' => ''], ['email' => 'required|email', 'name' => 'required']);
        $this->assertTrue($v->fails());
        $this->assertContains('valid email', $v->errors()['email'][0]);
        $this->assertContains('required', $v->errors()['name'][0]);
    }

    public function testMinMaxForStringsAndIntegers(): void
    {
        $v = new Validator(['name' => 'ab', 'age' => '150'], ['name' => 'string|min:3', 'age' => 'integer|max:100']);
        $this->assertContains('at least 3 characters', $v->errors()['name'][0]);
        $this->assertContains('greater than 100', $v->errors()['age'][0]);

        $ok = new Validator(['age' => '42'], ['age' => 'required|integer|min:5|max:100']);
        $this->assertFalse($ok->fails());
        $this->assertSame(42, $ok->validated()['age']);
    }

    public function testConfirmedInAndTimezone(): void
    {
        $v = new Validator(
            ['password' => 'a', 'password_confirmation' => 'b', 'status' => 'deleted', 'tz' => 'Mars/Base'],
            ['password' => 'confirmed', 'status' => 'in:active,inactive', 'tz' => 'timezone']
        );
        $this->assertSame(3, count($v->errors()));
    }

    public function testRejectsArraysWhereScalarsExpected(): void
    {
        $v = new Validator(['email' => ['a@b.c']], ['email' => 'required|email']);
        $this->assertTrue($v->fails());
    }

    public function testNullableAndBooleanCasting(): void
    {
        $v = new Validator(['phone' => '', 'remember' => '1'], ['phone' => 'nullable|string|max:20', 'remember' => 'nullable|boolean']);
        $this->assertFalse($v->fails());
        $this->assertNull($v->validated()['phone']);
        $this->assertTrue($v->validated()['remember']);
    }
}
