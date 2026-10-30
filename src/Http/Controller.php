<?php

declare(strict_types=1);

namespace HeardLive\Http;

abstract class Controller
{
    public function __construct(protected View $view)
    {
    }

    /** @param array<string, mixed> $data */
    protected function page(string $view, string $title, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($view, ['title' => $title] + $data), $status);
    }
}
