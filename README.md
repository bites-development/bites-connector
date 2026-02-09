## Composer Require
composer require bites-development/bites-connector
## Run Vendor
php artisan vendor:publish --tag="bites"

## Add Middleware DB Config at env file
MASTER_DB_HOST

MASTER_DB_USER

MASTER_DB_PASSWORD

MASTER_DB_DATABASE

## Plan Schema Sync (Connector -> Middleware)
Export plan form schema/options from the app code and sync it to middleware mapper storage:

```bash
php artisan bites:sync-plan-schema
```

Useful flags:

```bash
php artisan bites:sync-plan-schema --dry-run
php artisan bites:sync-plan-schema --table=subscriptions --app-prefix=mart
```

Required env (recommended):

```env
MIDDLEWARE_SERVER=middleware-no.test
BITES_APP_PREFIX=mart
BITES_MIDDLEWARE_API_TOKEN=your-shared-token
```

Optional env:

```env
BITES_PLAN_SCHEMA_SYNC_ENABLED=true
BITES_PLAN_SCHEMA_SYNC_ENDPOINT=/api/webhooks/plans/schema-sync
BITES_PLAN_SCHEMA_SYNC_TIMEOUT=20
BITES_PLAN_SCHEMA_MAX_SNIPPETS=20
BITES_PLAN_SCHEMA_MAX_SNIPPET_CHARS=12000
```
