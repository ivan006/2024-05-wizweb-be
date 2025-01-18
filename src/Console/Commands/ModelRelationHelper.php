<?php

namespace WizwebBe\Console\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ModelRelationHelper
{
    public function splitName($name)
    {
        // Splits camelCase, PascalCase, and poorly named attrs like userid into separated words
        $segments = preg_split('/(?<=[a-z])(?=[A-Z])|_|(?<=[A-Za-z])(?=\\d)/', $name);
        return implode('_', array_map('strtolower', $segments));
    }


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

    public function generateRelationName($fieldName, $existingFields)
    {
        $relationName = preg_replace('/(_ID|_Id|_id|id|ID|Id)$/', '', $this->splitName($fieldName));
        $relationName = Str::camel(Str::singular($relationName));

        if (in_array(strtolower($relationName), $existingFields)) {
            $relationName .= 'Rel';
        }

        return Str::snake($relationName);
    }


    public function resolveConflicts($relationName, $existingFields)
    {
        return in_array(strtolower($relationName), $existingFields) ? $relationName . 'Rel' : $relationName;
    }

    public function generateBelongsTo($relatedModel, $relationshipName, $fieldName)
    {
        return [
            'type' => 'belongsTo',
            'model' => $relatedModel,
            'name' => $relationshipName,
            'foreignKey' => $fieldName,
        ];
    }

    public function generateHasMany($relatedModel, $relationshipName, $foreignKey)
    {
        return [
            'type' => 'hasMany',
            'model' => $relatedModel,
            'name' => $relationshipName,
            'foreignKey' => $foreignKey,
        ];
    }

    public function generateBelongsToMany($relatedModel, $relationshipName, $pivotTable, $foreignPivotKey, $relatedPivotKey)
    {
        return [
            'type' => 'belongsToMany',
            'model' => $relatedModel,
            'name' => $relationshipName,
            'pivotTable' => $pivotTable,
            'foreignPivotKey' => $foreignPivotKey,
            'relatedPivotKey' => $relatedPivotKey,
        ];
    }

    public function getAllRelationships($tableName, $columns)
    {
        $relations = $this->getModelRelations($tableName, $columns);

        $allRelations = [];
        foreach ($relations['foreignKeys'] as $foreignKey) {
            $relationName = $this->generateRelationName($foreignKey['COLUMN_NAME'], array_column($columns, 'Field'));
            $allRelations[] = $this->generateBelongsTo($foreignKey['RELATED_MODEL'], $relationName, $foreignKey['COLUMN_NAME']);
        }

        foreach ($relations['hasMany'] as $relation) {
            $relationName = $this->generateRelationName($relation['name'], array_column($columns, 'Field'));
            $allRelations[] = $this->generateHasMany($relation['RELATED_MODEL'], $relationName, $relation['COLUMN_NAME']);
        }

        foreach ($this->getBelongsToManyRelations($tableName, $columns) as $relation) {
            $relationName = $this->generateRelationName(Str::plural($relation['model']), array_column($columns, 'Field'));
            $allRelations[] = $this->generateBelongsToMany($relation['model'], $relationName, $relation['pivotTable'], $relation['foreignPivotKey'], $relation['relatedPivotKey']);
        }

        return $allRelations;
    }
}
