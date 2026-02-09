<?php

use Illuminate\Support\Str;
use Modules\BitesMiddleware\Middleware\CheckAuthUser;
use Modules\BitesMiddleware\Models\Workspace;
use Modules\BitesMiddleware\Models\WorkspaceMasterDB;
use Modules\BitesMiddleware\Models\WorkspaceModel;
use Modules\BitesMiddleware\Models\WorkspaceUser;

return [
    'name' => 'Bite Middleware',
    'CHECK_AUTH_PATH' => CheckAuthUser::class,
    'app_prefix_defaults' => [
        'inbusiness' => 'dash',
        'dash' => 'dash',
        'incallz' => 'incontact',
        'incontact' => 'incontact',
        'incontactai' => 'incontact',
        'incontacts' => 'incontact',
        'ineventz' => 'ineventz',
        'infilez' => 'infilez',
        'inweb' => 'inweb',
        'tabletrack' => 'resturant',
        'resturant' => 'resturant',
        'jobs' => 'jobs',
        'injobs' => 'jobs',
        'mart' => 'mart',
    ],

    // Enable/disable SNS publishing for workspace events
    'SNS_ENABLED' => env('BITES_SNS_ENABLED', false),

    // Push Notification API Configuration
    'push' => [
        'base_url' => env('BITES_PUSH_API_URL', 'https://api.bites.com'),
        'api_key' => env('BITES_PUSH_API_KEY'),
    ],
    //'IGNORE_WORKSPACE_ROLES' => ['admin', 'manager'],
    //'IGNORE_WORKSPACE_ROUTES' => ['api/v1/dashboard/admin/*'],
    'WORKSPACE' => [
        'MAIN_WORKSPACE_CLASS' => Workspace::class,
        'MASTER_WORKSPACE_CLASS' => WorkspaceMasterDB::class,
        'MASTER_MORPH_WORKSPACE_NAME' => WorkspaceModel::class,
        'WORKSPACE_USER' => [
            'USER_COLUMN' => 'b_user_id',
            'WORKSPACE_COLUMN' => 'workspace_id',
            'MODEL' => WorkspaceUser::class
        ],
        'WORKSPACE_COLUMN_MAP' => [
            //'MASTER_DB_KEY' => 'PROJECT_TABLE_KEY'
            'name' => 'name',
            //'slug' => 'slug',
            'slug' => function ($workspace) {
                return  $workspace->slug = Str::slug($workspace->name); // Please Consider Make The Equal Mapper Here
            },
            //'status' => 'slug,1', // 1 considered as default value
            //'status' => // You can use it as array
            // [
            //    'key' => 'status',
            //    'default' => 1
            //],
            'status' => function ($workspace) {
                return  $workspace->status ?? 1;
            },
            'b_user_id' => function ($workspace) {
                return  auth()->user()?->id;
            },
        ],
        'TARGET_WORKSPACE_COLUMN_MAP' => [
            'name' => 'name',
            'slug' => 'slug',
        ],
        'FILTERED_MODULES'=>[
            // \App\Models\Posts::class,
        ],
    ],
    'plan_webhooks' => [
        'enabled' => env('BITES_PLAN_WEBHOOKS_ENABLED', true),
        'app_model' => env('BITES_PLAN_APP_MODEL', 'Bites\Modules\Role\Models\App'),
        'plan_model' => env('BITES_PLAN_MODEL'),
        'webhook_path' => env('BITES_PLAN_WEBHOOK_PATH', '/api/webhooks/plan-changed'),
        'timeout' => env('BITES_PLAN_WEBHOOK_TIMEOUT', 10),
        'topic_column' => env('BITES_PLAN_TOPIC_COLUMN', 'prefix'),
        'slug_column' => env('BITES_PLAN_SLUG_COLUMN', 'slug'),
        'name_column' => env('BITES_PLAN_NAME_COLUMN', 'name'),
        'metadata_column' => env('BITES_PLAN_METADATA_COLUMN', 'metadata'),
        'status_column' => env('BITES_PLAN_STATUS_COLUMN', 'active'),
    ],
    'plan_schema_sync' => [
        'enabled' => env('BITES_PLAN_SCHEMA_SYNC_ENABLED', true),
        'endpoint' => env('BITES_PLAN_SCHEMA_SYNC_ENDPOINT', '/api/webhooks/plans/schema-sync'),
        'timeout' => env('BITES_PLAN_SCHEMA_SYNC_TIMEOUT', 20),
        'token' => env('BITES_MIDDLEWARE_API_TOKEN', env('BITES_CONNECTOR_SYNC_TOKEN', '')),
        'max_snippets' => env('BITES_PLAN_SCHEMA_MAX_SNIPPETS', 20),
        'max_snippet_chars' => env('BITES_PLAN_SCHEMA_MAX_SNIPPET_CHARS', 12000),
    ],
];
