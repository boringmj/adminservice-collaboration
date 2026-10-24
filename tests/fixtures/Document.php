<?php

namespace Tests\Fixtures;

use base\Orm\Model;

/**
 * Model with an explicit string primary key.
 */
class Document extends Model {

    protected static string $table='documents';

    protected static string $primaryKey='uuid';

    protected static bool $timestamps=false;

    protected array $fillable=array('uuid','title');

}
