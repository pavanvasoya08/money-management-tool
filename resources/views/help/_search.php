<?php /** Help search box. @var string|null $q @var bool|null $large */ ?>
<form class="help-search<?= !empty($large) ? ' is-large' : '' ?>" method="get" action="<?= e(route('help.search')) ?>" role="search">
    <div class="input-icon">
        <i data-lucide="search"></i>
        <input class="form-control" type="search" name="q" value="<?= e($q ?? '') ?>" placeholder="Search guides, steps and FAQs — e.g. “withdrawal”, “reset password”" aria-label="Search the user guide" maxlength="100" required>
    </div>
    <button class="btn btn-primary" type="submit">Search</button>
</form>
