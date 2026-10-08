<?php

namespace Fledge\Fiber\Database;

use Fledge\Fiber\Database\Connections\FledgeMariaDbConnection;
use Fledge\Fiber\Database\Connections\FledgeMySqlConnection;
use Fledge\Fiber\Database\Connections\FledgePostgresConnection;
use Fledge\Fiber\Database\Connectors\FledgeMariaDbConnector;
use Fledge\Fiber\Database\Connectors\FledgeMySqlConnector;
use Fledge\Fiber\Database\Connectors\FledgePostgresConnector;
use Fledge\Fiber\Database\Native\FiberAwareTransactionsManager;
use Fledge\Fiber\Database\Native\NativeMariaDbConnection;
use Fledge\Fiber\Database\Native\NativeMariaDbConnector;
use Fledge\Fiber\Database\Native\NativeMySqlConnection;
use Fledge\Fiber\Database\Native\NativeMySqlConnector;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\ServiceProvider;

class FiberDatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConnectors();
        $this->registerConnectionResolvers();
        $this->registerFiberAwareTransactionsManager();
    }

    /**
     * Keep transaction callbacks per fiber for the native drivers.
     *
     * The native connections track transaction levels per fiber. Wrapping the
     * shared `db.transactions` manager makes DB::afterCommit(), after-commit
     * jobs, broadcasts and Eloquent events follow the same per-fiber state.
     * The stock binding is always wrapped, so a native connection configured
     * after boot is covered too: the wrapper only routes per fiber for
     * connections that registered themselves as fiber-scoped and otherwise
     * delegates every call to the original manager unchanged.
     *
     * Only Laravel's own manager class is wrapped. An app's own subclass is
     * left as bound (its extra methods and instanceof checks keep working);
     * native connections still wrap it per connection in
     * setTransactionManager(). The testing manager RefreshDatabase and
     * DatabaseTransactions install goes through Container::instance(), which
     * never runs extenders, so it is not affected either.
     */
    protected function registerFiberAwareTransactionsManager(): void
    {
        $this->app->extend('db.transactions', fn ($manager) => is_object($manager) && $manager::class === DatabaseTransactionsManager::class
            ? FiberAwareTransactionsManager::wrap($manager)
            : $manager);
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
        $this->app->bind('db.connector.fledge-mariadb', fn () => new FledgeMariaDbConnector);
        $this->app->bind('db.connector.fledge-pgsql', fn () => new FledgePostgresConnector);
        $this->app->bind('db.connector.fledge-mysql-native', fn () => new NativeMySqlConnector);
        $this->app->bind('db.connector.fledge-mariadb-native', fn () => new NativeMariaDbConnector);
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

        Connection::resolverFor('fledge-mysql-native', fn ($pdo, $database, $prefix, $config) => new NativeMySqlConnection($pdo, $database, $prefix, $config));

        Connection::resolverFor('fledge-mariadb-native', fn ($pdo, $database, $prefix, $config) => new NativeMariaDbConnection($pdo, $database, $prefix, $config));
    }
}
