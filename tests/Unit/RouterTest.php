<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\App;
use App\Core\Request;
use App\Exceptions\HttpException;
use Tests\TestCase;

final class RouterTest extends TestCase
{
    public function testNamedRoutesAndParameters(): void
    {
        $router = App::router();
        $this->assertSame('/users/42/edit', $router->url('users.edit', ['id' => 42]));
        $this->assertSame('/users?status=active', $router->url('users.index', ['status' => 'active']));
        $this->assertSame('/reset-password/abc', $router->url('password.reset', ['token' => 'abc']));
    }

    public function testRedirectRejectsExternalUrls(): void
    {
        $this->assertSame('/', \App\Core\Response::redirect('https://evil.example')->headers['Location']);
        $this->assertSame('/', \App\Core\Response::redirect('//evil.example')->headers['Location']);
        $this->assertSame('/dashboard', \App\Core\Response::redirect('/dashboard')->headers['Location']);
    }

    public function testUnknownRouteIs404(): void
    {
        $this->freshDatabase();
        $response = App::handle(new Request('GET', '/does-not-exist', [], [], ['REMOTE_ADDR' => '127.0.0.1'], []));
        $this->assertSame(404, $response->status);
    }

    public function testWrongMethodIs405(): void
    {
        $this->freshDatabase();
        App::session()->start(false);
        $response = App::handle(new Request('GET', '/logout', [], [], ['REMOTE_ADDR' => '127.0.0.1'], []));
        $this->assertSame(405, $response->status);
        $this->assertTrue($response->status !== 200);
        unset($response);
        $this->assertTrue(class_exists(HttpException::class));
    }
}
