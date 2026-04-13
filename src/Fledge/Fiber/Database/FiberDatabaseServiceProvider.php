<?php

namespace Fledge\Fiber\Database;

use Fledge\Fiber\Database\Connections\FledgeMariaDbConnection;
use Fledge\Fiber\Database\Connections\FledgeMySqlConnection;
use Fledge\Fiber\Database\Connections\FledgePostgresConnection;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class FiberDatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConnectors();
        $this->registerConnectionResolvers();
    }

    /**
     * Register the Fledge database connectors.
     *
     * These are resolved by ConnectionFactory::createConnector() when it
     * checks the container for "db.connector.{driver}" before falling
     * back to the built-in match statement.
     */
    protected function registerConnectors(): void
    {
        $this->app->bind('db.connector.fledge-mysql', fn () => new FledgeMySqlConnector);
        $this->app->bind('db.connector.fledge-mariadb', fn () => new FledgeMySqlConnector);
        $this->app->bind('db.connector.fledge-pgsql', fn () => new FledgePostgresConnector);
    }

    /**
     * Register the Fledge Async connection resolvers.
     *
     * These are checked by ConnectionFactory::createConnection() via
     * Connection::getResolver() before falling back to the built-in
     * match statement.
     */
    protected function registerConnectionResolvers(): void
    {
        Connection::resolverFor('fledge-mysql', fn ($pdo, $database, $prefix, $config) => new FledgeMySqlConnection($pdo, $database, $prefix, $config));

        Connection::resolverFor('fledge-mariadb', fn ($pdo, $database, $prefix, $config) => new FledgeMariaDbConnection($pdo, $database, $prefix, $config));

        Connection::resolverFor('fledge-pgsql', fn ($pdo, $database, $prefix, $config) => new FledgePostgresConnection($pdo, $database, $prefix, $config));
    }
}
