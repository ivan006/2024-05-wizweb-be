<?php
//HighLevelEloquentAbstractor
namespace WizwebBe;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;
use ReflectionMethod;
use WizwebBe\ComparisonFilter;


class OrmApi
{
    //Log::info('2024-13-06--12-53', ['$payload' =>  $data,"request"=>$request,]);

    public static function inferSpatieCodes(Model $model, $depth = 0, $maxDepth = 5, $visited = [])
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

        //$allFields = [...$model->getFillable()];
        $allFields = array_merge($model->getFillable(), [$model->getKeyName()]);

        $relations = [];

        // Relationships types to check
        $relationshipTypes = ['parentRelationships', 'spouseRelationships', 'childRelationships'];

        // Limit the recursion depth to prevent infinite loops
        if ($depth < $maxDepth) {
            foreach ($relationshipTypes as $relationshipType) {
                if (method_exists($model, $relationshipType) && is_array($model->$relationshipType())) {
                    foreach ($model->$relationshipType() as $relationship => $config) {
                        if (method_exists($model, $relationship)) {
                            $relations[] = $relationship;

                            $relationModel = $model->$relationship()->getRelated();
                            //$relatedFieldNames = $relationModel->getFillable();
                            $relatedFieldNames = array_merge($relationModel->getFillable(), [$relationModel->getKeyName()]);

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


    public static function fetchAllWithFullQueryExposure(Model $model, Request $request, $entityName = 'Item')
    {
        $inferSpatieCodes = self::inferSpatieCodes($model);

        // Get the primary key of the model
        $primaryKey = $model->getKeyName();
        $directFields = array_merge($model->getFillable(), [$primaryKey]);

        // Include the primary key in the allowed sorts
        $allFieldsWithPrimary = array_merge($inferSpatieCodes["allFields"], [$primaryKey]);

        // Initialize filters with partial matching
        $filters = array_map(function ($field) {
            return AllowedFilter::partial($field);
        }, $allFieldsWithPrimary);

        // Add comparison filters dynamically based on ontology
        $extraInfo = $model->fieldExtraInfo();
        foreach ($extraInfo as $field => $info) {
            if (isset($info['ontologyType']) && $info['ontologyType'] === 'time') {
                $filters = array_merge($filters, ComparisonFilter::setFilters($field, ['gt', 'ge', 'lt', 'le', 'eq', 'ne']));
            }
        }

        // Get listable conditions from the model
        $listableConditions = $model->listable();

        // Initialize the query builder
        $result = QueryBuilder::for(get_class($model))
            ->select($directFields)
            ->allowedFields($allFieldsWithPrimary)
            ->allowedIncludes($inferSpatieCodes["relations"])
            ->allowedFilters($filters)
            ->allowedSorts($allFieldsWithPrimary)
            ->where($listableConditions); // Apply listable conditions

        // Handle search
        if (isset($request->search) && $inferSpatieCodes["searchable_fields"]) {
            $result = $result->whereFullText(
                $inferSpatieCodes["searchable_fields"],
                $request->search
            );
        }



        // Extract whereFilters from the request
        $whereFilters = $request->input('whereFilters', []);

        // Iterate over each filter in whereFilters
        foreach ($whereFilters as $key => $conditions) {
            if ($key === 'whereHas') {
                // Handle whereHas filters specifically
                foreach ($conditions as $relation => $relationConditions) {
                    $result = $result->whereHas($relation, function ($query) use ($relationConditions) {
                        foreach ($relationConditions as $field => $value) {
                            // Use whereIn if value is an array, otherwise use where
                            if (is_array($value)) {
                                $query->whereIn($field, $value);
                            } else {
                                $query->where($field, $value);
                            }
                        }
                    });
                }
            } else {
                // Handle base model attributes for where and whereIn
                // Use whereIn if the value is an array
                if (is_array($conditions)) {
                    $result = $result->whereIn($key, $conditions);
                } else {
                    $result = $result->where($key, $conditions);
                }
            }
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

        try {
            self::beforeRead($model, $result);
        } catch (ValidationException $e) {
            return [
                "res" => [
                    'message' => $e->getMessage(),
                ],
                "code" => $e->status,
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
            $message = method_exists($model, 'creatable_msg') ? $model->creatable_msg($data) : 'Unauthorized to create ' . $model->humanReadableNameSingular();
            throw ValidationException::withMessages(['Unauthorized' => $message]);
        }

        $extraInfo = $model->fieldExtraInfo();
        foreach ($extraInfo as $field => $info) {
            if (isset($info['ontologyType']) && $info['ontologyType'] === 'file' && isset($data[$field])) {
                if (is_a($data[$field], 'Illuminate\Http\UploadedFile')) {
                    // Set file fields to null in the data to be saved initially
                    $data[$field] = null;
                }
            }
        }

        return $data;
    }


    public static function afterCreate($model, $record, $request)
    {
        $extraInfo = $model->fieldExtraInfo();
        $modelName = class_basename($model); // Get the model class name
        $recordId = $record->id;
        $subfolder = $request->query('subfolder', "{$modelName}/{$recordId}"); // Default to "{$modelName}/{$recordId}" if no subfolder is specified

        // Ensure the subfolder exists
        if (!Storage::disk('public')->exists($subfolder)) {
            Storage::disk('public')->makeDirectory($subfolder);
        }

        foreach ($extraInfo as $field => $info) {
            if (isset($info['ontologyType']) && $info['ontologyType'] === 'file' && $request->hasFile($field)) {
                $file = $request->file($field);
                if ($file) {
                    // Handle single file upload
                    $disk = "public";
                    if (config('app.API_GEN_STORAGE_DISK')){
                        $disk = config('app.API_GEN_STORAGE_DISK');
                    }
                    $filePath = $file->store($subfolder, $disk);
                    if ($disk === 's3'){
                        Storage::disk('s3')->setVisibility($filePath, 'public');
                        $filePath = Storage::disk('s3')->url($filePath);
                    } else {
                        $storage_path = "/storage/";
                        if (config('app.API_GEN_STORAGE_PATH_OVERRIDE')){
                            $storage_path = "/".config('app.API_GEN_STORAGE_PATH_OVERRIDE')."/";
                        }
                        $filePath = config('app.url') . $storage_path . $filePath;
                    }
                    // Update the field with the file path
                    $record->$field = $filePath;
                }
            }
        }

        // Save the updated record
        $record->save();

        return $record;
    }


    public static function beforeUpdate($data, $modelItem)
    {
        if (!$modelItem->updatable($data, $modelItem)) {
            $message = method_exists($modelItem, 'updatable_msg') ? $modelItem->updatable_msg($data) : 'Unauthorized to update ' . $modelItem->humanReadableNameSingular();
            throw ValidationException::withMessages(['Unauthorized' => $message]);
        }

        $extraInfo = $modelItem->fieldExtraInfo();
        foreach ($extraInfo as $field => $info) {
            if (isset($info['ontologyType']) && $info['ontologyType'] === 'file') {
                unset($data[$field]);
            }
        }
        return $data;
    }


    public static function normalizeRelationItem($relationItemPayloadOrRecord)
    {
        if (is_array($relationItemPayloadOrRecord)) {
            return $relationItemPayloadOrRecord;
        } elseif (is_object($relationItemPayloadOrRecord)) {
            return $relationItemPayloadOrRecord->toArray();
        }
        return [];
    }

    public static function beforeAttach($config, $relationItem, $currentItem, $request)
    {
        if (isset($config['attachable']) && is_callable($config['attachable'])) {

            $attachable = $config['attachable']($relationItem, $currentItem, $request);
            if (!$attachable) {
                $message = $config['attachable_msg'] ?? 'Unauthorized to attach item';
                throw ValidationException::withMessages(['Unauthorized' => $message])->status(403);
            }
        }
    }

    public static function beforeDetach($config, $relationItemPayload, $currentItem, $request)
    {
        if (isset($config['detachable']) && is_callable($config['detachable'])) {
            $detachable = $config['detachable']($relationItemPayload, $currentItem, $request);
            if (!$detachable) {
                $message = $config['detachable_msg'] ?? 'Unauthorized to detach item';
                throw ValidationException::withMessages(['Unauthorized' => $message])->status(403);
            }
        }
    }


    public static function beforeDelete($model, $record)
    {
        if (!$model->deletable($record)) {
            $message = method_exists($model, 'deletable_msg') ? $model->deletable_msg() : 'Unauthorized to delete ' . $model->humanReadableNameSingular();
            throw ValidationException::withMessages(['Unauthorized' => $message]);
        }
    }

    public static function beforeRead($model, $record)
    {
        if (!$model->readable($record)) {
            $message = method_exists($model, 'readable_msg') ? $model->readable_msg() : 'Unauthorized to read ' . $model->humanReadableNameSingular();
            throw ValidationException::withMessages(['Unauthorized' => $message]);
        }
    }


    public static function inferValidation(Model $model, $depth = 0, $maxDepth = 5, $visited = [], $exceptionToRule = null)
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

                    //if ($rule == "required_without_primary") {
                    //    $primary = $model->getKeyName();
                    //    $validationRules[$field] = "min:1|required_without:{$primary}";
                    //} else {
                    $validationRules[$field] = $rule;
                    //}
                }
            }
        }


        // Relationships types to check
        $relationshipTypes = ['parentRelationships', 'spouseRelationships', 'childRelationships'];

        // Limit the recursion depth to prevent infinite loops
        if ($depth < $maxDepth) {
            foreach ($relationshipTypes as $relationshipType) {
                if (method_exists($model, $relationshipType) && is_array($model->$relationshipType())) {
                    foreach ($model->$relationshipType() as $relationship => $config) {
                        if (method_exists($model, $relationship)) {
                            $refMethod = new ReflectionMethod(get_class($model), $relationship);
                            $relationshipInvoked = $refMethod->invoke($model);

                            if ($relationshipInvoked instanceof Relation) {
                                //$relationType = class_basename(get_class($model->$relationship()));

                                //if ($relationType == "BelongsToMany") {
                                if ($relationshipType == "spouseRelationships") {
                                    // No specific action for BelongsToMany in validation
                                } else if ($relationshipType == "childRelationships") {
                                    $exceptionToRule = $model->$relationship()->getForeignKeyName();
                                }
                            }

                            $relationModel = $model->$relationship()->getRelated();

                            $nestedResult = self::inferValidation($relationModel, $depth + 1, $maxDepth, $visited, $exceptionToRule);

                            foreach ($nestedResult['validationRules'] as $field => $rule) {

                                //if ($rule == "required_without_primary") {
                                //    $primary = $relationModel->getKeyName();
                                //    $validationRules["{$relationship}.*.{$field}"] = "min:1|required_without:{$relationship}.*.{$primary}";
                                //} else if (str_starts_with($rule, 'min:1|required_without:')) {
                                //    $prefix = 'min:1|required_without:';
                                //    $str = $rule;
                                //    if (substr($str, 0, strlen($prefix)) == $prefix) {
                                //        $str = substr($str, strlen($prefix));
                                //    }
                                //    $validationRules["{$relationship}.*.{$field}"] = "{$prefix}{$relationship}.*.{$str}";
                                //
                                //} else {
                                $validationRules["{$relationship}.*.{$field}"] = $rule;
                                //}

                            }


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

    public static function createItem($request, $model, $entityName = 'Item')
    {
        try {
            $createSuccessMsg = $entityName . " created successfully!";
            DB::transaction(function () use ($request, $model, $entityName, &$resultData, &$modelItem, &$createSuccessMsg) {
                $inferValidation = self::inferValidation($model);
                $validationRules = $inferValidation['validationRules'];

                $fields = $model->getFillable();

                if (method_exists($model, 'rules')) {
                    $data = $request->validate($validationRules);
                } else {
                    $data = $request->all();
                }

                // Upsert functionality
                $upsertCompareOn = $request->get('upsertCompareOn');
                $upsertCompareMode = $request->get('upsertCompareMode');

                if ($upsertCompareOn && in_array($upsertCompareOn, $fields)) {
                    $compareValue = $data[$upsertCompareOn];

                    // Normalize based on compare mode
                    if ($upsertCompareMode === 'sluggify') {
                        $slugifiedValue = Str::slug($compareValue);
                        $existingItem = $model->whereRaw("REPLACE(REPLACE(LOWER($upsertCompareOn), ' ', '-'), '--', '-') = ?", [$slugifiedValue])->first();
                    } else {
                        $existingItem = $model->whereRaw("LOWER(TRIM($upsertCompareOn)) = ?", [strtolower(trim($compareValue))])->first();
                    }

                    if ($existingItem) {
                        // If exists, update the item using the updateItem function

                        $updateItem = self::updateItem($request, $model, $existingItem->id, $entityName);
                        $createSuccessMsg = $updateItem["res"]["message"];
                        $resultData = $updateItem["res"]["data"];
                        return;
                    } else {
                        // Create a new item if not found
                        $data = self::beforeCreate($data, $model, $request);
                        $modelItem = $model->create(array_intersect_key($data, array_flip($fields)));
                        $modelItem = self::afterCreate($model, $modelItem, $request);
                    }
                } else {
                    // Regular create operation
                    $data = self::beforeCreate($data, $model, $request);
                    $modelItem = $model->create(array_intersect_key($data, array_flip($fields)));
                    $modelItem = self::afterCreate($model, $modelItem, $request);
                }

                $children = self::recursiveUpdateOrAttachManyToManyRels($model, $request->all(), $modelItem, $request);

                $resultData = [
                    ...$modelItem->getAttributes(),
                    ...$children
                ];
            });

            Log::info('2024-13-06--12-53', ['$payload' =>  [1234444]]);
            return [
                "res" => [
                    'message' => $createSuccessMsg,
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






    public static function recursiveUpdateOrAttachManyToManyRels($model, $itemData, $parent, $request)
    {
        $result = [];
        $relationshipTypes = ['parentRelationships', 'spouseRelationships', 'childRelationships'];

        $m2mRelConfigs = $request->get('m2mRelConfigs', []);

        foreach ($relationshipTypes as $relationshipType) {
            if (method_exists($model, $relationshipType) && is_array($model->$relationshipType())) {
                foreach ($model->$relationshipType() as $relationshipName => $config) {
                    if (isset($itemData[$relationshipName])) {
                        $relationItems = $itemData[$relationshipName];
                        $relatedModel = $model->$relationshipName()->getRelated();
                        $pKey = $relatedModel->getKeyName();
                        $relatedTable = $relatedModel->getTable();

                        if ($relationshipType == "spouseRelationships" || $relationshipType == "childRelationships") {

                            $m2mRelConfig = collect($m2mRelConfigs)->firstWhere('rel', $relationshipName);

                            // Existing related item IDs
                            $currentRelatedIds = $parent->$relationshipName()->pluck($pKey)->toArray();

                            // New related item IDs
                            $newRelatedIds = array_column($relationItems, $pKey);

                            // Items to detach
                            $detachIds = array_diff($currentRelatedIds, $newRelatedIds);

                            // Detach items not in the new list
                            foreach ($detachIds as $detachId) {
                                $relationItemPayload = $relatedModel->find($detachId);
                                if ($relationItemPayload) {
                                    self::beforeDetach($config, $relationItemPayload, $parent, $request);
                                    $parent->$relationshipName()->detach($detachId);
                                }
                            }

                            foreach ($relationItems as $relationItemPayload) {
                                if (isset($relationItemPayload[$pKey]) && !empty($relationItemPayload[$pKey]) && $relationItemPayload[$pKey] != 0) {
                                    if ($m2mRelConfig && isset($m2mRelConfig['action']) && $m2mRelConfig['action'] === 'detach') {
                                        self::beforeDetach($config, $relationItemPayload, $parent, $request);
                                        if ($relationshipType == "spouseRelationships") {
                                            $parent->$relationshipName()->detach($relationItemPayload[$pKey]);
                                        } elseif ($relationshipType == "childRelationships") {
                                            $relatedModel->where($pKey, $relationItemPayload[$pKey])->delete();
                                        }
                                    } else {
                                        $existingItem = $relatedModel->find($relationItemPayload[$pKey]);
                                        if ($existingItem) {
                                            $relationItemPayload = self::beforeUpdate($relationItemPayload, $existingItem);
                                            if ($relationshipType == "spouseRelationships") {
                                                $existingItem->update($relationItemPayload);
                                                $existingRelation = $parent->$relationshipName()
                                                    ->where("{$relatedTable}.{$pKey}", $relationItemPayload[$pKey])
                                                    ->exists();
                                                if (!$existingRelation) {
                                                    self::beforeAttach($config, $existingRelation, $parent, $request);
                                                    $parent->$relationshipName()->attach($relationItemPayload[$pKey]);
                                                }
                                            } elseif ($relationshipType == "childRelationships") {
                                                $existingItem->update($relationItemPayload);
                                            }
                                            $children = self::recursiveUpdateOrAttachManyToManyRels($relatedModel, $relationItemPayload, $existingItem, $request);
                                            $result[$relationshipName][] = [
                                                ...$existingItem->getAttributes(),
                                                ...$children
                                            ];
                                        }
                                    }
                                } else {
                                    if ($m2mRelConfig && isset($m2mRelConfig['action']) && $m2mRelConfig['action'] === 'createOrAttachSimilar') {
                                            $similarItem = false;
                                            if (isset($m2mRelConfig['compareOn'])) {
                                                $compareOnField = $m2mRelConfig['compareOn'];
                                                $compareMode = $m2mRelConfig['compareMode'] ?? 'direct';

                                                $normalizedValue = self::normalizeString($relationItemPayload[$compareOnField], $compareMode);

                                                if ($compareMode === 'sluggify') {
                                                    $slugifiedValue = Str::slug($normalizedValue);
                                                    $similarItem = $relatedModel
                                                        ->whereRaw("REPLACE(REPLACE(LOWER($compareOnField), ' ', '-'), '--', '-') = ?", [$slugifiedValue])
                                                        ->first();
                                                } else {
                                                    $similarItem = $relatedModel
                                                        ->whereRaw("LOWER(TRIM($compareOnField)) = ?", [strtolower(trim($normalizedValue))])
                                                        ->first();
                                                }
                                            }

                                            if ($similarItem) {

                                                if ($relationshipType == "spouseRelationships") {
                                                    $existingRelation = $parent->$relationshipName()
                                                        ->where("{$relatedTable}.{$pKey}", $similarItem->$pKey)
                                                        ->exists();



                                                    if (!$existingRelation) {
                                                        self::beforeAttach($config, $existingRelation, $parent, $request);
                                                        $parent->$relationshipName()->attach($similarItem->$pKey);
                                                    }
                                                }

                                                $children = self::recursiveUpdateOrAttachManyToManyRels($relatedModel, $relationItemPayload, $similarItem, $request);
                                                $result[$relationshipName][] = [
                                                    ...$similarItem->getAttributes(),
                                                    ...$children
                                                ];
                                            } else {
                                                $relationItemPayload = self::beforeCreate($relationItemPayload, $relatedModel, $request);

                                                $createdChild = $relatedModel->create($relationItemPayload);
                                                $createdChild = self::afterCreate($relatedModel, $createdChild, $request);

                                                self::beforeAttach($config, $createdChild, $parent, $request);
                                                if ($relationshipType == "spouseRelationships") {
                                                    $parent->$relationshipName()->attach($createdChild->id);
                                                } elseif ($relationshipType == "childRelationships") {
                                                    $parent->$relationshipName()->save($createdChild);
                                                }
                                                $createdChild->refresh();

                                                $children = self::recursiveUpdateOrAttachManyToManyRels($relatedModel, $relationItemPayload, $createdChild, $request);
                                                $result[$relationshipName][] = [
                                                    ...$createdChild->getAttributes(),
                                                    ...$children
                                                ];
                                            }
                                    } else {
                                        $relationItemPayload = self::beforeCreate($relationItemPayload, $relatedModel, $request);

                                        $createdChild = $relatedModel->create($relationItemPayload);
                                        $createdChild = self::afterCreate($relatedModel, $createdChild, $request);

                                        self::beforeAttach($config, $createdChild, $parent, $request);
                                        if ($relationshipType == "spouseRelationships") {
                                            $parent->$relationshipName()->attach($createdChild->id);
                                        } elseif ($relationshipType == "childRelationships") {
                                            $parent->$relationshipName()->save($createdChild);
                                        }
                                        $createdChild->refresh();

                                        $children = self::recursiveUpdateOrAttachManyToManyRels($relatedModel, $relationItemPayload, $createdChild, $request);
                                        $result[$relationshipName][] = [
                                            ...$createdChild->getAttributes(),
                                            ...$children
                                        ];
                                    }
                                }
                            }
                        } elseif ($relationshipType == "parentRelationships") {
                            $relationItemPayload = $itemData[$relationshipName];

                            if (isset($relationItemPayload[$pKey]) && !empty($relationItemPayload[$pKey]) && $relationItemPayload[$pKey] != 0) {
                                $existingItem = $relatedModel->find($relationItemPayload[$pKey]);

                                if ($existingItem) {
                                    $relationItemPayload = self::beforeUpdate($relationItemPayload, $existingItem);
                                    $existingItem->update($relationItemPayload);
                                    self::beforeAttach($config, $existingItem, $parent, $request);
                                    $parent->$relationshipName()->associate($existingItem);
                                    $parent->save();
                                    $result[$relationshipName] = $existingItem->getAttributes();
                                }
                            } else {
                                $relationItemPayload = self::beforeCreate($relationItemPayload, $relatedModel, $request);
                                $createdParent = $relatedModel->create($relationItemPayload);
                                $createdParent = self::afterCreate($relatedModel, $createdParent, $request);

                                self::beforeAttach($config, $createdParent, $parent, $request);
                                $parent->$relationshipName()->associate($createdParent);
                                $parent->save();
                                $result[$relationshipName] = $createdParent->getAttributes();
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }



    public static function customSync($relation, $relatedIds, $detaching = true)
    {
        // Get the current related items
        $currentRelatedItems = $relation->get();

        // Detach items that are not in the new related IDs
        if ($detaching) {
            foreach ($currentRelatedItems as $item) {
                if (!in_array($item->id, $relatedIds)) {
                    // Run beforeDetach logic
                    $config = $relation->getRelated()->parentRelationships()[$relation->getRelationName()] ?? [];
                    self::beforeDetach($config, $item, $relation->getParent(), request());

                    // Detach the item
                    $relation->detach($item->id);
                }
            }
        }

        // Attach new items
        $relation->sync($relatedIds, false);
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

                try {
                    self::beforeDelete($model, $item);
                } catch (ValidationException $e) {
                    $response = [
                        'message' => $e->getMessage(),
                    ];
                    $code = $e->status;
                    return;
                }

                $parentsToDelete = $request->input('parentsToDelete', []);
                $relationshipTypes = ['parentRelationships', 'spouseRelationships', 'childRelationships'];

                foreach ($relationshipTypes as $relationshipType) {
                    if (method_exists($model, $relationshipType) && is_array($model->$relationshipType())) {
                        foreach ($model->$relationshipType() as $relationshipName => $config) {
                            //$relationType = self::getRelationType($model, $relationshipName);
                            if ($relationshipType == "parentRelationships" && in_array($relationshipName, $parentsToDelete)) {
                                $parent = $item->$relationshipName;
                                if ($parent) {
                                    $parent->delete();
                                }
                            }
                        }
                    }
                }

                $extraInfo = $item->fieldExtraInfo();
                foreach ($extraInfo as $field => $info) {
                    if (isset($info['ontologyType']) && $info['ontologyType'] === 'file') {
                        if ($item->$field) {

                            $storage_path = "/storage/";
                            if (config('app.API_GEN_STORAGE_PATH_OVERRIDE')){
                                $storage_path = "/".config('app.API_GEN_STORAGE_PATH_OVERRIDE')."/";
                            }

                            $originalString = $item->$field;
                            $prefixToRemove = config('app.url') . $storage_path;

                            if (substr($originalString, 0, strlen($prefixToRemove)) === $prefixToRemove) {
                                $originalString = substr($originalString, strlen($prefixToRemove));
                            }
                            Storage::disk('public')->delete($originalString);
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
                if (str_contains($request->header('Content-Type'), 'multipart/form-data')) {
                    $data = self::parseMultipartFormDataForPatchRequest($request);
                    $request->merge($data);
                } else {
                    $data = $request->all();
                }

                $inferValidation = self::inferValidation($model);
                $validationRules = $inferValidation['validationRules'];

                foreach ($validationRules as $vKey => $vVal) {
                    $validationRules[$vKey] = $vVal;
                }

                $fields = $model->getFillable();

                if (method_exists($model, 'rules')) {
                    $request->validate($validationRules);
                }

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


    public static function normalizeString($string, $mode)
    {
        $result = $string;
        switch ($mode) {
            case 'lowercase':
                $result = strtolower(trim($string));
            case 'uppercase':
                $result = strtoupper(trim($string));
            case 'trim':
                $result = trim($string);
            case 'sluggify':
                $result = Str::slug($string);
            case 'direct':
                $result = $string;
        }
        return $result;
    }

    public static function parseMultipartFormDataForPatchRequest($request)
    {
        $data = []; // Initialize an empty array to hold parsed data
        $input = $request->getContent(); // Get the raw content of the request
        $boundary = substr($input, 0, strpos($input, "\r\n")); // Extract the boundary string from the raw content

        // If no boundary is found, return the empty data array
        if (empty($boundary)) {
            return $data;
        }

        // Split the input into parts using the boundary string, ignore the first empty part
        $parts = array_slice(explode($boundary, $input), 1);

        foreach ($parts as $part) {
            // Break the loop if the end boundary is found
            if ($part == "--\r\n") break;

            // Trim whitespace from the part
            $part = trim($part);
            // Skip empty parts
            if (empty($part)) continue;

            // Ensure the part contains headers and content
            $rawHeaders = '';
            $content = '';

            if (strpos($part, "\r\n\r\n") !== false) {
                list($rawHeaders, $content) = explode("\r\n\r\n", $part, 2);
            } else {
                $rawHeaders = $part;
                $content = '';
            }

            $rawHeaders = explode("\r\n", $rawHeaders);
            $headers = [];

            // Parse headers into an associative array
            foreach ($rawHeaders as $header) {
                list($name, $value) = explode(':', $header);
                $headers[strtolower(trim($name))] = trim($value);
            }

            // If the part contains content-disposition header
            if (isset($headers['content-disposition'])) {
                // Match the name and filename (if present) from the header
                if (preg_match('/name="(?<name>[^"]+)"(; filename="(?<filename>[^"]+)")?/', $headers['content-disposition'], $matches)) {
                    $name = $matches['name'];
                    // If filename is present, treat it as a file upload
                    if (isset($matches['filename'])) {
                        $filename = $matches['filename'];
                        $tmpName = tempnam(sys_get_temp_dir(), 'upl'); // Create a temporary file
                        file_put_contents($tmpName, $content); // Write the content to the temporary file
                        $data = self::assignNestedArrayValue($data, $name, new \Illuminate\Http\UploadedFile($tmpName, $filename, null, null, true));
                    } else {
                        // If content is empty, set it to empty string; otherwise, use the content
                        $value = strlen(trim($content)) == 0 ? "" : $content;
                        $data = self::assignNestedArrayValue($data, $name, $value);
                    }
                }
            }
        }

        return $data; // Return the parsed data array
    }




    public static function assignNestedArrayValue($array, $path, $value)
    {
        // Split the path into keys based on square brackets
        $keys = preg_split('/[\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
        $current = &$array; // Reference to the current level of the array

        // Iterate over each key and traverse/build the array structure
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = []; // Create a new array if the key does not exist
            }
            $current = &$current[$key]; // Move the reference to the next level
        }

        $current = $value; // Set the value at the final level
        return $array; // Return the modified array
    }








}

