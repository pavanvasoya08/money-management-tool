<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Documentation\DocumentationMediaService;
use App\Services\Documentation\DocumentationService;

/**
 * Help & User Guide — read-only for every signed-in user.
 *
 * Deliberately NO permission, role, record or field checks on reading: every
 * user (Super Admin, Partner, Accountant, Manager, Viewer, custom roles) sees
 * all published guides. Draft guides and their screenshots are only shown to
 * holders of documentation.manage and otherwise answer 404.
 */
final class HelpController extends Controller
{
    private DocumentationService $docs;

    public function __construct()
    {
        $this->docs = new DocumentationService(App::db());
    }

    public function index(Request $request): Response
    {
        $q = $request->string('q');
        if ($q !== '') {
            return $this->redirectRoute('help.search', ['q' => $q]);
        }
        $categories = $this->docs->categories();
        $byCategory = [];
        foreach ($categories as $c) {
            $byCategory[(int) $c['id']] = $this->docs->guidesIn((int) $c['id']);
        }
        return $this->view('help/index', [
            'title' => 'Help & User Guide',
            'categories' => $categories,
            'byCategory' => $byCategory,
            'popular' => $this->docs->popular(6),
            'recent' => $this->docs->recent(6),
            'tutorials' => $this->docs->tutorials(6),
            'examples' => $this->docs->examples(6),
            'faqs' => $this->docs->generalFaqs(),
            'canManage' => can('documentation.manage'),
        ]);
    }

    public function search(Request $request): Response
    {
        $q = mb_substr($request->string('q'), 0, 100);
        return $this->view('help/search', [
            'title' => 'Search help',
            'breadcrumbs' => ['Help & User Guide' => route('help.index'), 'Search' => null],
            'q' => $q,
            'results' => $q === '' ? [] : $this->docs->search($q),
            'categories' => $this->docs->categories(),
        ]);
    }

    public function category(Request $request, string $slug): Response
    {
        $cat = $this->docs->category($slug) ?? throw new HttpException(404, 'Help category not found.');
        $guides = $this->docs->guidesIn((int) $cat['id']);
        if ($guides === []) {
            throw new HttpException(404, 'Help category not found.');
        }
        $sections = [];
        foreach ($guides as $g) {
            $sections[(string) ($g['section'] ?? '') !== '' ? (string) $g['section'] : 'Guides'][] = $g;
        }
        return $this->view('help/category', [
            'title' => $cat['name'],
            'breadcrumbs' => ['Help & User Guide' => route('help.index'), $cat['name'] => null],
            'category' => $cat,
            'sections' => $sections,
            'categories' => $this->docs->categories(),
        ]);
    }

    public function guide(Request $request, string $slug): Response
    {
        $canManage = can('documentation.manage');
        $guide = $this->docs->guide($slug, $canManage) ?? throw new HttpException(404, 'Guide not found.');
        if ($guide['status'] === 'published') {
            $this->docs->recordView((int) $guide['id']);
        }
        return $this->view('help/guide', $this->guideData($this->docs->assemble($guide, $canManage), $canManage, false));
    }

    public function media(Request $request, string $id): Response
    {
        $media = (new DocumentationMediaService(App::db()))->find($this->intParam($id)) ?? throw new HttpException(404, 'Screenshot not found.');
        $status = App::db()->scalar('SELECT status FROM documentation_guides WHERE id = ?', [$media['guide_id']]);
        if ($status !== 'published' && !can('documentation.manage')) {
            throw new HttpException(404, 'Screenshot not found.');
        }
        return (new DocumentationMediaService(App::db()))->response($media);
    }

    /**
     * Shared view data for a guide page (also used by management preview and revision view).
     * @param array<string, mixed> $doc from DocumentationService::assemble() or a revision snapshot
     * @return array<string, mixed>
     */
    public static function guideData(array $doc, bool $canManage, bool $preview, ?string $banner = null): array
    {
        $docs = new DocumentationService(App::db());
        $g = $doc['guide'];
        return [
            'title' => $g['title'],
            'breadcrumbs' => ['Help & User Guide' => route('help.index'), (string) $g['category_name'] => route('help.category', ['slug' => $g['category_slug']]), (string) $g['title'] => null],
            'doc' => $doc,
            'nav' => $docs->neighbours((int) $g['id'], $preview),
            'categoryGuides' => $docs->guidesIn((int) $g['category_id'], $preview),
            'canManage' => $canManage,
            'preview' => $preview,
            'banner' => $banner,
        ];
    }
}
