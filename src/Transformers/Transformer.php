<?php

namespace Flugg\Responder;

use Illuminate\Database\Eloquent\Relations\Pivot;
use League\Fractal\Resource\ResourceAbstract;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\Scope;
use League\Fractal\TransformerAbstract;

/**
 * All transformer classes should extend this abstract base class. This class also
 * extends Fractal's abstract transformer.
 *
 * @package flugger/laravel-responder
 * @author  Alexander Tømmerås <flugged@gmail.com>
 * @license The MIT License
 */
abstract class Transformer extends TransformerAbstract
{
    /**
     * List of all whitelisted relations.
     *
     * @var array
     */
    protected $relations = ['*'];

    /**
     * Cache of resources generated for every relation.
     *
     * @var array
     */
    protected $relationsCache = [];

    /**
     * Get list of whitelisted relations.
     *
     * @return array
     */
    public function getRelations():array
    {
        return array_filter($this->relations, function ($relation) {
            return $relation !== '*';
        });
    }

    /**
     * Check if the transformer has whitelisted all relations.
     *
     * @return bool
     */
    public function allRelationsAllowed():bool
    {
        return $this->relations == ['*'];
    }

    /**
     * This method overrides Fractal's own [callIncludeMethod] method to load relations
     * directly from your models.
     *
     * @param  Scope  $scope
     * @param  string $includeName
     * @param  mixed  $data
     * @return \League\Fractal\Resource\ResourceInterface|bool
     */
    protected function callIncludeMethod(Scope $scope, $includeName, $data)
    {
        if (key_exists($includeName, $this->relationsCache)) {
            return $this->makeResourceFromCache($data->$includeName, $includeName);
        }

        if (method_exists($this, $includeName)) {
            return $this->makeResourceFromMethod($data, $includeName, $scope);
        }

        if ($includeName === 'pivot') {
            return $this->makeResourceFromPivot($data->pivot);
        }

        return $this->makeResource($data->$includeName, $includeName, $scope);
    }

    /**
     * Make a new resource for the relation data based on the cached resource.
     *
     * @param  mixed  $data
     * @param  string $key
     * @return \League\Fractal\Resource\ResourceInterface
     */
    protected function makeResourceFromCache($data, string $key):ResourceInterface
    {
        $resource = $this->relationsCache[$key];
        $className = get_class($resource);

        if (is_null($data)) {
            return $this->null();
        }

        return new $className($data, $resource->getTransformer(), $resource->getResourceKey());
    }

    /**
     * Call the method on the transformer to retrieve a resource.
     *
     * @param  mixed                 $data
     * @param  string                $method
     * @param  \League\Fractal\Scope $scope
     * @return \League\Fractal\Resource\ResourceInterface|bool
     */
    protected function makeResourceFromMethod($data, string $method, Scope $scope):ResourceInterface
    {
        $parameters = $scope->getManager()->getIncludeParams($scope->getIdentifier($method));
        $result = $this->$method($data, $parameters);

        if (is_bool($result) || $result instanceof ResourceAbstract) {
            return $result;
        }

        return $this->makeResource($result, $method, $scope);
    }

    /**
     * Transform a pivot model by trying to call the [transformPivot] method on the
     * transformer. If the method doesn't exist we wont return anything.
     *
     * @param  Pivot $data
     * @return \League\Fractal\Resource\ResourceInterface|bool
     */
    protected function makeResourceFromPivot(Pivot $data)
    {
        if (! method_exists($this, 'transformPivot')) {
            return false;
        }

        return app(TransformationFactory::class)->make($data, function ($pivot) {
            return $this->transformPivot($pivot);
        })->getResource();
    }

    /**
     * Make a resource from the data and store it in the cache.
     *
     * @param  Pivot                 $data
     * @param  string                $relation
     * @param  \League\Fractal\Scope $scope
     * @return \League\Fractal\Resource\ResourceInterface
     */
    protected function makeResource($data, string $relation, Scope $scope)
    {
        $transformation = app(TransformationFactory::class)->make($data);
        $level = count($scope->getParentScopes()) + 1;
        $transformation->setRelations($scope->getManager()->getRequestedIncludes(), $level);

        $resource = $transformation->getResource();

        if ($transformation->getModel()) {
            $this->relationsCache[$relation] = $resource;
        }

        return $resource;
    }
}