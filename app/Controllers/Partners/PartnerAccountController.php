<?php

declare(strict_types=1);

namespace App\Controllers\Partners;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Partners\PartnerService;

/**
 * Link partners to ERP logins. Requires partners.link_account (route).
 * Creating a login also needs users.create; assigning the Partner role needs
 * users.assign_roles — the Phase 1/2 anti-escalation rules apply unchanged.
 */
final class PartnerAccountController extends Controller
{
    private PartnerService $partners;

    public function __construct()
    {
        $this->partners = new PartnerService(App::db());
    }

    public function link(Request $request, string $id): Response
    {
        $partner = $this->find($id);
        $data = $this->validate($request, ['user_id' => 'required|integer', 'note' => 'nullable|string|max:255'], ['user_id' => 'User']);
        $this->partners->link($partner, (int) $data['user_id'], $this->user(), $data['note']);
        return $this->back($partner, 'Login linked. What the partner can see is still decided by their roles and permissions.');
    }

    public function unlink(Request $request, string $id): Response
    {
        $partner = $this->find($id);
        $data = $this->validate($request, ['note' => 'nullable|string|max:255']);
        $this->partners->unlink($partner, $this->user(), $data['note']);
        return $this->back($partner, 'Login unlinked. The user account itself was not changed.');
    }

    public function createLogin(Request $request, string $id): Response
    {
        $partner = $this->find($id);
        $this->authorize('users.create');
        $data = $this->validate($request, [
            'email' => 'required|email|max:191|unique:users,email',
            'password' => 'required|string|confirmed',
            'assign_partner_role' => 'nullable|boolean',
        ], ['password' => 'Temporary password']);
        $assign = (bool) $data['assign_partner_role'];
        if ($assign) {
            $this->authorize('users.assign_roles');
        }
        $this->partners->createLogin($partner, ['email' => (string) $data['email'], 'password' => (string) $data['password'], 'assign_partner_role' => $assign], $this->user());
        return $this->back($partner, 'Login created and linked. The partner must change the temporary password at first sign-in.');
    }

    public function suspendLogin(Request $request, string $id): Response
    {
        $partner = $this->find($id);
        $data = $this->validate($request, ['reason' => 'required|string|max:255']);
        $this->partners->suspendLinkedLogin($partner, (string) $data['reason'], $this->user());
        return $this->back($partner, 'Login suspended and signed out of all devices. The partner record is unchanged.');
    }

    public function reactivateLogin(Request $request, string $id): Response
    {
        $partner = $this->find($id);
        $this->partners->reactivateLinkedLogin($partner, $this->user());
        return $this->back($partner, 'Login access reactivated.');
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $pid = $this->intParam($id);
        App::dataAccess()->assertCanAccess($this->user(), 'partners', $pid);
        return $this->partners->find($pid) ?? throw new HttpException(404, 'Partner not found.');
    }

    /** @param array<string, mixed> $partner */
    private function back(array $partner, string $message): Response
    {
        return $this->redirectRoute('partners.show', ['id' => $partner['id'], 'tab' => 'personal'])->with('success', $message);
    }
}
