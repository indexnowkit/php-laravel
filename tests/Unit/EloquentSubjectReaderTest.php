<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Laravel\Eloquent\EloquentSubjectReader;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @property string $slug
 * @property bool   $published
 */
final class ReaderModel extends Model
{
    protected $guarded = [];
    protected $casts = ['published' => 'bool'];

    public function isPublished(): bool
    {
        return !$this->published;
    }

    /** @return BelongsTo<ReaderModel, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    public function untyped()
    {
        return 'plain method';
    }

    public function getShoutAttribute(): string
    {
        return strtoupper($this->slug);
    }
}

final class EloquentSubjectReaderTest extends TestCase
{
    protected function setUp(): void
    {
        ParamExtractor::registerReader(new EloquentSubjectReader());
    }

    protected function tearDown(): void
    {
        ParamExtractor::unregisterReader(EloquentSubjectReader::class);
    }

    #[TestDox('attributes, casts and accessors are read through getAttribute(); a method with the same name as an attribute does not shadow it')]
    public function testAttributes(): void
    {
        $model = new ReaderModel(['slug' => 'hello', 'published' => 1]);
        $reader = new EloquentSubjectReader();

        self::assertTrue($reader->supports($model));
        self::assertFalse($reader->supports(new stdClass()));
        self::assertTrue($reader->has($model, 'slug'));
        self::assertSame('hello', ParamExtractor::read($model, 'slug'));
        self::assertTrue(ParamExtractor::read($model, 'published'), 'cast applied, and the attribute wins over isPublished()');
        self::assertSame('HELLO', ParamExtractor::read($model, 'shout'), 'accessor');
        self::assertFalse(ParamExtractor::read($model, 'isPublished'), 'a method not backed by an attribute goes to the DSL');
        self::assertSame('plain method', ParamExtractor::read($model, 'untyped'));
    }

    #[TestDox('a relation method with a declared Relation return type is read as the related model, not the Relation object')]
    public function testRelations(): void
    {
        $model = new ReaderModel(['slug' => 'child']);
        $model->setRelation('parent', new ReaderModel(['slug' => 'parent']));

        self::assertTrue((new EloquentSubjectReader())->has($model, 'parent'));
        self::assertSame('parent', ParamExtractor::read($model, 'parent.slug'));
    }

    #[TestDox('an unknown attribute is a ConfigurationException, not null')]
    public function testUnknown(): void
    {
        $this->expectException(ConfigurationException::class);
        ParamExtractor::read(new ReaderModel(['slug' => 'x']), 'missingProperty');
    }

    #[TestDox('a model in a route parameter stays an object (route model binding), although Eloquent models are Stringable')]
    public function testModelStaysObject(): void
    {
        $model = new ReaderModel(['slug' => 'x']);
        $model->setRelation('parent', $parent = new ReaderModel(['slug' => 'p']));
        $params = ParamExtractor::extract($model, ['post' => 'self', 'parent' => 'parent', 'slug' => 'slug']);

        self::assertSame($model, $params['post']);
        self::assertSame($parent, $params['parent']);
        self::assertSame('x', $params['slug']);
    }
}
