<?php
/** @var array<string, mixed> $p @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $activityOptions */
$pid = (int) $p['id'];
echo $view->partial('products/_dated', [
    'mappings' => $mappings, 'options' => $activityOptions, 'canManage' => $p['status'] !== 'archived' && can('products.manage_business_mappings'),
    'addUrl' => route('products.activities.store', ['id' => $pid]), 'endUrl' => static fn (int $m): string => route('products.activities.end', ['id' => $pid, 'mapping' => $m]),
    'field' => 'business_activity_id', 'label' => 'Business activity', 'targetRoute' => null,
    'intro' => 'Business activities from the shared master (also used by GST registrations and bank accounts). A product can belong to several activities.',
    'empty' => 'No business activities linked yet.',
]);
