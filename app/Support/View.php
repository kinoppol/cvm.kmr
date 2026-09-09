<?php

declare(strict_types=1);

namespace App\Support;

use Latte\Engine;
use Psr\Http\Message\ResponseInterface;

final class View
{
    private Engine $latte;

    /** @param array<string,mixed> $shared ตัวแปรที่ทุกหน้ามองเห็น */
    public function __construct(private array $shared = [])
    {
        $cache = Paths::storage('cache/latte');
        if (!is_dir($cache)) {
            @mkdir($cache, 0775, true);
        }

        $this->latte = new Engine();
        $this->latte->setTempDirectory($cache);
        $this->latte->setAutoRefresh(true);
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string,mixed> $params */
    public function render(ResponseInterface $response, string $template, array $params = []): ResponseInterface
    {
        $html = $this->latte->renderToString(
            Paths::views() . '/' . ltrim($template, '/') . '.latte',
            $params + $this->shared + ['flash' => Flash::pull(), 'csrf' => Csrf::token()]
        );

        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
