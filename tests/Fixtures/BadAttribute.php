<?php

declare(strict_types=1);

namespace IndexNowKit\Laravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Laravel\Eloquent\IndexNowable;

/** #[IndexNow] without route or resolver: reading the rules throws. The save must survive. */
#[IndexNow(events: ['created'])]
final class BadAttribute extends Model
{
    use IndexNowable;

    protected $table = 'bad_attribute';
    protected $guarded = [];
    public $timestamps = false;
}
