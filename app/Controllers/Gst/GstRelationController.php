<?php

declare(strict_types=1);

namespace App\Controllers\Gst;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Gst\GstDocumentService;
use App\Services\Gst\GstRelationService;

/**
 * Partner associations, business activities, locations, settings and documents
 * of one registration. Route middleware checks the action permission; the
 * registration itself is loaded through the record-scope check (404 if outside).
 */
final class GstRelationController extends Controller
{
    private GstRelationService $rel;

    public function __construct()
    {
        $this->rel = new GstRelationService(App::db());
    }

    public function addPartner(Request $request, string $id): Response
    {
        $reg = $this->reg($id);
        $data = $this->validate($request, ['partner_id' => 'required|integer', 'relationship_type_id' => 'required|integer', 'effective_from' => 'required|date',
            'effective_to' => 'nullable|date', 'notes' => 'nullable|string|max:255'], ['partner_id' => 'Partner', 'relationship_type_id' => 'Relationship', 'effective_from' => 'From', 'effective_to' => 'To']);
        // The partner must be one the user may see.
        App::dataAccess()->assertCanAccess($this->user(), 'partners', (int) $data['partner_id']);
        $this->rel->addPartner($reg, $data, $this->user());
        return $this->back($reg, 'partners', 'Partner mapped.');
    }

    public function endPartner(Request $request, string $id, string $mapping): Response
    {
        $reg = $this->reg($id);
        $data = $this->validate($request, ['effective_to' => 'nullable|date', 'reason' => 'required|string|max:255'], ['effective_to' => 'End date']);
        $this->rel->endPartner($reg, $this->intParam($mapping), $data['effective_to'] ?? null, (string) $data['reason'], $this->user());
        return $this->back($reg, 'partners', 'Mapping ended. It stays in the mapping history.');
    }

    public function addActivity(Request $request, string $id): Response
    {
        $reg = $this->reg($id);
        $data = $this->validate($request, ['business_activity_id' => 'required|integer', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date', 'notes' => 'nullable|string|max:255'],
            ['business_activity_id' => 'Business activity', 'effective_from' => 'From', 'effective_to' => 'To']);
        $this->rel->addActivity($reg, $data, $this->user());
        return $this->back($reg, 'activities', 'Business activity mapped.');
    }

    public function updateActivity(Request $request, string $id, string $mapping): Response
    {
        $reg = $this->reg($id);
        $data = $this->validate($request, ['effective_from' => 'required|date', 'effective_to' => 'nullable|date', 'status' => 'required|in:active,inactive', 'notes' => 'nullable|string|max:255'],
            ['effective_from' => 'From', 'effective_to' => 'To']);
        $this->rel->updateActivity($reg, $this->intParam($mapping), $data, $this->user());
        return $this->back($reg, 'activities', 'Business activity mapping saved.');
    }

    public function saveLocation(Request $request, string $id, ?string $location = null): Response
    {
        $reg = $this->reg($id);
        $this->assertAddressEditable();
        $data = $this->validate($request, [
            'label' => 'nullable|string|max:100', 'address_line1' => 'required|string|max:200', 'address_line2' => 'nullable|string|max:200', 'city' => 'required|string|max:100',
            'district' => 'nullable|string|max:100', 'state_id' => 'required|integer', 'pin_code' => 'required|string|max:10', 'country' => 'nullable|string|max:80',
            'contact_name' => 'nullable|string|max:150', 'contact_phone' => 'nullable|string|regex:/^(\+?\d{1,3}[\s-]?)?[6-9]\d{9}$|^\+?[0-9][0-9\s-]{6,18}$/', 'contact_email' => 'nullable|email|max:191',
        ], ['address_line1' => 'Address line 1', 'state_id' => 'State', 'pin_code' => 'PIN code', 'contact_phone' => 'Contact number']);
        $this->rel->saveLocation($reg, $data, $this->user(), $location !== null ? $this->intParam($location) : null);
        return $this->back($reg, 'locations', $location === null ? 'Business location added.' : 'Business location saved.');
    }

    public function updateLocation(Request $request, string $id, string $location): Response
    {
        return $this->saveLocation($request, $id, $location);
    }

    public function locationStatus(Request $request, string $id, string $location): Response
    {
        $reg = $this->reg($id);
        $active = $request->string('status') === 'active';
        $this->rel->setLocationStatus($reg, $this->intParam($location), $active, $this->user());
        return $this->back($reg, 'locations', $active ? 'Location activated.' : 'Location deactivated.');
    }

    public function principal(Request $request, string $id, string $location): Response
    {
        $reg = $this->reg($id);
        $this->rel->makePrincipal($reg, $this->intParam($location), $this->user());
        return $this->back($reg, 'locations', 'Principal place of business changed.');
    }

    public function saveSettings(Request $request, string $id): Response
    {
        $reg = $this->reg($id);
        $data = $this->validate($request, [
            'is_default' => 'nullable|boolean', 'default_place_of_supply_state_id' => 'nullable|integer', 'default_state_id' => 'nullable|integer',
            'applicable_registration_type_id' => 'nullable|integer', 'prices_include_tax' => 'nullable|boolean', 'e_invoicing_applicable' => 'nullable|boolean',
            'e_way_bill_applicable' => 'nullable|boolean', 'effective_from' => 'nullable|date', 'effective_to' => 'nullable|date', 'notes' => 'nullable|string|max:500',
        ], ['effective_from' => 'Effective from', 'effective_to' => 'Effective to']);
        $this->rel->saveSettings($reg, $data, $this->user());
        return $this->back($reg, 'settings', 'Settings saved.');
    }

    public function uploadDocument(Request $request, string $id): Response
    {
        $reg = $this->reg($id);
        $data = $this->validate($request, ['document_type' => 'required|string|max:40', 'title' => 'nullable|string|max:150', 'description' => 'nullable|string|max:500', 'expiry_date' => 'nullable|date'],
            ['document_type' => 'Document type', 'expiry_date' => 'Expiry date']);
        $file = $request->file('document') ?? throw \App\Exceptions\ValidationException::withMessage('document', 'Choose a file to upload.');
        (new GstDocumentService(App::db()))->upload($reg, $file, $data, $this->user());
        return $this->back($reg, 'documents', 'Document uploaded.');
    }

    public function download(Request $request, string $doc): Response
    {
        [$d, $reg] = $this->doc($doc);
        return (new GstDocumentService(App::db()))->response($d, $reg, $this->user(), false);
    }

    public function preview(Request $request, string $doc): Response
    {
        [$d, $reg] = $this->doc($doc);
        if (!GstDocumentService::previewable($d)) {
            throw new HttpException(404, 'This file type cannot be previewed. Download it instead.');
        }
        return (new GstDocumentService(App::db()))->response($d, $reg, $this->user(), true);
    }

    public function archiveDocument(Request $request, string $doc): Response
    {
        [$d, $reg] = $this->doc($doc);
        $data = $this->validate($request, ['reason' => 'required|string|max:255']);
        (new GstDocumentService(App::db()))->archive($d, $reg, (string) $data['reason'], $this->user());
        return $this->back($reg, 'documents', 'Document archived. It is kept in the document history.');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function reg(string $id): array
    {
        return (new GstRegistrationController())->findReg($id);
    }

    /** Documents are reachable only through a registration the user may see. @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function doc(string $id): array
    {
        $d = (new GstDocumentService(App::db()))->find($this->intParam($id)) ?? throw new HttpException(404, 'Document not found.');
        $reg = $this->reg((string) $d['gst_registration_id']);
        return [$d, $reg];
    }

    private function assertAddressEditable(): void
    {
        $access = App::fields()->access($this->user(), 'gst_registrations', 'address');
        if (!$access->canEdit()) {
            throw new \App\Exceptions\AuthorizationException('gst_registrations.field.address', 'You are not allowed to change business addresses.');
        }
    }

    /** @param array<string, mixed> $reg */
    private function back(array $reg, string $tab, string $message): Response
    {
        return $this->redirectRoute('gst.show', ['id' => $reg['id'], 'tab' => $tab])->with('success', $message);
    }
}
