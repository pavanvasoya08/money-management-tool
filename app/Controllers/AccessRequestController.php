<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Admin\AccessRequestService;

final class AccessRequestController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $this->validate($request, ['permission' => 'required|string|max:121|regex:/^[a-z_]+\.[a-z_]+$/', 'reason' => 'required|string|min:5|max:500']);
        (new AccessRequestService())->request($this->user(), (string) $data['permission'], (string) $data['reason']);
        return $this->redirectRoute('dashboard')->with('success', 'Your access request was sent to the Super Admin.');
    }
}
