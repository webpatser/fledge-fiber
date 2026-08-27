<?php

namespace Fledge\Fiber\Database\Connectors;

/**
 * Connector for Fledge-based non-blocking MariaDB connections.
 *
 * Identical to the MySQL connector except for the strict sql_mode, which
 * never includes NO_AUTO_CREATE_USER (removed in MariaDB long before the
 * MySQL 8.0.11 cutoff) and ignores the version config entirely.
 */
class FledgeMariaDbConnector extends FledgeMySqlConnector
{
    protected function getSqlMode(array $config): ?string
    {
        if (isset($config['modes'])) {
            return implode(',', $config['modes']);
        }

        if (! isset($config['strict'])) {
            return null;
        }

        if (! $config['strict']) {
            return 'NO_ENGINE_SUBSTITUTION';
        }

        return 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
    }
}
