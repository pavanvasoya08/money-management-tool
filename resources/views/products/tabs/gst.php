<?php
/** @var array<string, mixed> $p @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $gstOptions */
$pid = (int) $p['id'];
echo $view->partial('products/_dated', [
    'mappings' => $mappings, 'options' => $gstOptions, 'canManage' => $p['status'] !== 'archived' && can('products.manage_gst_mappings') && $gstOptions !== [],
    'addUrl' => route('products.gst.store', ['id' => $pid]), 'endUrl' => static fn (int $m): string => route('products.gst.end', ['id' => $pid, 'mapping' => $m]),
    'field' => 'gst_registration_id', 'label' => 'GST registration', 'targetRoute' => can('gst_registrations.view') ? 'gst.show' : null,
    'intro' => 'The GST registrations this product is sold or bought under. A product can belong to several registrations and a registration has many products; a product is not available to a registration unless it is linked here. Registration-specific prices and GST/HSN configurations need this link.',
    'empty' => 'Not linked to any GST registration yet.',
]);
