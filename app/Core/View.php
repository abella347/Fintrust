<?php

declare(strict_types=1);

namespace App\Core;

/** Renders a page template inside a layout. */
final class View
{
    public static function render(string $template, array $data = [], string $layout = 'public'): void
    {
        $data['title'] ??= config('app.name');
        $data['flashes'] = Session::pullFlashes();

        $content = self::capture($template, $data);
        echo self::capture('layouts/' . $layout, $data + ['content' => $content]);
    }

    /** Render a partial (e.g. 'partials/alert') and return its HTML. */
    public static function partial(string $template, array $data = []): string
    {
        return self::capture($template, $data);
    }

    public static function notFound(): never
    {
        http_response_code(404);
        self::render('pages/404', ['title' => 'Page not found'], 'public');
        exit;
    }

    private static function capture(string $template, array $data): string
    {
        $file = base_path('app/Views/' . $template . '.php');
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }

        // Variables in $data become local variables inside the template.
        // EXTR_SKIP: never overwrite $file or other locals already defined here.
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
