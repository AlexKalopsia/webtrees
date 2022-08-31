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

namespace Fisharebest\Webtrees\DB\Drivers;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\PDO\Connection;
use Fisharebest\Webtrees\DB;
use PDO;
use SensitiveParameter;

use function version_compare;

/**
 * Driver for MySQL
 */
class MySQLDriver extends AbstractMySQLDriver implements DriverInterface
{
    use DriverTrait;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        return new Connection($this->pdo);
    }

    public function collationASCII(): string
    {
        return 'ascii_bin';
    }

    public function collationUTF8(): string
    {
        $version = DB::connection()->getServerVersion();

        // MySQL
        if (version_compare($version, '5.7.7') >= 0 && version_compare($version, '10.0.0') < 0) {
            return 'utf8mb4_unicode_ci';
        }

        // MariaDB
        if (version_compare($version, '10.2.4') >= 0) {
            return 'utf8mb4_unicode_ci';
        }

        return 'utf8mb3_unicode_ci';
    }
}
