<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Feature;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Laravel\Tests\Fixtures\Post;
use IndexNowKit\Laravel\Tests\LaravelTestCase;
use IndexNowKit\Testing\Conformance\KeyFileAssertions;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

final class HttpConformanceTest extends LaravelTestCase
{
    private int $collectedDuringRequest = -1;
    private int $postsDuringRequest = -1;

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);
        \assert($router instanceof Router);
        $router->post('/articles', function (): array {
            $post = Post::query()->create(['slug' => (string) request()->query('slug')]);
            $this->collectedDuringRequest = $this->app->make(CollectorInterface::class)->count();
            $this->postsDuringRequest = \count($this->transport->posts);

            return ['id' => $post->id];
        });
        $router->post('/articles/fail', static function (): never {
            DB::transaction(static function (): void {
                Post::query()->create(['slug' => 'nope']);
                throw new RuntimeException('boom');
            });
        });
        $router->post('/articles/{slug}/delete', static function (string $slug): array {
            Post::query()->where('slug', $slug)->firstOrFail()->delete();

            return ['ok' => true];
        });
    }

    #[TestDox('H01 GET /{key}.txt -> 200 text/plain with the key, short cache, Vary: Host with a hosts map')]
    public function testH01KeyFile(): void
    {
        $response = $this->get('/' . self::KEY . '.txt');

        KeyFileAssertions::assertKeyFileResponse($response->getStatusCode(), $response->headers->all(), (string) $response->getContent(), self::KEY, expectVaryHost: true);
        self::assertFalse($response->headers->has('Set-Cookie'), 'no web middleware: no session cookie on the key file');
    }

    #[TestDox('H01b the key file of another configured host is served only on that host')]
    public function testKeyFileIsPerHost(): void
    {
        KeyFileAssertions::assertNotServed($this->get('/' . self::SECOND_KEY . '.txt')->getStatusCode());
        $response = $this->get('https://example.de/' . self::SECOND_KEY . '.txt');
        KeyFileAssertions::assertKeyFileResponse($response->getStatusCode(), $response->headers->all(), (string) $response->getContent(), self::SECOND_KEY, expectVaryHost: true);
        KeyFileAssertions::assertNotServed($this->get('https://example.de/' . self::KEY . '.txt')->getStatusCode());
    }

    #[TestDox('H02 GET /other.txt -> 404')]
    public function testH02UnknownKey(): void
    {
        KeyFileAssertions::assertNotServed($this->get('/abcdefghijklmnop.txt')->getStatusCode());
        KeyFileAssertions::assertNotServed($this->get('/short.txt')->getStatusCode());
    }

    #[TestDox('H06 model created in a request -> nothing sent before the response, POST on terminate')]
    public function testCreatedInRequestIsSubmittedAfterResponse(): void
    {
        $response = $this->post('/articles?slug=hello');

        $response->assertOk();
        self::assertSame(1, $this->collectedDuringRequest, 'the URL waited in the collector while the response was built');
        self::assertSame(0, $this->postsDuringRequest);
        self::assertSame(['https://www.example.com/posts/hello'], $this->sentUrls());
        self::assertSame('www.example.com', $this->transport->posts[0]['body']['host']);
        self::assertSame(self::KEY, $this->transport->posts[0]['body']['key']);
    }

    #[TestDox('A02 request whose transaction throws after the save -> no POST')]
    public function testRolledBackRequestSubmitsNothing(): void
    {
        $this->withoutExceptionHandling();
        try {
            $this->post('/articles/fail');
            self::fail('expected the route to throw');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        $this->app->terminate();

        self::assertSame([], $this->sentUrls());
        self::assertSame(0, Post::query()->count());
    }

    #[TestDox('A04 delete in a request -> the URL resolved before the row disappeared is submitted')]
    public function testDeleteSubmitsUrl(): void
    {
        $this->post('/articles?slug=bye')->assertOk();
        $this->post('/articles/bye/delete')->assertOk();

        self::assertCount(2, $this->transport->posts);
        self::assertSame(['https://www.example.com/posts/bye'], $this->transport->posts[1]['body']['urlList']);
    }
}
