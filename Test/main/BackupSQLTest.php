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
use FacturaScripts\Plugins\Backup\Lib\BackupSQL;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

/**
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class BackupSQLTest extends TestCase
{
    use LogErrorsTrait;

    /** @var array<string> */
    private array $filesBeforeTest = [];

    protected function setUp(): void
    {
        // capturamos los archivos .sql existentes antes de cada test
        $folder = Tools::folder('MyFiles', 'Backups');
        if (is_dir($folder)) {
            $this->filesBeforeTest = glob($folder . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        }
    }

    public function testGenerateReturnsFalseOnNonMySQL(): void
    {
        if (Tools::config('db_type') === 'mysql') {
            $this->markTestSkipped('Test solo aplica cuando db_type != mysql');
        }

        $result = BackupSQL::generate('test');
        $this->assertFalse($result);
    }

    public function testGenerateCreatesFile(): void
    {
        if (Tools::config('db_type') !== 'mysql') {
            $this->markTestSkipped('BackupSQL solo funciona con MySQL');
        }

        $result = BackupSQL::generate('test');
        $this->assertTrue($result);

        // verificamos que se ha creado un archivo .sql nuevo
        $folder = Tools::folder('MyFiles', 'Backups');
        $filesAfter = glob($folder . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $newFiles = array_diff($filesAfter, $this->filesBeforeTest);

        $this->assertNotEmpty($newFiles, 'No se ha creado ningún archivo .sql');

        // tomamos el primero nuevo
        $newFile = reset($newFiles);

        // verificamos que sigue el patrón de nombre YYYY-MM-DD_HH-MM-SS.sql
        $this->assertMatchesRegularExpression(
            '/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/',
            basename($newFile),
            'El nombre del archivo .sql no sigue el formato esperado YYYY-MM-DD_HH-MM-SS.sql'
        );

        // verificamos que el archivo no está vacío
        $this->assertGreaterThan(0, filesize($newFile), 'El archivo .sql generado está vacío');
    }

    public function testGenerateCreatesBackupsFolder(): void
    {
        if (Tools::config('db_type') !== 'mysql') {
            $this->markTestSkipped('BackupSQL solo funciona con MySQL');
        }

        BackupSQL::generate('test');

        $folder = Tools::folder('MyFiles', 'Backups');
        $this->assertDirectoryExists($folder, 'La carpeta MyFiles/Backups no existe tras ejecutar generate()');
    }

    protected function tearDown(): void
    {
        // eliminamos los archivos .sql creados durante el test
        $folder = Tools::folder('MyFiles', 'Backups');
        if (is_dir($folder)) {
            $filesAfter = glob($folder . DIRECTORY_SEPARATOR . '*.sql') ?: [];
            $newFiles = array_diff($filesAfter, $this->filesBeforeTest);
            foreach ($newFiles as $file) {
                unlink($file);
            }
        }

        $this->logErrors();
    }
}
