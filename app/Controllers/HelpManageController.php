<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Documentation\DocumentationMediaService;
use App\Services\Documentation\DocumentationService;

/**
 * Help & User Guide management. Every route is protected by the existing Gate:
 * documentation.manage (create/edit/drafts/screenshots/reorder/preview),
 * documentation.publish (publish/unpublish) and documentation.delete.
 * Super Admins hold all three; anyone else needs a grant from the Control Center.
 */
final class HelpManageController extends Controller
{
    private DocumentationService $docs;
    private DocumentationMediaService $media;

    public function __construct()
    {
        $this->docs = new DocumentationService(App::db());
        $this->media = new DocumentationMediaService(App::db());
    }

    public function index(Request $request): Response
    {
        $categories = $this->docs->categories(true);
        $guides = [];
        foreach ($categories as $c) {
            $guides[(int) $c['id']] = $this->docs->guidesIn((int) $c['id'], true);
        }
        return $this->view('help/manage/index', [
            'title' => 'Manage help guides',
            'breadcrumbs' => ['Help & User Guide' => route('help.index'), 'Manage' => null],
            'categories' => $categories,
            'guides' => $guides,
        ]);
    }

    // ------------------------------------------------------------- categories

    public function createCategory(Request $request): Response
    {
        return $this->categoryForm(null);
    }

    public function storeCategory(Request $request): Response
    {
        $this->docs->saveCategory($this->categoryInput($request), $this->user());
        return $this->redirectRoute('help.manage')->with('success', 'Category created.');
    }

    public function editCategory(Request $request, string $id): Response
    {
        return $this->categoryForm($this->docs->categoryById($this->intParam($id)) ?? throw new HttpException(404));
    }

    public function updateCategory(Request $request, string $id): Response
    {
        $cid = $this->intParam($id);
        $this->docs->categoryById($cid) ?? throw new HttpException(404);
        $this->docs->saveCategory($this->categoryInput($request), $this->user(), $cid);
        return $this->redirectRoute('help.manage')->with('success', 'Category saved.');
    }

    public function moveCategory(Request $request, string $id): Response
    {
        $ids = array_map(static fn (array $c): int => (int) $c['id'], $this->docs->categories(true));
        $this->docs->reorder('categories', self::moved($ids, $this->intParam($id), $request->string('direction')), $this->user());
        return $this->redirectRoute('help.manage')->with('success', 'Category order saved.');
    }

    public function deleteCategory(Request $request, string $id): Response
    {
        $this->docs->deleteCategory($this->intParam($id), $this->user());
        return $this->redirectRoute('help.manage')->with('success', 'Category deleted.');
    }

    // ------------------------------------------------------------- guides

    public function createGuide(Request $request): Response
    {
        return $this->guideForm(null, (int) $request->string('category'));
    }

    public function storeGuide(Request $request): Response
    {
        $id = $this->docs->saveGuide($this->guideInput($request), $this->user(), null, $request->string('change_note'));
        return $this->redirectRoute('help.manage.guides.edit', ['id' => $id])->with('success', 'Draft saved. Add screenshots below, preview it, then publish.');
    }

    public function editGuide(Request $request, string $id): Response
    {
        $g = $this->docs->guideById($this->intParam($id)) ?? throw new HttpException(404);
        return $this->guideForm($g);
    }

    public function updateGuide(Request $request, string $id): Response
    {
        $gid = $this->intParam($id);
        $this->docs->guideById($gid) ?? throw new HttpException(404);
        $this->docs->saveGuide($this->guideInput($request), $this->user(), $gid, $request->string('change_note'));
        return $this->redirectRoute('help.manage.guides.edit', ['id' => $gid])->with('success', 'Guide saved.');
    }

    public function moveGuide(Request $request, string $id): Response
    {
        $g = $this->docs->guideById($this->intParam($id)) ?? throw new HttpException(404);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->docs->guidesIn((int) $g['category_id'], true));
        $this->docs->reorder('guides', self::moved($ids, (int) $g['id'], $request->string('direction')), $this->user(), (int) $g['category_id']);
        return $this->redirect(route('help.manage') . '#cat-' . (int) $g['category_id'])->with('success', 'Guide order saved.');
    }

    public function publish(Request $request, string $id): Response
    {
        $gid = $this->intParam($id);
        $this->docs->setStatus($gid, true, $this->user());
        return $this->redirectBack($request, $gid)->with('success', 'Guide published. Every user can now read it.');
    }

    public function unpublish(Request $request, string $id): Response
    {
        $gid = $this->intParam($id);
        $this->docs->setStatus($gid, false, $this->user());
        return $this->redirectBack($request, $gid)->with('success', 'Guide moved back to draft. Only documentation managers can see it.');
    }

    public function deleteGuide(Request $request, string $id): Response
    {
        $this->docs->deleteGuide($this->intParam($id), $this->user(), $this->media);
        return $this->redirectRoute('help.manage')->with('success', 'Guide deleted.');
    }

    public function preview(Request $request, string $id): Response
    {
        $g = $this->docs->guideById($this->intParam($id)) ?? throw new HttpException(404);
        $banner = $g['status'] === 'published' ? 'Preview of the published guide.' : 'Draft preview — only documentation managers can see this guide.';
        return $this->view('help/guide', HelpController::guideData($this->docs->assemble($g, true), true, true, $banner));
    }

    public function revisions(Request $request, string $id): Response
    {
        $g = $this->docs->guideById($this->intParam($id)) ?? throw new HttpException(404);
        return $this->view('help/manage/revisions', [
            'title' => 'Revision history',
            'breadcrumbs' => ['Help & User Guide' => route('help.index'), 'Manage' => route('help.manage'), $g['title'] => route('help.manage.guides.edit', ['id' => $g['id']]), 'Revisions' => null],
            'guide' => $g,
            'revisions' => $this->docs->revisions((int) $g['id']),
        ]);
    }

    public function revision(Request $request, string $id, string $rev): Response
    {
        $g = $this->docs->guideById($this->intParam($id)) ?? throw new HttpException(404);
        $r = $this->docs->revision((int) $g['id'], $this->intParam($rev)) ?? throw new HttpException(404);
        $snap = $r['snapshot'];
        if (!isset($snap['guide'])) {
            throw new HttpException(404);
        }
        $snap['guide'] += ['category_name' => $g['category_name'], 'category_slug' => $g['category_slug']];
        foreach (['steps', 'media', 'faqs', 'related'] as $k) {
            $snap[$k] = (array) ($snap[$k] ?? []);
        }
        return $this->view('help/guide', HelpController::guideData($snap, true, true, 'Revision ' . (int) $r['revision_no'] . ' (' . $r['event'] . ', ' . format_datetime($r['created_at']) . '). Read-only snapshot.'));
    }

    // ------------------------------------------------------------- screenshots

    public function uploadMedia(Request $request, string $id): Response
    {
        $gid = $this->intParam($id);
        $this->docs->guideById($gid) ?? throw new HttpException(404);
        $file = $request->file('screenshot') ?? throw ValidationException::withMessage('screenshot', 'Choose an image to upload.');
        $this->media->store($gid, $file, $request->string('caption'), $request->string('alt_text'), $this->user());
        $this->docs->writeRevision($gid, 'updated', 'Screenshot added', $this->user());
        return $this->redirect(route('help.manage.guides.edit', ['id' => $gid]) . '#screenshots')->with('success', 'Screenshot uploaded. Click on it to add numbered markers.');
    }

    public function updateMedia(Request $request, string $id): Response
    {
        $m = $this->media->find($this->intParam($id)) ?? throw new HttpException(404);
        $this->media->updateMeta($m, $request->string('caption'), $request->string('alt_text'), $request->string('annotations'), max(0, min(10000, (int) $request->string('sort_order'))), $this->user());
        $this->docs->writeRevision((int) $m['guide_id'], 'updated', 'Screenshot annotations changed', $this->user());
        return $this->redirect(route('help.manage.guides.edit', ['id' => $m['guide_id']]) . '#media-' . (int) $m['id'])->with('success', 'Screenshot saved.');
    }

    public function deleteMedia(Request $request, string $id): Response
    {
        $m = $this->media->find($this->intParam($id)) ?? throw new HttpException(404);
        $this->media->delete($m, $this->user());
        $this->docs->writeRevision((int) $m['guide_id'], 'updated', 'Screenshot removed', $this->user());
        return $this->redirect(route('help.manage.guides.edit', ['id' => $m['guide_id']]) . '#screenshots')->with('success', 'Screenshot deleted.');
    }

    // ------------------------------------------------------------- helpers

    private function categoryForm(?array $cat): Response
    {
        return $this->view('help/manage/category-form', [
            'title' => $cat === null ? 'New help category' : 'Edit ' . $cat['name'],
            'breadcrumbs' => ['Help & User Guide' => route('help.index'), 'Manage' => route('help.manage'), $cat === null ? 'New category' : $cat['name'] => null],
            'category' => $cat,
        ]);
    }

    private function guideForm(?array $guide, int $categoryId = 0): Response
    {
        $categories = $this->docs->categories(true);
        $all = [];
        foreach ($categories as $c) {
            foreach ($this->docs->guidesIn((int) $c['id'], true) as $g) {
                if ($guide === null || (int) $g['id'] !== (int) $guide['id']) {
                    $all[$c['name']][] = $g;
                }
            }
        }
        $doc = $guide !== null ? $this->docs->assemble($guide, true) : ['steps' => [], 'media' => [], 'faqs' => [], 'related' => []];
        return $this->view('help/manage/guide-form', [
            'title' => $guide === null ? 'New guide' : 'Edit guide',
            'breadcrumbs' => ['Help & User Guide' => route('help.index'), 'Manage' => route('help.manage'), $guide === null ? 'New guide' : $guide['title'] => null],
            'guide' => $guide,
            'doc' => $doc,
            'categories' => $categories,
            'categoryId' => $guide !== null ? (int) $guide['category_id'] : $categoryId,
            'allGuides' => $all,
            'relatedIds' => array_map(static fn (array $r): int => (int) $r['id'], $doc['related']),
        ]);
    }

    /** @return array<string, mixed> */
    private function categoryInput(Request $request): array
    {
        return $this->validate($request, [
            'name' => 'required|string|max:150', 'slug' => 'nullable|string|max:110', 'description' => 'nullable|string|max:500', 'icon' => 'nullable|string|max:60',
        ]);
    }

    /** @return array<string, mixed> */
    private function guideInput(Request $request): array
    {
        $rules = [
            'category_id' => 'required|integer', 'title' => 'required|string|max:200', 'slug' => 'nullable|string|max:110', 'section' => 'nullable|string|max:120',
            'summary' => 'required|string|max:600', 'example_title' => 'nullable|string|max:200', 'keywords' => 'nullable|string|max:500', 'is_featured' => 'nullable|boolean',
        ];
        foreach (DocumentationService::TEXT_FIELDS as $f) {
            $rules[$f] = 'nullable|string|max:20000';
        }
        $data = $this->validate($request, $rules, ['category_id' => 'Category', 'summary' => 'Short introduction']);
        foreach (['steps', 'faqs', 'related'] as $k) {
            $v = $request->input($k, []);
            $data[$k] = is_array($v) ? $v : [];
        }
        foreach ($data['steps'] as $s) {
            if (is_array($s) && mb_strlen((string) ($s['body'] ?? '')) > 20000) {
                throw ValidationException::withMessage('steps', 'A step is too long (20,000 characters maximum).');
            }
        }
        return $data;
    }

    private function redirectBack(Request $request, int $guideId): Response
    {
        return $request->string('back') === 'list' ? $this->redirectRoute('help.manage') : $this->redirectRoute('help.manage.guides.edit', ['id' => $guideId]);
    }

    /** @param list<int> $ids @return list<int> */
    private static function moved(array $ids, int $id, string $direction): array
    {
        $pos = array_search($id, $ids, true);
        if ($pos === false) {
            throw new HttpException(404);
        }
        $swap = $direction === 'up' ? $pos - 1 : $pos + 1;
        if (isset($ids[$swap])) {
            [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        }
        return $ids;
    }
}
