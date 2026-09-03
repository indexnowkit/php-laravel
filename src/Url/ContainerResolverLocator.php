<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Url;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\ResolverLocatorInterface;
use IndexNowKit\Url\UrlResolverInterface;

/**
 * #[IndexNow(resolver: ...)] values are resolved through the container: a binding id, or any class the container
 * can build (constructor dependencies are injected).
 */
final class ContainerResolverLocator implements ResolverLocatorInterface
{
    public function __construct(private readonly Container $container) {}

    public function get(string $id): UrlResolverInterface
    {
        if (!$this->container->bound($id) && !class_exists($id)) {
            throw new ConfigurationException(\sprintf('IndexNow resolver "%s" is neither a container binding nor a class. Implement %s and reference the class or its binding id.', $id, UrlResolverInterface::class));
        }
        try {
            $resolver = $this->container->make($id);
        } catch (BindingResolutionException $e) {
            throw new ConfigurationException(\sprintf('IndexNow resolver "%s" cannot be built by the container: %s', $id, $e->getMessage()), 0, $e);
        }
        if (!$resolver instanceof UrlResolverInterface) {
            throw new ConfigurationException(\sprintf('IndexNow resolver "%s" resolves to %s, which does not implement %s.', $id, get_debug_type($resolver), UrlResolverInterface::class));
        }

        return $resolver;
    }
}
