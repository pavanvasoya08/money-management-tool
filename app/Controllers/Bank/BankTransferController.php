<?php

declare(strict_types=1);

namespace App\Controllers\Bank;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Bank\BankDocumentService;
use App\Services\Bank\BankLedgerService;
use App\Services\Bank\BankTransferService;
use App\Support\BusinessDate;
use App\Support\Money;

/** Internal transfers: list, create, detail, submit, post, reverse, cancel. */
final class BankTransferController extends BankController
{
    private BankTransferService $transfers;

    public function __construct()
    {
        $this->transfers = new BankTransferService(App::db());
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'status', 'from', 'to', 'central'], ['account', 'gst', 'activity']);
        return $this->view('bank/transfers/index', [
            'title' => 'Internal transfers', 'breadcrumbs' => self::CRUMB + ['Transfers' => null],
            'transfers' => $this->transfers->paginate($f, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15) * 2, $this->scope('fa'), $this->scope('ta')),
            'f' => $f, 'accounts' => $this->accountOptions(), 'amounts' => App::fields()->access($this->user(), 'bank_accounts', 'current_balance'),
        ]);
    }

    public function create(Request $request): Response
    {
        $accounts = $this->accountOptions(true);
        $ledger = new BankLedgerService(App::db());
        $avail = [];
        foreach ($accounts as $a) {
            $avail[(int) $a['id']] = Money::toFloat($ledger->available((int) $a['id']));
        }
        return $this->view('bank/transfers/form', [
            'title' => 'New internal transfer', 'breadcrumbs' => self::CRUMB + ['Transfers' => route('bank.transfers.index'), 'New' => null],
            'accounts' => $accounts, 'available' => can('bank_accounts.view') && App::fields()->access($this->user(), 'bank_accounts', 'current_balance')->showsRealValue() ? $avail : [],
            'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(), 'today' => BusinessDate::today(),
            'from' => (int) $request->input('from', 0), 'to' => (int) $request->input('to', 0),
        ]);
    }

    public function store(Request $request): Response
    {
        foreach (['from_account_id', 'to_account_id'] as $k) {
            if (($id = (int) $request->input($k, 0)) > 0) {
                $this->findAccount($id); // both accounts inside the user's scope
            }
        }
        if (($g = (int) $request->input('gst_registration_id', 0)) > 0) {
            App::dataAccess()->assertCanAccess($this->user(), 'gst_registrations', $g);
        }
        $tr = $this->transfers->create($request->all(), $this->user(), $request->string('action') === 'submit');
        if (($file = $request->file('document')) !== null && can('bank_accounts.manage_documents')) {
            (new BankDocumentService(App::db()))->upload($this->findAccount((int) $tr['from_account_id']), $file, ['document_type' => 'transaction_proof', 'title' => 'Proof for ' . $tr['transfer_no'], 'transfer_id' => $tr['id']], $this->user());
        }
        return $this->redirectRoute('bank.transfers.show', ['id' => $tr['id']])->with(...$this->outcome($tr));
    }

    public function show(Request $request, string $id): Response
    {
        $tr = $this->find($id);
        $me = $this->user();
        return $this->view('bank/transfers/show', [
            'title' => $tr['transfer_no'], 'breadcrumbs' => self::CRUMB + ['Transfers' => route('bank.transfers.index'), $tr['transfer_no'] => null],
            'tr' => $tr, 'entries' => $this->transfers->entries((int) $tr['id']), 'documents' => (new BankDocumentService(App::db()))->forTransfer((int) $tr['id']),
            'amounts' => App::fields()->access($me, 'bank_accounts', 'current_balance'),
            'canSeeFrom' => App::dataAccess()->canAccess($me, 'bank_accounts', (int) $tr['from_account_id']), 'canSeeTo' => App::dataAccess()->canAccess($me, 'bank_accounts', (int) $tr['to_account_id']),
            'audit' => App::db()->select("SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.subject_type = 'bank_transfer' AND l.subject_id = ? ORDER BY l.id DESC", [$tr['id']]),
        ]);
    }

    public function submit(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.manage_transfers');
        $tr = $this->transfers->submit($this->find($id, true), $this->user());
        return $this->redirectRoute('bank.transfers.show', ['id' => $tr['id']])->with(...$this->outcome($tr));
    }

    public function cancel(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.manage_transfers');
        $tr = $this->find($id, true);
        $this->transfers->cancel($tr, $this->user());
        return $this->redirectRoute('bank.transfers.show', ['id' => $tr['id']])->with('success', 'Transfer cancelled.');
    }

    public function post(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.approve_transfers');
        $tr = $this->find($id, true);
        $this->transfers->post($tr, $this->user());
        return $this->redirectRoute('bank.transfers.show', ['id' => $tr['id']])->with('success', 'Transfer posted: both sides are in the ledger.');
    }

    public function reverse(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.reverse_transactions');
        $tr = $this->find($id, true);
        $data = $this->validate($request, ['reason' => 'required|string|min:5|max:500', 'reversal_date' => 'required|date|before_or_equal:today'], ['reversal_date' => 'Reversal date']);
        $this->transfers->reverse($tr, (string) $data['reason'], (string) $data['reversal_date'], $this->user());
        return $this->redirectRoute('bank.transfers.show', ['id' => $tr['id']])->with('success', 'Transfer reversed on both accounts.');
    }

    /**
     * Visible when either account is in scope; changing it needs both.
     * @return array<string, mixed>
     */
    private function find(string $id, bool $both = false): array
    {
        $tr = $this->transfers->find($this->intParam($id)) ?? throw new HttpException(404, 'Transfer not found.');
        $da = App::dataAccess();
        $me = $this->user();
        $from = $da->canAccess($me, 'bank_accounts', (int) $tr['from_account_id']);
        $to = $da->canAccess($me, 'bank_accounts', (int) $tr['to_account_id']);
        if ($both ? !($from && $to) : !($from || $to)) {
            throw new HttpException(404, 'Transfer not found.');
        }
        return $tr;
    }

    /** @param array<string, mixed> $tr @return array{0: string, 1: string} */
    private function outcome(array $tr): array
    {
        return match ($tr['status']) {
            'draft' => ['success', "Saved transfer {$tr['transfer_no']} as a draft."],
            'pending_approval' => ['info', "Transfer {$tr['transfer_no']} sent for approval. Both sides are posted once approved."],
            'posted' => ['success', "Transfer {$tr['transfer_no']} posted: debit on the source, credit on the destination."],
            'approved' => ['warning', "Transfer {$tr['transfer_no']} is approved but could not be posted yet: " . ($tr['posting_error'] ?? '')],
            default => ['success', 'Saved.'],
        };
    }
}
