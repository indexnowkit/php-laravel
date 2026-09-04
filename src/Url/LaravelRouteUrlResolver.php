<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Url;

use Exception;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Laravel\Eloquent\RouteBindingFieldsInterface;
use IndexNowKit\Url\RouteUrlResolverInterface;

/**
 * Generates absolute URLs through the Laravel router (`route()`), including route model binding for
 * `params: ['post' => 'self']`.
 *
 * Origin: inside an HTTP request the URL keeps the host Laravel generated it on; in the console (artisan, queue
 * workers) it is rebased onto `base_url`; a rule that pins a host (`#[IndexNow(host: ...)]`) is rebased onto
 * `hosts.<host>.base_url`, else `https://<host>`. Routes with their own `Route::domain()` keep it. The
 * UrlGenerator's global state (`forceRootUrl`) is never touched.
 */
final class LaravelRouteUrlResolver implements RouteUrlResolverInterface, RouteBindingFieldsInterface
{
    /**
     * @param list<string> $locales         locales of `locales: 'all'` (`router.locales`)
     * @param string       $localeParameter route parameter carrying the locale, added only when the route declares it
     * @param bool         $setAppLocale    switch the application locale while generating a locale's URL
     */
    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly Router $router,
        private readonly Config $config,
        private readonly Application $app,
        private readonly array $locales = [],
        private readonly string $localeParameter = 'locale',
        private readonly bool $setAppLocale = true,
    ) {}

    public function locales(array|string $locales): array
    {
        if (\is_array($locales)) {
            return $locales === [] ? [null] : $locales;
        }
        if ($locales === 'all' && $this->locales !== []) {
            return $this->locales;
        }

        return [null];
    }

    public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string
    {
        $definition = $this->route($route);
        if ($definition === null) {
            throw new ConfigurationException(\sprintf('Cannot generate route "%s": no route has that name.', $route));
        }
        if ($locale !== null && \in_array($this->localeParameter, $definition->parameterNames(), true)) {
            $params[$this->localeParameter] = $locale;
        }
        $previousLocale = null;
        if ($locale !== null && $this->setAppLocale && $this->app->getLocale() !== $locale) {
            $previousLocale = $this->app->getLocale();
            $this->app->setLocale($locale);
        }
        try {
            $url = $this->urls->route($route, $params, true);
        } catch (Exception $e) {
            throw new ConfigurationException(\sprintf('Cannot generate route "%s": %s', $route, $e->getMessage()), 0, $e);
        } finally {
            if ($previousLocale !== null) {
                $this->app->setLocale($previousLocale);
            }
        }
        $root = $this->rootFor($host);

        return $root === null || $definition->getDomain() !== null ? $url : self::rebase($url, $root);
    }

    /**
     * The model field a `{param}` of the named route binds to (`{post:slug}` -> slug), or null for the default key.
     */
    public function bindingFieldFor(string $route, string $param): ?string
    {
        $field = $this->route($route)?->bindingFieldFor($param);

        return \is_string($field) && $field !== '' ? $field : null;
    }

    private function route(string $name): ?Route
    {
        return $this->router->getRoutes()->getByName($name);
    }

    /**
     * Origin to generate on: the pinned host's base URL, else base_url outside HTTP requests; null keeps Laravel's.
     */
    private function rootFor(?string $host): ?string
    {
        if ($host !== null) {
            return $this->config->baseUrlFor($host) ?? 'https://' . $host;
        }

        return $this->app->runningInConsole() ? $this->config->baseUrl : null;
    }

    /**
     * Replaces scheme, host and port of $url with those of $root; path, query and fragment stay.
     */
    private static function rebase(string $url, string $root): string
    {
        $target = parse_url($root);
        $source = parse_url($url);
        if (!\is_array($target) || !\is_array($source) || !isset($target['scheme'], $target['host'])) {
            return $url;
        }
        $origin = $target['scheme'] . '://' . $target['host'] . (isset($target['port']) ? ':' . $target['port'] : '');

        return $origin . ($source['path'] ?? '/') . (isset($source['query']) ? '?' . $source['query'] : '') . (isset($source['fragment']) ? '#' . $source['fragment'] : '');
    }
}
