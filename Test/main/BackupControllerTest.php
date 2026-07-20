<?php
/**
 * This file is part of Backup plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Plugins\Backup\Controller\Backup;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BackupControllerTest extends TestCase
{
    public function testParseBackupFileNameAcceptsValidNames(): void
    {
        $this->assertSame([
            'day' => '2026-07-20',
            'extension' => 'sql',
            'key' => '2026-07-20_14-30-59',
        ], $this->parseBackupFileName('2026-07-20_14-30-59.sql'));

        $this->assertSame([
            'day' => '2024-02-29',
            'extension' => 'zip',
            'key' => '2024-02-29_00-00-00',
        ], $this->parseBackupFileName('2024-02-29_00-00-00.ZIP'));
    }

    public function testParseBackupFileNameRejectsInvalidNames(): void
    {
        $invalidNames = [
            '.backupauto_2026-07-20.sql',
            '2026-02-29_14-30-59.sql',
            '2026-07-20_24-00-00.sql',
            '2026-07-20_14-30-59.txt',
        ];

        foreach ($invalidNames as $fileName) {
            $this->assertSame([], $this->parseBackupFileName($fileName));
        }
    }

    public function testSetConfigConstantUpdatesExistingDefinition(): void
    {
        $config = "<?php\ndefine(\"FS_MYSQL_CHARSET\", \"utf8\");\n";

        $result = $this->setConfigConstant($config, 'FS_MYSQL_CHARSET', 'utf8mb4');

        $this->assertStringContainsString("define('FS_MYSQL_CHARSET', 'utf8mb4');", $result);
        $this->assertStringNotContainsString('utf8\");', $result);
    }

    public function testSetConfigConstantAddsMissingDefinition(): void
    {
        $config = "<?php\ndefine('FS_DB_TYPE', 'mysql');\n";

        $result = $this->setConfigConstant($config, 'FS_MYSQL_CHARSET', 'utf8mb4');

        $this->assertSame(
            $config . "define('FS_MYSQL_CHARSET', 'utf8mb4');\n",
            $result
        );
    }

    public function testSetConfigConstantAddsDefinitionBeforeClosingTag(): void
    {
        $config = "<?php\ndefine('FS_DB_TYPE', 'mysql');\n?>";

        $result = $this->setConfigConstant($config, 'FS_MYSQL_COLLATE', 'utf8mb4_unicode_520_ci');

        $this->assertSame(
            "<?php\ndefine('FS_DB_TYPE', 'mysql');\ndefine('FS_MYSQL_COLLATE', 'utf8mb4_unicode_520_ci');\n?>",
            $result
        );
    }

    private function setConfigConstant(string $config, string $name, string $value): string
    {
        $reflection = new ReflectionClass(Backup::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('setConfigConstant');
        $method->setAccessible(true);

        return $method->invoke($controller, $config, $name, $value);
    }

    private function parseBackupFileName(string $fileName): array
    {
        $reflection = new ReflectionClass(Backup::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('parseBackupFileName');
        $method->setAccessible(true);

        return $method->invoke($controller, $fileName);
    }
}
