<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Console\ClassNameResolver;
use IndexNowKit\Console\SubjectLoaderInterface;
use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;

/**
 * Resolves the model argument of `indexnow:submit-model` / `indexnow:explain` (FQCN or a short name under
 * App\Models) and loads models by id; SoftDeletes models are loaded `withTrashed()` for the deleted event. Bind your
 * own `SubjectLoaderInterface` to honour tenant scoping or a different id format.
 */
class ModelLoader implements SubjectLoaderInterface
{
    private readonly ClassNameResolver $classes;

    /**
     * @param list<string> $namespaces namespaces a short class name is looked up in
     */
    public function __construct(array $namespaces = ['App\\Models'])
    {
        $this->classes = new ClassNameResolver($namespaces, static fn(string $class): bool => is_subclass_of($class, Model::class), 'an Eloquent model');
    }

    /**
     * @return class-string<Model>
     */
    public function resolveClass(string $class): string
    {
        return self::modelClass($this->classes->resolve($class));
    }

    /**
     * @param class-string $class
     * @param list<string> $ids
     *
     * @return array{0: list<Model>, 1: list<string>} found models and missing ids
     */
    public function byIds(string $class, array $ids, Event $event): array
    {
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $model = $this->query(self::modelClass($class), $event === Event::Deleted)->find($id);
            if ($model instanceof Model) {
                $found[] = $model;
            } else {
                $missing[] = $id;
            }
        }

        return [$found, $missing];
    }

    /**
     * @param class-string $class
     *
     * @return iterable<Model>
     */
    public function all(string $class, int $limit, Event $event): iterable
    {
        // @phpstan-ignore staticMethod.dynamicCall (larastan models Query\Builder::limit() as static through @mixin)
        return $this->query(self::modelClass($class), $event === Event::Deleted)->limit(max(1, $limit))->get()->all();
    }

    /**
     * @param class-string $class
     *
     * @return class-string<Model>
     */
    private static function modelClass(string $class): string
    {
        if (!is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not an Eloquent model.', $class));
        }

        return $class;
    }

    /**
     * @param class-string<Model> $class
     *
     * @return Builder<Model>
     */
    private function query(string $class, bool $withTrashed): Builder
    {
        $query = $class::query();
        if ($withTrashed && method_exists($query, 'withTrashed')) {
            $query = $query->withTrashed();
        }

        return $query;
    }
}
