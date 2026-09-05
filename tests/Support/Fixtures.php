<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Routing\Router;
use IndexNowKit\Laravel\Tests\Fixtures\Category;
use IndexNowKit\Laravel\Tests\Fixtures\Post;

/**
 * The package's test application: config, routes and schema of the conformance fixtures, shared by the Testbench
 * test case and the harness the core conformance kits run on.
 */
final class Fixtures
{
    public const KEY = 'abcdef1234567890abcdef1234567890';
    public const SECOND_KEY = 'fedcba0987654321fedcba0987654321';
    public const BASE_URL = 'https://www.example.com';

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'key' => self::KEY,
            'base_url' => self::BASE_URL,
            'hosts' => ['example.de' => self::SECOND_KEY],
            'dispatch' => 'sync',
            'dry_run' => false, // explicit: "testing" is not production, an unset dry_run fails check
            'debounce' => ['per_url' => 0, 'store' => 'memory'],
            'router' => ['locales' => ['en', 'de']],
            'collector' => ['detect_leaks' => false],
            'sitemap' => ['spool' => 'memory'],
        ];
    }

    /**
     * Overrides on top of the test config: nested arrays merge, an empty array (or a list) replaces.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $current = $base[$key] ?? null;
            $base[$key] = \is_array($value) && $value !== [] && !array_is_list($value) && \is_array($current) ? self::merge($current, $value) : $value;
        }

        return $base;
    }

    public static function routes(Router $router): void
    {
        $router->get('/posts/{post:slug}', static fn(Post $post): string => $post->slug)->name('posts.show');
        $router->get('/posts/{slug}', static fn(string $slug): string => $slug)->name('pages.show');
        $router->get('/amp/{slug}', static fn(string $slug): string => $slug)->name('posts.amp');
        $router->get('/categories/{category}', static fn(Category $category): string => $category->slug)->name('categories.show');
        $router->get('/{locale}/articles/{slug}', static fn(string $locale, string $slug): string => $locale . $slug)->name('articles.show');
        $router->domain('shop.example.com')->get('/products/{slug}', static fn(string $slug): string => $slug)->name('products.show');
        $router->getRoutes()->refreshNameLookups();
    }

    public static function migrate(Application $app): void
    {
        $schema = $app->make('db')->connection()->getSchemaBuilder();
        \assert($schema instanceof Builder);
        $schema->create('posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title')->default('title');
            $table->boolean('published')->default(true);
            $table->integer('views')->default(0);
        });
        $schema->create('multi_posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->boolean('published')->default(true);
            $table->boolean('amp')->default(false);
        });
        $schema->create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
        });
        $schema->create('categorized_posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->integer('views')->default(0);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->timestamps();
        });
        $schema->create('tags', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('categorized_post_tags', static function (Blueprint $table): void {
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('tag_id');
        });
        foreach (['untracked', 'broken', 'bad_attribute'] as $name) {
            $schema->create($name, static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            });
        }
        $schema->create('soft_posts', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
