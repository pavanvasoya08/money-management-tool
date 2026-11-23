<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\RememberMeService;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login', ['title' => 'Sign in'], layout: 'layouts/auth');
    }

    public function login(Request $request): Response
    {
        $data = $this->validate($request, [
            'email' => 'required|email|max:191',
            'password' => 'required|string|max:4096',
            'remember' => 'nullable|boolean',
        ]);

        $result = App::auth()->attempt((string) $data['email'], (string) $request->post['password'], (bool) $data['remember'], $request);
        if (!$result->success) {
            return $this->redirectRoute('login')->withErrors(['email' => [(string) $result->message]], ['email' => $data['email']]);
        }

        $intended = (string) App::session()->pull('url.intended', route('dashboard'));
        $response = $this->redirect($intended)->with('success', 'Welcome back, ' . (App::auth()->user()['name'] ?? '') . '!');
        if ($result->rememberCookie !== null) {
            (new RememberMeService())->attachCookie($response, $result->rememberCookie, $request);
        }
        return $response;
    }

    public function logout(Request $request): Response
    {
        App::auth()->logout($request);
        $response = $this->redirectRoute('login')->with('success', 'You have been signed out.');
        (new RememberMeService())->clearCookie($response);
        return $response;
    }
}
