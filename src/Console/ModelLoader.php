<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Console\AbstractSubjectLoader;
use IndexNowKit\Event;

/**
 * Resolves the model argument of `indexnow:submit-model` / `indexnow:explain` (FQCN or a short name under
 * App\Models) and loads models by id; SoftDeletes models are loaded `withTrashed()` for the deleted event. Bind your
 * own `SubjectLoaderInterface` to honour tenant scoping or a different id format. The skeleton is
 * `Console\AbstractSubjectLoader` of `indexnowkit/console`; what is here is Eloquent: the `Model` marker, `find()`,
 * `limit()->get()` and `withTrashed()`.
 */
class ModelLoader extends AbstractSubjectLoader
{
    /**
     * @param list<string> $namespaces namespaces a short class name is looked up in
     */
    public function __construct(array $namespaces = ['App\\Models'])
    {
        parent::__construct($namespaces, Model::class, 'an Eloquent model');
    }

    protected function findOne(string $class, string $id, Event $event): ?object
    {
        $model = self::query($class, $event === Event::Deleted)->find($id);

        return $model instanceof Model ? $model : null;
    }

    protected function findMany(string $class, int $limit, Event $event): iterable
    {
        // @phpstan-ignore staticMethod.dynamicCall (larastan models Query\Builder::limit() as static through @mixin)
        return self::query($class, $event === Event::Deleted)->limit($limit)->get()->all();
    }

    /**
     * @param class-string $class a subclass of Model, as the guard of the parent checked
     *
     * @return Builder<Model>
     */
    private static function query(string $class, bool $withTrashed): Builder
    {
        \assert(is_subclass_of($class, Model::class));
        $query = $class::query();
        if ($withTrashed && method_exists($query, 'withTrashed')) {
            $query = $query->withTrashed();
        }

        return $query;
    }
}
