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
use FacturaScripts\Plugins\Backup\Lib\BackupFile;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class BackupFileTest extends TestCase
{
    use LogErrorsTrait;

    private static string $zipFile = '';

    /** @var array<string> */
    private static array $filesBeforeClass = [];

    public static function setUpBeforeClass(): void
    {
        // capturamos los archivos .zip existentes antes de generar el backup
        $folder = Tools::folder('MyFiles', 'Backups');
        if (is_dir($folder)) {
            self::$filesBeforeClass = glob($folder . DIRECTORY_SEPARATOR . '*.zip') ?: [];
        }

        // generamos el ZIP una sola vez para todos los tests de la clase
        BackupFile::generate('test');

        // localizamos el archivo nuevo generado
        $filesAfter = glob($folder . DIRECTORY_SEPARATOR . '*.zip') ?: [];
        $newFiles = array_diff($filesAfter, self::$filesBeforeClass);
        if (!empty($newFiles)) {
            self::$zipFile = reset($newFiles);
        }
    }

    public static function tearDownAfterClass(): void
    {
        // eliminamos el ZIP generado durante los tests
        if (!empty(self::$zipFile) && file_exists(self::$zipFile)) {
            unlink(self::$zipFile);
        }
    }

    public function testGenerateCreatesZipFile(): void
    {
        $this->assertNotEmpty(self::$zipFile, 'No se generó ningún archivo ZIP');
        $this->assertFileExists(self::$zipFile, 'El archivo ZIP generado no existe en disco');
    }

    public function testZipFileNameFormat(): void
    {
        $this->assertNotEmpty(self::$zipFile, 'No se generó ningún archivo ZIP');

        // verificamos que el nombre sigue el patrón YYYY-MM-DD_HH-MM-SS.zip
        $this->assertMatchesRegularExpression(
            '/\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/',
            basename(self::$zipFile),
            'El nombre del archivo ZIP no sigue el formato esperado YYYY-MM-DD_HH-MM-SS.zip'
        );
    }

    public function testZipFileNotEmpty(): void
    {
        $this->assertNotEmpty(self::$zipFile, 'No se generó ningún archivo ZIP');
        $this->assertGreaterThan(0, filesize(self::$zipFile), 'El archivo ZIP generado está vacío');
    }

    public function testGenerateCreatesBackupsFolder(): void
    {
        $folder = Tools::folder('MyFiles', 'Backups');
        $this->assertDirectoryExists($folder, 'La carpeta MyFiles/Backups no existe tras ejecutar generate()');
    }

    public function testZipExcludesBackupsFolder(): void
    {
        $this->assertNotEmpty(self::$zipFile, 'No se generó ningún archivo ZIP');

        $zip = new ZipArchive();
        $this->assertNotFalse($zip->open(self::$zipFile), 'No se pudo abrir el archivo ZIP');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertStringStartsNotWith(
                'MyFiles/Backups',
                $name,
                "El ZIP contiene una entrada de la carpeta excluida MyFiles/Backups: $name"
            );
        }

        $zip->close();
    }

    public function testZipExcludesCacheFolder(): void
    {
        $this->assertNotEmpty(self::$zipFile, 'No se generó ningún archivo ZIP');

        $zip = new ZipArchive();
        $this->assertNotFalse($zip->open(self::$zipFile), 'No se pudo abrir el archivo ZIP');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertStringStartsNotWith(
                'MyFiles/Cache',
                $name,
                "El ZIP contiene una entrada de la carpeta excluida MyFiles/Cache: $name"
            );
        }

        $zip->close();
    }

    public function testZipExcludesTmpFolder(): void
    {
        $this->assertNotEmpty(self::$zipFile, 'No se generó ningún archivo ZIP');

        $zip = new ZipArchive();
        $this->assertNotFalse($zip->open(self::$zipFile), 'No se pudo abrir el archivo ZIP');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertStringStartsNotWith(
                'MyFiles/Tmp',
                $name,
                "El ZIP contiene una entrada de la carpeta excluida MyFiles/Tmp: $name"
            );
        }

        $zip->close();
    }

    public function testZipExcludesDinamicFolder(): void
    {
        $this->assertNotEmpty(self::$zipFile, 'No se generó ningún archivo ZIP');

        $zip = new ZipArchive();
        $this->assertNotFalse($zip->open(self::$zipFile), 'No se pudo abrir el archivo ZIP');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertStringStartsNotWith(
                'Dinamic/',
                $name,
                "El ZIP contiene una entrada de la carpeta excluida Dinamic: $name"
            );
        }

        $zip->close();
    }

    public function testZipExcludesZipFiles(): void
    {
        $this->assertNotEmpty(self::$zipFile, 'No se generó ningún archivo ZIP');

        $zip = new ZipArchive();
        $this->assertNotFalse($zip->open(self::$zipFile), 'No se pudo abrir el archivo ZIP');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertStringEndsNotWith(
                '.zip',
                $name,
                "El ZIP contiene un archivo .zip en su interior: $name"
            );
        }

        $zip->close();
    }

    protected function tearDown(): void
    {
        $this->logErrors();
    }
}
