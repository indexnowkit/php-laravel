<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use IndexNowKit\Attribute\SubjectReaderInterface;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Reads #[IndexNow] accessors off Eloquent models, whose attributes are not PHP properties. Claims an accessor when
 * it is an attribute, a cast, a mutator/accessor, a loaded relation, or a relation method with a declared `Relation`
 * return type; everything else (a helper such as `isPublished()`, a real property) stays with the core DSL, so a typo
 * still raises the core's "no property, getter or method" error instead of Eloquent's silent null.
 */
final class EloquentSubjectReader implements SubjectReaderInterface
{
    /** @var array<string, bool> "class::method" => declares a Relation return type */
    private array $relationMethods = [];

    public function supports(object $subject): bool
    {
        return $subject instanceof Model;
    }

    public function has(object $subject, string $accessor): bool
    {
        if (!$subject instanceof Model) {
            return false;
        }

        return self::hasAttribute($subject, $accessor) || $subject->relationLoaded($accessor) || $this->isRelationMethod($subject, $accessor);
    }

    public function read(object $subject, string $accessor): mixed
    {
        \assert($subject instanceof Model);

        return $subject->getAttribute($accessor);
    }

    private static function hasAttribute(Model $model, string $key): bool
    {
        return \array_key_exists($key, $model->getAttributes()) || $model->hasCast($key) || $model->hasGetMutator($key) || $model->hasAttributeMutator($key);
    }

    private function isRelationMethod(Model $model, string $method): bool
    {
        if (!method_exists($model, $method)) {
            return false;
        }
        $id = $model::class . '::' . $method;
        if (!isset($this->relationMethods[$id])) {
            $type = (new ReflectionMethod($model, $method))->getReturnType();
            $this->relationMethods[$id] = $type instanceof ReflectionNamedType && !$type->isBuiltin() && is_a($type->getName(), Relation::class, true);
        }

        return $this->relationMethods[$id];
    }
}
