<?php

namespace QuicklistsOrmApi\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ModelRelationHelper
{
    public function getModelRelations($tableName, $columns)
    {
        $databaseName = config('database.connections.mysql.database');
        
        $foreignKeys = DB::select("SELECT
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME,
            CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_COLUMN_NAME IS NOT NULL", [$databaseName, $tableName]);

        //Log::info('Foreign keys detected:', $foreignKeys);
        //Log::info('xx:', [$databaseName, $tableName]);


        $hasManyRelations = DB::select("SELECT
            TABLE_NAME,
            COLUMN_NAME,
            REFERENCED_COLUMN_NAME,
            CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME = ?", [$databaseName, $tableName]);

        $foreignKeysArray = [];
        $hasManyArray = [];

        foreach ($foreignKeys as $foreignKey) {
            $foreignKeysArray[] = [
                'COLUMN_NAME' => $foreignKey->COLUMN_NAME,
                'REFERENCED_TABLE_NAME' => $foreignKey->REFERENCED_TABLE_NAME,
                'REFERENCED_COLUMN_NAME' => $foreignKey->REFERENCED_COLUMN_NAME,
                'RELATED_MODEL' => Str::studly(Str::singular($foreignKey->REFERENCED_TABLE_NAME))
            ];
        }

        foreach ($hasManyRelations as $relation) {
            $hasManyArray[] = [
                'model' => Str::studly(Str::singular($relation->TABLE_NAME)),
                'name' => Str::camel(Str::plural($relation->TABLE_NAME)),
                'COLUMN_NAME' => $relation->COLUMN_NAME,
                'KEY_COLUMN_NAME' => $relation->REFERENCED_COLUMN_NAME,
                'RELATED_MODEL' => Str::studly(Str::singular($relation->TABLE_NAME))
            ];
        }

        return ['foreignKeys' => $foreignKeysArray, 'hasMany' => $hasManyArray];
    }

    public function getRelatedModelName($fieldName, $foreignKeys)
    {
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey['COLUMN_NAME'] === $fieldName) {
                return $foreignKey['RELATED_MODEL'];
            }
        }
        return null;
    }

    public function groupHasManyRelations($hasManyRelations)
    {
        $groupedRelations = [];
        foreach ($hasManyRelations as $relation) {
            $groupedRelations[$relation['model']][] = $relation;
        }
        return $groupedRelations;
    }


    public function getBelongsToManyRelations($tableName, $columns)
    {
        $relations = [];

        // Assuming your pivot table naming convention is alphabetical order of table names separated by underscore
        $tables = DB::select('SHOW TABLES');
        foreach ($tables as $table) {
            $tableArray = get_object_vars($table);
            $pivotTable = reset($tableArray);

            if (strpos($pivotTable, '_') !== false) {
                [$firstTable, $secondTable] = explode('_', $pivotTable);

                if ($firstTable === $tableName || $secondTable === $tableName) {
                    $relatedTable = $firstTable === $tableName ? $secondTable : $firstTable;
                    $relatedModel = Str::studly(Str::singular($relatedTable));

                    $foreignPivotKey = $firstTable . '_id';
                    $relatedPivotKey = $secondTable . '_id';

                    if ($firstTable === $tableName) {
                        $foreignPivotKey = $secondTable . '_id';
                        $relatedPivotKey = $firstTable . '_id';
                    }

                    $relations[] = [
                        'model' => $relatedModel,
                        'pivotTable' => $pivotTable,
                        'foreignPivotKey' => $foreignPivotKey,
                        'relatedPivotKey' => $relatedPivotKey,
                    ];
                }
            }
        }

        return $relations;
    }
}
