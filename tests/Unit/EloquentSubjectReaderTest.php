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
    /** @var array<string, string> */
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

    /** @return string intentionally no native return type: the extractor must not require reflection type info to call it */
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
    private ParamExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ParamExtractor(new EloquentSubjectReader()); // what the provider binds as ParamExtractor::class
    }

    #[TestDox('attributes, casts and accessors are read through getAttribute(); a method with the same name as an attribute does not shadow it')]
    public function testAttributes(): void
    {
        $model = new ReaderModel(['slug' => 'hello', 'published' => 1]);
        $reader = new EloquentSubjectReader();

        self::assertTrue($reader->supports($model));
        self::assertFalse($reader->supports(new stdClass()));
        self::assertTrue($reader->has($model, 'slug'));
        self::assertSame('hello', $this->extractor->read($model, 'slug'));
        self::assertTrue($this->extractor->read($model, 'published'), 'cast applied, and the attribute wins over isPublished()');
        self::assertSame('HELLO', $this->extractor->read($model, 'shout'), 'accessor');
        self::assertFalse($this->extractor->read($model, 'isPublished'), 'a method not backed by an attribute goes to the DSL');
        self::assertSame('plain method', $this->extractor->read($model, 'untyped'));
    }

    #[TestDox('a relation method with a declared Relation return type is read as the related model, not the Relation object')]
    public function testRelations(): void
    {
        $model = new ReaderModel(['slug' => 'child']);
        $model->setRelation('parent', new ReaderModel(['slug' => 'parent']));

        self::assertTrue((new EloquentSubjectReader())->has($model, 'parent'));
        self::assertSame('parent', $this->extractor->read($model, 'parent.slug'));
    }

    #[TestDox('an unknown attribute is a ConfigurationException, not null')]
    public function testUnknown(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->extractor->read(new ReaderModel(['slug' => 'x']), 'missingProperty');
    }

    #[TestDox('a model in a route parameter stays an object (route model binding), although Eloquent models are Stringable')]
    public function testModelStaysObject(): void
    {
        $model = new ReaderModel(['slug' => 'x']);
        $model->setRelation('parent', $parent = new ReaderModel(['slug' => 'p']));
        $params = $this->extractor->extract($model, ['post' => 'self', 'parent' => 'parent', 'slug' => 'slug']);

        self::assertSame($model, $params['post']);
        self::assertSame($parent, $params['parent']);
        self::assertSame('x', $params['slug']);
    }
}
