<?php

declare(strict_types=1);

namespace App\Controllers\Product;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthorizationException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Product\DatedMappingService;
use App\Services\Product\PricingService;
use App\Services\Product\ProductMediaService;
use App\Services\Product\SupplierMappingService;
use App\Services\Product\TaxConfigService;
use App\Services\Product\VariantService;

/**
 * Actions on a product's variants, components, prices, GST/HSN configuration, mappings,
 * images and documents. Each loads the product through the record scope first; field rules
 * guard prices (purchase / selling), supplier details and the tax configuration.
 */
final class ProductDetailController extends ProductBaseController
{
    // ---------------------------------------------------------------- variants & components

    public function addVariant(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        (new VariantService(App::db()))->create($p, $request->all(), $this->user());
        return $this->backTo((int) $p['id'], 'variants', 'Variant added.');
    }

    public function updateVariant(Request $request, string $id, string $variant): Response
    {
        $p = $this->findProduct($id);
        $vs = new VariantService(App::db());
        $v = $vs->find((int) $p['id'], $this->intParam($variant)) ?? throw new HttpException(404, 'Variant not found.');
        $vs->update($p, $v, $request->all(), $this->user());
        return $this->backTo((int) $p['id'], 'variants', 'Variant updated.');
    }

    public function variantStatus(Request $request, string $id, string $variant): Response
    {
        $p = $this->findProduct($id);
        $vs = new VariantService(App::db());
        $v = $vs->find((int) $p['id'], $this->intParam($variant)) ?? throw new HttpException(404, 'Variant not found.');
        $vs->setStatus($p, $v, $request->string('status'), $request->string('reason') ?: null, $this->user());
        return $this->backTo((int) $p['id'], 'variants', 'Variant status changed.');
    }

    public function addComponent(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        $component = (int) $request->input('component_product_id', 0);
        App::dataAccess()->assertCanAccess($this->user(), 'products', $component);
        (new VariantService(App::db()))->addComponent($p, $component, (int) $request->input('component_variant_id', 0) ?: null, $request->string('quantity'), $request->string('notes') ?: null, $this->user());
        return $this->backTo((int) $p['id'], 'information', 'Component added.');
    }

    public function removeComponent(Request $request, string $id, string $item): Response
    {
        $p = $this->findProduct($id);
        (new VariantService(App::db()))->removeComponent($p, $this->intParam($item), $request->string('reason'), $this->user());
        return $this->backTo((int) $p['id'], 'information', 'Component removed.');
    }

    // ---------------------------------------------------------------- prices

    public function addPrice(Request $request, string $id): Response
    {
        $me = $this->user();
        $p = $this->findProduct($id);
        $type = $request->string('price_type');
        if (isset(PricingService::TYPES[$type]) && !App::fields()->access($me, 'products', PricingService::field($type))->canEdit()) {
            throw new AuthorizationException('products.manage_pricing', 'You may not set ' . (PricingService::field($type) === 'purchase_cost' ? 'purchase' : 'selling') . ' prices.');
        }
        $this->assertSupplierVisible((int) $request->input('supplier_id', 0));
        $price = (new PricingService(App::db()))->add($p, $request->all(), $me);
        return $this->backTo((int) $p['id'], 'pricing', $price['status'] === 'pending_approval' ? 'Price sent for approval. The current price applies until it is approved.' : 'Price saved. The previous price is kept in the history.',
            $price['status'] === 'pending_approval' ? 'info' : 'success');
    }

    public function cancelPrice(Request $request, string $id, string $price): Response
    {
        $me = $this->user();
        $p = $this->findProduct($id);
        $svc = new PricingService(App::db());
        $row = $svc->find($this->intParam($price));
        if ($row === null || (int) $row['product_id'] !== (int) $p['id']) {
            throw new HttpException(404, 'Price not found.');
        }
        if (!App::fields()->access($me, 'products', PricingService::field((string) $row['price_type']))->canEdit()) {
            throw new AuthorizationException('products.manage_pricing', 'You may not change this kind of price.');
        }
        $svc->cancel($row, $request->string('reason'), $me);
        return $this->backTo((int) $p['id'], 'pricing', 'Price cancelled.');
    }

    // ---------------------------------------------------------------- GST / HSN configuration

    public function addTax(Request $request, string $id): Response
    {
        $me = $this->user();
        if (!can('products.manage_hsn') && !can('products.manage_gst')) {
            throw new AuthorizationException('products.manage_gst', 'You may not change the GST / HSN configuration.');
        }
        if (!App::fields()->access($me, 'products', 'tax_configuration')->canEdit()) {
            throw new AuthorizationException('products.manage_gst', 'The GST / HSN configuration is read-only for you.');
        }
        $p = $this->findProduct($id);
        $cfg = (new TaxConfigService(App::db()))->add($p, $request->all(), $me);
        return $this->backTo((int) $p['id'], 'tax', $cfg['status'] === 'pending_approval' ? 'GST / HSN configuration sent for approval.' : 'GST / HSN configuration saved. The previous one is kept in the history.',
            $cfg['status'] === 'pending_approval' ? 'info' : 'success');
    }

    public function cancelTax(Request $request, string $id, string $config): Response
    {
        if (!can('products.manage_hsn') && !can('products.manage_gst')) {
            throw new AuthorizationException('products.manage_gst', 'You may not change the GST / HSN configuration.');
        }
        $p = $this->findProduct($id);
        $svc = new TaxConfigService(App::db());
        $cfg = $svc->find($this->intParam($config));
        if ($cfg === null || (int) $cfg['product_id'] !== (int) $p['id']) {
            throw new HttpException(404, 'Configuration not found.');
        }
        $svc->cancel($cfg, $request->string('reason'), $this->user());
        return $this->backTo((int) $p['id'], 'tax', 'Configuration cancelled.');
    }

    // ---------------------------------------------------------------- GST registration & business-activity mappings

    public function addGst(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        $this->assertWritable($p);
        $gst = (int) $request->input('gst_registration_id', 0);
        if (!in_array($gst, array_map(static fn (array $g): int => (int) $g['id'], $this->gstOptions()), true)) {
            throw ValidationException::withMessage('gst_registration_id', 'Choose a GST registration you can see.');
        }
        (new DatedMappingService(App::db(), 'product_gst'))->add($p, $gst, $request->all(), $this->user());
        return $this->backTo((int) $p['id'], 'gst', 'GST registration linked.');
    }

    public function endGst(Request $request, string $id, string $mapping): Response
    {
        $p = $this->findProduct($id);
        (new DatedMappingService(App::db(), 'product_gst'))->end($p, $this->intParam($mapping), $request->string('effective_to') ?: null, $request->string('reason'), $this->user());
        return $this->backTo((int) $p['id'], 'gst', 'Link ended. It stays in the history.');
    }

    public function addActivity(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        $this->assertWritable($p);
        (new DatedMappingService(App::db(), 'product_activity'))->add($p, (int) $request->input('business_activity_id', 0), $request->all(), $this->user());
        return $this->backTo((int) $p['id'], 'activities', 'Business activity linked.');
    }

    public function endActivity(Request $request, string $id, string $mapping): Response
    {
        $p = $this->findProduct($id);
        (new DatedMappingService(App::db(), 'product_activity'))->end($p, $this->intParam($mapping), $request->string('effective_to') ?: null, $request->string('reason'), $this->user());
        return $this->backTo((int) $p['id'], 'activities', 'Link ended. It stays in the history.');
    }

    // ---------------------------------------------------------------- suppliers

    public function addSupplier(Request $request, string $id): Response
    {
        $me = $this->user();
        $p = $this->findProduct($id);
        $this->assertSupplierVisible((int) $request->input('supplier_id', 0), true);
        $input = $request->all();
        if (trim((string) ($input['purchase_price'] ?? '')) !== '' && !App::fields()->access($me, 'products', 'purchase_cost')->canEdit()) {
            throw new AuthorizationException('products.manage_pricing', 'You may not set purchase prices.');
        }
        if (!App::fields()->access($me, 'products', 'supplier_details')->canEdit()) {
            foreach (['supplier_product_code', 'supplier_sku', 'min_order_qty', 'max_order_qty', 'lead_time_days'] as $k) {
                unset($input[$k]);
            }
        }
        [, $price, $pref] = (new SupplierMappingService(App::db()))->add($p, $input, $me);
        $notes = ['Supplier linked.'];
        if ($price !== null) {
            $notes[] = $price['status'] === 'pending_approval' ? 'The purchase price is waiting for approval.' : 'Purchase price saved.';
        }
        if ($pref !== null) {
            $notes[] = $pref['status'] === 'approved' ? 'It is now the preferred supplier.' : 'Making it the preferred supplier is waiting for approval.';
        }
        return $this->backTo((int) $p['id'], 'suppliers', implode(' ', $notes));
    }

    public function updateSupplier(Request $request, string $id, string $mapping): Response
    {
        $me = $this->user();
        $p = $this->findProduct($id);
        $svc = new SupplierMappingService(App::db());
        $m = $this->mapping($svc, $p, $mapping);
        $input = $request->all();
        if (!App::fields()->access($me, 'products', 'supplier_details')->canEdit()) {
            foreach (['supplier_product_code', 'supplier_sku', 'min_order_qty', 'max_order_qty', 'lead_time_days'] as $k) {
                $input[$k] = $m[$k];
            }
        }
        $svc->update($p, $m, $input, $me);
        return $this->backTo((int) $p['id'], 'suppliers', 'Supplier mapping updated.');
    }

    public function endSupplier(Request $request, string $id, string $mapping): Response
    {
        $p = $this->findProduct($id);
        $svc = new SupplierMappingService(App::db());
        $svc->end($p, $this->mapping($svc, $p, $mapping), $request->string('effective_to') ?: null, $request->string('reason'), $this->user());
        return $this->backTo((int) $p['id'], 'suppliers', 'Supplier mapping ended. It stays in the history.');
    }

    public function preferSupplier(Request $request, string $id, string $mapping): Response
    {
        $p = $this->findProduct($id);
        $svc = new SupplierMappingService(App::db());
        $m = $this->mapping($svc, $p, $mapping);
        $change = $svc->requestPreferred($p, (int) $m['id'], $request->string('reason'), $this->user());
        return $this->backTo((int) $p['id'], 'suppliers', $change['status'] === 'approved' ? "{$m['supplier_name']} is now the preferred supplier." : 'Preferred-supplier change sent for approval.',
            $change['status'] === 'approved' ? 'success' : 'info');
    }

    // ---------------------------------------------------------------- images & documents

    public function uploadImage(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        (new ProductMediaService(App::db()))->uploadImage($p, $this->file($request, 'image'), $request->all(), $this->user());
        return $this->backTo((int) $p['id'], 'media', 'Image uploaded.');
    }

    public function image(Request $request, string $id, string $image): Response
    {
        $p = $this->findProduct($id);
        $img = App::db()->first('SELECT * FROM product_images WHERE id = ? AND product_id = ?', [$this->intParam($image), $p['id']]) ?? throw new HttpException(404, 'Image not found.');
        return (new ProductMediaService(App::db()))->imageResponse($img);
    }

    public function primaryImage(Request $request, string $id, string $image): Response
    {
        $p = $this->findProduct($id);
        $img = App::db()->first('SELECT * FROM product_images WHERE id = ? AND product_id = ?', [$this->intParam($image), $p['id']]) ?? throw new HttpException(404, 'Image not found.');
        (new ProductMediaService(App::db()))->setPrimary($p, $img, $this->user());
        return $this->backTo((int) $p['id'], 'media', 'Primary image changed.');
    }

    public function archiveImage(Request $request, string $id, string $image): Response
    {
        $p = $this->findProduct($id);
        $img = App::db()->first('SELECT * FROM product_images WHERE id = ? AND product_id = ?', [$this->intParam($image), $p['id']]) ?? throw new HttpException(404, 'Image not found.');
        (new ProductMediaService(App::db()))->archiveImage($p, $img, $request->string('reason'), $this->user());
        return $this->backTo((int) $p['id'], 'media', 'Image archived.');
    }

    public function uploadDocument(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        (new ProductMediaService(App::db()))->uploadDocument($p, $this->file($request, 'document'), $request->all(), $this->user());
        return $this->backTo((int) $p['id'], 'media', 'Document uploaded.');
    }

    public function document(Request $request, string $id, string $doc): Response
    {
        $p = $this->findProduct($id);
        $d = App::db()->first('SELECT * FROM product_documents WHERE id = ? AND product_id = ?', [$this->intParam($doc), $p['id']]) ?? throw new HttpException(404, 'Document not found.');
        return (new ProductMediaService(App::db()))->documentResponse($d, $p, $request->string('download') !== '1');
    }

    public function archiveDocument(Request $request, string $id, string $doc): Response
    {
        $p = $this->findProduct($id);
        $d = App::db()->first('SELECT * FROM product_documents WHERE id = ? AND product_id = ?', [$this->intParam($doc), $p['id']]) ?? throw new HttpException(404, 'Document not found.');
        (new ProductMediaService(App::db()))->archiveDocument($p, $d, $request->string('reason'), $this->user());
        return $this->backTo((int) $p['id'], 'media', 'Document archived.');
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $p */
    private function assertWritable(array $p): void
    {
        if ($p['status'] === 'archived') {
            throw new \App\Exceptions\DomainRuleException('Archived products are read-only.');
        }
    }

    /** A supplier chosen on a product page must be one the user may see. */
    private function assertSupplierVisible(int $supplierId, bool $required = false): void
    {
        if ($supplierId === 0 && !$required) {
            return;
        }
        if (!in_array($supplierId, array_map(static fn (array $s): int => (int) $s['id'], $this->supplierOptions()), true)) {
            throw ValidationException::withMessage('supplier_id', 'Choose a supplier you can see.');
        }
    }

    /** @param array<string, mixed> $p @return array<string, mixed> */
    private function mapping(SupplierMappingService $svc, array $p, string $mapping): array
    {
        $m = $svc->find($this->intParam($mapping));
        if ($m === null || (int) $m['product_id'] !== (int) $p['id']) {
            throw new HttpException(404, 'Mapping not found.');
        }
        return $m;
    }

    /** @return array{name: string, tmp_name: string, error: int, size: int} */
    private function file(Request $request, string $key): array
    {
        $f = $request->file($key);
        if ($f === null) {
            throw ValidationException::withMessage($key, 'Choose a file to upload.');
        }
        return $f;
    }
}
