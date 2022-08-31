<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2023 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Fisharebest\Webtrees\Http\Middleware;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\DB\WebtreesSchema;
use Fisharebest\Webtrees\Services\MigrationService;
use Fisharebest\Webtrees\Webtrees;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function array_map;
use function implode;
use function str_contains;
use function usort;

/**
 * Middleware to update the database automatically, after an upgrade.
 */
class UpdateDatabaseSchema implements MiddlewareInterface
{
    private MigrationService $migration_service;

    /**
     * @param MigrationService $migration_service
     */
    public function __construct(MigrationService $migration_service)
    {
        $this->migration_service = $migration_service;
    }

    /**
     * Update the database schema, if necessary.
     *
     * @param ServerRequestInterface  $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->migration_service
            ->updateSchema('\Fisharebest\Webtrees\Schema', 'WT_SCHEMA_VERSION', Webtrees::SCHEMA_VERSION);

        $platform = DB::connection()->getDatabasePlatform();
        $platform->registerDoctrineTypeMapping(dbType: 'enum', doctrineType: 'string');

        $schema_manager = DB::connection()->createSchemaManager();
        $comparator     = $schema_manager->createComparator();
        $source         = $schema_manager->introspectSchema();
        $target         = WebtreesSchema::schema();

        // doctrine/dbal 4.0 does not have the concept of "saveSQL"
        foreach ($source->getTables() as $table) {
            if (!$target->hasTable($table->getName())) {
                $source->dropTable($table->getName());
            }
        }

        $schema_diff    = $comparator->compareSchemas(oldSchema: $source, newSchema: $target);
        $queries        = $platform->getAlterSchemaSQL($schema_diff);

        foreach ($target->getTables() as $table) {
            foreach ($table->getForeignKeys() as $foreign_key) {
                // Cannot do this with self-referencing tables
                if ($table->getName() !== $foreign_key->getForeignTableName()) {
                    $queries[] =
                        'DELETE FROM ' . $table->getName() .
                        ' WHERE (' . implode(', ', $foreign_key->getLocalColumns()) . ')' .
                        ' NOT IN (' .
                        '  SELECT ' . implode(', ', $foreign_key->getForeignColumns()) . ' FROM ' . $foreign_key->getForeignTableName() .
                        '  WHERE ' . implode(' AND ', array_map(static fn (string $col): string => $col . ' IS NOT NULL ', $foreign_key->getLocalColumns())) .
                        ' )';
                }
            }
        }

        // SQLite, PostgreSQL and SQL-Server all support DDL in transactions
        if (DB::connection()->getDriver() instanceof AbstractMySQLDriver) {
            $phase = fn (string $query): int => str_contains($query, 'DROP FOREIGN KEY') ? 1 : (str_contains($query, 'FOREIGN KEY') ? 3 : 2);
            usort($queries, fn (string $x, string $y): int => $phase($x) <=> $phase($y));
        } else {
            $queries = [
                'START TRANSACTION',
                ...$queries,
                'COMMIT',
            ];
        }

        foreach ($queries as $query) {
            if (str_contains($query, 'RENAME INDEX')) {
                echo '<p><i>', $query, ';</i></p>';
            } elseif (str_contains($query, 'CHANGE')) {
                echo '<p><b>', $query, ';</b></p>';
            } else {
                echo '<p>', $query, ';</p>';
            }
            try {
                //$t = microtime(true);
                DB::connection()->executeStatement($query);
                //echo '<p>', (int) (1000.8 * (microtime(true) - $t)), 'ms</p>';
            } catch (Throwable $ex) {
                echo '<p style="color:red">', $ex->getMessage(), ';</p>';
            }
        }

        exit;

        return $handler->handle($request);
    }
}
