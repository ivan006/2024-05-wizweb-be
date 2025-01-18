<?php

namespace WizwebBe\Console\Commands\Scaffolding;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use WizwebBe\Console\Commands\WordSplitter;
use WizwebBe\Console\Commands\ModelRelationHelper;

class GenerateLaravelModels extends Command
{
    protected $signature = 'generate:ql-api-m';
    protected $description = 'Generate Laravel models from database schema with relationships, rules, fillable attributes, and table name';
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

        foreach ($tables as $table) {
            $tableArray = get_object_vars($table);
            $tableName = reset($tableArray);
            $cleanedTableName = preg_replace('/[^a-zA-Z]/', '', $tableName);
            $this->info("Processing table: $tableName (cleaned: $cleanedTableName)");

            $segmentationResult = $this->wordSplitter->split($cleanedTableName);
            $segmentedTableName = $segmentationResult['words'];
            $pascalTableName = implode('', array_map('ucfirst', $segmentedTableName));

            $modelName = Str::singular($pascalTableName);
            $columns = DB::select("SHOW COLUMNS FROM $tableName");

            $fillable = [];
            $rules = [];
            $attributeNames = [];
            $autoIncrement = "";

            foreach ($columns as $column) {
                $fieldName = $this->relationHelper->splitName($column->Field); // Word-split field names
                $nullable = $column->Null === 'YES';
                $isAutoIncrement = strpos($column->Extra, 'auto_increment') !== false;

                if (!$isAutoIncrement) {
                    $fillable[] = "'$fieldName'";
                    $rules[] = "'$fieldName' => '" . ($nullable ? 'nullable' : 'sometimes:required') . "'";
                    $attributeNames[] = strtolower($fieldName);
                } else {
                    $autoIncrement = $fieldName;
                }
            }

            $allRelations = $this->relationHelper->getAllRelationships($tableName, $columns);

            $parentRelationships = [];
            $childRelationships = [];
            $spouseRelationships = [];
            $belongsToMethods = [];
            $hasManyMethods = [];
            $belongsToManyMethods = [];

            foreach ($allRelations as $relation) {
                if ($relation['type'] === 'belongsTo') {
                    $parentRelationships[] = "'{$relation['name']}' => []";
                    $belongsToMethods[] = $this->generateBelongsToMethod($relation['model'], $relation['name'], $relation['foreignKey']);
                } elseif ($relation['type'] === 'hasMany') {
                    $childRelationships[] = "'{$relation['name']}' => []";
                    $hasManyMethods[] = $this->generateHasManyMethod($relation['model'], $relation['name'], $relation['foreignKey']);
                } elseif ($relation['type'] === 'belongsToMany') {
                    $spouseRelationships[] = "'{$relation['name']}' => []";
                    $belongsToManyMethods[] = $this->generateBelongsToManyMethod(
                        $relation['model'],
                        $relation['name'],
                        $relation['pivotTable'],
                        $relation['foreignPivotKey'],
                        $relation['relatedPivotKey']
                    );
                }
            }

            $fillableString = implode(",\n        ", $fillable);
            $rulesString = implode(",\n            ", $rules);
            $parentRelationshipsString = implode(",\n            ", $parentRelationships);
            $spouseRelationshipsString = implode(",\n            ", $spouseRelationships);
            $childRelationshipsString = implode(",\n            ", $childRelationships);
            $belongsToMethodsString = implode("\n\n    ", $belongsToMethods);
            $hasManyMethodsString = implode("\n\n    ", $hasManyMethods);
            $belongsToManyMethodsString = implode("\n\n    ", $belongsToManyMethods);

            $phpModel = <<<EOT
<?php

namespace App\Models;

use WizwebBe\OrmApiBaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class $modelName extends OrmApiBaseModel
{
    protected \$table = '$tableName';

    public \$timestamps = false;

    protected \$primaryKey = '$autoIncrement';

    public function parentRelationships()
    {
        return [
            $parentRelationshipsString
        ];
    }

    public function spouseRelationships()
    {
        return [
            $spouseRelationshipsString
        ];
    }

    public function childRelationships()
    {
        return [
            $childRelationshipsString
        ];
    }

    public function rules()
    {
        return [
            $rulesString
        ];
    }

    protected \$fillable = [
        $fillableString
    ];

    $belongsToMethodsString

    $hasManyMethodsString

    $belongsToManyMethodsString
}
EOT;

            $path = app_path("Models/{$modelName}.php");
            File::put($path, $phpModel);

            $this->info("Generated Laravel model for $tableName");
        }
    }




    protected function generateBelongsToMethod($relatedModel, $relationshipName, $fieldName)
    {
        $cleanedName = preg_replace('/[^a-zA-Z]/', '', $relatedModel);

        $segmentationResult = $this->wordSplitter->split($cleanedName);
        $segmentedTableName = $segmentationResult['words'];
        $pascalName = implode('', array_map('ucfirst', $segmentedTableName));

        $relatedModel = Str::singular($pascalName);
        return <<<EOT
    public function $relationshipName(): BelongsTo
    {
        return \$this->belongsTo($relatedModel::class, '$fieldName');
    }
EOT;
    }

    protected function generateHasManyMethod($relatedModel, $relationshipName, $foreignKey)
    {
        $cleanedName = preg_replace('/[^a-zA-Z]/', '', $relatedModel);

        $segmentationResult = $this->wordSplitter->split($cleanedName);
        $segmentedTableName = $segmentationResult['words'];
        $pascalName = implode('', array_map('ucfirst', $segmentedTableName));

        $relatedModel = Str::singular($pascalName);
        return <<<EOT
    public function $relationshipName(): HasMany
    {
        return \$this->hasMany($relatedModel::class, '$foreignKey');
    }
EOT;
    }

    protected function generateBelongsToManyMethod($relatedModel, $relationshipName, $pivotTable, $foreignPivotKey, $relatedPivotKey)
    {
        $cleanedName = preg_replace('/[^a-zA-Z]/', '', $relatedModel);

        $segmentationResult = $this->wordSplitter->split($cleanedName);
        $segmentedTableName = $segmentationResult['words'];
        $pascalName = implode('', array_map('ucfirst', $segmentedTableName));

        $relatedModel = Str::singular($pascalName);
        return <<<EOT
    public function $relationshipName(): BelongsToMany
    {
        return \$this->belongsToMany($relatedModel::class, '$pivotTable', '$foreignPivotKey', '$relatedPivotKey');
    }
EOT;
    }
}
