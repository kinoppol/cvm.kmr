<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;
use Slim\Routing\RouteContext;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            Flash::warning('กรุณาเข้าสู่ระบบก่อนใช้งาน');

            return $this->redirectToLogin($request);
        }

        return $handler->handle($request->withAttribute('user', $user));
    }

    private function redirectToLogin(Request $request): Response
    {
        $url = RouteContext::fromRequest($request)->getRouteParser()->urlFor('login');

        return (new SlimResponse())->withHeader('Location', $url)->withStatus(302);
    }
}
