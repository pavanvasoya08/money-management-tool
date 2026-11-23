<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Models\User;

/** Base for Control Center controllers (all routes are behind the super-admin middleware). */
abstract class AdminController extends Controller
{
    /** @param array<string, mixed> $data */
    protected function admin(string $template, string $section, string $title, array $data = []): Response
    {
        return $this->view($template, $data + [
            'title' => $title,
            'section' => $section,
            'breadcrumbs' => ['Control Center' => route('admin.overview'), $title => null],
        ]);
    }

    /** Any user including deleted ones (Super Admins can see and restore them). @return array<string, mixed> */
    protected function findUser(string $id): array
    {
        return User::find($this->intParam($id)) ?? throw new HttpException(404, 'User not found.');
    }

    /** @return array<string, mixed> */
    protected function findRole(string $id): array
    {
        return App::db()->first('SELECT * FROM roles WHERE id = ?', [$this->intParam($id)]) ?? throw new HttpException(404, 'Role not found.');
    }

    /** Normalise a datetime-local input (display timezone) to a UTC storage string. */
    protected function utc(?string $local): ?string
    {
        $local = trim((string) $local);
        if ($local === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($local, display_tz()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw \App\Exceptions\ValidationException::withMessage('date', 'Invalid date/time.');
        }
    }

    protected function back(Request $request, string $fallback): Response
    {
        $ref = parse_url((string) $request->header('Referer'));
        $path = ($ref['path'] ?? '') . (isset($ref['query']) ? '?' . $ref['query'] : '');
        return $this->redirect(str_starts_with($path, '/admin') ? $path : $fallback);
    }
}
