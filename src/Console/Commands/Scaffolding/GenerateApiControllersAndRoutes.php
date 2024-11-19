<?php

namespace WizwebBe\Console\Commands\Scaffolding;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use WizwebBe\Console\Commands\WordSplitter;

class GenerateApiControllersAndRoutes extends Command
{
    protected $signature = 'generate:ql-api-c';
    protected $description = 'Generate API controllers and routes from database schema';
    protected $wordSplitter;

    public function __construct()
    {
        parent::__construct();
        $this->wordSplitter = new WordSplitter();
    }

    public function handle()
    {
        $tables = DB::select('SHOW TABLES');
        $report = [];

        foreach ($tables as $table) {
            try {
                $tableArray = get_object_vars($table);
                $tableName = reset($tableArray);
                $cleanedTableName = preg_replace('/[^a-zA-Z]/', '', $tableName);
                $this->info("Processing table: $tableName (cleaned: $cleanedTableName)");

                $this->info("Splitting table name: $cleanedTableName");
                $segmentationResult = $this->wordSplitter->split($cleanedTableName);

                $segmentedTableName = $segmentationResult['words'];
                $this->info("Segmented table name: " . implode(' ', $segmentedTableName));

                $pascalTableName = implode('', array_map(function ($word) {
                    return ucfirst(strtolower($word));
                }, $segmentedTableName));
                $this->info("Pascal table name: $pascalTableName");

                $modelName = Str::singular($pascalTableName);
                $controllerName = $modelName . 'Controller';
                $itemNameSingular = Str::title(Str::replace('_', ' ', Str::singular($tableName)));

                $this->info("Generating controller content for: $controllerName");
                $controllerContent = $this->generateControllerContent($modelName, $controllerName, $itemNameSingular);

                $this->info("Generating route content for: $controllerName");
                $routeContent = $this->generateRouteContent($segmentedTableName, $controllerName);

                $this->info("Saving controller file: $controllerName");
                $controllerPath = app_path("Http/Controllers/Api/{$controllerName}.php");
                File::put($controllerPath, $controllerContent);

                $this->info("Appending to route file for: $controllerName");
                $routePath = base_path("routes/api.php");
                File::append($routePath, $routeContent);

                $this->info("Generated API controller and route for $tableName");

                $report[$tableName] = $segmentationResult['log'];
            } catch (\Exception $e) {
                $this->error("Failed to process table: $tableName. Error: " . $e->getMessage());
            }
        }

        $this->generateReport($report);
    }

    protected function generateControllerContent($modelName, $controllerName, $itemNameSingular)
    {
        $this->info("Creating controller content for model: $modelName");

        return <<<EOT
<?php

namespace App\Http\Controllers\Api;

use WizwebBe\OrmApi;
use App\Http\Controllers\Controller;
use App\Models\\$modelName;
use Illuminate\Http\Request;

class $controllerName extends Controller
{
    protected \$itemNameSingular = "$itemNameSingular";
    protected \$model;

    public function __construct()
    {
        \$this->model = new $modelName();
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request \$request)
    {
        \$result = OrmApi::fetchAllWithFullQueryExposure(\$this->model, \$request, \$this->itemNameSingular);
        return response()->json(\$result['res'], \$result['code']);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request \$request)
    {
        \$result = OrmApi::createItem(
            \$request,
            \$this->model,
            \$this->itemNameSingular
        );
        return response()->json(\$result['res'], \$result['code']);
    }

    /**
     * Display the specified resource.
     */
    public function show(string \$id)
    {
        \$result = OrmApi::fetchByIdWithFullQueryExposure(\$this->model, \$id, \$this->itemNameSingular);
        return response()->json(\$result['res'], \$result['code']);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request \$request, string \$id)
    {
        \$result = OrmApi::updateItem(
            \$request,
            \$this->model,
            \$id,
            \$this->itemNameSingular
        );
        return response()->json(\$result['res'], \$result['code']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request \$request, string \$id)
    {
        \$result = OrmApi::deleteItem(
            \$this->model,
            \$id,
            \$this->itemNameSingular,
            \$request
        );
        return response()->json(\$result['res'], \$result['code']);
    }
}
EOT;
    }

    protected function generateRouteContent($segmentedTableName, $controllerName)
    {
        $routeName = Str::plural(Str::kebab(implode('-', $segmentedTableName)));
        return <<<EOT

// API routes for $routeName
Route::apiResource('$routeName', \\App\\Http\\Controllers\\Api\\$controllerName::class);
EOT;
    }

    protected function generateReport($report)
    {
        $reportPath = storage_path('app/segmentation_report.txt');
        $reportContent = '';

        foreach ($report as $word => $log) {
            $reportContent .= "Word: $word\n";
            foreach ($log as $entry) {
                $reportContent .= "  Segment: {$entry['segment']} at position {$entry['position']}\n";
            }
            $reportContent .= "\n";
        }

        file_put_contents($reportPath, $reportContent);
        $this->info("Segmentation report generated at $reportPath");
    }
}
