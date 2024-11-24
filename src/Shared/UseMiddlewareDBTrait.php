<?php
namespace Modules\BitesMiddleware\Shared;

trait UseMiddlewareDBTrait
{
    public function getConnectionName(): string
    {
        $this->setConf();
        return 'MiddlewareDB';
    }

    public function setConf()
    {
        $config = config('database.connections.mysql');
        $connection = 'MiddlewareDB';
        $databaseHost = env('MASTER_DB_HOST','127.0.0.1');
        $databaseUser = env('MASTER_DB_USER','root');
        $databasePassword = env('MASTER_DB_PASSWORD','');
        $databaseName = env('MASTER_DB_DATABASE','laravel');
        $config['host'] = $databaseHost;
        $config['database'] = $databaseName;
        $config['username'] = $databaseUser;
        $config['password'] = $databasePassword;
        unset($config['charset']);
        unset($config['collation']);
        config()->set('database.connections.' . $connection, $config);
        config()->set('database.MiddlewareDB', $connection);
        if(!str_contains($this->getTable(),'.')) {
            $this->setTable($databaseName . '.' . $this->getTable());
        }

    }

}