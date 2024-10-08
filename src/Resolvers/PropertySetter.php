<?php

namespace CatLab\Charon\Laravel\Resolvers;

use CatLab\Charon\Collections\PropertyValueCollection;
use CatLab\Charon\Interfaces\Context;
use CatLab\Charon\Interfaces\ResourceTransformer;
use CatLab\Charon\Exceptions\InvalidPropertyException;
use CatLab\Charon\Laravel\Database\Model;
use CatLab\Charon\Laravel\Exceptions\PropertySetterException;
use CatLab\Charon\Models\Identifier;
use CatLab\Charon\Models\Properties\RelationshipField;
use CatLab\Charon\Models\Properties\ResourceField;
use CatLab\Charon\Interfaces\PropertyResolver as PropertyResolverContract;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Class PropertySetter
 * @package CatLab\RESTResource\Laravel\Resolvers
 */
class PropertySetter extends \CatLab\Charon\Resolvers\PropertySetter
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
     * @param ResourceTransformer $entity
     * @param mixed $name
     * @param ResourceField $value
     * @param array $setterParameters
     */
    protected function setChildInEntity($entity, $name, $value, $setterParameters = [])
    {
        // Check for link method name.
        $methodName = 'associate' . ucfirst($name);
        if ($this->methodExists($entity, $methodName)) {
            array_unshift($setterParameters, $value);
            call_user_func_array(array ($entity, $methodName), $setterParameters);
        } elseif (method_exists($entity, $name)) {
            $entity->$name()->associate($value);
        } else {
            $entity->$name = $value;
        }
    }

    /**
     * @param $entity
     * @param $name
     * @param array $setterParameters
     * @throws InvalidPropertyException
     */
    protected function clearChildInEntity($entity, $name, $setterParameters = [])
    {
        // Check for link method name.
        $methodName = 'dissociate' . ucfirst($name);
        if ($this->methodExists($entity, $methodName)) {
            call_user_func_array(array ($entity, $methodName), $setterParameters);
        } elseif (method_exists($entity, $name)) {
            $entity->$name()->dissociate();
        } else {
            $entity->$name = null;
        }
    }

    /**
     * @param mixed $entity
     * @param string $name
     * @param mixed $value
     * @param array $setterParameters
     * @return mixed
     */
    protected function setValueInEntity($entity, $name, $value, $setterParameters = [])
    {
        // Check for set method
        if ($this->methodExists($entity, 'set'.ucfirst($name))) {
            array_unshift($setterParameters, $value);
            return call_user_func_array(array($entity, 'set'.ucfirst($name)), $setterParameters);
        } else {
            $entity->$name = $value;
        }
    }


    /**
     * @param $entity
     * @param $name
     * @param array $childEntities
     * @param array $setterParameters
     * @return mixed|void
     * @throws PropertySetterException
     */
    protected function addChildrenToEntity($entity, $name, array $childEntities, $setterParameters = [])
    {
        if ($this->methodExists($entity, 'add'.ucfirst($name))) {

            array_unshift($setterParameters, $childEntities);
            return call_user_func_array(array($entity, 'add' . ucfirst($name)), $setterParameters);

        } elseif ($entity instanceof Model) {

            $entity->addChildrenToEntity($name, $childEntities, $setterParameters);
            return;

        } else {
            foreach ($childEntities as $childEntity) {
                $relationship = call_user_func([ $entity, $name ]);

                if ($relationship instanceof BelongsToMany) {
                    $relationship->attach($childEntity);
                } else {
                    throw PropertySetterException::makeTranslatable(
                        'Relationship of type %s is not supported yet. Use %s instead.',
                        [
                            get_class($relationship),
                            Model::class
                        ]
                    );
                }
            }
        }
    }

    /**
     * @param $entity
     * @param $name
     * @param array $childEntities
     * @param $parameters
     * @throws InvalidPropertyException
     */
    protected function editChildrenInEntity($entity, $name, array $childEntities, $parameters = [])
    {
        if ($entity instanceof Model) {
            $entity->editChildrenInEntity($name, $childEntities, $parameters);
            return;
        }

        return parent::editChildrenInEntity($entity, $name, $childEntities, $parameters);
    }

    /**
     * @param ResourceTransformer $transformer
     * @param PropertyResolverContract $propertyResolver
     * @param $entity
     * @param RelationshipField $field
     * @param Identifier[] $identifiers
     * @param Context $context
     * @return void
     * @throws InvalidPropertyException
     * @throws \CatLab\Charon\Exceptions\VariableNotFoundInContext
     */
    public function removeAllChildrenExcept(
        ResourceTransformer $transformer,
        PropertyResolverContract $propertyResolver,
        $entity,
        RelationshipField $field,
        array $identifiers,
        Context $context
    ): void {
        list ($entity, $name, $parameters) = $this->resolvePath($transformer, $entity, $field, $context);
        $existingChildren = $this->getValueFromEntity($entity, $name, $parameters, $context);

        // Existing children null? In that case this could be a brand new entry and there are no children to remove.
        if ($existingChildren === null) {
            return;
        }

        if ($existingChildren instanceof Relation) {
            $children = clone $existingChildren;

            if (count($identifiers) > 0) {
                $children->where(function ($builder) use ($identifiers, $context) {
                    foreach ($identifiers as $item) {

                        if (!$item instanceof Identifier) {
                            throw new \LogicException('All provided identifiers to keep must implement ' . Identifier::class);
                        }

                        /** @var Identifier $item */
                        $builder->where(function ($builder) use ($item, $context) {
                            foreach ($item->getIdentifiers()->transformToEntityValuesMap($context) as $k => $v) {
                                $builder->orWhere($k, '!=', $v);
                            }
                        });
                    }
                });
            }

            $toRemove = $children->get();
            if (count($toRemove) > 0) {
                $this->removeChildren($transformer, $entity, $field, $toRemove, $context);
            }

        } else {
            parent::removeAllChildrenExcept(
                $transformer,
                $propertyResolver,
                $entity,
                $field,
                $identifiers,
                $context
            );
        }
    }

    /**
     * @param $entity
     * @param $name
     * @param array $childEntities
     * @param array $parameters
     * @throws PropertySetterException
     */
    protected function removeChildrenFromEntity($entity, $name, $childEntities, $parameters = [])
    {
        // Check for add method
        if ($this->methodExists($entity, 'remove'.ucfirst($name))) {

            array_unshift($parameters, $childEntities);
            call_user_func_array(array($entity, 'remove'.ucfirst($name)), $parameters);

        } elseif ($entity instanceof Model) {

            $entity->removeChildrenFromEntity($name, $childEntities, $parameters);

        } else {

            $relationship = $entity->$name();

            if ($relationship instanceof BelongsToMany) {
                foreach ($childEntities as $childEntity) {
                    $relationship->detach($childEntity);

                    /*
                    if ($entity->relationLoaded($name)) {
                        $entity->setRelation(
                            $name,
                            $entity
                                ->getRelation($name)
                                ->filter(function($value, $key) use ($childEntity) {
                                    return $childEntity->id != $value->id;
                                })
                        );
                    }*/
                }
            } else {
                throw PropertySetterException::makeTranslatable('Relationship of type %s is not supported yet. Use %s instead.', [
                    get_class($relationship),
                    Model::class
                ]);
            }
        }
    }
}
