<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * No trait, no attribute: hooked at runtime through IndexNowKit::observe() (models of third-party packages).
 *
 * @property string $slug
 * @property bool   $published
 */
final class PlainPost extends Model
{
    protected $table = 'posts';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['published' => 'bool'];
}
