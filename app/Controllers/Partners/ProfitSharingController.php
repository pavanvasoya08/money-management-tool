<?php

declare(strict_types=1);

namespace App\Controllers\Partners;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Partners\DocumentService;
use App\Services\Partners\ProfitSharingService;
use App\Support\BusinessDate;
use App\Support\Percent;

/** Profit-sharing arrangements and effective-dated allocations (configuration only). */
final class ProfitSharingController extends Controller
{
    private ProfitSharingService $svc;

    public function __construct()
    {
        $this->svc = new ProfitSharingService(App::db());
    }

    public function index(Request $request): Response
    {
        $arrangements = $this->svc->arrangements();
        foreach ($arrangements as &$a) {
            $a['current'] = $a['current_allocation_id'] ? $this->svc->allocation((int) $a['current_allocation_id']) : null;
        }
        return $this->view('profit-sharing/index', [
            'title' => 'Profit sharing',
            'breadcrumbs' => ['Profit sharing' => null],
            'arrangements' => $arrangements,
            'percentAccess' => App::fields()->access($this->user(), 'profit_sharing', 'percentage'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('profit-sharing/arrangement-form', ['title' => 'New arrangement', 'breadcrumbs' => ['Profit sharing' => route('profit-sharing.index'), 'New arrangement' => null], 'arrangement' => null, 'locked' => false]);
    }

    public function store(Request $request): Response
    {
        $id = $this->svc->createArrangement($this->arrangementInput($request), $this->user());
        return $this->redirectRoute('profit-sharing.show', ['id' => $id])->with('success', 'Arrangement created. Add the first allocation of partner percentages.');
    }

    public function show(Request $request, string $id): Response
    {
        $arr = $this->find($id);
        $me = $this->user();
        return $this->view('profit-sharing/show', [
            'title' => $arr['name'],
            'breadcrumbs' => ['Profit sharing' => route('profit-sharing.index'), $arr['name'] => null],
            'arrangement' => $arr,
            'allocations' => $this->svc->allocations((int) $arr['id']),
            'percentAccess' => App::fields()->access($me, 'profit_sharing', 'percentage'),
            'notesAccess' => App::fields()->access($me, 'profit_sharing', 'notes'),
            'documents' => can('partners.view_documents') ? App::db()->select("SELECT d.* FROM partner_documents d JOIN profit_sharing_allocations x ON x.id = d.allocation_id WHERE x.arrangement_id = ? AND d.deleted_at IS NULL ORDER BY d.id DESC", [$arr['id']]) : [],
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        $arr = $this->find($id);
        return $this->view('profit-sharing/arrangement-form', ['title' => 'Edit ' . $arr['name'], 'breadcrumbs' => ['Profit sharing' => route('profit-sharing.index'), $arr['name'] => route('profit-sharing.show', ['id' => $arr['id']]), 'Edit' => null],
            'arrangement' => $arr, 'locked' => (bool) App::db()->scalar('SELECT 1 FROM profit_sharing_allocations WHERE arrangement_id = ?', [$arr['id']])]);
    }

    public function update(Request $request, string $id): Response
    {
        $arr = $this->find($id);
        $this->svc->updateArrangement($arr, $this->arrangementInput($request), $this->user());
        return $this->redirectRoute('profit-sharing.show', ['id' => $arr['id']])->with('success', 'Arrangement updated.');
    }

    public function status(Request $request, string $id): Response
    {
        $arr = $this->find($id);
        $this->svc->setArrangementActive($arr, $request->string('active') === '1', $this->user());
        return $this->redirectRoute('profit-sharing.show', ['id' => $arr['id']])->with('success', $request->string('active') === '1' ? 'Arrangement activated.' : 'Arrangement deactivated. Its history is kept.');
    }

    public function createAllocation(Request $request, string $id): Response
    {
        $arr = $this->find($id);
        $latest = $this->svc->allocations((int) $arr['id'])[0] ?? null;
        return $this->view('profit-sharing/allocation-form', $this->allocationFormData($arr, null, $latest));
    }

    public function storeAllocation(Request $request, string $id): Response
    {
        $arr = $this->find($id);
        [$from, $to, $lines, $notes] = $this->allocationInput($request);
        $allocId = $this->svc->createAllocation($arr, $from, $to, $lines, $notes, $this->user());
        $this->attachAgreement($request, $allocId, $lines);
        return $this->redirectRoute('profit-sharing.show', ['id' => $arr['id']])->with('success', 'Allocation saved. Earlier allocations are kept as history.');
    }

    public function editAllocation(Request $request, string $id, string $alloc): Response
    {
        $arr = $this->find($id);
        $a = $this->findAllocation($arr, $alloc);
        if ($a['state'] !== 'future') {
            return $this->redirectRoute('profit-sharing.show', ['id' => $arr['id']])->with('error', 'Allocations that have taken effect cannot be edited.');
        }
        return $this->view('profit-sharing/allocation-form', $this->allocationFormData($arr, $a, null));
    }

    public function updateAllocation(Request $request, string $id, string $alloc): Response
    {
        $arr = $this->find($id);
        $a = $this->findAllocation($arr, $alloc);
        [$from, $to, $lines, $notes] = $this->allocationInput($request);
        $this->svc->updateAllocation($a, $from, $to, $lines, $notes, $this->user());
        return $this->redirectRoute('profit-sharing.show', ['id' => $arr['id']])->with('success', 'Future allocation updated.');
    }

    public function deactivateAllocation(Request $request, string $id, string $alloc): Response
    {
        $arr = $this->find($id);
        $this->svc->deactivateAllocation($this->findAllocation($arr, $alloc), $this->user());
        return $this->redirectRoute('profit-sharing.show', ['id' => $arr['id']])->with('success', 'Future allocation deactivated.');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        return $this->svc->arrangement($this->intParam($id)) ?? throw new HttpException(404, 'Arrangement not found.');
    }

    /** @param array<string, mixed> $arr @return array<string, mixed> */
    private function findAllocation(array $arr, string $alloc): array
    {
        $a = $this->svc->allocation($this->intParam($alloc));
        if ($a === null || (int) $a['arrangement_id'] !== (int) $arr['id']) {
            throw new HttpException(404, 'Allocation not found.');
        }
        return $a;
    }

    /** @return array<string, mixed> */
    private function arrangementInput(Request $request): array
    {
        return $this->validate($request, [
            'name' => 'required|string|min:3|max:120',
            'description' => 'nullable|string|max:255',
            'allocation_type' => 'required|in:' . implode(',', array_keys(ProfitSharingService::TYPES)),
            'calculation_method' => 'required|in:' . implode(',', array_keys(ProfitSharingService::METHODS)),
        ], ['allocation_type' => 'Allocation type', 'calculation_method' => 'Calculation method']);
    }

    /** @return array{0: string, 1: ?string, 2: array<int, int>, 3: ?string} */
    private function allocationInput(Request $request): array
    {
        $data = $this->validate($request, [
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'notes' => 'nullable|string|max:5000',
            'percent' => 'nullable|array',
        ], ['effective_from' => 'Effective from', 'effective_to' => 'Effective to']);
        $lines = [];
        foreach ((array) $request->input('percent', []) as $partnerId => $value) {
            $value = trim((string) $value);
            if ($value === '' || $value === '0') {
                continue;
            }
            if (!ctype_digit((string) $partnerId) || !\App\Support\Percent::isValidInput($value)) {
                throw ValidationException::withMessage('lines', 'Percentages must be numbers between 0 and 100 with at most 4 decimals.');
            }
            $lines[(int) $partnerId] = Percent::parse($value);
        }
        return [(string) $data['effective_from'], $data['effective_to'] ?: null, $lines, $data['notes']];
    }

    /** @param array<string, mixed> $arr @param array<string, mixed>|null $alloc @param array<string, mixed>|null $latest */
    private function allocationFormData(array $arr, ?array $alloc, ?array $latest): array
    {
        $partners = App::db()->select("SELECT id, partner_code, name, status FROM partners WHERE status <> 'archived' ORDER BY name");
        $source = $alloc ?? $latest;
        $values = [];
        foreach ($source['lines'] ?? [] as $l) {
            $values[(int) $l['partner_id']] = rtrim(rtrim(Percent::toDb((int) $l['units']), '0'), '.');
        }
        return [
            'title' => $alloc ? 'Edit future allocation' : 'New allocation',
            'breadcrumbs' => ['Profit sharing' => route('profit-sharing.index'), $arr['name'] => route('profit-sharing.show', ['id' => $arr['id']]), $alloc ? 'Edit allocation' : 'New allocation' => null],
            'arrangement' => $arr, 'allocation' => $alloc, 'latest' => $latest, 'partners' => $partners, 'values' => $values,
            'today' => BusinessDate::today(), 'canAttach' => can('partners.upload_documents'),
        ];
    }

    /** Optional agreement document, stored against each partner in the allocation (first partner if many). @param array<int, int> $lines */
    private function attachAgreement(Request $request, int $allocId, array $lines): void
    {
        $file = $request->file('document');
        if ($file === null || $lines === [] || !can('partners.upload_documents')) {
            return;
        }
        (new DocumentService(App::db()))->store((int) array_key_first($lines), $file, 'profit_sharing_agreement', 'Profit-sharing agreement', $this->user(), null, $allocId);
    }
}
