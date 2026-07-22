<?php

namespace LinkRobins\Warble\Middleware;

use LinkRobins\Warble\Frontend\AdminAssetsHealth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs the stale-bundle check before the admin document renders, so a flushed
 * bundle recompiles within the same page load. Extender-added middleware sits
 * at the end of the admin stack, after core's session auth and admin check.
 *
 * GET only: the check is a read (plus, at worst, a cache flush), and healing
 * must never piggyback on a state-changing request.
 */
class HealAdminAssets implements MiddlewareInterface
{
    public function __construct(protected AdminAssetsHealth $health)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'GET') {
            $this->health->healIfStale();
        }

        return $handler->handle($request);
    }
}
