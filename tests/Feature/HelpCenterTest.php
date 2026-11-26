<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\Documentation\DocumentationMediaService;
use App\Services\Documentation\DocumentationService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DocumentationSeeder;

/**
 * Help & User Guide: read-only for every signed-in user, management only through
 * documentation.* permissions, drafts hidden, search, escaping and seeding.
 */
final class HelpCenterTest extends HttpTestCase
{
    private function roleWith(string $slug, array $permissions): int
    {
        $now = now();
        $id = App::db()->insert('roles', ['name' => ucfirst($slug), 'slug' => $slug, 'description' => null, 'is_system' => 0, 'is_super_admin' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        foreach ($permissions as $p) {
            $pid = (int) App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$p]);
            App::db()->insert('role_permissions', ['role_id' => $id, 'permission_id' => $pid, 'created_at' => $now, 'updated_at' => $now]);
        }
        App::flushAuthorization();
        return $id;
    }

    private function draftGuide(array $root, string $title = 'Secret draft guide'): int
    {
        $cat = (int) App::db()->scalar("SELECT id FROM documentation_categories WHERE slug = 'partners-capital'");
        return (new DocumentationService(App::db()))->saveGuide([
            'category_id' => $cat, 'title' => $title, 'summary' => 'Draft summary', 'what_is' => 'Draft body',
            'steps' => [['title' => 'Only step', 'body' => 'Do the thing']], 'faqs' => [], 'related' => [],
        ], $root);
    }

    public function testEveryRoleCanReadPublishedGuidesButNotManage(): void
    {
        $this->roleWith('custom-clerk', ['dashboard.view']);
        $users = [
            'super@example.com' => ['super-admin'], 'partner@example.com' => ['partner'], 'acct@example.com' => ['accountant'],
            'mgr@example.com' => ['manager'], 'viewer@example.com' => ['viewer'], 'custom@example.com' => ['custom-clerk'], 'norole@example.com' => [],
        ];
        foreach ($users as $email => $roles) {
            $this->makeUser($email, $roles);
        }
        $media = (int) App::db()->scalar("SELECT m.id FROM documentation_media m JOIN documentation_guides g ON g.id = m.guide_id WHERE g.status = 'published' LIMIT 1");
        $this->assertTrue($media > 0, 'seeded screenshots exist');
        foreach (array_keys($users) as $email) {
            $b = $this->browser();
            $this->signIn($b, $email);
            $home = $b->get('/help');
            $this->assertSame(200, $home['status'], "{$email} opens help");
            $this->assertContains('Setup &amp; Authentication', $home['body']);
            $this->assertContains('Super Admin Control System', $home['body'], "{$email} sees all categories (no role-based content)");
            $this->assertContains('Partner Management &amp; Capital', $home['body']);
            $this->assertContains('Phase 6 – Product &amp; Supplier Management', $home['body']);
            $this->assertSame(200, $b->get('/help/guides/supplier-bank-details')['status'], "{$email} reads a Phase 6 guide");
            $this->assertSame(200, $b->get('/help/guides/capital-withdrawals')['status'], "{$email} reads a Phase 3 guide");
            $this->assertSame(200, $b->get('/help/guides/user-permission-overrides')['status'], "{$email} reads a Control Center guide");
            $this->assertSame(200, $b->get('/help/category/super-admin-control')['status']);
            $this->assertSame(200, $b->get('/help/media/' . $media)['status'], "{$email} sees published screenshots");
            $this->assertContains('Help &amp; User Guide', $b->get('/dashboard')['body'], 'sidebar entry for everyone');
            if ($email !== 'super@example.com') {
                $this->assertSame(403, $b->get('/help/manage')['status'], "{$email} cannot manage");
                $this->assertNotContains('Manage guides', $home['body']);
            }
        }
    }

    public function testGuestsMustSignIn(): void
    {
        $r = $this->browser()->get('/help');
        $this->assertSame(302, $r['status']);
    }

    public function testDraftsAndManagementRequireDocumentationPermissions(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $draft = $this->draftGuide($root);
        $slug = (string) App::db()->scalar('SELECT slug FROM documentation_guides WHERE id = ?', [$draft]);
        $mediaFile = tempnam(sys_get_temp_dir(), 'png');
        imagepng(imagecreatetruecolor(40, 30), $mediaFile);
        $mediaId = (new DocumentationMediaService(App::db()))->store($draft, ['name' => 'shot.png', 'tmp_name' => $mediaFile, 'error' => 0, 'size' => filesize($mediaFile)], 'Cap', 'Alt', $root, false);

        $this->makeUser('viewer@example.com', ['viewer']);
        $v = $this->browser();
        $this->signIn($v, 'viewer@example.com');
        $this->assertSame(404, $v->get('/help/guides/' . $slug)['status'], 'drafts are invisible to readers');
        $this->assertSame(404, $v->get('/help/media/' . $mediaId)['status'], 'draft screenshots are invisible');
        $this->assertNotContains('Secret draft guide', $v->get('/help/search?q=secret')['body']);
        $this->assertSame(403, $v->post('/help/manage/guides', ['category_id' => 1, 'title' => 'Hack', 'summary' => 'x'])['status']);
        $this->assertSame(403, $v->post('/help/manage/guides/' . $draft . '/publish')['status']);
        $this->assertSame('draft', App::db()->scalar('SELECT status FROM documentation_guides WHERE id = ?', [$draft]));
        $this->assertSame(0, (int) App::db()->scalar("SELECT COUNT(*) FROM documentation_guides WHERE title = 'Hack'"));

        // Grant manage (but not publish) through the existing role system.
        $this->roleWith('doc-writer', ['dashboard.view', 'documentation.manage']);
        $this->makeUser('writer@example.com', ['doc-writer']);
        $w = $this->browser();
        $this->signIn($w, 'writer@example.com');
        $this->assertSame(200, $w->get('/help/manage')['status']);
        $this->assertSame(200, $w->get('/help/guides/' . $slug)['status'], 'managers can read drafts');
        $this->assertSame(200, $w->get('/help/manage/guides/' . $draft . '/preview')['status']);
        $w->get('/help/manage/guides/' . $draft . '/edit');
        $save = $w->post('/help/manage/guides/' . $draft, [
            'category_id' => App::db()->scalar('SELECT category_id FROM documentation_guides WHERE id = ?', [$draft]), 'title' => 'Secret draft guide',
            'summary' => 'Updated summary', 'what_is' => '<script>alert(1)</script> **bold**', 'is_featured' => '0',
            'steps' => [['title' => 'Step one', 'body' => 'Uses the frobnicator'], ['title' => 'Step two', 'body' => '']],
            'faqs' => [['question' => 'Is it zorblified?', 'answer' => 'Yes']], 'change_note' => 'second pass',
        ]);
        $this->assertSame(302, $save['status']);
        $this->assertSame(2, (int) App::db()->scalar('SELECT COUNT(*) FROM documentation_steps WHERE guide_id = ?', [$draft]));
        $this->assertTrue((int) App::db()->scalar('SELECT COUNT(*) FROM documentation_revisions WHERE guide_id = ?', [$draft]) >= 2, 'a revision per save');
        $this->assertSame(403, $w->post('/help/manage/guides/' . $draft . '/publish')['status'], 'publishing needs documentation.publish');
        $this->assertSame(403, $w->post('/help/manage/guides/' . $draft . '/delete')['status'], 'deleting needs documentation.delete');

        // Super Admin publishes; now every reader sees it, escaped.
        $r = $this->browser();
        $this->signIn($r, 'root@example.com');
        $r->get('/help/manage/guides/' . $draft . '/edit');
        $this->assertSame(302, $r->post('/help/manage/guides/' . $draft . '/publish')['status']);
        $page = $v->get('/help/guides/' . $slug);
        $this->assertSame(200, $page['status']);
        $this->assertContains('&lt;script&gt;alert(1)&lt;/script&gt;', $page['body'], 'author text is escaped');
        $this->assertNotContains('<script>alert(1)', $page['body']);
        $this->assertContains('<strong>bold</strong>', $page['body']);
        $this->assertSame(200, $v->get('/help/media/' . $mediaId)['status']);
        $this->assertContains('Secret draft guide', $v->get('/help/search?q=frobnicator')['body'], 'search covers step text');
        $this->assertContains('Secret draft guide', $v->get('/help/search?q=zorblified')['body'], 'search covers FAQs');
        $this->assertTrue((int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'documentation.guide_published'") === 1, 'audited');
    }

    public function testUploadsAreValidatedAndAnnotationsClamped(): void
    {
        $root = $this->makeUser('root@example.com', ['super-admin']);
        $draft = $this->draftGuide($root);
        $svc = new DocumentationMediaService(App::db());
        $bad = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($bad, '<?php echo 1;');
        $this->assertThrows(\App\Exceptions\ValidationException::class, fn () => $svc->store($draft, ['name' => 'evil.png', 'tmp_name' => $bad, 'error' => 0, 'size' => 13], '', '', $root, false));
        $clean = DocumentationMediaService::cleanAnnotations([['x' => 150, 'y' => -5, 'label' => 'Out'], ['x' => 'a', 'y' => 1], ['x' => 10.123, 'y' => 20, 'label' => str_repeat('L', 300)]]);
        $this->assertSame(2, count($clean));
        $this->assertSame(100.0, $clean[0]['x']);
        $this->assertSame(0.0, $clean[0]['y']);
        $this->assertSame(160, mb_strlen($clean[1]['label']));
    }

    public function testSeederIsCompleteIdempotentAndKeepsEdits(): void
    {
        $db = App::db();
        $this->assertSame(7, (int) $db->scalar('SELECT COUNT(*) FROM documentation_categories'));
        $guides = (int) $db->scalar("SELECT COUNT(*) FROM documentation_guides WHERE status = 'published'");
        $this->assertTrue($guides >= 102, 'Phase 1–7 guides seeded');
        $gstCat = (int) $db->scalar("SELECT id FROM documentation_categories WHERE slug = 'gst-registrations'");
        $this->assertSame('Phase 4 – GST Registration Management', (string) $db->scalar('SELECT name FROM documentation_categories WHERE id = ?', [$gstCat]));
        $this->assertSame(10, (int) $db->scalar('SELECT COUNT(*) FROM documentation_guides WHERE category_id = ?', [$gstCat]));
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM documentation_guides g WHERE g.category_id = ? AND NOT EXISTS (SELECT 1 FROM documentation_steps s WHERE s.guide_id = g.id AND s.media_id IS NOT NULL)', [$gstCat]), 'every GST guide has a real screenshot');
        $bankCat = (int) $db->scalar("SELECT id FROM documentation_categories WHERE slug = 'bank-accounts'");
        $this->assertSame('Phase 5 – Bank Account Management', (string) $db->scalar('SELECT name FROM documentation_categories WHERE id = ?', [$bankCat]));
        $this->assertSame(14, (int) $db->scalar('SELECT COUNT(*) FROM documentation_guides WHERE category_id = ?', [$bankCat]));
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM documentation_guides g WHERE g.category_id = ? AND NOT EXISTS (SELECT 1 FROM documentation_steps s WHERE s.guide_id = g.id AND s.media_id IS NOT NULL)', [$bankCat]), 'every bank guide has a real screenshot');
        $p6Cat = (int) $db->scalar("SELECT id FROM documentation_categories WHERE slug = 'products-suppliers'");
        $this->assertSame('Phase 6 – Product & Supplier Management', (string) $db->scalar('SELECT name FROM documentation_categories WHERE id = ?', [$p6Cat]));
        $this->assertSame(14, (int) $db->scalar('SELECT COUNT(*) FROM documentation_guides WHERE category_id = ?', [$p6Cat]));
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM documentation_guides g WHERE g.category_id = ? AND NOT EXISTS (SELECT 1 FROM documentation_steps s WHERE s.guide_id = g.id AND s.media_id IS NOT NULL)', [$p6Cat]), 'every Phase 6 guide has screenshots');
        $p7Cat = (int) $db->scalar("SELECT id FROM documentation_categories WHERE slug = 'inventory'");
        $this->assertSame('Phase 7 – Inventory Management', (string) $db->scalar('SELECT name FROM documentation_categories WHERE id = ?', [$p7Cat]));
        $this->assertSame(19, (int) $db->scalar('SELECT COUNT(*) FROM documentation_guides WHERE category_id = ?', [$p7Cat]));
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM documentation_guides g WHERE g.category_id = ? AND NOT EXISTS (SELECT 1 FROM documentation_steps s WHERE s.guide_id = g.id AND s.media_id IS NOT NULL)', [$p7Cat]), 'every Phase 7 guide has screenshots');
        $this->assertSame(0, (int) $db->scalar("SELECT COUNT(*) FROM documentation_guides WHERE summary = '' OR what_is IS NULL"), 'every guide has an introduction and explanation');
        $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM documentation_guides g WHERE NOT EXISTS (SELECT 1 FROM documentation_steps s WHERE s.guide_id = g.id)'), 'every guide has steps');
        $this->assertTrue((int) $db->scalar('SELECT COUNT(*) FROM documentation_media WHERE annotations IS NOT NULL') > 40, 'annotated screenshots');
        foreach ($db->select('SELECT stored_name FROM documentation_media') as $m) {
            $this->assertTrue(is_file(DocumentationMediaService::bundledDirectory() . '/' . substr((string) $m['stored_name'], 8)), 'bundled file exists: ' . $m['stored_name']);
        }
        foreach (['Sales & Order', 'E-commerce Platform', 'Purchase invoice'] as $future) {
            $this->assertSame(0, (int) $db->scalar('SELECT COUNT(*) FROM documentation_guides WHERE title LIKE ?', ['%' . $future . '%']), "no guide for future module {$future}");
        }
        $db->update('documentation_guides', ['title' => 'Edited by a manager'], ['slug' => 'capital-ledger']);
        (new DatabaseSeeder($db, static function (): void {}))->run();
        $this->assertSame($guides, (int) $db->scalar("SELECT COUNT(*) FROM documentation_guides WHERE status = 'published'"), 'no duplicates on re-seed');
        $this->assertSame('Edited by a manager', $db->scalar("SELECT title FROM documentation_guides WHERE slug = 'capital-ledger'"), 'edits survive re-seeding');
        $parsed = DocumentationSeeder::parse("title: T\nslug: t\nsummary: S\n=== step: One | shot\nBody\n=== faq: Q?\nA");
        $this->assertSame('shot', $parsed['steps'][0][2]);
        $this->assertSame('Q?', $parsed['faqs'][0][0]);
    }

    public function testCapitalExceptionPolicyCanBeSavedFromSecuritySettings(): void
    {
        $this->makeUser('root@example.com', ['super-admin']);
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $page = $b->get('/admin/security');
        $this->assertContains('allow_capital_overdraw', $page['body'], 'finance setting is rendered');
        $b->post('/admin/security', ['login_max_attempts' => 5, 'lockout_minutes' => 15, 'password_min_length' => 10, 'session_idle_minutes' => 30, 'remember_me_days' => 30,
            'password_reset_expiry_minutes' => 60, 'max_concurrent_sessions' => 0, 'allow_user_level_grants' => '1', 'block_self_approval' => '1', 'allow_capital_overdraw' => '1']);
        App::resetServices();
        $this->assertSame('1', (string) App::db()->scalar("SELECT value FROM system_settings WHERE setting_key = 'allow_capital_overdraw'"));
    }
}
