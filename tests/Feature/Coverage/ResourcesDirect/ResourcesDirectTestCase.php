<?php

namespace Tests\Feature\Coverage\ResourcesDirect;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Tests\TestCase;

abstract class ResourcesDirectTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $relations
     */
    protected function inMemory(string $class, array $attributes = [], array $relations = []): Model
    {
        $model = (new $class())->forceFill($attributes + ['uuid' => 'model-uuid']);

        foreach ($relations as $name => $value) {
            $model->setRelation($name, $value);
        }

        return $model;
    }

    /**
     * Resolves and JSON-encodes the resource so nested resources are serialised and
     * MissingValue entries dropped, without the response's "data" wrapping rules.
     *
     * @return array<string, mixed>
     */
    protected function render(JsonResource $resource, ?Request $request = null): array
    {
        return json_decode(json_encode($resource->resolve($request ?? Request::create('/'))), true);
    }
}
