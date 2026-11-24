<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Admin\WorkflowService;
use App\Services\Gst\GstDocumentService;
use App\Services\Gst\GstinValidator;
use App\Services\Gst\GstLinkRegistry;
use App\Services\Gst\GstMasterService;
use App\Services\Gst\GstRegistrationService;
use App\Services\Gst\GstRelationService;
use App\Services\Partners\PartnerService;
use Tests\TestCase;

/** Phase 4: GST registration rules at the service layer. */
final class GstRegistrationTest extends TestCase
{
    private array $root;
    private GstRegistrationService $gs;
    private GstRelationService $rel;
    private int $p1;
    private int $p2;

    public function setUp(): void
    {
        $this->freshDatabase();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $this->gs = new GstRegistrationService(App::db());
        $this->rel = new GstRelationService(App::db());
        $ps = new PartnerService(App::db());
        $this->p1 = $ps->create(['name' => 'Alpha Partner', 'date_of_joining' => '2025-04-01'], $this->root);
        $this->p2 = $ps->create(['name' => 'Bravo Partner', 'date_of_joining' => '2025-04-01'], $this->root);
    }

    public static function g(string $first14): string
    {
        return $first14 . GstinValidator::checksum($first14);
    }

    private function id(string $table, string $code): int
    {
        return (int) App::db()->scalar("SELECT id FROM {$table} WHERE code = ?", [$code]);
    }

    /** @param array<string, mixed> $extra */
    private function create(string $gstin, array $extra = []): int
    {
        $state = substr(GstinValidator::normalize($gstin), 0, 2);
        [$id] = $this->gs->create($extra + [
            'gstin' => $gstin, 'legal_name' => 'Mehta Traders', 'registration_type_id' => $this->id('gst_registration_types', 'regular'),
            'registration_date' => '2025-04-01', 'effective_from' => '2025-04-01', 'status' => 'active',
            'addr_line1' => '1 Main Road', 'addr_city' => 'Ahmedabad', 'addr_state_id' => $this->id('gst_states', $state), 'addr_pin_code' => '380001',
        ], $this->root);
        return $id;
    }

    private function enableWorkflow(string $key): void
    {
        $wf = (int) App::db()->scalar('SELECT id FROM approval_workflows WHERE workflow_key = ?', [$key]);
        (new WorkflowService(App::db()))->saveSteps($wf, [['name' => 'Compliance review', 'approver_type' => 'permission', 'min_amount' => '0']], $this->root);
        (new WorkflowService(App::db()))->update($wf, true, null, $this->root);
    }

    public function testStateMasterUsesOfficialCodes(): void
    {
        $db = App::db();
        $this->assertSame('Gujarat', (string) $db->scalar("SELECT name FROM gst_states WHERE code = '24'"));
        $this->assertSame('Andhra Pradesh', (string) $db->scalar("SELECT name FROM gst_states WHERE code = '37'"));
        $this->assertSame('Ladakh', (string) $db->scalar("SELECT name FROM gst_states WHERE code = '38'"));
        $this->assertSame(0, (int) $db->scalar("SELECT is_active FROM gst_states WHERE code = '25'"), 'legacy Daman & Diu code is inactive');
        $this->assertSame(0, (int) $db->scalar("SELECT allows_registration FROM gst_states WHERE code = '96'"), 'Other Country is not a GSTIN state code');
    }

    public function testValidGstinIsAcceptedAndNormalised(): void
    {
        $v = new GstinValidator(App::db());
        $gstin = self::g('24AAKFM4821R1Z');
        $r = $v->validate('  ' . strtolower($gstin) . ' ');
        $this->assertTrue($r['valid'], implode(' ', $r['errors']));
        $this->assertSame($gstin, $r['gstin']);
        $this->assertSame('AAKFM4821R', $r['pan']);
        $this->assertSame('24', $r['state']['code']);
        // Known public examples verify the checksum algorithm itself.
        $this->assertTrue($v->validate('27AAPFU0939F1ZV')['valid']);
        $this->assertTrue($v->validate('29AAGCR4375J1ZU')['valid']);
        $id = $this->create(strtolower($gstin));
        $this->assertSame($gstin, (string) App::db()->scalar('SELECT gstin FROM gst_registrations WHERE id = ?', [$id]), 'stored uppercase');
    }

    public function testInvalidFormatsAreRejectedWithClearReasons(): void
    {
        $v = new GstinValidator(App::db());
        $cases = [
            '24AAKFM4821R1Z' => '15',                              // too short
            '24AAKFM4821R1Z55' => '15',                            // too long
            '24AAK-M4821R1Z5' => 'letters A–Z and digits',          // bad character
            self::g('24AAKDM4821R1Z') => 'PAN',                    // D is not a PAN holder type
            self::g('24AAKFM4821R0Z') => 'Character 13',             // entity number 0
            self::g('24AAKFM4821R1A') => '14',                       // 14th char must be Z for PAN-based types
        ];
        foreach ($cases as $gstin => $needle) {
            $r = $v->validate($gstin, App::db()->first("SELECT * FROM gst_registration_types WHERE code = 'regular'"));
            $this->assertFalse($r['valid'], $gstin);
            $this->assertContains($needle, implode(' ', $r['errors']), $gstin);
        }
    }

    public function testInvalidStateCodesAreRejected(): void
    {
        $v = new GstinValidator(App::db());
        foreach (['40', '00', '96'] as $code) {
            $this->assertFalse($v->validate(self::g($code . 'AAKFM4821R1Z'))['valid'], $code);
        }
        $legacy = $v->validate(self::g('25AAKFM4821R1Z'));
        $this->assertFalse($legacy['valid'], 'inactive legacy code rejected for new registrations');
        $this->assertTrue($v->validate(self::g('25AAKFM4821R1Z'), null, null, true)['valid'], 'but accepted when explicitly allowed (historic records)');
        $this->assertTrue($v->validate(self::g('97AAKFM4821R1Z'))['valid'], 'Other Territory 97 is valid');
    }

    public function testChecksumAndPanMismatchAreDetected(): void
    {
        $v = new GstinValidator(App::db());
        $good = self::g('24AAKFM4821R1Z');
        $bad = substr($good, 0, 14) . ($good[14] === 'A' ? 'B' : 'A');
        $r = $v->validate($bad);
        $this->assertFalse($r['valid']);
        $this->assertContains('check character', implode(' ', $r['errors']));
        $r = $v->validate($good, null, 'BKNPN5678L');
        $this->assertFalse($r['valid'], 'PAN entered separately must match characters 3–12');
        $this->assertContains('PAN', implode(' ', $r['errors']));
    }

    public function testTanBasedTypeNeedsTan(): void
    {
        $v = new GstinValidator(App::db());
        $tds = App::db()->first("SELECT * FROM gst_registration_types WHERE code = 'tds'");
        $this->assertTrue($v->validate(self::g('24AHMA12345B1D'), $tds)['valid'], 'TAN-based GSTIN');
        $this->assertFalse($v->validate(self::g('24AAKFM4821R1Z'), $tds)['valid'], 'PAN under a TDS registration is rejected');
    }

    public function testDuplicateGstinIsRejected(): void
    {
        $gstin = self::g('24AAKFM4821R1Z');
        $this->create($gstin);
        $this->assertThrows(ValidationException::class, fn () => $this->create(strtolower($gstin)), 'already registered');
        $this->assertSame(1, (int) App::db()->scalar('SELECT COUNT(*) FROM gst_registrations'));
    }

    public function testPartnersAndRegistrationsAreManyToManyWithHistory(): void
    {
        $a = $this->create(self::g('24AAKFM4821R1Z'));
        $b = $this->create(self::g('27AAKFM4821R1Z'));
        $partner = $this->id('gst_partner_relationship_types', 'partner');
        $m1 = $this->rel->addPartner($this->gs->find($a), ['partner_id' => $this->p1, 'relationship_type_id' => $partner, 'effective_from' => '2025-04-01'], $this->root);
        $this->rel->addPartner($this->gs->find($a), ['partner_id' => $this->p2, 'relationship_type_id' => $partner, 'effective_from' => '2025-04-01'], $this->root);
        $this->rel->addPartner($this->gs->find($b), ['partner_id' => $this->p1, 'relationship_type_id' => $partner, 'effective_from' => '2025-04-01'], $this->root);
        $this->assertSame(2, count($this->rel->partnerMappings($a)), 'one registration, two partners');
        $this->assertSame(2, count($this->rel->registrationsForPartner($this->p1, ['1 = 1', []])), 'one partner, two registrations');
        $this->assertThrows(ValidationException::class, fn () => $this->rel->addPartner($this->gs->find($a), ['partner_id' => $this->p1, 'relationship_type_id' => $partner, 'effective_from' => '2025-05-01'], $this->root));
        $this->rel->endPartner($this->gs->find($a), $m1, '2026-03-31', 'Retired', $this->root);
        $maps = $this->rel->partnerMappings($a);
        $this->assertSame(2, count($maps), 'ended mapping kept as history');
        $ended = array_values(array_filter($maps, static fn (array $m): bool => (int) $m['id'] === $m1))[0];
        $this->assertSame('ended', $ended['status']);
        $this->assertSame('2026-03-31', $ended['effective_to']);
        // The partner can be mapped again later as a new period.
        $this->rel->addPartner($this->gs->find($a), ['partner_id' => $this->p1, 'relationship_type_id' => $partner, 'effective_from' => '2026-06-01'], $this->root);
        $this->assertSame(3, count($this->rel->partnerMappings($a)));
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM partner_capital_transactions'), 'mappings never touch capital');
    }

    public function testSingleOwnerRuleIsOptional(): void
    {
        $a = $this->create(self::g('24AAKFM4821R1Z'));
        $owner = $this->id('gst_partner_relationship_types', 'owner');
        $this->rel->addPartner($this->gs->find($a), ['partner_id' => $this->p1, 'relationship_type_id' => $owner, 'effective_from' => '2025-04-01'], $this->root);
        $this->rel->addPartner($this->gs->find($a), ['partner_id' => $this->p2, 'relationship_type_id' => $owner, 'effective_from' => '2025-04-01'], $this->root);
        $this->assertSame(2, count($this->rel->partnerMappings($a)), 'several owners allowed by default');
        $b = $this->create(self::g('27AAKFM4821R1Z'));
        App::settings()->update(['gst_single_owner_rule' => '1', 'gst_document_expiry_warning_days' => '30'], (int) $this->root['id'], ['gst']);
        $this->rel->addPartner($this->gs->find($b), ['partner_id' => $this->p1, 'relationship_type_id' => $owner, 'effective_from' => '2025-04-01'], $this->root);
        $this->assertThrows(ValidationException::class, fn () => $this->rel->addPartner($this->gs->find($b), ['partner_id' => $this->p2, 'relationship_type_id' => $owner, 'effective_from' => '2025-04-01'], $this->root), 'only one owner');
    }

    public function testBusinessActivityMappings(): void
    {
        $a = $this->create(self::g('24AAKFM4821R1Z'));
        $ecom = $this->id('gst_business_activities', 'ecommerce');
        $mid = $this->rel->addActivity($this->gs->find($a), ['business_activity_id' => $ecom, 'effective_from' => '2025-04-01'], $this->root);
        $this->rel->addActivity($this->gs->find($a), ['business_activity_id' => $this->id('gst_business_activities', 'retail'), 'effective_from' => '2025-04-01'], $this->root);
        $this->assertThrows(ValidationException::class, fn () => $this->rel->addActivity($this->gs->find($a), ['business_activity_id' => $ecom, 'effective_from' => '2025-05-01'], $this->root), 'already mapped');
        $this->rel->updateActivity($this->gs->find($a), $mid, ['status' => 'inactive', 'effective_from' => '2025-04-01', 'effective_to' => '2026-01-31', 'notes' => 'Stopped marketplace sales'], $this->root);
        $this->assertSame('inactive', (string) App::db()->scalar('SELECT status FROM gst_registration_business_mappings WHERE id = ?', [$mid]));
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action LIKE 'gst.activity%' AND subject_id = ?", [$a]));
        // Masters: a code in use is locked.
        $this->assertThrows(ValidationException::class, fn () => (new GstMasterService(App::db()))->save('activities', ['code' => 'online', 'name' => 'E-commerce business', 'is_active' => '1'], $this->root, $ecom));
    }

    public function testExactlyOnePrincipalLocation(): void
    {
        $a = $this->create(self::g('24AAKFM4821R1Z'));
        $reg = $this->gs->find($a);
        $principal = (int) $this->rel->principal($a)['id'];
        $loc = $this->rel->saveLocation($reg, ['label' => 'Godown', 'address_line1' => 'Plot 41', 'city' => 'Ahmedabad', 'state_id' => $this->id('gst_states', '24'), 'pin_code' => '382415'], $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => $this->rel->setLocationStatus($reg, $principal, false, $this->root));
        $this->rel->makePrincipal($reg, $loc, $this->root);
        $this->assertSame($loc, (int) $this->rel->principal($a)['id']);
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM gst_registration_addresses WHERE gst_registration_id = ? AND is_principal = 1 AND status = 'active'", [$a]));
        $this->rel->setLocationStatus($reg, $principal, false, $this->root);
        $this->assertSame(2, count($this->rel->locations($a)), 'deactivated, not deleted');
    }

    public function testStatusLifecycleAndHistory(): void
    {
        $a = $this->create(self::g('24AAKFM4821R1Z'));
        $this->gs->changeStatus($this->gs->find($a), 'suspended', 'Notice received', '2026-01-10', $this->root);
        $this->gs->changeStatus($this->gs->find($a), 'active', null, '2026-02-01', $this->root);
        $this->assertThrows(ValidationException::class, fn () => $this->gs->changeStatus($this->gs->find($a), 'cancelled', '', '2026-03-01', $this->root), 'reason');
        $this->gs->changeStatus($this->gs->find($a), 'cancelled', 'Business closed', '2026-03-31', $this->root);
        $this->assertSame('cancelled', $this->gs->find($a)['status']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->gs->changeStatus($this->gs->find($a), 'active', null, null, $this->root));
        $hist = $this->gs->statusHistory($a);
        $this->assertSame(['cancelled', 'active', 'suspended', 'active', 'pending_verification'], array_column($hist, 'to_status'));
        $this->assertSame('Business closed', $hist[0]['reason']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->rel->addPartner($this->gs->find($a), ['partner_id' => $this->p1, 'relationship_type_id' => $this->id('gst_partner_relationship_types', 'partner')], $this->root), 'kept as history');
    }

    public function testApprovalWorkflowsForCreateKeyChangeAndCancellation(): void
    {
        $this->enableWorkflow('gst_registration_create');
        $this->enableWorkflow('gst_registration_update');
        $this->enableWorkflow('gst_registration_cancel');
        $checker = $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker');
        [$id, $change] = $this->gs->create([
            'gstin' => self::g('24AAKFM4821R1Z'), 'legal_name' => 'Mehta Traders', 'registration_type_id' => $this->id('gst_registration_types', 'regular'), 'status' => 'active',
            'addr_line1' => '1 Main Road', 'addr_city' => 'Ahmedabad', 'addr_state_id' => $this->id('gst_states', '24'), 'addr_pin_code' => '380001',
        ], $this->root);
        $this->assertSame('pending', $change['status']);
        $this->assertSame('pending_verification', $this->gs->find($id)['status']);
        $this->assertSame('pending', $this->gs->find($id)['approval_state']);
        App::approvals()->approve((int) $change['approval_request_id'], $checker);
        $this->assertSame('active', $this->gs->find($id)['status'], 'approval applies the requested status');
        $this->assertSame('none', $this->gs->find($id)['approval_state']);

        // Key change waits; non-key change applies at once.
        $reg = $this->gs->find($id);
        $res = $this->gs->update($reg, ['legal_name' => 'Mehta Traders LLP', 'trade_name' => 'Mehta Store'] + array_intersect_key($reg, array_flip(GstRegistrationService::KEY_FIELDS))
            + ['addr_line1' => '1 Main Road', 'addr_city' => 'Ahmedabad', 'addr_state_id' => $this->id('gst_states', '24'), 'addr_pin_code' => '380001'], $this->root, 'Converted to LLP');
        $this->assertSame(['legal_name'], $res['key']);
        $this->assertSame('Mehta Traders', $this->gs->find($id)['legal_name'], 'key change not applied before approval');
        $this->assertSame('Mehta Store', $this->gs->find($id)['trade_name'], 'non-key change applied');
        App::approvals()->reject((int) $res['change']['approval_request_id'], $checker, 'Certificate not yet amended');
        $this->assertSame('Mehta Traders', $this->gs->find($id)['legal_name']);
        $this->assertSame('rejected', $this->gs->change((int) $res['change']['id'])['status']);

        // Cancellation goes through approval too.
        $c = $this->gs->changeStatus($this->gs->find($id), 'cancelled', 'Branch closed', '2026-03-31', $this->root);
        $this->assertSame('pending', $c['status']);
        $this->assertSame('active', $this->gs->find($id)['status']);
        App::approvals()->approve((int) $c['approval_request_id'], $checker);
        $this->assertSame('cancelled', $this->gs->find($id)['status']);
    }

    public function testArchiveRulesAndReferencedRegistrationsAreProtected(): void
    {
        $a = $this->create(self::g('24AAKFM4821R1Z'));
        $this->assertThrows(DomainRuleException::class, fn () => $this->gs->archive($this->gs->find($a), 'old', $this->root), 'Deactivate or cancel');
        $this->gs->changeStatus($this->gs->find($a), 'inactive', 'Not used', null, $this->root);
        // A future module's record refers to it: archiving is refused.
        App::db()->insert('gst_registration_links', ['gst_registration_id' => $a, 'linked_type' => 'bank_account', 'linked_id' => 9, 'status' => 'active', 'created_by' => (int) $this->root['id'], 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue((new GstLinkRegistry(App::db()))->isReferenced($a));
        $this->assertThrows(DomainRuleException::class, fn () => $this->gs->archive($this->gs->find($a), 'old', $this->root), 'refer');
        App::db()->update('gst_registration_links', ['status' => 'ended'], ['gst_registration_id' => $a]);
        $this->gs->archive($this->gs->find($a), 'Old record', $this->root);
        $this->assertSame(1, (int) $this->gs->find($a)['is_archived']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->rel->saveLocation($this->gs->find($a), ['address_line1' => 'x', 'city' => 'y', 'state_id' => $this->id('gst_states', '24'), 'pin_code' => '1'], $this->root));
        $this->gs->restore($this->gs->find($a), $this->root);
        $this->assertSame(0, (int) $this->gs->find($a)['is_archived']);
        $this->assertSame(1, (int) App::db()->scalar('SELECT COUNT(*) FROM gst_registrations'), 'never deleted');
        $this->assertThrows(DomainRuleException::class, fn () => (new GstLinkRegistry(App::db()))->link($a, 'seller_account', 1, null, $this->root), 'not registered');
    }

    public function testOnlyOneDefaultRegistration(): void
    {
        $a = $this->create(self::g('24AAKFM4821R1Z'));
        $b = $this->create(self::g('27AAKFM4821R1Z'));
        $p = $this->create(self::g('29AAKFM4821R1Z'), ['status' => 'pending_verification']);
        $base = ['is_default' => '1', 'effective_from' => '2025-04-01'];
        $this->rel->saveSettings($this->gs->find($a), $base, $this->root);
        $this->rel->saveSettings($this->gs->find($b), $base, $this->root);
        $this->assertSame($b, (int) $this->rel->defaultRegistration()['id']);
        $this->assertSame(1, (int) App::db()->scalar('SELECT COUNT(*) FROM gst_registration_settings WHERE is_default = 1'));
        $this->assertThrows(ValidationException::class, fn () => $this->rel->saveSettings($this->gs->find($p), $base, $this->root), 'active, approved');
    }

    public function testDocumentsAreValidatedArchivedAndAudited(): void
    {
        $a = $this->create(self::g('24AAKFM4821R1Z'));
        $ds = new GstDocumentService(App::db());
        $tmp = tempnam(sys_get_temp_dir(), 'gst');
        file_put_contents($tmp, "%PDF-1.4\n%%EOF\n");
        $doc = $ds->upload($this->gs->find($a), ['name' => 'REG-06.pdf', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)], ['document_type' => 'registration_certificate'], $this->root, false);
        $this->assertSame('GST registration certificate (REG-06)', $ds->find($doc)['title'], 'title defaults to the type');
        $exe = tempnam(sys_get_temp_dir(), 'gst');
        file_put_contents($exe, "MZ\x90\x00binary");
        $this->assertThrows(ValidationException::class, fn () => $ds->upload($this->gs->find($a), ['name' => 'tool.exe', 'tmp_name' => $exe, 'error' => 0, 'size' => filesize($exe)], ['document_type' => 'other'], $this->root, false));
        $fake = tempnam(sys_get_temp_dir(), 'gst');
        file_put_contents($fake, "MZ\x90\x00binary");
        $this->assertThrows(ValidationException::class, fn () => $ds->upload($this->gs->find($a), ['name' => 'renamed.pdf', 'tmp_name' => $fake, 'error' => 0, 'size' => filesize($fake)], ['document_type' => 'other'], $this->root, false), null);
        $this->assertThrows(ValidationException::class, fn () => $ds->upload($this->gs->find($a), ['name' => 'a.pdf', 'tmp_name' => $tmp, 'error' => 0, 'size' => 10], ['document_type' => 'nonsense'], $this->root, false));
        $ds->archive($ds->find($doc), $this->gs->find($a), 'Replaced by amended certificate', $this->root);
        $this->assertSame(0, count($ds->forRegistration($a)));
        $this->assertSame(1, count($ds->forRegistration($a, true)), 'archived document kept as history');
        $stored = (string) App::db()->scalar('SELECT stored_name FROM gst_registration_documents WHERE id = ?', [$doc]);
        $this->assertFalse(str_contains($stored, 'REG-06'), 'stored under a random name');
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action = 'gst.document_uploaded' AND subject_id = ?", [$a]));
    }

    public function testEveryChangeIsAuditedWithoutSecrets(): void
    {
        $a = $this->create(self::g('24AAKFM4821R1Z'), ['portal_reference' => 'ARN AA2404250123']);
        $this->gs->changeStatus($this->gs->find($a), 'suspended', 'Notice', null, $this->root);
        $actions = array_column(App::db()->select("SELECT action FROM activity_logs WHERE subject_type = 'gst_registration' AND subject_id = ? ORDER BY id", [$a]), 'action');
        $this->assertTrue(in_array('gst.registration_created', $actions, true));
        $this->assertTrue(in_array('gst.status_changed', $actions, true));
        $cols = array_column(App::db()->select("PRAGMA table_info('gst_registrations')"), 'name');
        foreach ($cols as $c) {
            $this->assertFalse((bool) preg_match('/password|otp|pin_code|secret|token/i', $c), "no credential column: {$c}");
        }
    }
}
