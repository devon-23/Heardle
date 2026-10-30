<?php

declare(strict_types=1);

namespace HeardLive\Http;

use RuntimeException;

/** Renders views/{name}.php inside views/layout.php. Templates must escape output with e(). */
final class View
{
    public function __construct(private string $dir)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($name, $data);
        if ($layout === null) {
            return $content;
        }

        return $this->renderFile($layout, $data + ['content' => $content]);
    }

    /** Renders a template without the layout; templates call this as $view->partial(...). */
    public function partial(string $name, array $data = []): string
    {
        return $this->renderFile($name, $data);
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $name, array $data): string
    {
        $data['view'] = $this;
        if (!preg_match('/^[a-z0-9_\/-]+$/', $name)) {
            throw new RuntimeException("Invalid view name: $name");
        }
        $file = $this->dir . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("View not found: $name");
        }

        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })($file, $data);
    }
}
