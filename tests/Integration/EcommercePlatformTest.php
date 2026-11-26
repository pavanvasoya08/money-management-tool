<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Admin\PermissionService;
use App\Services\Admin\ResourceAssignmentService;
use App\Services\Ecommerce\PlatformCategoryService;
use App\Services\Ecommerce\PlatformDocumentService;
use App\Services\Ecommerce\PlatformNoteService;
use App\Services\Ecommerce\PlatformScope;
use App\Services\Ecommerce\PlatformService;
use App\Services\Inventory\WarehouseService;
use App\Services\Product\DatedMappingService;
use App\Services\Gst\GstinValidator;
use Tests\TestCase;

/** Phase 8A: platform master, categories, configuration, status, archive, mappings, documents, notes, history and scope at the service layer. */
final class EcommercePlatformTest extends TestCase
{
    private array $root;
    private PlatformService $svc;

    public function setUp(): void
    {
        $this->freshDatabase();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $this->svc = new PlatformService(App::db());
    }

    private function cat(string $code): int
    {
        return (int) App::db()->scalar('SELECT id FROM ecommerce_platform_categories WHERE code = ?', [$code]);
    }

    private function platform(string $name, string $cat = 'marketplace', array $extra = []): array
    {
        return $this->svc->find($this->svc->create(['name' => $name, 'category_id' => $this->cat($cat)] + $extra, $this->root));
    }

    private function gst(string $first14, string $state): int
    {
        return (new \App\Services\Gst\GstRegistrationService(App::db()))->create(['gstin' => $first14 . GstinValidator::checksum($first14), 'legal_name' => 'Mehta Traders',
            'registration_type_id' => (int) App::db()->scalar("SELECT id FROM gst_registration_types WHERE code = 'regular'"), 'status' => 'active', 'addr_line1' => '1 Road',
            'addr_city' => 'City', 'addr_state_id' => (int) App::db()->scalar('SELECT id FROM gst_states WHERE code = ?', [$state]), 'addr_pin_code' => '380001'], $this->root)[0];
    }

    private function warehouse(string $name): int
    {
        return (new WarehouseService(App::db()))->create(['name' => $name, 'warehouse_type_id' => (int) App::db()->scalar("SELECT id FROM warehouse_types WHERE code = 'main'")], $this->root);
    }

    /** @return array<string, mixed> */
    private function fileOf(string $content, string $name): array
    {
        $path = sys_get_temp_dir() . '/p8-' . bin2hex(random_bytes(4));
        file_put_contents($path, $content);
        return ['name' => $name, 'tmp_name' => $path, 'error' => 0, 'size' => filesize($path)];
    }

    public function testCreateMultiplePlatformsWithCodesCapabilitiesAndValidation(): void
    {
        $amz = $this->platform('Amazon India', extra: ['website_url' => 'https://sellercentral.amazon.in', 'description' => 'Marketplace']);
        $fk = $this->platform('Flipkart');
        $shop = $this->platform('Our Web Shop', 'own_website', ['platform_code' => 'web']);
        $this->assertSame('AMAZONINDIA', $amz['platform_code'], 'code generated from the name');
        $this->assertSame('WEB', $shop['platform_code'], 'code is upper-cased');
        $this->assertSame('under_configuration', $fk['status']);
        $this->assertSame('not_connected', $fk['connection_status'], 'never connected without an integration');
        $this->assertSame(36, strlen($amz['uuid']));
        $this->assertSame(count(PlatformService::CAPABILITIES), (int) App::db()->scalar('SELECT COUNT(*) FROM ecommerce_platform_capabilities WHERE platform_id = ?', [$fk['id']]));
        $this->assertSame(3, count($this->svc->list($this->root)));
        // An unlimited, editable list: a second platform with a similar name gets its own code.
        $this->assertSame('AMAZONINDIA-2', $this->platform('Amazon-India!')['platform_code']);
        $this->assertThrows(ValidationException::class, fn () => $this->platform('amazon india'), 'already exists');
        $this->assertThrows(ValidationException::class, fn () => $this->platform('Meesho', extra: ['platform_code' => 'WEB']), 'already used');
        $this->assertThrows(ValidationException::class, fn () => $this->platform('Meesho', extra: ['website_url' => 'javascript:alert(1)']), null);
        $this->assertThrows(ValidationException::class, fn () => $this->platform('Meesho', extra: ['country' => 'ZZ']), 'country');
        $this->assertThrows(ValidationException::class, fn () => $this->platform('Meesho', extra: ['platform_code' => 'a b']), null);
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'platforms.created' AND subject_id = ?", [$amz['id']]));
        $this->assertSame('created', App::db()->scalar('SELECT event FROM ecommerce_platform_history WHERE platform_id = ? ORDER BY id LIMIT 1', [$amz['id']]));
    }

    public function testCustomCategoriesAreEditableAndProtectedWhileInUse(): void
    {
        $cats = new PlatformCategoryService(App::db());
        $id = $cats->save(['name' => 'Quick Commerce', 'description' => 'Instant delivery apps'], null, $this->root);
        $this->assertSame('quick_commerce', App::db()->scalar('SELECT code FROM ecommerce_platform_categories WHERE id = ?', [$id]));
        $p = $this->platform('Blinkit', 'quick_commerce');
        $this->assertSame('Quick Commerce', $p['category_name']);
        $this->assertThrows(ValidationException::class, fn () => $cats->save(['name' => 'Quick Commerce'], null, $this->root), 'already exists');
        $this->assertThrows(ValidationException::class, fn () => $cats->save(['name' => 'Quick Commerce', 'code' => 'quick_commerce'], $id, $this->root), 'Move them');
        $cats->save(['name' => 'Quick commerce apps', 'code' => 'quick_commerce', 'is_active' => 1], $id, $this->root);
        $this->assertSame('Quick commerce apps', $this->svc->find((int) $p['id'])['category_name']);
        $other = $cats->save(['name' => 'Classifieds'], null, $this->root);
        $cats->save(['name' => 'Classifieds'], $other, $this->root);
        $this->assertSame(0, (int) App::db()->scalar('SELECT is_active FROM ecommerce_platform_categories WHERE id = ?', [$other]), 'an unused category can be deactivated');
        $this->assertThrows(ValidationException::class, fn () => $this->platform('OLX', 'classifieds'), 'inactive');
    }

    public function testConfigurationCapabilitiesAndSettings(): void
    {
        $p = $this->platform('Amazon Global');
        $this->svc->configure($p, ['currencies' => ['INR', 'usd'], 'default_currency' => 'USD', 'languages' => ['en', 'hi'], 'time_zone' => 'Asia/Kolkata', 'date_format' => 'd/m/Y',
            'number_format' => 'en_IN', 'reference_prefix' => 'amz', 'country' => 'XX'], $this->root);
        $p = $this->svc->find((int) $p['id']);
        $this->assertSame('INR,USD', $p['currencies']);
        $this->assertSame('USD', $p['default_currency']);
        $this->assertSame('AMZ', $p['reference_prefix']);
        $this->assertSame('en,hi', $p['languages']);
        $bad = static fn (array $over): array => $over + ['currencies' => ['INR'], 'default_currency' => 'INR', 'languages' => ['en'], 'time_zone' => 'Asia/Kolkata', 'date_format' => 'd-m-Y', 'number_format' => 'en_IN', 'country' => 'IN'];
        $this->assertThrows(ValidationException::class, fn () => $this->svc->configure($p, $bad(['default_currency' => 'EUR']), $this->root), 'supported currencies');
        $this->assertThrows(ValidationException::class, fn () => $this->svc->configure($p, $bad(['time_zone' => 'Mars/Base']), $this->root), 'time zone');
        $this->assertThrows(ValidationException::class, fn () => $this->svc->configure($p, $bad(['languages' => []]), $this->root), 'language');
        $q = $this->platform('Ajio');
        $this->assertThrows(ValidationException::class, fn () => $this->svc->configure($q, $bad(['reference_prefix' => 'AMZ']), $this->root), 'already uses');
        // Capabilities: recorded, but never "operational" until the ERP implements them.
        $this->svc->saveCapabilities($p, ['order_sync' => ['platform_support' => 'supported', 'erp_status' => 'planned', 'notes' => 'SP-API']], $this->root);
        $caps = array_column($this->svc->capabilities($p), null, 'capability_key');
        $this->assertSame('supported', $caps['order_sync']['platform_support']);
        $this->assertSame('planned', $caps['order_sync']['erp_status']);
        $this->assertThrows(ValidationException::class, fn () => $this->svc->saveCapabilities($p, ['inventory_sync' => ['erp_status' => 'operational']], $this->root), 'not implemented');
        // Custom settings; credentials are refused.
        $this->svc->saveSetting($p, ['setting_key' => 'return_window_days', 'value' => '7'], $this->root);
        $this->svc->saveSetting($p, ['setting_key' => 'return_window_days', 'value' => '10'], $this->root);
        $this->assertSame('10', App::db()->scalar("SELECT value FROM ecommerce_platform_settings WHERE platform_id = ? AND setting_key = 'return_window_days'", [$p['id']]));
        foreach (['api_key', 'seller_password', 'refresh_token', 'client_secret'] as $key) {
            $this->assertThrows(ValidationException::class, fn () => $this->svc->saveSetting($p, ['setting_key' => $key, 'value' => 'x'], $this->root), 'never stored');
        }
        $this->svc->saveSetting($p, ['setting_key' => 'return_window_days', 'value' => ''], $this->root);
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM ecommerce_platform_settings WHERE platform_id = ?', [$p['id']]));
        $events = array_column(App::db()->select('SELECT event FROM ecommerce_platform_history WHERE platform_id = ? ORDER BY id', [$p['id']]), 'event');
        foreach (['configuration_changed', 'capabilities_changed', 'setting_added', 'setting_changed', 'setting_removed'] as $ev) {
            $this->assertTrue(in_array($ev, $events, true), "history has {$ev}");
        }
        // An edit that changes nothing writes no history.
        $n = count($events);
        $this->svc->update($this->svc->find((int) $p['id']), ['name' => 'Amazon Global', 'category_id' => $p['category_id']], $this->root);
        $this->assertSame($n, (int) App::db()->scalar('SELECT COUNT(*) FROM ecommerce_platform_history WHERE platform_id = ?', [$p['id']]));
    }

    public function testActivateDeactivateAndStatusHistory(): void
    {
        $p = $this->platform('Meesho');
        $this->svc->changeStatus($p, 'active', '', $this->root);
        $p = $this->svc->find((int) $p['id']);
        $this->assertSame('active', $p['status']);
        $this->assertThrows(ValidationException::class, fn () => $this->svc->changeStatus($p, 'inactive', ' ', $this->root), 'reason');
        $this->svc->changeStatus($p, 'inactive', 'Paused for the season', $this->root);
        $p = $this->svc->find((int) $p['id']);
        $this->assertSame('inactive', $p['status']);
        $this->assertSame('Paused for the season', $p['status_reason']);
        // Inactive platforms can still be configured.
        $this->svc->saveSetting($p, ['setting_key' => 'payout_cycle', 'value' => 'weekly'], $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->changeStatus($p, 'archived', 'x', $this->root), null);
        $this->svc->changeStatus($p, 'active', '', $this->root);
        $rows = App::db()->select("SELECT from_status, to_status FROM ecommerce_platform_history WHERE platform_id = ? AND to_status IS NOT NULL ORDER BY id", [$p['id']]);
        $this->assertSame([['from_status' => null, 'to_status' => 'under_configuration'], ['from_status' => 'under_configuration', 'to_status' => 'active'],
            ['from_status' => 'active', 'to_status' => 'inactive'], ['from_status' => 'inactive', 'to_status' => 'active']], $rows);
        // Activation needs the required configuration.
        $q = $this->platform('Nykaa');
        App::db()->update('ecommerce_platforms', ['languages' => ''], ['id' => $q['id']]);
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->changeStatus($this->svc->find((int) $q['id']), 'active', '', $this->root), 'language');
    }

    public function testArchiveRestoreAndInvalidArchival(): void
    {
        $db = App::db();
        $p = $this->platform('Snapdeal');
        $this->assertThrows(ValidationException::class, fn () => $this->svc->archive($p, '', true, $this->root), 'reason');
        $wh = $this->warehouse('Snapdeal Hub');
        (new DatedMappingService($db, 'platform_warehouse'))->add($p, $wh, [], $this->root);
        $check = $this->svc->archiveCheck($p);
        $this->assertSame(1, count($check['warnings']));
        $this->assertThrows(ValidationException::class, fn () => $this->svc->archive($p, 'Closed', false, $this->root), 'confirmation');
        $this->assertSame('under_configuration', $this->svc->find((int) $p['id'])['status'], 'nothing changed without confirmation');
        $this->svc->archive($p, 'Closed our store', true, $this->root);
        $a = $this->svc->find((int) $p['id']);
        $this->assertSame('archived', $a['status']);
        $this->assertSame((int) $this->root['id'], (int) $a['archived_by']);
        $this->assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM ecommerce_platform_warehouse_mappings WHERE platform_id = ? AND status = 'active'", [$p['id']]), 'mappings are kept');
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->archive($a, 'Again', true, $this->root), 'already archived');
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->update($a, ['name' => 'X', 'category_id' => $a['category_id']], $this->root), 'read-only');
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->saveSetting($a, ['setting_key' => 'x_y', 'value' => '1'], $this->root), 'read-only');
        $this->assertThrows(DomainRuleException::class, fn () => (new PlatformNoteService($db))->save($a, ['title' => 'T', 'body' => 'B'], null, $this->root), 'read-only');
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->changeStatus($a, 'active', '', $this->root), null);
        $this->assertSame(0, count($this->svc->list($this->root)), 'archived platforms are hidden by default');
        $this->assertSame(1, count($this->svc->list($this->root, ['status' => 'archived'])));
        $this->svc->restore($a, $this->root);
        $r = $this->svc->find((int) $p['id']);
        $this->assertSame('inactive', $r['status']);
        $this->assertNull($r['archived_at']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->svc->restore($r, $this->root), 'Only archived');
        // A platform code used as a product price channel cannot change and is reported when archiving.
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM ecommerce_platforms WHERE id = 0'));
        $this->assertSame(1, (int) $db->scalar('SELECT COUNT(*) FROM ecommerce_platforms'), 'platforms are never deleted');
    }

    public function testGstWarehouseActivityAndPartnerMappings(): void
    {
        $db = App::db();
        $p = $this->platform('Amazon India');
        $g1 = $this->gst('24AAKFM4821R1Z', '24');
        $g2 = $this->gst('27AAKFM4821R1Z', '27');
        $gst = new DatedMappingService($db, 'platform_gst');
        $gst->add($p, $g1, ['effective_from' => '2026-04-01'], $this->root);
        $gst->add($p, $g2, [], $this->root);
        $this->assertSame(2, count($gst->activeTargetIds((int) $p['id'])), 'a platform may use several GST registrations');
        $this->assertThrows(DomainRuleException::class, fn () => $gst->add($p, $g1, [], $this->root), 'already linked');
        $q = $this->platform('Flipkart');
        $gst->add($q, $g1, [], $this->root);
        $this->assertSame(2, count($this->svc->list($this->root, ['gst' => $g1])), 'a GST registration may serve several platforms (no exclusivity)');
        $mid = (int) $db->scalar('SELECT id FROM ecommerce_platform_gst_mappings WHERE platform_id = ? AND gst_registration_id = ?', [$p['id'], $g2]);
        $this->assertThrows(ValidationException::class, fn () => $gst->end($p, $mid, null, '', $this->root), 'reason');
        $gst->end($p, $mid, null, 'Stopped selling in Maharashtra', $this->root);
        $this->assertSame([$g1], $gst->activeTargetIds((int) $p['id']));
        $this->assertSame(2, count($gst->list((int) $p['id'])), 'ended links stay as history');
        // Warehouses, business activities and partners
        $wh = new DatedMappingService($db, 'platform_warehouse');
        $w1 = $this->warehouse('Ahmedabad Central');
        $w2 = $this->warehouse('Amazon FBA BOM5');
        $wh->add($p, $w1, [], $this->root);
        $wh->add($p, $w2, [], $this->root);
        $this->assertSame(2, count($wh->activeTargetIds((int) $p['id'])));
        $act = new DatedMappingService($db, 'platform_activity');
        $act->add($p, (int) $db->scalar('SELECT MIN(id) FROM gst_business_activities'), [], $this->root);
        $partner = (new \App\Services\Partners\PartnerService($db))->create(['name' => 'Ravi Mehta', 'date_of_joining' => '2025-04-01'], $this->root);
        (new DatedMappingService($db, 'platform_partner'))->add($p, $partner, [], $this->root);
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM profit_sharing_arrangements'), 'no profit sharing is inferred');
        $this->svc->setBusinessCurrency($p, 'usd', $this->root);
        $this->assertSame('USD', $this->svc->find((int) $p['id'])['business_currency']);
        $events = array_column($db->select('SELECT event FROM ecommerce_platform_history WHERE platform_id = ?', [$p['id']]), 'event');
        foreach (['gst_mapping_added', 'gst_mapping_ended', 'warehouse_mapping_added', 'activity_mapping_added', 'partner_mapping_added', 'business_currency_changed'] as $ev) {
            $this->assertTrue(in_array($ev, $events, true), "history has {$ev}");
        }
        $readiness = $this->svc->readiness($this->svc->find((int) $p['id']));
        $this->assertTrue($readiness['recommended']['GST registration'] && $readiness['recommended']['warehouse']);
    }

    public function testDocumentsAreValidatedStoredPrivatelyAndLogged(): void
    {
        $db = App::db();
        $p = $this->platform('Shopify');
        $docs = new PlatformDocumentService($db);
        $id = $docs->upload($p, $this->fileOf("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'agreement.pdf'), ['document_type' => 'agreement', 'title' => 'Seller agreement 2026'], $this->root, false);
        $d = $docs->find($id);
        $this->assertSame('application/pdf', $d['mime_type']);
        $this->assertSame(64, strlen((string) $d['sha256']));
        $this->assertTrue(is_file(App::basePath('storage/uploads/platform-documents/' . $d['stored_name'])), 'stored outside the web root');
        $this->assertFalse(str_contains((string) $d['stored_name'], 'agreement'), 'random stored name');
        $this->assertThrows(ValidationException::class, fn () => $docs->upload($p, $this->fileOf('<?php system($_GET[1]);', 'fees.pdf'), ['document_type' => 'fee_schedule', 'title' => 'Fees'], $this->root, false), null);
        $this->assertThrows(ValidationException::class, fn () => $docs->upload($p, $this->fileOf('x', 'run.exe'), ['document_type' => 'other', 'title' => 'Tool'], $this->root, false), null);
        $this->assertThrows(ValidationException::class, fn () => $docs->upload($p, $this->fileOf("%PDF-1.4\n%%EOF", 'a.pdf'), ['document_type' => 'contract', 'title' => 'X'], $this->root, false), 'document type');
        $this->assertThrows(ValidationException::class, fn () => $docs->upload($p, $this->fileOf("%PDF-1.4\n%%EOF", 'a.pdf'), ['document_type' => 'other', 'title' => ''], $this->root, false), 'title');
        $resp = $docs->response($d, false, $this->root);
        $this->assertSame(200, $resp->status);
        $this->assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM ecommerce_platform_history WHERE platform_id = ? AND event = 'document_downloaded'", [$p['id']]));
        $this->assertSame(1, (int) $db->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'platforms.document_downloaded'"));
        $this->assertThrows(ValidationException::class, fn () => $docs->archive($d, '', $this->root), 'reason');
        $docs->archive($d, 'Superseded', $this->root);
        $this->assertSame(0, count($docs->list((int) $p['id'])));
        $this->assertSame(1, count($docs->list((int) $p['id'], true)), 'archived documents are kept');
        // Logo: images only.
        $png = sys_get_temp_dir() . '/p8-' . bin2hex(random_bytes(4)) . '.png';
        imagepng(imagecreatetruecolor(32, 32), $png);
        $this->svc->setLogo($p, ['name' => 'logo.png', 'tmp_name' => $png, 'error' => 0, 'size' => filesize($png)], $this->root, false);
        $this->assertSame('image/png', $this->svc->find((int) $p['id'])['logo_mime']);
        $this->assertThrows(ValidationException::class, fn () => $this->svc->setLogo($p, $this->fileOf("%PDF-1.4\n%%EOF", 'logo.pdf'), $this->root, false), null);
    }

    public function testNotesVisibilityAndHistoryDoesNotLeakPrivateText(): void
    {
        $db = App::db();
        $p = $this->platform('Myntra');
        $notes = new PlatformNoteService($db);
        $mgr = $this->makeUser('mgr@example.com', ['manager'], name: 'Manager');
        $acct = $this->makeUser('acct@example.com', ['accountant'], name: 'Accountant');
        $notes->save($p, ['title' => 'Onboarding', 'body' => 'Category approval pending', 'visibility' => 'everyone'], null, $mgr);
        $notes->save($p, ['title' => 'Escalation contact', 'body' => 'Speak to the KAM only', 'visibility' => 'managers'], null, $mgr);
        $private = $notes->save($p, ['title' => 'My reminder', 'body' => 'Negotiate the commission', 'visibility' => 'private'], null, $this->root);
        $titles = static fn (array $u): array => array_column($notes->visible((int) $p['id'], $u), 'title');
        $this->assertSame(['My reminder', 'Escalation contact', 'Onboarding'], $titles($this->root));
        $this->assertSame(['Escalation contact', 'Onboarding'], $titles($mgr), 'manager role has platforms.manage_notes');
        $this->assertSame(['Onboarding'], $titles($acct));
        $this->assertThrows(DomainRuleException::class, fn () => $notes->save($p, ['title' => 'X', 'body' => 'Y', 'visibility' => 'everyone'], $notes->find($private), $mgr), 'author');
        $this->assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM ecommerce_platform_history WHERE new_values LIKE '%Negotiate%' OR new_values LIKE '%My reminder%'"), 'private note text is not copied into history');
        $notes->archive($p, $notes->find($private), $this->root);
        $this->assertSame(['Escalation contact', 'Onboarding'], $titles($this->root));
    }

    public function testRecordScopeLimitsPlatformsToAssignedOnes(): void
    {
        $db = App::db();
        $a = $this->platform('Amazon India');
        $b = $this->platform('Flipkart');
        $u = $this->makeUser('seller@example.com', ['manager'], name: 'Seller Ops');
        $perms = new PermissionService($db);
        $pid = static fn (string $slug): int => (int) $db->scalar('SELECT id FROM permissions WHERE slug = ?', [$slug]);
        $perms->setUserOverrides((int) $u['id'], [$pid('platforms.view_all') => 'deny', $pid('platforms.view_assigned') => 'allow'], $this->root, null, 'Flipkart desk');
        (new ResourceAssignmentService($db))->sync((int) $u['id'], 'platform', [(int) $b['id']], $this->root);
        App::flushAuthorization();
        $this->assertSame([(int) $b['id']], PlatformScope::ids($u));
        $this->assertSame(['Flipkart'], array_column($this->svc->list($u), 'name'));
        $this->assertFalse(PlatformScope::allows($u, (int) $a['id']));
        $this->assertThrows(\App\Exceptions\HttpException::class, fn () => PlatformScope::assert($u, (int) $a['id']), null);
        $viewer = $this->makeUser('viewer@example.com', ['viewer']);
        $this->assertSame([], PlatformScope::ids($viewer));
        $this->assertNull(PlatformScope::ids($this->root));
    }
}
