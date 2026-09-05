<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Laravel\Tests\Readme\Category;
use IndexNowKit\Laravel\Tests\Readme\Post;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The model of the README quick start (tests/Readme/Post.php) is the README text, and it works: created inside
 * the test application it submits its page, its AMP page, its category's page and the homepage.
 */
final class ReadmeQuickstartTest extends LaravelTestCase
{
    /** The README shows the fixture verbatim (from its first `use` line): the file is what compiles, the README is what people copy. */
    public function testTheReadmeShowsTheFixtureVerbatim(): void
    {
        $readme = (string) file_get_contents(\dirname(__DIR__, 2) . '/README.md');
        preg_match('/<!-- test: quickstart-model -->\n```php\n(.*?)\n```\n<!-- \/test -->/s', $readme, $m);
        self::assertArrayHasKey(1, $m, 'README.md has no <!-- test: quickstart-model --> block');
        $fixture = (string) file_get_contents(__DIR__ . '/../Readme/Post.php');
        $body = substr($fixture, (int) strpos($fixture, "\nuse ") + 1);
        self::assertSame(trim($body), trim($m[1]));
    }

    #[TestDox('the README model submits its page, the AMP page, the category page and the homepage')]
    public function testTheReadmeModelSubmitsItsPages(): void
    {
        $category = Category::query()->create(['slug' => 'news']);
        $post = new Post();
        $post->slug = 'hello';
        $post->title = 'Hello';
        $post->published = true;
        $post->amp = true;
        $post->category()->associate($category);
        $post->save();
        $this->kit()->flush();

        $urls = $this->sentUrls();
        sort($urls);
        self::assertSame(['https://www.example.com/', 'https://www.example.com/amp/hello', 'https://www.example.com/categories/news', 'https://www.example.com/posts/hello'], $urls);
    }
}
