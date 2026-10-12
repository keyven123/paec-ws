<?php

namespace Tests\Feature\Coverage\ResourcesDirect;

use Illuminate\Database\Eloquent\Model;

/**
 * Plain attribute bag used as a loaded relation, so resource closures that only read
 * attributes can be exercised without persisting the related model.
 */
class ResourceStub extends Model
{
    protected $guarded = [];

    public static function make(array $attributes = []): self
    {
        return (new self())->forceFill($attributes + [
            'uuid' => 'stub-uuid',
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'full_name' => 'Ana Cruz',
            'email' => 'ana@example.com',
            'name' => 'Stub Name',
            'code' => 'STUB',
            'description' => 'Stub description',
            'path' => 'uploads/stub.png',
            'url' => 'https://cdn.test/stub.png',
            'disk' => 'public',
            'event_name' => 'Stub Event',
            'ticket_number' => 'TKT-0001',
        ]);
    }
}
