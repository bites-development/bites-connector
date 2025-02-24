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
            \App\Models\Posts::class,
        ],
    ]
];
