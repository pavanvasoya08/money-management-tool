<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Services\PasswordPolicy;
use App\Services\PasswordResetService;

final class PasswordResetController extends Controller
{
    private const SENT_MESSAGE = 'If an active account exists for that email, a password reset link has been sent. Please check your inbox.';

    public function showRequest(Request $request): Response
    {
        return $this->view('auth/forgot-password', ['title' => 'Forgot password'], layout: 'layouts/auth');
    }

    public function sendLink(Request $request): Response
    {
        $data = $this->validate($request, ['email' => 'required|email|max:191']);
        (new PasswordResetService())->request((string) $data['email'], $request);
        // Same response whether or not the account exists (no user enumeration).
        return $this->redirectRoute('password.request')->with('success', self::SENT_MESSAGE);
    }

    public function showReset(Request $request, string $token): Response
    {
        $valid = (new PasswordResetService())->findValid($token);
        return $this->view('auth/reset-password', [
            'title' => 'Reset password',
            'token' => $valid !== null ? $token : null,
            'email' => $valid['email'] ?? null,
            'policy' => PasswordPolicy::description(),
        ], layout: 'layouts/auth');
    }

    public function reset(Request $request): Response
    {
        $token = $request->string('token');
        $service = new PasswordResetService();
        $valid = $service->findValid($token);
        if ($valid === null) {
            return $this->redirectRoute('password.request')->with('error', 'This reset link is invalid, expired or has already been used. Please request a new one.');
        }

        $data = $this->validate($request, ['password' => 'required|string|confirmed'], ['password' => 'New password']);
        $problems = PasswordPolicy::validate((string) $data['password'], ['name' => $valid['name'], 'email' => $valid['email']]);
        if ($problems !== []) {
            throw new ValidationException(['password' => $problems], route('password.reset', ['token' => $token]));
        }
        if (!$service->reset($token, (string) $data['password'])) {
            return $this->redirectRoute('password.request')->with('error', 'This reset link is no longer valid. Please request a new one.');
        }
        return $this->redirectRoute('login')->with('success', 'Your password has been reset. You can now sign in.');
    }
}
