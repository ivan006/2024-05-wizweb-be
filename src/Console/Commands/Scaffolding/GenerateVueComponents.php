<?php

namespace QuicklistsOrmApi\Console\Commands\Scaffolding;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use QuicklistsOrmApi\Console\Commands\WordSplitter;

class GenerateVueComponents extends Command
{
    protected $signature = 'generate:ql-ui-c';
    protected $description = 'Generate Vue component files for each model, including controllers and views, and a router file';
    protected $wordSplitter;

    public function __construct()
    {
        parent::__construct();
        $this->wordSplitter = new WordSplitter();
    }

    public function handle()
    {
        $tables = DB::select('SHOW TABLES');
        $routes = [];

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
            $pluralKebabModel = Str::kebab(Str::plural(implode('-', $segmentedTableName)));

            // Generate List and Read components for both views and controllers
            $this->generateListComponents($modelName, $pluralKebabModel);
            $this->generateReadComponents($modelName, $pluralKebabModel);

            $routes[] = [
                'model' => $modelName,
                'kebab' => $pluralKebabModel
            ];
        }

        $this->generateRouterFile($routes);
        $this->generateMenuFile($routes);
    }

    protected function generateListComponents($modelName, $pluralKebabModel)
    {
        // Generate List View Component
        $listViewContent = $this->getListViewComponentContent($modelName, $pluralKebabModel);
        $listViewPath = base_path("resources/js/views/lists/$pluralKebabModel/{$modelName}List.vue");
        File::ensureDirectoryExists(dirname($listViewPath));
        File::put($listViewPath, $listViewContent);

        // Generate List Controller Component
        $listControllerContent = $this->getListControllerComponentContent($modelName, $pluralKebabModel);
        $listControllerPath = base_path("resources/js/controllers/lists/$pluralKebabModel/{$modelName}ListController.vue");
        File::ensureDirectoryExists(dirname($listControllerPath));
        File::put($listControllerPath, $listControllerContent);

        $this->info("Generated List components (view and controller) for $modelName");
    }

    protected function generateReadComponents($modelName, $pluralKebabModel)
    {
        // Generate Read View Component
        $readViewContent = $this->getReadViewComponentContent($modelName, $pluralKebabModel);
        $readViewPath = base_path("resources/js/views/lists/$pluralKebabModel/{$modelName}Read.vue");
        File::ensureDirectoryExists(dirname($readViewPath));
        File::put($readViewPath, $readViewContent);

        // Generate Read Controller Component
        $readControllerContent = $this->getReadControllerComponentContent($modelName, $pluralKebabModel);
        $readControllerPath = base_path("resources/js/controllers/lists/$pluralKebabModel/{$modelName}ReadController.vue");
        File::ensureDirectoryExists(dirname($readControllerPath));
        File::put($readControllerPath, $readControllerContent);

        $this->info("Generated Read components (view and controller) for $modelName");
    }

    protected function getListViewComponentContent($modelName, $pluralKebabModel)
    {
        return <<<EOT
<template>
    <SuperTable
        :showMap="true"
        :model="superTableModel"
        @clickRow="openRecord"
        :displayMapField="false"
        :parentKeyValuePair="parentKeyValuePair"
        :fetchFlags="fetchFlags"
    />
</template>

<script>
import { SuperTable } from 'quicklists-vue-orm-ui'
import $modelName from 'src/models/orm-api/$modelName'

export default {
    name: '$modelName-list',
    components: {
        SuperTable,
    },

    props: {
        parentKeyValuePair: {
            type: Object,
            default: () => ({})
        },
        fetchFlags: {
            type: Object,
            default: () => ({})
        }
    },

    computed: {
        superTableModel() {
            return $modelName
        },
    },
    methods: {
        openRecord(pVal, item, router) {
            router.push({
                name: '/lists/$pluralKebabModel/:rId/:rName',
                params: {
                    rId: pVal,
                    rName: pVal,
                },
            })
        },
    },
}
</script>
EOT;
    }

    protected function getReadViewComponentContent($modelName, $pluralKebabModel)
    {
        return <<<EOT
<template>
    <SuperRecord
        :model="superRecordModel"
        :id="+\$route.params.rId"
        :displayMapField="true"
        @initialLoadHappened="\$emit('initialLoadHappened')"
    >
    </SuperRecord>
</template>

<script>
import { SuperRecord } from 'quicklists-vue-orm-ui'
import $modelName from 'src/models/orm-api/$modelName'

export default {
    name: '$modelName-read',
    components: { SuperRecord },
    computed: {
        superRecordModel() {
            return $modelName
        },
    },
}
</script>

<style scoped></style>
EOT;
    }

    protected function getListControllerComponentContent($modelName, $pluralKebabModel)
    {
        $modelNameList = Str::camel($modelName) . 'List';

        return <<<EOT
<template>

    <div>
        <q-card class="q-pa-md q-mt-md">
            <$modelNameList
                :parentKeyValuePair="parentKeyValuePair"
                :fetchFlags="fetchFlags"
            />
        </q-card>
    </div>
</template>

<script>
import $modelNameList from 'src/views/lists/$pluralKebabModel/{$modelName}List.vue'

export default {
    name: '$modelName-list-controller',
    components: {
        $modelNameList,
    },

    data() {
        return {
            parentKeyValuePair: {},
            fetchFlags: {}
        }
    },
}
</script>
EOT;
    }

    protected function getReadControllerComponentContent($modelName, $pluralKebabModel)
    {
        $modelNameRead = Str::camel($modelName) . 'Read';

        return <<<EOT
<template>

    <div>
        <q-card class="q-mb-md">
            <$modelNameRead :id="id" />
        </q-card>
    </div>
</template>

<script>
import $modelNameRead from 'src/views/lists/$pluralKebabModel/{$modelName}Read.vue'

export default {
    name: '$modelName-read-controller',
    components: {
        $modelNameRead,
    },

    data() {
        return {
            id: +this.\$route.params.rId
        }
    },
}
</script>
EOT;
    }

    protected function generateRouterFile($routes)
    {
        $routeEntries = array_map(function ($route) {
            $pluralModel = Str::plural($route['model']);
            return <<<EOT
      {
        path: '/lists/{$route['kebab']}',
        name: '/lists/{$route['kebab']}',
        component: () => import('src/controllers/lists/{$route['kebab']}/{$route['model']}ListController.vue'),
        meta: {
          breadcrumbName: '{$pluralModel}',
          breadcrumbParentName: '',
        },
      },
      {
        path: '/lists/{$route['kebab']}/:rId/:rName',
        name: '/lists/{$route['kebab']}/:rId/:rName',
        component: () => import('src/controllers/lists/{$route['kebab']}/{$route['model']}ReadController.vue'),
        meta: {
          breadcrumbName: ':rName',
          breadcrumbParentName: '/lists/{$route['kebab']}',
        },
      }
EOT;
        }, $routes);

        $routesString = implode(",\n", $routeEntries);

        $routerFileContent = <<<EOT
import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: '/',
    component: () => import('../views/MenuView.vue'),
    children: [
$routesString
    ],
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

export default router
EOT;

        $routerFilePath = base_path('resources/js/router/index.js');
        File::ensureDirectoryExists(dirname($routerFilePath));
        File::put($routerFilePath, $routerFileContent);

        $this->info('Generated router file');
    }

    protected function generateMenuFile($routes)
    {
        $menuLinks = array_map(function ($route) {
            $titleCaseText = ucwords(str_replace('-', ' ', Str::plural($route['kebab'])));
            return <<<EOT
                {
                    title: '{$titleCaseText}',
                    route: '/lists/{$route['kebab']}',
                }
EOT;
        }, $routes);

        $menuLinksString = implode(",\n", $menuLinks);

        $menuFileContent = <<<EOT
<template>
    <div>
      <baseline-layout>
        <template v-slot:sidebar>
          <v-list nav density="compact">
            <div v-for="(link, i) in links" :key="i">
              <template v-if="!link.subLinks">
                <MenuSystemItem :link="link" />
              </template>
              <v-list-group v-else :key="link.text" no-action :prepend-icon="link.icon" :value="false">
                <template v-slot:activator="{props, isOpen}">
                  <v-list-item v-bind="props" :key="link.text" :title="link.text"></v-list-item>
                </template>
                <div class="ml-2 pl-2" style="border-left: solid 1px Gainsboro">
                  <template v-if="typeof link.subLinks == 'string'">
                    <component :is="link.subLinks"> </component>
                  </template>
                  <template v-else>
                    <template v-for="sublink in link.subLinks" :key="sublink.text">
                      <MenuSystemItem :link="sublink" />
                    </template>
                  </template>
                </div>
              </v-list-group>
            </div>
          </v-list>
        </template>
        <template v-slot:header>
          <v-spacer></v-spacer>
        </template>
        <template v-slot:main>
          <slot name="main"></slot>
        </template>
      </baseline-layout>
    </div>
</template>

<script>
import VueCookies from 'vue-cookies'
import MenuSystemItem from 'src/pages/global/MenuSystemItem.vue'
import MyProversAndCustomerAsMenuList from 'src/pages/global/MyProversAndCustomerAsMenuList.vue'
import BaselineLayout from "@/layouts/baselineLayout.vue";

export default {
    name: 'MenuSystem',
    components: {
        BaselineLayout,
        MyProversAndCustomerAsMenuList,
        MenuSystemItem,
    },
    data() {
        return {
            drawer: false,
            appTitle: 'Insert title here',
        }
    },
    methods: {
        logout() {
            VueCookies.remove('VITE_AUTH')
        },
    },
    computed: {
        links() {
            return [
$menuLinksString
            ]
        },
    },
    watch: {
        drawer(newVal) {
            this.\$emit('drawer', newVal)
        }
    },
    mounted() {
    },
}
</script>

<style scoped></style>
EOT;

        $menuFilePath = base_path('resources/js/views/MenuView.vue');
        File::ensureDirectoryExists(dirname($menuFilePath));
        File::put($menuFilePath, $menuFileContent);

        $this->info('Generated menu file');
    }
}

