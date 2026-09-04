<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Eloquent;

/**
 * Which model field a route parameter is bound to (`{post:slug}` binds `post` to `slug`). The observer asks this
 * to know when a changed field renames the page behind a `params: ['post' => 'self']` rule; without an answer it
 * falls back to the model's route key. Implemented by the Laravel router bridge; the Eloquent layer itself needs
 * nothing from `illuminate/routing`.
 */
interface RouteBindingFieldsInterface
{
    /**
     * @return string|null the binding field, null when the route does not name one (or is unknown)
     */
    public function bindingFieldFor(string $route, string $param): ?string;
}
