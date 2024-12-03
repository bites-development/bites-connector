## Run This To Terminal
composer config repositories.bites-development vcs https://github.com/bites-development/bites-connector.git
## Composer Require
composer require bites-development/bites-connector:dev-main
## Run Vendor
php artisan vendor:publish --tag="bites"

## Add Middleware DB Config at env file
MASTER_DB_HOST

MASTER_DB_USER

MASTER_DB_PASSWORD

MASTER_DB_DATABASE
