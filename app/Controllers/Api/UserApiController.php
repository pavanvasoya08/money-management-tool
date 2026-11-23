<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Models\User;

/**
 * JSON API for users. Uses exactly the same Gate permission, record-level
 * scope and field policy as the HTML pages: hidden fields are never serialized.
 */
final class UserApiController extends Controller
{
    public function index(Request $request): Response
    {
        $me = $this->user();
        $page = User::paginate(
            ['q' => mb_substr($request->string('q'), 0, 100), 'status' => in_array($request->string('status'), ['active', 'inactive', 'suspended', 'expired'], true) ? $request->string('status') : ''],
            max(1, (int) $request->input('page', 1)),
            min(100, max(1, (int) $request->input('per_page', 25))),
            App::dataAccess()->constrain($me, 'users', 'u')
        );
        $data = array_map(fn (array $u): array => $this->serialize($me, $u), $page->items);
        return Response::json(['data' => $data, 'meta' => ['total' => $page->total, 'page' => $page->page, 'per_page' => $page->perPage, 'last_page' => $page->lastPage]]);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->user();
        $userId = $this->intParam($id);
        $user = User::find($userId);
        if ($user === null || !empty($user['deleted_at'])) {
            throw new HttpException(404, 'Record not found.');
        }
        App::dataAccess()->assertCanAccess($me, 'users', $userId);
        return Response::json(['data' => $this->serialize($me, $user + ['roles' => User::roles($userId)])]);
    }

    /** @param array<string, mixed> $me @param array<string, mixed> $u @return array<string, mixed> */
    private function serialize(array $me, array $u): array
    {
        $public = array_intersect_key($u, array_flip(['id', 'name', 'email', 'phone', 'status', 'last_login_at', 'last_login_ip', 'created_at']));
        $public['roles'] = array_map(static fn (array $r): array => ['name' => $r['name'], 'slug' => $r['slug']], $u['roles'] ?? []);
        $public['state'] = \App\Services\Authorization\AccountState::of($u);
        return App::fields()->present($me, 'users', $public);
    }
}
