<?php

namespace CatLab\Charon\Laravel\Resolvers;

use CatLab\Charon\Collections\PropertyValueCollection;
use CatLab\Charon\Collections\ResourceCollection;
use CatLab\Charon\Enums\Action;
use CatLab\Charon\Exceptions\InvalidPropertyException;
use CatLab\Charon\Exceptions\VariableNotFoundInContext;
use CatLab\Charon\Interfaces\Context;
use CatLab\Charon\Interfaces\ResourceTransformer;
use CatLab\Charon\Laravel\Exceptions\InvalidChildrenCollectionTypeException;
use CatLab\Charon\Models\Identifier;
use CatLab\Charon\Models\Properties\RelationshipField;
use CatLab\Charon\Models\Values\PropertyValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Class PropertyResolver
 *
 * Resolve (get) data from entities.
 *
 * Order of resolvement:
 * 1. check for getFieldNameIdentifier() method (if action === identifier)
 * 2. check for getFieldName() method
 * 3. check for laravel relationship (with support for action === identifier)
 * 4. check for isFieldName() method
 * 5. return stdObject->$fieldName
 *
 * @package CatLab\RESTResource\Laravel\Resolvers
 */
class PropertyResolver extends \CatLab\Charon\Resolvers\PropertyResolver
{
    /**
     * @var ValueResolver
     */
    private $valueResolver;

    /**
     *
     */
    public function __construct()
    {
        $this->valueResolver = new ValueResolver();
    }

    /**
     * @param $entity
     * @param $name
     * @param array $getterParameters
     * @param Context $context
     * @return mixed|null
     * @throws \Exception
     */
    protected function getValueFromEntity($entity, $name, array $getterParameters, Context $context)
    {
        return $this->valueResolver->getValueFromEntity($entity, $name, $getterParameters, $context);
    }

    /**
     * @param ResourceTransformer $transformer
     * @param RelationshipField $field
     * @param mixed $parentEntity
     * @param Identifier $identifier
     * @param Context $context
     * @return mixed
     * @throws InvalidPropertyException
     * @throws VariableNotFoundInContext
     */
    public function getChildByIdentifiers(
        ResourceTransformer $transformer,
        RelationshipField $field,
        $parentEntity,
        Identifier $identifier,
        Context $context
    ) {
        $identifiers = $identifier->getIdentifiers();

        /** @var Builder $entities */
        $entities = $this->resolveProperty($transformer, $parentEntity, $field, $context);
        if ($entities === null) {
            return null;
        }

        foreach ($identifiers->toArray() as $identifier) {
            /** @var PropertyValue $identifier */
            if ($entities instanceof \Illuminate\Support\Collection) {
                $entities = $entities->where(
                    $identifier->getField()->getName(),
                    '=',
                    $identifier->getValue()
                );
                return $entities->first();
            } elseif ($entities instanceof \Illuminate\Contracts\Database\Eloquent\Builder) {
                $entities = $entities->where(
                    $transformer->getQueryAdapter()->getQualifiedName($identifier->getField()),
                    '=',
                    $identifier->getValue()
                );
                return $entities->first();
            } elseif (is_array($entities)) {
                foreach ($entities as $entity) {
                    if ($this->resolveProperty($transformer, $entity, $field, $context) === $identifier->getValue()) {
                        return $entity;
                    }
                }
            } else {
                throw new InvalidChildrenCollectionTypeException(
                    'Unexpected child collection type when trying to resolve  ' . $field->getName() . ': ' . get_class($entities)
                );
            }
        }

        return null;
    }

    /**
     * @param ResourceTransformer $transformer
     * @param mixed $entity
     * @param RelationshipField $field
     * @param Context $context
     * @return ResourceCollection
     * @throws InvalidPropertyException
     * @throws \CatLab\Charon\Exceptions\VariableNotFoundInContext
     */
    public function resolveManyRelationship(
        ResourceTransformer $transformer,
        $entity,
        RelationshipField $field,
        Context $context
    ) {
        $models = $this->resolveProperty($transformer, $entity, $field, $context);

        if ($models instanceof Collection) {
            return $models;
        }

        if ($models instanceof Relation) {
            // Clone to avoid setting multiple filters
            $models = clone $models;

            if ($field->getRecords()) {
                $models->take($field->getRecords());
            }

            // Handle the order
            $orderBys = $field->getOrderBy();
            foreach ($orderBys as $orderBy) {
                $sortField = $field->getChildResourceDefinition()->getFields()->getFromDisplayName($orderBy[0]);
                if ($sortField) {
                    $models->orderBy($transformer->getQualifiedName($sortField, $orderBy[1]));
                }
            }

            return $models;
        }

        return $models;
    }
}
