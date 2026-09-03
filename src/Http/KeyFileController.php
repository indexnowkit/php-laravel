<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use IndexNowKit\Key\KeyFileResponder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /{key}.txt -> the key itself, only for a key of the requested host (404 otherwise). Registered by the
 * service provider without the `web` middleware group: no session, no CSRF, no cookies.
 */
final class KeyFileController
{
    /**
     * @param bool $varyHost `Vary: Host` on the response (set when a `hosts` map is configured)
     */
    public function __construct(private readonly KeyFileResponder $responder, private readonly int $maxAge = KeyFileResponder::DEFAULT_MAX_AGE, private readonly bool $varyHost = false) {}

    public function __invoke(Request $request, string $key): Response
    {
        $body = $this->responder->bodyForKey($key, $request->getHost());
        if ($body === null) {
            throw new NotFoundHttpException();
        }

        return new Response($body, 200, KeyFileResponder::headers($this->maxAge, $this->varyHost));
    }
}
