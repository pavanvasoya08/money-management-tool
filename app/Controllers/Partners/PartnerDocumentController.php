<?php

declare(strict_types=1);

namespace App\Controllers\Partners;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthorizationException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Partners\DocumentService;

/**
 * Private partner documents. Every request re-checks: permission, record
 * scope of the partner (404 outside it), and the identity-document field rule.
 * There is no public URL: files are streamed by this controller only.
 */
final class PartnerDocumentController extends Controller
{
    private DocumentService $docs;

    public function __construct()
    {
        $this->docs = new DocumentService(App::db());
    }

    public function store(Request $request, string $id): Response
    {
        $pid = $this->intParam($id);
        $me = $this->user();
        App::dataAccess()->assertCanAccess($me, 'partners', $pid);
        $partner = App::db()->first('SELECT id, status FROM partners WHERE id = ?', [$pid]) ?? throw new HttpException(404);
        if ($partner['status'] === 'archived') {
            throw ValidationException::withMessage('document', 'Archived partners are read-only.');
        }
        $data = $this->validate($request, ['category' => 'required|in:' . implode(',', array_keys(DocumentService::CATEGORIES)), 'title' => 'nullable|string|max:150'], ['category' => 'Document type']);
        if (!$this->docs->categoryVisible($me, (string) $data['category'])) {
            throw new AuthorizationException('partners.identity_documents', 'You are not allowed to handle identity documents.');
        }
        $file = $request->file('document') ?? throw ValidationException::withMessage('document', 'Choose a file to upload.');
        $this->docs->store($pid, $file, (string) $data['category'], (string) ($data['title'] ?? ''), $me);
        return $this->redirectRoute('partners.show', ['id' => $pid, 'tab' => $data['category'] === 'photo' ? 'overview' : 'documents'])->with('success', 'Document uploaded.');
    }

    public function download(Request $request, string $id, string $doc): Response
    {
        $d = $this->findDoc($id, $doc);
        App::activity()->log('partner.document_downloaded', "Downloaded \"{$d['original_name']}\"", 'partner_document', (int) $d['id'], module: 'partners', newValues: ['partner_id' => (int) $d['partner_id']]);
        return $this->docs->response($d, inline: $request->string('inline') === '1' && in_array($d['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true));
    }

    public function destroy(Request $request, string $id, string $doc): Response
    {
        $d = $this->findDoc($id, $doc);
        $this->docs->remove($d, $this->user());
        return $this->redirectRoute('partners.show', ['id' => $d['partner_id'], 'tab' => 'documents'])->with('success', 'Document removed. It is kept in the audit trail.');
    }

    /** @return array<string, mixed> */
    private function findDoc(string $partnerId, string $docId): array
    {
        $pid = $this->intParam($partnerId);
        $me = $this->user();
        App::dataAccess()->assertCanAccess($me, 'partners', $pid);
        $d = $this->docs->find($this->intParam($docId));
        if ($d === null || (int) $d['partner_id'] !== $pid || $d['category'] === 'photo' || !$this->docs->categoryVisible($me, (string) $d['category'])) {
            throw new HttpException(404, 'Document not found.');
        }
        return $d;
    }
}
