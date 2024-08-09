<?php

namespace QuicklistsOrmApi\Console\Commands\Scaffolding;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use QuicklistsOrmApi\Console\Commands\WordSplitter;

class GenerateVueComponents extends Command
{
    protected $signature = 'generate:vue-components';
    protected $description = 'Generate Vue component files for each model and a router file';
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
            $pluralKebabModel = Str::kebab(Str::plural(implode('-', $segmentedTableName)));

            $listComponentContent = $this->getListComponentContent($modelName, $pluralKebabModel);
            $readComponentContent = $this->getReadComponentContent($modelName, $pluralKebabModel);

            $listPath = base_path("resources/js/views/lists/$pluralKebabModel/{$modelName}List.vue");
            $readPath = base_path("resources/js/views/lists/$pluralKebabModel/{$modelName}Read.vue");

            File::ensureDirectoryExists(dirname($listPath));
            File::put($listPath, $listComponentContent);

            File::ensureDirectoryExists(dirname($readPath));
            File::put($readPath, $readComponentContent);

            $this->info("Generated Vue components for $tableName");

            $routes[] = [
                'model' => $modelName,
                'kebab' => $pluralKebabModel
            ];

            $models[] = [
                'modelName' => $modelName
            ];
        }

        $this->generateRouterFile($routes);
        $this->generateMenuFile($routes);
    }




    protected function getListComponentContent($modelName, $pluralKebabModel)
    {
        return <<<EOT
<template>
    <div>
        <q-card class="q-pa-md q-mt-md">
            <SuperTable
                :user="user"
                :showMap="true"
                :model="superTableModel"
                @update:modelValue="openRecord"
                :displayMapField="false"
            />
        </q-card>
    </div>
</template>

<script>
import { SuperTable } from 'quicklists-vue-orm-ui'
import $modelName from '@/models/$modelName'
import router from '@/router'

export default {
    name: '$modelName-list',
    components: {
        SuperTable,
    },

    computed: {
        superTableModel() {
            return $modelName
        },
        user() {
          let result = {}
          // if (this.\$store.getters['entities/login-sessions/all']()?.[0]){
          //   const id = this.\$store.getters['entities/login-sessions/all']()?.[0]?.\$id
          //   result = this.\$store.state.entities['login-sessions'].data[id]?.user
          // }
          return result
        },
    },
    methods: {
        openRecord(e) {
            //router.push({
            //    name: '/lists/$pluralKebabModel/:rId',
            //    params: {
            //        rId: e.id,
            //    },
            //})
        },
    },
}
</script>
EOT;
    }

    protected function getReadComponentContent($modelName, $pluralKebabModel)
    {
        return <<<EOT
<template>
    <div>
        <q-card class="q-pa-md q-mt-md">
            <SuperRecord
                :model="superRecordModel"
                :id="+\$route.params.rId"
                :displayMapField="true"
                :user="user"
            >
            </SuperRecord>
        </q-card>
    </div>
</template>

<script>
import { SuperRecord } from 'quicklists-vue-orm-ui'
import $modelName from '@/models/$modelName'

export default {
    name: '$modelName-read',
    components: { SuperRecord },
    computed: {
        superRecordModel() {
            return $modelName
        },
        user() {
          let result = {}
          // if (this.\$store.getters['entities/login-sessions/all']()?.[0]){
          //   const id = this.\$store.getters['entities/login-sessions/all']()?.[0]?.\$id
          //   result = this.\$store.state.entities['login-sessions'].data[id]?.user
          // }
          return result
        },
    },
}
</script>

<style scoped></style>
EOT;
    }

    protected function generateRouterFile($routes)
    {
        $routeEntries = array_map(function ($route) {
            return <<<EOT
      {
        path: '/lists/{$route['kebab']}',
        name: '/lists/{$route['kebab']}',
        component: () => import('@/views/lists/{$route['kebab']}/{$route['model']}List.vue'),
      },
      {
        path: '/lists/{$route['kebab']}/:rId',
        name: '/lists/{$route['kebab']}/:rId',
        component: () => import('@/views/lists/{$route['kebab']}/{$route['model']}Read.vue'),
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
                            icon: '',
                            text: '{$titleCaseText}',
                            to: '/lists/{$route['kebab']}',
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
import MenuSystemItem from '@/views/global/MenuSystemItem.vue'
import MyProversAndCustomerAsMenuList from '@/views/global/MyProversAndCustomerAsMenuList.vue'
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
                {
                    icon: '',
                    text: 'Home',
                    to: '/',
                },
                {
                    icon: '',
                    text: 'Lists',
                    subLinks: [
$menuLinksString
                    ],
                },
            ]
        },
    },
    watch: {
        drawer(newVal) {
            this.\$emit('drawer', newVal)
        },
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
