<?php /* Account state as a pastel pill (reference: Active / Blocked). */
$locked = !empty($user['locked_until']) && $user['locked_until'] > now();
$state = \App\Services\Authorization\AccountState::of($user);
$tone = ['active' => 'success', 'inactive' => 'danger', 'deleted' => 'dark'][$state] ?? 'warning';
?>
<span class="badge badge-soft-<?= $tone ?>"><?= e(ucfirst($state)) ?></span>
<?php if ($locked): ?><span class="badge badge-soft-warning" title="Temporarily locked after failed sign-ins">Locked</span><?php endif; ?>
