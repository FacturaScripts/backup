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

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Backup\Controller\Backup;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ZipArchive;

final class BackupControllerTest extends TestCase
{
    /** @var string carpeta de MyFiles creada por los tests de restauración */
    private string $restoreDir = '';

    /** @var string archivo ZIP temporal creado por los tests de restauración */
    private string $zipPath = '';

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

    public function testRestoreFilesFromZipCleansUpAfterExtractError(): void
    {
        // «a» es un archivo, así que «a/b» no se puede extraer y extractTo() falla a mitad
        $this->createZip(['a' => 'x', 'a/b' => 'y']);

        // ignoramos el warning de extractTo() para comprobar la limpieza posterior
        set_error_handler(fn() => true, E_WARNING);
        try {
            $result = $this->restoreFilesFromZip($this->zipPath);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);
        $this->assertDirectoryDoesNotExist(Tools::folder('MyFiles', 'Tmp', 'zip_backup'));
    }

    public function testRestoreFilesFromZipCopiesMissingFolders(): void
    {
        $this->createZip(['MyFiles/' . $this->restoreDir . '/file.txt' => 'restored']);

        $this->assertTrue($this->restoreFilesFromZip($this->zipPath));
        $this->assertSame('restored', file_get_contents(Tools::folder('MyFiles', $this->restoreDir, 'file.txt')));
        $this->assertDirectoryDoesNotExist(Tools::folder('MyFiles', 'Tmp', 'zip_backup'));
    }

    public function testRestoreFilesFromZipKeepsExistingFolders(): void
    {
        Tools::folderCheckOrCreate(Tools::folder('MyFiles', $this->restoreDir));
        file_put_contents(Tools::folder('MyFiles', $this->restoreDir, 'file.txt'), 'current');
        $this->createZip(['MyFiles/' . $this->restoreDir . '/file.txt' => 'restored']);

        $this->assertTrue($this->restoreFilesFromZip($this->zipPath));
        $this->assertSame('current', file_get_contents(Tools::folder('MyFiles', $this->restoreDir, 'file.txt')));
    }

    public function testRestoreFilesFromZipRejectsInvalidZip(): void
    {
        file_put_contents($this->zipPath, 'not a zip file');

        $this->assertFalse($this->restoreFilesFromZip($this->zipPath));
        $this->assertDirectoryDoesNotExist(Tools::folder('MyFiles', 'Tmp', 'zip_backup'));
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

    protected function setUp(): void
    {
        $this->restoreDir = 'BackupControllerTest_' . substr(md5(uniqid('', true)), 0, 8);
        $this->zipPath = Tools::folder('MyFiles', 'Tmp', $this->restoreDir . '.zip');
        Tools::folderCheckOrCreate(Tools::folder('MyFiles', 'Tmp'));
    }

    protected function tearDown(): void
    {
        Tools::folderDelete(Tools::folder('MyFiles', $this->restoreDir));
        Tools::folderDelete(Tools::folder('MyFiles', 'Tmp', 'zip_backup'));
        if (file_exists($this->zipPath)) {
            unlink($this->zipPath);
        }
    }

    private function createZip(array $entries): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
    }

    private function parseBackupFileName(string $fileName): array
    {
        $reflection = new ReflectionClass(Backup::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('parseBackupFileName');
        $method->setAccessible(true);

        return $method->invoke($controller, $fileName);
    }

    private function restoreFilesFromZip(string $zipPath): bool
    {
        $reflection = new ReflectionClass(Backup::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('restoreFilesFromZip');
        $method->setAccessible(true);

        return $method->invoke($controller, $zipPath);
    }
}
