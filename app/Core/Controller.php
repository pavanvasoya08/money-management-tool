<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/**
 * Base controller: keeps controllers thin. Business rules live in Services,
 * authorization in the Gate, data access in Models.
 */
abstract class Controller
{
    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200, ?string $layout = 'layouts/app'): Response
    {
        return new Response(App::view()->render($template, $data, $layout), $status);
    }

    protected function redirect(string $url): Response
    {
        return Response::redirect($url);
    }

    protected function redirectRoute(string $name, array $params = []): Response
    {
        return Response::redirect(route($name, $params));
    }

    /**
     * Validate input or throw (the error handler redirects back with errors + old input).
     *
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules, array $labels = []): array
    {
        $input = $request->all();
        if (isset($input['email']) && is_string($input['email'])) {
            // Emails are stored lowercase; normalise before uniqueness checks.
            $input['email'] = mb_strtolower(trim($input['email']));
        }
        $validator = Validator::make($input, $rules, $labels);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }
        return $validator->validated();
    }

    /** Server-side permission check; throws AuthorizationException (403). */
    protected function authorize(string $permission, mixed $resource = null): void
    {
        App::gate()->authorize($this->user(), $permission, $resource);
    }

    /** @return array<string, mixed> */
    protected function user(): array
    {
        return App::auth()->user() ?? throw new HttpException(401, 'Not authenticated.');
    }

    protected function intParam(string $value): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new HttpException(404, 'Page not found.');
        }
        return (int) $value;
    }
}
