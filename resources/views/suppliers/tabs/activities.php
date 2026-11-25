<?php
/** @var array<string, mixed> $s @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $activityOptions */
$sid = (int) $s['id'];
echo $view->partial('products/_dated', [
    'mappings' => $mappings, 'options' => $activityOptions, 'canManage' => $s['status'] !== 'archived' && can('suppliers.manage_business_mappings'),
    'addUrl' => route('suppliers.activities.store', ['id' => $sid]), 'endUrl' => static fn (int $m): string => route('suppliers.activities.end', ['id' => $sid, 'mapping' => $m]),
    'field' => 'business_activity_id', 'label' => 'Business activity', 'targetRoute' => null,
    'intro' => 'Business activities this supplier serves, from the shared activity master.',
    'empty' => 'No business activities linked yet.',
]);
