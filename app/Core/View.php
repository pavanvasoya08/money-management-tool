<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Plain-PHP template renderer. Templates must escape output with e().
 */
final class View
{
    public function __construct(private readonly string $path)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = $this->partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return $this->partial($layout, $data + ['content' => $content]);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $__template, array $__data = []): string
    {
        if (!preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-]+)*$#', $__template)) {
            throw new RuntimeException('Invalid view name.');
        }
        $__file = $this->path . '/' . $__template . '.php';
        if (!is_file($__file)) {
            throw new RuntimeException("View [{$__template}] not found.");
        }
        $view = $this;
        extract($__data, EXTR_SKIP);
        ob_start();
        try {
            require $__file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
