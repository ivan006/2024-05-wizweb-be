<?php

namespace QuicklistsOrmApi\Console\Commands\Scaffolding;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use QuicklistsOrmApi\Console\Commands\WordSplitter;
use QuicklistsOrmApi\Console\Commands\ModelRelationHelper;

class GenerateVuexOrmModels extends Command
{
    protected $signature = 'generate:ql-ui-m';
    protected $description = 'Generate Vuex ORM models from database schema';
    protected $wordSplitter;
    protected $relationHelper;

    public function __construct()
    {
        parent::__construct();
        $this->wordSplitter = new WordSplitter();
        $this->relationHelper = new ModelRelationHelper();
    }

    public function handle()
    {
        $tables = DB::select('SHOW TABLES');
        $routes = [];
        $models = [];

        foreach ($tables as $table) {
            // Extract the table name dynamically
            $tableArray = get_object_vars($table);
            $tableName = reset($tableArray);
            $cleanedTableName = preg_replace('/[^a-zA-Z]/', '', $tableName);
            $this->info("Processing table: $tableName (cleaned: $cleanedTableName)");

            $segmentationResult = $this->wordSplitter->split($cleanedTableName);
            $segmentedTableName = $segmentationResult['words'];
            $this->info("Segmented table name: " . implode(' ', $segmentedTableName));

            $pascalTableName = implode('', array_map('ucfirst', $segmentedTableName));
            $modelName = Str::singular($pascalTableName);
            $jsModelName = Str::camel(Str::singular($cleanedTableName));
            $pluralTableName = Str::plural(Str::kebab(implode('-', $segmentedTableName)));

            $columns = DB::select("SHOW COLUMNS FROM $tableName");
            $primaryKey = $this->getPrimaryKey($columns);

            $fields = [];
            $fieldsMetadata = [];
            $relations = $this->relationHelper->getModelRelations($tableName, $columns);
            $imports = $this->generateImports($modelName, $relations['foreignKeys'], $relations['hasMany']);
            $parentWithables = [];

            foreach ($columns as $column) {
                $fieldName = $column->Field;
                $fieldMeta = "{}";
                if (in_array($fieldName, array_column($relations['foreignKeys'], 'COLUMN_NAME'))) {
                    $relatedFieldName = $this->generateRelationName($fieldName, array_map(function ($column) { return strtolower($column->Field); }, $columns));
                    $parentWithables[] = "'$relatedFieldName'";
                    $fieldMeta = "{ relationRules: { linkables: (user) => { return {} } } }";
                }
                $fields[] = "'$fieldName': this.attr('').nullable()";
                $fieldsMetadata[] = "'$fieldName': $fieldMeta"; // Placeholder for actual metadata logic
            }

            $fieldsString = implode(",\n            ", $fields);
            $fieldsMetadataString = implode(",\n            ", $fieldsMetadata);
            $relationsString = $this->generateRelationsString($relations['foreignKeys'], $relations['hasMany'], $columns);
            $parentWithablesString = implode(",\n        ", $parentWithables);

            $jsModel = <<<EOT
$imports

export default class $modelName extends MyBaseModel {
    static entity = '$jsModelName';
    static entityUrl = '/api/$pluralTableName';
    static primaryKey = '$primaryKey';
    static titleKey = '$primaryKey';
    static openRecord(pKey){
      router.push({
        name: '/lists/$pluralTableName/:rId',
        params: {
          rId: pKey,
        },
      })
    }

    static parentWithables = [
        $parentWithablesString
    ];

    static rules = {
        readables: () => true,
        readable: (item) => true,
        editable: (item) => true,
        creatable: () => true,
    };

    static fieldsMetadata = {
        $fieldsMetadataString
    };

    static fields() {
        return {
            $fieldsString,
            $relationsString
        };
    }

    static templateListGrid = {
        // Define templateListGrid
    };

    static templateOverview = {
        // Define templateOverview
    };

    static FetchAll(relationships = [], flags = {}, moreHeaders = {}, options = { page: 1, limit: 15, filters: {}, clearPrimaryModelOnly: false }) {
        return this.customSupabaseApiFetchAll(
            `\${this.baseUrl}\${this.entityUrl}`,
            [...this.parentWithables, ...relationships],
            flags,
            this.mergeHeaders(moreHeaders),
            options,
            this
        );
    }

    static FetchById(id, relationships = [], flags = {}, moreHeaders = {}) {
        return this.customSupabaseApiFetchById(
            `\${this.baseUrl}\${this.entityUrl}`,
            id,
            [...this.parentWithables, ...relationships],
            flags,
            this.mergeHeaders(moreHeaders),
            this
        );
    }

    static Store(entity, relationships = [], flags = {}, moreHeaders = {}) {
        return this.customSupabaseApiStore(
            `\${this.baseUrl}\${this.entityUrl}`,
            entity,
            [...this.parentWithables, ...relationships],
            flags,
            this.mergeHeaders(moreHeaders),
            this
        );
    }

    static Update(entity, relationships = [], flags = {}, moreHeaders = {}) {
        return this.customSupabaseApiUpdate(
            `\${this.baseUrl}\${this.entityUrl}`,
            entity,
            [...this.parentWithables, ...relationships],
            flags,
            this.mergeHeaders(moreHeaders),
            this
        );
    }

    static Delete(entityId, flags = {}, moreHeaders = {}) {
        return this.customSupabaseApiDelete(
            `\${this.baseUrl}\${this.entityUrl}`,
            entityId,
            flags,
            this.mergeHeaders(moreHeaders),
            this
        );
    }
}
EOT;

            $path = base_path("resources/js/models/{$modelName}.js");
            File::put($path, $jsModel);

            $this->info("Generated Vuex ORM model for $tableName");

            $models[] = [
                'modelName' => $modelName
            ];
        }

        $this->generateStoreFile($models);
    }

    protected function getPrimaryKey($columns)
    {
        foreach ($columns as $column) {
            if ($column->Key === 'PRI') {
                return $column->Field;
            }
        }

        return 'id'; // Default primary key if none is found
    }

    protected function generateRelationsString($foreignKeys, $hasManyRelations, $columns)
    {
        $relations = [];
        $existingFields = array_map(function ($column) {
            return strtolower($column->Field);
        }, $columns);

        // Handle belongsTo relationships
        foreach ($foreignKeys as $foreignKey) {
            $relationFieldName = $foreignKey['COLUMN_NAME'];
            $relatedModel = $foreignKey['RELATED_MODEL'];
            $relationName = $this->generateRelationName($relationFieldName, $existingFields);

            // Check if this relationship already exists
            if (isset($relations[$relationName])) {
                $relationName .= Str::studly($relationFieldName);
            }

            $segmentationResult = $this->wordSplitter->split($relatedModel);
            $segmentedModelName = implode('', array_map('ucfirst', $segmentationResult['words']));

            $relations[$relationName] = "'$relationName': this.belongsTo($segmentedModelName, '$relationFieldName')";
        }

        // Handle hasMany relationships
        $groupedHasMany = $this->relationHelper->groupHasManyRelations($hasManyRelations);
        foreach ($groupedHasMany as $model => $relationsArray) {
            foreach ($relationsArray as $relation) {
                $relationName = Str::camel(Str::plural($relation['name']));
                $relatedModel = $relation['RELATED_MODEL'];

                // Check for conflicts in hasMany relation names
                if (isset($relations[$relationName])) {
                    $relationName .= Str::studly($relation['COLUMN_NAME']);
                }

                $segmentationResult = $this->wordSplitter->split($relatedModel);
                $segmentedModelName = implode('', array_map('ucfirst', $segmentationResult['words']));

                $relations[$relationName] = "'$relationName': this.hasMany($segmentedModelName, '{$relation['COLUMN_NAME']}')";
            }
        }

        return implode(",\n            ", $relations);
    }


    protected function generateRelationName($fieldName, $existingFields)
    {
        // Remove suffixes like _ID, _Id, _id, id, ID, Id
        $relationName = preg_replace('/(_ID|_Id|_id|id|ID|Id)$/', '', $fieldName);
        $relationName = Str::camel(Str::singular($relationName));

        // Check for conflicts
        if (in_array(strtolower($relationName), $existingFields)) {
            $relationName .= 'Rel';
        }

        // Convert to snake_case for consistency
        $relationName = Str::snake($relationName);

        return $relationName;
    }


    protected function generateImports($modelName, $foreignKeys, $hasManyRelations)
    {
        $relatedModels = array_unique(array_merge(
            array_column($foreignKeys, 'RELATED_MODEL'),
            array_column($hasManyRelations, 'RELATED_MODEL')
        ));

        $imports = array_map(function($relatedModel) {
            $segmentationResult = $this->wordSplitter->split($relatedModel);
            $segmentedModelName = implode('', array_map('ucfirst', $segmentationResult['words']));
            $relatedModelFile = implode('', array_map('ucfirst', $segmentationResult['words']));
            return "import $segmentedModelName from 'src/models/$relatedModelFile';";
        }, $relatedModels);

        array_unshift($imports, "import MyBaseModel from 'src/models/helpers/MyBaseModel';", "import router from 'src/router';");
        return implode("\n", $imports);
    }


    protected function generateStoreFile($models)
    {
        $imports = array_map(function($model) {
            return "import {$model['modelName']} from 'src/models/{$model['modelName']}';";
        }, $models);

        $registrations = array_map(function($model) {
            return "database.register({$model['modelName']});";
        }, $models);

        $importsString = implode("\n", $imports);
        $registrationsString = implode("\n", $registrations);

        $storeFileContent = <<<EOT
import { createStore } from 'vuex';
import VuexORM from '@vuex-orm/core';
import VuexORMAxios from '@vuex-orm/plugin-axios';
import axios from 'axios';

import { DBCrudCacheSet } from 'quicklists-vue-orm-ui';

$importsString

VuexORM.use(VuexORMAxios, {
  axios,
  baseURL: 'https://your-api-url.com'  // Set your API base URL here
});

const database = new VuexORM.Database();

database.register(DBCrudCacheSet);
$registrationsString

const store = createStore({
  plugins: [VuexORM.install(database)]
});

export default store;
EOT;

        $storeFilePath = base_path('resources/js/store/index.js');
        File::ensureDirectoryExists(dirname($storeFilePath));
        File::put($storeFilePath, $storeFileContent);

        $this->info('Generated store file');
    }
}
