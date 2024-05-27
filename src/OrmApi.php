<?php

namespace QuicklistsOrmApi;

use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use Illuminate\Support\Facades\DB;
use Exception;

class OrmApi
{

    public static function inferSpatieCodes(Model $model, $depth = 0, $maxDepth = 10, $visited = [])
    {
        $modelKey = get_class($model); // Unique identifier for the model class

        // Check if this model has already been visited
        if (in_array($modelKey, $visited)) {
            return [
                "allFields" => [],
                "relations" => [],
                "searchable_fields" => []
            ];
        }

        // Add this model to the visited list
        $visited[] = $modelKey;


        $allFields = [...$model->getFillable()];
        $relations = [];

        // Limit the recursion depth to prevent infinite loops
        if ($depth < $maxDepth) {
            if (method_exists($model, 'relationships') && is_array($model->relationships())) {
                foreach ($model->relationships() as $relationship) {
                    if (method_exists($model, $relationship)) {
                        $relations[] = $relationship;

                        $relationModel = $model->$relationship()->getRelated();
                        $relatedFieldNames = $relationModel->getFillable();

                        foreach ($relatedFieldNames as $attribute) {
                            $allFields[] = "{$relationship}.{$attribute}";
                        }

                        // Recursively gather nested relationships and rules
                        $nestedResult = self::inferSpatieCodes($relationModel, $depth + 1, $maxDepth, $visited);
                        foreach ($nestedResult['relations'] as $nestedRelation) {
                            $relations[] = "{$relationship}.{$nestedRelation}";
                        }
                    }
                }
            }
        }

        $valid_searchable_fields = [];
        if (method_exists($model, 'searchable_fields') && is_array($model->searchable_fields())) {
            $valid_searchable_fields = $model->searchable_fields();
        }

        $result = [
            "allFields" => $allFields,
            "relations" => $relations,
            "searchable_fields" => $valid_searchable_fields,
        ];

        return $result;
    }

    public static function fetchAllWithFullQueryExposure(Model $model, $request, $entityName = 'Item')
    {
        $inferSpatieCodes = self::inferSpatieCodes($model);

        $result = QueryBuilder::for(get_class($model))
            ->allowedIncludes($inferSpatieCodes["relations"])
            ->allowedFilters($inferSpatieCodes["allFields"])
            ->allowedSorts($inferSpatieCodes["allFields"]);

        // Handle search
        if (isset($request->search) && $inferSpatieCodes["searchable_fields"]) {
            $result = $result->whereFullText(
                $inferSpatieCodes["searchable_fields"],
                $request->search
            );
        }

        // Handle pagination
        $perPage = $request->input('per_page', 1000); // Default to 1000 records per page
        $result = $result->paginate($perPage);

        $response = array_merge(
            ['message' => $entityName . " list retrieved successfully!"],
            $result->toArray()
        );

        return [
            "res" => $response,
            "code" => 200,
        ];
    }



    public static function fetchByIdWithFullQueryExposure(Model $model, $id, $entityName = 'Item')
    {

        $inferSpatieCodes = self::inferSpatieCodes($model);

        $result = QueryBuilder::for(get_class($model))
            ->allowedIncludes($inferSpatieCodes["relations"])
            ->allowedFilters($inferSpatieCodes["allFields"])
            ->find($id);

        return [
            "res" => [
                'message' => $entityName . " retrieved successfully!",
                'item' => $result,
            ],
            "code" => 200,
        ];
    }

    public static function inferValidation(Model $model, $depth = 0, $maxDepth = 10, $visited = [], $exceptionToRule = null)
    {
        $modelKey = get_class($model); // Unique identifier for the model class

        // Check if this model has already been visited
        if (in_array($modelKey, $visited)) {
            return [
                "validationRules" => [],
            ];
        }

        // Add this model to the visited list
        $visited[] = $modelKey;

        $validationRules = [];

        // Gather the model's own validation rules if they exist
        if (method_exists($model, 'rules')) {
            foreach ($model->rules() as $field => $rule) {
                if ($field !== $exceptionToRule) {
                    $validationRules[$field] = $rule;
                }
            }
        }

        // Limit the recursion depth to prevent infinite loops
        if ($depth < $maxDepth) {
            if (method_exists($model, 'relationships') && is_array($model->relationships())) {
                foreach ($model->relationships() as $relationship) {
                    if (method_exists($model, $relationship)) {

                        $refMethod = new ReflectionMethod(get_class($model), $relationship);

                        $relationshipInvoked = $refMethod->invoke($model);

                        if ($relationshipInvoked instanceof Relation) {

                            $relationType = class_basename(get_class($model->$relationship()));

                            if ($relationType == "BelongsToMany") {

                            } else if ($relationType == "HasMany") {
                                $exceptionToRule = $model->$relationship()->getForeignKeyName();
                            }

                        }

                        $relationModel = $model->$relationship()->getRelated();

                        $nestedResult = self::inferValidation($relationModel, $depth + 1, $maxDepth, $visited, $exceptionToRule);

                        foreach ($nestedResult['validationRules'] as $field => $rule) {
                            $validationRules["{$relationship}.*.{$field}"] = $rule;
                        }
                    }
                }
            }
        }

        $result = [
            "validationRules" => $validationRules,
        ];

        return $result;
    }

    public static function createItemWithOptionalBulkRelations($request, $model, $entityName = 'Item')
    {
        try {

            DB::transaction(function () use ($request, $model, $entityName, &$resultData, &$modelItem) {

                // Retrieve the high-level abstractor helper data
                $inferValidation = self::inferValidation($model);

                //echo json_encode($inferValidation, JSON_PRETTY_PRINT);
                //die;

                // Extract validation rules, fields, and relations
                $validationRules = $inferValidation['validationRules'];
                $fields = $model->getFillable();

                if (method_exists($model, 'rules')) {
                    $data = $request->validate($validationRules);
                } else {
                    $data = $request->all();
                }

                $modelItem = $model->create(array_intersect_key($data, array_flip($fields)));


                $children = self::recursiveCreateOrAttached($model, $request->all(), $modelItem);


                $resultData = [
                    ...$modelItem->getAttributes(),
                    ...$children
                ];

                //dd($resultData);


            });

            return [
                "modelItem" => $modelItem,
                "res" => [
                    'message' => $entityName . " created successfully!",
                    'item' => $resultData,
                ],
                "code" => 200,
            ];


        } catch (Exception $exception) {
            if ($exception instanceof \Illuminate\Validation\ValidationException) {
                return [
                    "res" => [
                        'message' => 'Validation Error',
                        'errors' => $exception->errors(),
                    ],
                    "code" => 422,
                ];

            } else {

                return [
                    "res" => [
                        'message' => 'An unexpected error occurred. Please try again later.',
                        'exception_class' => get_class($exception),
                        'exception_message' => $exception->getMessage(),
                    ],
                    "code" => 500,
                ];
            }
        }
    }


    public static function recursiveCreateOrAttached($model, $itemData, $createdParent)
    {
        $result = [];

        $relationships = [];

        if (method_exists($model, 'relationships') && is_array($model->relationships())) {
            $relationships = $model->relationships();
        }

        foreach ($relationships as $relationshipName) {

            if (isset($itemData[$relationshipName])) {

                $relationItems = $itemData[$relationshipName];

                $relationType = self::getRelationType($model, $relationshipName);

                if ($relationType == "BelongsToMany") {

                    foreach ($relationItems as $relationItem) {

                        if (isset($relationItem['id']) && $relationItem["id"] != 0) {
                            $createdParent->$relationshipName()
                                ->attach($relationItem['id']);
                            $result[$relationshipName][] = $createdParent->$relationshipName()->find($relationItem['id']);
                        } else {
                            $createdChild = $createdParent->$relationshipName()
                                ->create($relationItem);


                            $relatedModel = $model->$relationshipName()->getRelated();


                            $children = self::recursiveCreateOrAttached($relatedModel, $relationItem, $createdChild);


                            $result[$relationshipName][] = [
                                ...$createdChild->getAttributes(),
                                ...$children
                            ];
                        }

                    }
                } else if ($relationType == "HasMany") {

                    $result[$relationshipName] = $createdParent->$relationshipName()->createMany($relationItems);

                } else {


                    //dd([$relationType,$relationship]);
                }
            }

        }
        return $result;
    }

    public static function getRelationType($model, $relationshipName)
    {
        $result = "";

        if (method_exists($model, $relationshipName)) {

            $refMethod = new ReflectionMethod(get_class($model), $relationshipName);

            $relationshipInvoked = $refMethod->invoke($model);

            if ($relationshipInvoked instanceof Relation) {

                $relationType = class_basename(get_class($model->$relationshipName()));


                $result = $relationType;

            }
        }
        return $result;
    }

    public static function deleteItem($model, $id, $entityName)
    {
        $item = $model::find($id);

        if (!$item) {
            return [
                "res" => [
                    'message' => $entityName . ' not found'
                ],
                "code" => 422,
            ];
        }

        $item->delete();

        return [
            "res" => [
                'message' => $entityName . ' deleted successfully'
            ],
            "code" => 200,
        ];
    }

    public static function updateItem($request, $model, $id, $entityName = 'Item')
    {

        try {

            $item = $model::findOrFail($id);

            $data = $request->all();

            $item->update($data);
            $item->refresh();

        } catch (ValidationException $e) {
            return [
                "res" => [
                    'message' => "Error",
                    'errors' => $e->errors()
                ],
                "code" => 422,
            ];
        }

        return [
            "modelItem" => $item,
            "res" => [
                'message' => $entityName . " updated successfully!",
                'item' => $item->getAttributes(),
            ],
            "code" => 200,
        ];
    }

}
