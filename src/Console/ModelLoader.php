<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Console;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Exception\InvalidArgumentException;

/**
 * Resolves the model argument of `indexnow:submit-model` / `indexnow:explain` (FQCN or a short name under
 * App\Models) and loads models by id. Bind your own instance to honour tenant scoping or a different id format.
 */
class ModelLoader
{
    /**
     * @return class-string<Model>
     *
     * @throws InvalidArgumentException when the class is unknown or not an Eloquent model
     */
    public function resolveClass(string $class): string
    {
        $candidate = ltrim($class, '\\');
        if (!class_exists($candidate) && class_exists('App\\Models\\' . $candidate)) {
            $candidate = 'App\\Models\\' . $candidate;
        }
        if (!class_exists($candidate)) {
            throw new InvalidArgumentException(\sprintf('Class "%s" not found.', $class));
        }
        if (!is_subclass_of($candidate, Model::class)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not an Eloquent model.', $candidate));
        }

        return $candidate;
    }

    /**
     * @param class-string<Model> $class
     * @param list<string>        $ids
     *
     * @return array{0: list<Model>, 1: list<string>} found models and missing ids
     */
    public function byIds(string $class, array $ids, bool $withTrashed = false): array
    {
        $found = [];
        $missing = [];
        foreach ($ids as $id) {
            $model = $this->query($class, $withTrashed)->find($id);
            if ($model instanceof Model) {
                $found[] = $model;
            } else {
                $missing[] = $id;
            }
        }

        return [$found, $missing];
    }

    /**
     * @param class-string<Model> $class
     *
     * @return iterable<Model>
     */
    public function all(string $class, int $limit, bool $withTrashed = false): iterable
    {
        return $this->query($class, $withTrashed)->limit(max(1, $limit))->get()->all();
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
