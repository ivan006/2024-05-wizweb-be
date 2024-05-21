<?php


use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\QueryBuilder;

class OrmApi
{

    public static function fullQueryExposureHelper(Model $model, $depth = 0, $maxDepth = 10)
    {
        $fields = $model->getFillable();
        $relatedFields = [];
        $relations = [];

        // Limit the recursion depth to prevent infinite loops
        if ($depth < $maxDepth) {
            // If the model has the static $relationships property
            if (property_exists($model, 'relationships') && is_array($model::$relationships)) {
                foreach ($model::$relationships as $relationship) {
                    // Check if the relationship method exists
                    if (method_exists($model, $relationship)) {
                        // Store the relationship name
                        $relations[] = $relationship;

                        $relationModel = $model->$relationship()->getRelated();
                        $relatedFieldNames = $relationModel->getFillable();

                        foreach ($relatedFieldNames as $attribute) {
                            $relatedFields[] = "{$relationship}.{$attribute}";
                        }

                        // Recursively get nested relationships
                        $nestedRelations = self::fullQueryExposureHelper($relationModel, $depth + 1, $maxDepth);
                        foreach ($nestedRelations['relations'] as $nestedRelation) {
                            $relations[] = "{$relationship}.{$nestedRelation}";
                        }
                    }
                }
            }
        }

        $allFields = array_merge(
            $fields,
            $relatedFields
        );

        $valid_searchable_fields = [];

        if (property_exists($model, 'searchable_fields') && is_array($model::$searchable_fields)) {
            $valid_searchable_fields = $model::$searchable_fields;
        }

        $result = [
            "allFields" => $allFields,
            "relations" => $relations,
            "searchable_fields" => $valid_searchable_fields,
        ];
        return $result;
    }

    public static function fetchAllWithFullQueryExposure (Model $model, $request) {

        $spatieQBExposeAllHelper = self::fullQueryExposureHelper($model);

        $result = QueryBuilder::for(get_class($model))
            ->allowedIncludes($spatieQBExposeAllHelper["relations"])
            ->allowedFilters($spatieQBExposeAllHelper["allFields"]);


        if (isset($request->search) && $spatieQBExposeAllHelper["searchable_fields"]){
            $result = $result->whereFullText(
                $spatieQBExposeAllHelper["searchable_fields"],
                $request->search
            );
        }

        $result = $result->get();
        return $result;
    }

    public static function fetchByIdWithFullQueryExposure (Model $model, $id) {

        $spatieQBExposeAllHelper = self::fullQueryExposureHelper($model);

        $result = QueryBuilder::for(get_class($model))
            ->allowedIncludes($spatieQBExposeAllHelper["relations"])
            ->allowedFilters($spatieQBExposeAllHelper["allFields"])
            ->find($id);

        return $result;
    }


    public static function deleteItem($model, $id, $entityName)
    {
        $item = $model::find($id);

        if (!$item) {
            return \App\Helpers\response()->json(['message' => $entityName.' not found'], 404);
        }

        $item->delete();

        return \App\Helpers\response()->json(['message' => $entityName.' deleted successfully'], 200);
    }

    public static function createItem($request, $model, $entityName = 'Item')
    {

        if (property_exists($model, 'rules')) {
            $data = $request->validate($model::$rules);
        } else {
            $data = $request;
        }

        $item = $model::create($data);

        return \App\Helpers\response()->json([
            'message' => $entityName." created successfully!",
            'item' => $item,
        ]);
    }

    public static function updateItem($request, $model, $id, $entityName = 'Item')
    {
        $item = $model::findOrFail($id);

        if (property_exists($model, 'rules')) {
            $data = $request->validate($model::$rules);
        } else {
            $data = $request;
        }

        $item->update($data);

        return \App\Helpers\response()->json([
            'message' => $entityName." updated successfully!",
            'item' => $item,
        ]);
    }

}
