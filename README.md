##Run This To Terminal03
composer config repositories.bites-development vcs https://github.com/bites-development/bites-connector.git
##Composer Require
composer require bites-development/bites-connector:dev-main
###Run Vendor
php artisan vendor:publish --tag="bites"
