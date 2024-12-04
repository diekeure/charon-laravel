<?php

namespace CatLab\Charon\Laravel\Controllers;

use CatLab\Base\Helpers\ArrayHelper;
use CatLab\Charon\Collections\FilterCollection;
use CatLab\Charon\Collections\ResourceCollection;
use CatLab\Charon\Enums\Action;
use CatLab\Charon\Factories\ResourceFactory;
use CatLab\Charon\Interfaces\ResourceDefinitionFactory;
use CatLab\Charon\Laravel\Factories\AuthorizedEntityFactory;
use CatLab\Charon\Laravel\Factories\EntityFactory;
use CatLab\Charon\Laravel\Models\ModelFilterResults;
use CatLab\Charon\Laravel\Resolvers\QueryAdapter;
use CatLab\Charon\Models\ResourceDefinition;
use CatLab\Charon\Models\RESTResource;
use CatLab\Charon\Laravel\InputParsers\JsonBodyInputParser;
use CatLab\Charon\Laravel\Models\ResourceResponse;
use CatLab\Charon\Laravel\Resolvers\PropertyResolver;
use CatLab\Charon\Laravel\Resolvers\PropertySetter;
use CatLab\Charon\Laravel\ResourceTransformer;
use CatLab\Charon\Interfaces\SerializableResource;
use CatLab\Charon\Interfaces\Context;
use CatLab\Charon\Interfaces\ResourceDefinition as ResourceDefinitionContract;
use CatLab\Charon\Interfaces\ResourceTransformer as ResourceTransformerContract;
use CatLab\Charon\Models\StaticResourceDefinitionFactory;
use CatLab\Charon\Resolvers\RequestResolver;
use CatLab\Charon\Laravel\Contracts\Response as ResponseContract;
use CatLab\Requirements\Exceptions\ResourceValidationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use CatLab\Laravel\Database\SelectQueryTransformer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Request as RequestFacade;

/**
 * Class ResourceController
 * @package CatLab\RESTResource\Laravel\Controllers
 */
trait ResourceController
{
    /**
     * @var string|ResourceDefinitionContract|\CatLab\Charon\Interfaces\ResourceFactory
     */
    protected $resourceDefinition;

    /**
     * @var ResourceTransformer
     */
    protected $resourceTransformer;

    /**
     * From a query builder, filter models based on input and processor and return the resulting
     * models. Since order is important, the returned Collection will be a plain laravel collection!
     * @param $queryBuilder
     * @param Context $context
     * @param ResourceDefinition|string|null $resourceDefinition
     * @return ResourceCollection
     * @throws \CatLab\Charon\Exceptions\InvalidContextAction
     * @throws \CatLab\Charon\Exceptions\InvalidEntityException
     * @throws \CatLab\Charon\Exceptions\InvalidPropertyException
     * @throws \CatLab\Charon\Exceptions\InvalidTransformer
     * @throws \CatLab\Charon\Exceptions\IterableExpected
     * @throws \CatLab\Charon\Exceptions\NotImplementedException
     * @throws \CatLab\Charon\Exceptions\VariableNotFoundInContext
     * @throws \CatLab\Charon\Exceptions\InvalidResourceDefinition
     */
    public function getResources($queryBuilder, Context $context, $resourceDefinition = null)
    {
        $resourceDefinition = $resourceDefinition ?? $this->resourceDefinition;

        $filters = $this->resourceTransformer->getFilters(
            $this->getRequest()->query(),
            $resourceDefinition,
            $context
        );

        $modelFilterResults = $this->getFilteredModels($queryBuilder, $context, $filters, $resourceDefinition);

        //return $this->resourceTransformer->toResources($resourceDefinition, $models, $context, $filterResults);
        return $this->toResources(
            $modelFilterResults->getModels(),
            $context,
            $resourceDefinition,
            $modelFilterResults->getFilterResults()
        );
    }

    /**
     * From a query builder, filter models based on input and processor and return the resulting models.
     * Since order is important, the returned Collection will be a plain laravel collection!
     * @param $queryBuilder
     * @param Context $context
     * @param FilterCollection $filters
     * @param $resourceDefinition
     * @return ModelFilterResults
     * @throws \CatLab\Charon\Exceptions\InvalidResourceDefinition
     */
    protected function getFilteredModels(
        $queryBuilder,
        Context $context,
        FilterCollection $filters,
        $resourceDefinition = null
    ) {
        $isQueryBuilder =
            $queryBuilder instanceof Builder ||
            $queryBuilder instanceof Relation;

        $factory = null;
        $filterResults = null;

        if ($isQueryBuilder) {
            $filterResults = $this->resourceTransformer->applyFilters(
                $this->getRequest()->query(),
                $filters,
                $context,
                $queryBuilder
            );

            $queryBuilder = $filterResults->getQueryBuilder();

            $this->applyGlobalFilters($queryBuilder, $factory?->getDefault(), $context);
        }

        // Process eager loading
        $this->resourceTransformer->processEagerLoading($queryBuilder, $resourceDefinition, $context);

        if ($isQueryBuilder) {
            $models = $queryBuilder->get();
        } else {
            $models = $queryBuilder;
        }

        if ($filterResults && $filterResults->isReversed()) {
            $models = $models->reverse();
        }

        return new ModelFilterResults($models, $filterResults);
    }

    /**
     * @param ResourceDefinitionContract|string $resourceDefinition
     * @param ResourceTransformerContract $resourceTransformer
     * @return $this
     */
    public function setResourceDefinition($resourceDefinition, $resourceTransformer = null)
    {
        $this->resourceDefinition = $resourceDefinition;

        if (!isset($resourceTransformer)) {
            $this->resourceTransformer = $this->createResourceTransformer();
        }

        return $this;
    }

    /**
     * @return ResourceDefinitionFactory
     */
    public function getResourceDefinitionFactory(): ResourceDefinitionFactory
    {
        return StaticResourceDefinitionFactory::getFactoryOrDefaultFactory($this->resourceDefinition);
    }

    /**
     * @return \CatLab\Charon\Laravel\ResourceTransformer
     */
    public function getResourceTransformer(): ResourceTransformer
    {
        return $this->resourceTransformer;
    }

    /**
     * @param mixed $entity
     * @param Context $context
     * @param null $resourceDefinition
     * @return \CatLab\Charon\Interfaces\RESTResource|RESTResource
     * @throws \CatLab\Charon\Exceptions\InvalidContextAction
     * @throws \CatLab\Charon\Exceptions\InvalidEntityException
     * @throws \CatLab\Charon\Exceptions\InvalidPropertyException
     * @throws \CatLab\Charon\Exceptions\InvalidTransformer
     * @throws \CatLab\Charon\Exceptions\IterableExpected
     * @throws \CatLab\Charon\Exceptions\VariableNotFoundInContext
     * @throws \CatLab\Charon\Exceptions\InvalidResourceDefinition
     */
    public function toResource($entity, Context $context, $resourceDefinition = null) : RESTResource
    {
        return $this->resourceTransformer->toResource(
            $resourceDefinition ?? $this->resourceDefinition,
            $entity,
            $context
        );
    }

    /**
     * @param mixed $entities
     * @param Context $context
     * @param null $resourceDefinition
     * @param null $filterResults
     * @return \CatLab\Charon\Interfaces\ResourceCollection
     * @throws \CatLab\Charon\Exceptions\InvalidContextAction
     * @throws \CatLab\Charon\Exceptions\InvalidEntityException
     * @throws \CatLab\Charon\Exceptions\InvalidPropertyException
     * @throws \CatLab\Charon\Exceptions\InvalidTransformer
     * @throws \CatLab\Charon\Exceptions\IterableExpected
     * @throws \CatLab\Charon\Exceptions\InvalidResourceDefinition
     */
    public function toResources($entities, Context $context, $resourceDefinition = null, $filterResults = null) : ResourceCollection
    {
        return $this->resourceTransformer->toResources(
            $resourceDefinition ?? $this->resourceDefinition,
            $entities,
            $context,
            $filterResults
        );
    }

    /**
     * Transform a resource into (an existing?) entity.
     * @param RESTResource $resource
     * @param Context $context
     * @param mixed|null $existingEntity
     * @param ResourceDefinitionContract|null $resourceDefinition
     * @param \CatLab\Charon\Interfaces\EntityFactory|null $entityFactory
     * @return mixed
     * @throws \CatLab\Charon\Exceptions\InvalidTransformer
     * @throws \CatLab\Charon\Exceptions\InvalidResourceDefinition
     */
    public function toEntity(
        RESTResource $resource,
        Context $context,
        $existingEntity = null,
        $resourceDefinition = null,
        $entityFactory = null
    ) {
        $entityFactory = $entityFactory ?? $this->createEntityFactory();

        return $this->resourceTransformer->toEntity(
            $resource,
            $entityFactory,
            $context,
            $existingEntity
        );
    }

    /**
     * @deprecated Use the ResourceTransformer directly
     * @param Context $context
     * @param null $resourceDefinition
     * @return RESTResource
     * @throws \CatLab\Charon\Exceptions\InvalidContextAction
     * @throws \CatLab\Charon\Exceptions\NoInputDataFound
     * @throws \CatLab\Charon\Exceptions\InvalidResourceDefinition
     */
    public function bodyToResource(Context $context, $resourceDefinition = null) : RESTResource
    {
        $resourceDefinition = $resourceDefinition ?? $this->resourceDefinition;
        return $this->resourceTransformer->fromInput($resourceDefinition, $context, $this->getRequest())
            ->first();
    }

    /**
     * @deprecated Use the ResourceTransformer directly
     * @param Context $context
     * @param null $resourceDefinition
     * @return \CatLab\Charon\Interfaces\ResourceCollection
     * @throws \CatLab\Charon\Exceptions\NoInputDataFound
     * @throws \CatLab\Charon\Exceptions\InvalidResourceDefinition
     */
    public function bodyToResources(Context $context, $resourceDefinition = null) : ResourceCollection
    {
        $resourceDefinition = $resourceDefinition ?? $this->resourceDefinition;
        return $this->resourceTransformer->fromInput($resourceDefinition, $context, $this->getRequest());
    }

    /**
     * @param Context $context
     * @param $resourceDefinition
     * @return array
     * @throws \CatLab\Charon\Exceptions\InvalidContextAction
     * @throws \CatLab\Charon\Exceptions\InvalidResourceDefinition
     */
    public function bodyIdentifiersToEntities(Context $context, $resourceDefinition = null)
    {
        $resourceDefinition = $resourceDefinition ?? $this->resourceDefinition;

        $identifiers = $this->resourceTransformer->identifiersFromInput(
            $resourceDefinition,
            $context
        );

        return $this->resourceTransformer->entitiesFromIdentifiers(
            $resourceDefinition,
            $identifiers,
            $this->createEntityFactory(),
            $context
        );
    }

    /**
     * Apply any global filters that might be implemented by child classes.
     * @param $queryBuilder
     * @param $resourceDefinition
     * @param $context
     */
    protected function applyGlobalFilters(
        $queryBuilder,
        ResourceDefinitionContract $resourceDefinition = null,
        Context $context = null
    ) {

    }

    /**
     * @param string $action
     * @param array $parameters
     * @return Context|string
     */
    protected function getContext($action = Action::VIEW, $parameters = []) : \CatLab\Charon\Interfaces\Context
    {
        $context = new \CatLab\Charon\Models\Context($action, $parameters);

        if ($toShow = \Request::input(ResourceTransformer::FIELDS_PARAMETER)) {
            if (is_string($toShow)) {
                $context->showFields(array_map('trim', explode(',', $toShow)));
            }
        }

        if ($toExpand = \Request::input(ResourceTransformer::EXPAND_PARAMETER)) {
            if (is_string($toExpand)) {
                $context->expandFields(array_map('trim', explode(',', $toExpand)));
            }
        }

        $context->setUrl(\Request::url());
        $this->setInputParsers($context);

        return $context;
    }

    /**
     * Set the input parsers that will be used to turn requests into resources.
     * @param \CatLab\Charon\Models\Context $context
     */
    protected function setInputParsers(\CatLab\Charon\Models\Context $context)
    {
        $context->addInputParser(JsonBodyInputParser::class);
        // $context->addInputParser(PostInputParser::class);
    }

    /**
     * @param int $id
     * @param string $resource
     * @return \Illuminate\Http\JsonResponse
     */
    protected function notFound($id, $resource)
    {
        if ($resource) {
            throw new ModelNotFoundException('Resource ' . $id . ' ' . $resource . ' not found.');
        } else {
            throw new ModelNotFoundException('Resource ' . $id . ' not found.');
        }
    }

    /**
     * Output a resource or a collection of resources
     * @param $models
     * @param array $parameters
     * @return \Illuminate\Http\JsonResponse
     * @throws \CatLab\Charon\Exceptions\InvalidContextAction
     * @throws \CatLab\Charon\Exceptions\InvalidEntityException
     * @throws \CatLab\Charon\Exceptions\InvalidPropertyException
     * @throws \CatLab\Charon\Exceptions\InvalidTransformer
     * @throws \CatLab\Charon\Exceptions\IterableExpected
     * @throws \CatLab\Charon\Exceptions\VariableNotFoundInContext
     * @deprecated Use getModels(), toResource() and toResources()
     *
     */
    protected function output($models, array $parameters = [])
    {
        if (ArrayHelper::isIterable($models)) {
            $context = $this->getContext(Action::INDEX, $parameters);
        } else {
            $context = $this->getContext(Action::VIEW, $parameters);
        }

        $output = $this->modelsToResources($models, $context);
        return $this->toResponse($output);
    }

    /**
     * Take one or multiple models and transform them into one or multiple resources.
     * Notice: When non-model content is found, it is returned "as-is".
     * @param Model|Model[] $models
     * @param Context $context
     * @param ResourceDefinitionContract|null $resourceDefinition
     * @return RESTResource|RESTResource[]|mixed
     * @throws \CatLab\Charon\Exceptions\InvalidContextAction
     * @throws \CatLab\Charon\Exceptions\InvalidEntityException
     * @throws \CatLab\Charon\Exceptions\InvalidPropertyException
     * @throws \CatLab\Charon\Exceptions\InvalidTransformer
     * @throws \CatLab\Charon\Exceptions\IterableExpected
     * @throws \CatLab\Charon\Exceptions\VariableNotFoundInContext
     * @throws \CatLab\Charon\Exceptions\InvalidResourceDefinition
     * @deprecated Use toResources() or toResource().
     */
    protected function modelsToResources($models, Context $context, $resourceDefinition = null)
    {
        if (ArrayHelper::isIterable($models)) {
            return $this->toResources($models, $context, $resourceDefinition);
        } elseif ($models instanceof Model) {
            return $this->toResource($models, $context, $resourceDefinition);
        } else {
            return $models;
        }
    }

    /**
     * @param ResourceValidationException $e
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function getValidationErrorResponse(ResourceValidationException $e)
    {
        return $this->toResponse([
            'error' => [
                'message' => 'Could not decode resource.',
                'issues' => $e->getMessages()->toMap()
            ]
        ])->setStatusCode(400);
    }

    /**
     * @param $data
     * @param Context|null $context
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function toResponse($data, Context $context = null)
    {
        if ($data instanceof SerializableResource) {
            return new ResourceResponse($data, $context);
        } else {
            return new JsonResponse($this->resourceToArray($data));
        }
    }

    /**
     * @param $data
     * @param Context|null $context
     * @return ResourceResponse
     */
    protected function getResourceResponse($data, Context $context  = null): ResponseContract
    {
        return new ResourceResponse($data, $context);
    }

    /**
     * Convert collections or resources to arrays.
     * @param $data
     * @return array|mixed
     */
    protected function resourceToArray($data)
    {
        if ($data instanceof ResourceCollection) {
            return $data->toArray();
        } else if ($data instanceof RESTResource) {
            return $data->toArray();
        } else if (ArrayHelper::isIterable($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->resourceToArray($v);
            }
            return $data;
        }

        return $data;
    }

    /**
     * @return Request
     */
    protected function getRequest()
    {
        if (method_exists(\Illuminate\Http\Request::class, 'instance')) {
            return RequestFacade::instance();
        } else {
            return RequestFacade::getInstance();
        }
    }

    /**
     * Create (and return) a resource transformer.
     * @return ResourceTransformer
     */
    protected function createResourceTransformer()
    {
        return new ResourceTransformer(
            new PropertyResolver(),
            new PropertySetter(),
            new RequestResolver(),
            new QueryAdapter(),
            new ResourceFactory()
        );
    }

    /**
     * @return \CatLab\Charon\Interfaces\EntityFactory
     */
    protected function createEntityFactory()
    {
        return new EntityFactory();
    }

    /**
     * Output a resource or a collection of resources
     *
     * @param $models
     * @param array $parameters
     * @param null $resourceDefinition
     * @return \Illuminate\Http\JsonResponse
     */
    protected function outputList($models, array $parameters = [], $resourceDefinition = null)
    {
        $resources = $this->filteredModelsToResources($models, $parameters, $resourceDefinition);
        return $this->toResponse($resources);
    }


    /**
     * @param $models
     * @param array $parameters
     * @param null $resourceDefinition
     * @return array|\mixed[]
     */
    protected function filteredModelsToResources($models, array $parameters = [], $resourceDefinition = null)
    {
        $resourceDefinition = $resourceDefinition ?? $this->resourceDefinition;
        $context = $this->getContext(Action::INDEX, $parameters);

        return $this->getResources(
            $models,
            $context,
            $resourceDefinition,
        );
    }
}
