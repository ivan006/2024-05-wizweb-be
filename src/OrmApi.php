<?php

namespace QuicklistsOrmApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;
use ReflectionMethod;


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

        // Get the primary key of the model
        $primaryKey = $model->getKeyName();

        // Include the primary key in the allowed sorts
        $allowedSorts = array_merge($inferSpatieCodes["allFields"], [$primaryKey]);

        // Get listable conditions from the model
        $listableConditions = $model->listable();

        $result = QueryBuilder::for(get_class($model))
            ->allowedIncludes($inferSpatieCodes["relations"])
            ->allowedFilters($inferSpatieCodes["allFields"])
            ->allowedSorts($allowedSorts) // Add allowed sorts including primary key
            ->where($listableConditions); // Apply listable conditions

        // Handle search
        if (isset($request->search) && $inferSpatieCodes["searchable_fields"]) {
            $result = $result->whereFullText(
                $inferSpatieCodes["searchable_fields"],
                $request->search
            );
        }

        // Handle pagination
        $perPage = $request->input('per_page', 1500); // Default to 1500 records per page
        $page = $request->input('page', 1); // Default to the first page
        $result = $result->paginate($perPage, ['*'], 'page', $page);

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

        // Check if the current user can read this record
        if (!$model->readable($result)) {
            return [
                "res" => [
                    'message' => 'Unauthorized',
                ],
                "code" => 403,
            ];
        }

        return [
            "res" => [
                'message' => $entityName . " retrieved successfully!",
                'data' => $result,
            ],
            "code" => 200,
        ];
    }

    public static function beforeCreate($data, $model, $request)
    {
        if (!$model->creatable($data)) {
            throw ValidationException::withMessages(['Unauthorized' => 'Unauthorized to create item'])->status(403);
        }

        $extraInfo = $model->fieldExtraInfo();
        $subfolder = $request->query('subfolder', 'uploads'); // Default to 'uploads' if no subfolder is specified

        // Ensure the subfolder exists
        if (!Storage::disk('public')->exists($subfolder)) {
            Storage::disk('public')->makeDirectory($subfolder);
        }

        foreach ($extraInfo as $field => $info) {
            if (isset($info['ontologyType']) && $info['ontologyType'] === 'file' && isset($data[$field])) {
                if (is_a($data[$field], 'Illuminate\Http\UploadedFile')) {
                    // Handle single file upload
                    $data[$field] = $data[$field]->store($subfolder, 'public');
                }
            }
        }
        return $data;
    }



    public static function beforeUpdate($data, $modelItem)
    {
        if (!$modelItem->updatable($data, $modelItem)) {
            throw ValidationException::withMessages(['Unauthorized' => 'Unauthorized to update item'])->status(403);
        }

        $extraInfo = $modelItem->fieldExtraInfo();
        foreach ($extraInfo as $field => $info) {
            if (isset($info['ontologyType']) && $info['ontologyType'] === 'file') {
                unset($data[$field]);
            }
        }
        return $data;
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
                $inferValidation = self::inferValidation($model);
                $validationRules = $inferValidation['validationRules'];
                $fields = $model->getFillable();

                if (method_exists($model, 'rules')) {
                    $data = $request->validate($validationRules);
                } else {
                    $data = $request->all();
                }

                $data = self::beforeCreate($data, $model, $request);

                $modelItem = $model->create(array_intersect_key($data, array_flip($fields)));
                $children = self::recursiveCreateOrAttach($model, $request->all(), $modelItem, $request);

                $resultData = [
                    ...$modelItem->getAttributes(),
                    ...$children
                ];
            });

            return [
                "res" => [
                    'message' => $entityName . " created successfully!",
                    'data' => $resultData,
                ],
                "code" => 200,
            ];
        } catch (ValidationException $exception) {
            return [
                "res" => [
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ],
                "code" => $exception->status,
            ];
        } catch (Exception $exception) {
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



    public static function recursiveCreateOrAttach($model, $itemData, $createdParent, $request)
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
                $relatedModel = $model->$relationshipName()->getRelated();
                $pKey = $relatedModel->getKeyName();

                foreach ($relationItems as $relationItem) {
                    $relationItem = self::beforeCreate($relationItem, $relatedModel, $request);

                    if ($relationType == "BelongsToMany") {
                        if (isset($relationItem[$pKey]) && $relationItem[$pKey] != 0) {
                            $createdParent->$relationshipName()->attach($relationItem[$pKey]);
                            $result[$relationshipName][] = $createdParent->$relationshipName()->find($relationItem[$pKey]);
                        } else {
                            $createdChild = $createdParent->$relationshipName()->create($relationItem);
                            $children = self::recursiveCreateOrAttach($relatedModel, $relationItem, $createdChild, $request);
                            $result[$relationshipName][] = [
                                ...$createdChild->getAttributes(),
                                ...$children
                            ];
                        }
                    } elseif ($relationType == "HasMany") {
                        if (isset($relationItem[$pKey]) && $relationItem[$pKey] != 0) {
                            $createdChild = $createdParent->$relationshipName()->find($relationItem[$pKey]);
                            $createdChild->update($relationItem);
                        } else {
                            $createdChild = $createdParent->$relationshipName()->create($relationItem);
                        }
                        $children = self::recursiveCreateOrAttach($relatedModel, $relationItem, $createdChild, $request);
                        $result[$relationshipName][] = [
                            ...$createdChild->getAttributes(),
                            ...$children
                        ];
                    } elseif ($relationType == "BelongsTo") {
                        $createdParent->$relationshipName()->associate($relatedModel->find($relationItem[$pKey]));
                        $createdParent->save();
                    }
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



    public static function deleteItem($model, $id, $entityName, $request)
    {
        try {
            DB::transaction(function () use ($model, $id, $entityName, $request, &$response, &$code) {
                $item = $model::find($id);

                if (!$item) {
                    $response = [
                        'message' => $entityName . ' not found'
                    ];
                    $code = 422;
                    return;
                }

                if (!$model->deletable($item)) {
                    $response = [
                        'message' => 'Unauthorized',
                    ];
                    $code = 403;
                    return;
                }

                $parentsToDelete = $request->input('parentsToDelete', []);
                $relationships = [];

                if (method_exists($model, 'relationships') && is_array($model->relationships())) {
                    $relationships = $model->relationships();
                }
                foreach ($relationships as $relationshipName) {
                    $relationType = self::getRelationType($model, $relationshipName);
                    if ($relationType == "BelongsTo" && in_array($relationshipName, $parentsToDelete)) {
                        $parent = $item->$relationshipName;
                        if ($parent) {
                            $parent->delete();
                        }
                    }
                }

                $extraInfo = $item->fieldExtraInfo();
                foreach ($extraInfo as $field => $info) {
                    if (isset($info['ontologyType']) && $info['ontologyType'] === 'file') {
                        if ($item->$field) {
                            Storage::disk('public')->delete($item->$field);
                        }
                    }
                }

                $item->delete();

                $response = [
                    'message' => $entityName . ' deleted successfully'
                ];
                $code = 200;
            });

            return [
                'res' => $response,
                'code' => $code,
            ];
        } catch (Exception $exception) {
            return [
                'res' => [
                    'message' => 'An unexpected error occurred. Please try again later.',
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                ],
                'code' => 500,
            ];
        }
    }

    public static function updateItem($request, $model, $id, $entityName = 'Item')
    {
        try {
            $itemId = $id;
            $modelItem = $model->find($itemId);

            DB::transaction(function () use ($request, $model, $modelItem, $entityName, &$resultData) {
                $fields = $model->getFillable();


                if (str_contains($request->header('Content-Type'), 'multipart/form-data')) {
                    //$data = self::parseMultipartFormDataForPatchRequest($request);
                    $data = self::parseMultipartFormDataForPatchRequest($request);
                } else {
                    $data = $request->all();
                }

                //Log::info('2024-13-06--12-53', ['$payload' =>  $data,"request"=>$request,]);

                $data = self::beforeUpdate($data, $modelItem);

                $modelItem->update(array_intersect_key($data, array_flip($fields)));

                $children = self::recursiveUpdateOrAttachManyToManyRels($model, $data, $modelItem, $request);

                $resultData = [
                    ...$modelItem->getAttributes(),
                    ...$children
                ];
            });

            return [
                "res" => [
                    'message' => $entityName . " updated successfully!",
                    'data' => $resultData,
                ],
                "code" => 200,
            ];
        } catch (ValidationException $exception) {
            return [
                "res" => [
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ],
                "code" => $exception->status,
            ];
        } catch (Exception $exception) {
            return [
                "res" => [
                    'message' => 'An unexpected error occurred. Please try again later.',
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTrace(),
                ],
                "code" => 500,
            ];
        }
    }



    public static function parseMultipartFormDataForPatchRequest($request)
    {
        $data = [];
        $input = $request->getContent();
        $boundary = substr($input, 0, strpos($input, "\r\n"));

        if (empty($boundary)) {
            return $data;
        }

        $parts = array_slice(explode($boundary, $input), 1);

        foreach ($parts as $part) {
            if ($part == "--\r\n") break;

            $part = trim($part);
            if (empty($part)) continue;

            if (strpos($part, "\r\n\r\n") !== false) {
                list($rawHeaders, $content) = explode("\r\n\r\n", $part, 2);
                $rawHeaders = explode("\r\n", $rawHeaders);
                $headers = [];

                foreach ($rawHeaders as $header) {
                    list($name, $value) = explode(':', $header);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                if (isset($headers['content-disposition'])) {
                    if (preg_match('/name="(?<name>[^"]+)"(; filename="(?<filename>[^"]+)")?/', $headers['content-disposition'], $matches)) {
                        $name = $matches['name'];
                        if (isset($matches['filename'])) {
                            $filename = $matches['filename'];
                            $tmpName = tempnam(sys_get_temp_dir(), 'upl');
                            file_put_contents($tmpName, $content);
                            self::assignNestedArrayValue($data, $name, new \Illuminate\Http\UploadedFile($tmpName, $filename, null, null, true));
                        } else {
                            self::assignNestedArrayValue($data, $name, $content);
                        }
                    }
                }
            }
        }

        return $data;
    }


    public static function assignNestedArrayValue(&$array, $path, $value)
    {
        $keys = preg_split('/[\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            } elseif (!is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }

        $current = $value;
    }


    public static function recursiveUpdateOrAttachManyToManyRels($model, $itemData, $updatedParent, $request)
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
                $relatedModel = $model->$relationshipName()->getRelated();
                $pKey = $relatedModel->getKeyName();

                if ($relationType == "BelongsToMany" || $relationType == "HasMany") {
                    foreach ($relationItems as $relationItemPayload) {
                        if (isset($relationItemPayload[$pKey]) && !empty($relationItemPayload[$pKey]) && $relationItemPayload[$pKey] != 0) {



                            $existingItem = $relatedModel->find($relationItemPayload[$pKey]);

                            if ($existingItem) {
                                $relationItemPayload = self::beforeUpdate($relationItemPayload, $existingItem);

                                if ($relationType == "BelongsToMany") {
                                    $existingItem->update($relationItemPayload);

                                    $existingRelation = $updatedParent->$relationshipName()
                                        ->where($pKey, $relationItemPayload[$pKey])
                                        ->exists();
                                    if (!$existingRelation) {
                                        $updatedParent->$relationshipName()->syncWithoutDetaching([$relationItemPayload[$pKey]]);
                                    }
                                } elseif ($relationType == "HasMany") {
                                    $existingItem->update($relationItemPayload);
                                }

                                $children = self::recursiveUpdateOrAttachManyToManyRels($relatedModel, $relationItemPayload, $existingItem, $request);
                                $result[$relationshipName][] = [
                                    ...$existingItem->getAttributes(),
                                    ...$children
                                ];
                            }



                        } else {



                            $relationItemPayload = self::beforeCreate($relationItemPayload, $relatedModel, $request);

                            if ($relationType == "BelongsToMany") {
                                $createdChild = $updatedParent->$relationshipName()->create($relationItemPayload);
                                $updatedParent->$relationshipName()->syncWithoutDetaching([$createdChild->id]);

                                $children = self::recursiveUpdateOrAttachManyToManyRels($relatedModel, $relationItemPayload, $createdChild, $request);
                                $result[$relationshipName][] = [
                                    ...$createdChild->getAttributes(),
                                    ...$children
                                ];
                            } elseif ($relationType == "HasMany") {
                                $createdChild = $updatedParent->$relationshipName()->create($relationItemPayload);

                                $children = self::recursiveUpdateOrAttachManyToManyRels($relatedModel, $relationItemPayload, $createdChild, $request);
                                $result[$relationshipName][] = [
                                    ...$createdChild->getAttributes(),
                                    ...$children
                                ];
                            }



                        }
                    }
                } elseif ($relationType == "BelongsTo") {

                    $relationItemPayload = $itemData[$relationshipName];

                    if (isset($relationItemPayload[$pKey]) && !empty($relationItemPayload[$pKey]) && $relationItemPayload[$pKey] != 0) {


                        $existingItem = $relatedModel->find($relationItemPayload[$pKey]);

                        if ($existingItem) {
                            $relationItemPayload = self::beforeUpdate($relationItemPayload, $existingItem);
                            $existingItem->update($relationItemPayload);
                            $updatedParent->$relationshipName()->associate($existingItem);
                            $updatedParent->save();
                            $result[$relationshipName] = $existingItem->getAttributes();
                        }


                    } else {


                        $relationItemPayload = self::beforeCreate($relationItemPayload, $relatedModel, $request);
                        $createdParent = $relatedModel->create($relationItemPayload);
                        $updatedParent->$relationshipName()->associate($createdParent);
                        $updatedParent->save();
                        $result[$relationshipName] = $createdParent->getAttributes();


                    }
                }
            }
        }

        return $result;
    }







}
