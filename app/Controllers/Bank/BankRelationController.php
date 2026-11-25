<?php

declare(strict_types=1);

namespace App\Controllers\Bank;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Bank\BankDocumentService;
use App\Services\Bank\BankRelationService;

/** Partner / GST / business-activity mappings and documents of a bank account. */
final class BankRelationController extends BankController
{
    private BankRelationService $rel;

    public function __construct()
    {
        $this->rel = new BankRelationService(App::db());
    }

    public function addPartner(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $this->rel->addPartner($acc, $request->all(), $this->user());
        return $this->tab($acc, 'partners', 'Partner mapped.');
    }

    public function endPartner(Request $request, string $id, string $mapping): Response
    {
        $acc = $this->findAccount($id);
        $this->rel->endPartner($acc, $this->intParam($mapping), $request->string('effective_to'), $request->string('reason'), $this->user());
        return $this->tab($acc, 'partners', 'Mapping ended. It stays in the history.');
    }

    public function addGst(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $regId = (int) $request->input('gst_registration_id', 0);
        // Only registrations the user may see can be mapped.
        App::dataAccess()->assertCanAccess($this->user(), 'gst_registrations', $regId);
        $this->rel->addGst($acc, $request->all(), $this->user());
        return $this->tab($acc, 'gst', 'GST registration mapped.');
    }

    public function designation(Request $request, string $id, string $mapping): Response
    {
        $acc = $this->findAccount($id);
        $this->rel->setDesignation($acc, $this->intParam($mapping), $request->string('designation'), $this->user());
        return $this->tab($acc, 'gst', 'Designation changed.');
    }

    public function endGst(Request $request, string $id, string $mapping): Response
    {
        $acc = $this->findAccount($id);
        $this->rel->endGst($acc, $this->intParam($mapping), $request->string('effective_to'), $request->string('reason'), $this->user());
        return $this->tab($acc, 'gst', 'GST mapping ended. It stays in the history.');
    }

    public function addActivity(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $this->rel->addActivity($acc, $request->all(), $this->user());
        return $this->tab($acc, 'activities', 'Business activity mapped.');
    }

    public function updateActivity(Request $request, string $id, string $mapping): Response
    {
        $acc = $this->findAccount($id);
        $this->rel->updateActivity($acc, $this->intParam($mapping), $request->all(), $this->user());
        return $this->tab($acc, 'activities', 'Mapping updated.');
    }

    public function uploadDocument(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $file = $request->file('document') ?? throw \App\Exceptions\ValidationException::withMessage('document', 'Choose a file.');
        (new BankDocumentService(App::db()))->upload($acc, $file, $request->all(), $this->user());
        $back = $request->string('back');
        if (str_starts_with($back, '/bank/') && !str_contains($back, '//')) {
            return $this->redirect($back)->with('success', 'Document uploaded.');
        }
        return $this->tab($acc, 'documents', 'Document uploaded.');
    }

    public function download(Request $request, string $doc): Response
    {
        [$d, $acc] = $this->doc($doc);
        return (new BankDocumentService(App::db()))->response($d, $acc, false);
    }

    public function preview(Request $request, string $doc): Response
    {
        [$d, $acc] = $this->doc($doc);
        return (new BankDocumentService(App::db()))->response($d, $acc, true);
    }

    public function archiveDocument(Request $request, string $doc): Response
    {
        [$d, $acc] = $this->doc($doc);
        (new BankDocumentService(App::db()))->archive($d, $acc, $request->string('reason'), $this->user());
        return $this->tab($acc, 'documents', 'Document archived.');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function doc(string $id): array
    {
        $d = (new BankDocumentService(App::db()))->find($this->intParam($id)) ?? throw new HttpException(404, 'Document not found.');
        return [$d, $this->findAccount((int) $d['bank_account_id'])];
    }

    /** @param array<string, mixed> $acc */
    private function tab(array $acc, string $tab, string $message): Response
    {
        return $this->redirectRoute('bank.show', ['id' => $acc['id'], 'tab' => $tab])->with('success', $message);
    }
}
