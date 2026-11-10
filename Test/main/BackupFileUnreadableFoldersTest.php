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

use FacturaScripts\Plugins\Backup\Lib\BackupFile;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ZipArchive;

/**
 * Comprueba que la copia de archivos poda las carpetas excluidas y salta las que no se pueden leer.
 *
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
final class BackupFileUnreadableFoldersTest extends TestCase
{
    /** @var string Carpeta temporal que hace de raíz de FacturaScripts. */
    private string $root = '';

    /** @var string Ruta del zip generado. */
    private string $zipPath = '';

    public static function setUpBeforeClass(): void
    {
        // sin el autoloader del vendor del plugin no se encuentra ZipStream
        if (false === class_exists(\ZipStream\ZipStream::class)) {
            require_once FS_FOLDER . '/Plugins/Backup/vendor/autoload.php';
        }
    }

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'backup_test_' . uniqid();
        $this->root = $base . DIRECTORY_SEPARATOR . 'root';
        $this->zipPath = $base . DIRECTORY_SEPARATOR . 'out.zip';

        // estructura: archivos normales, carpetas excluidas y una carpeta sin permisos
        $files = [
            'visible.txt',
            'sub/visible2.txt',
            'MyFiles/user.txt',
            'MyFiles/Backups/old.zip',
            'MyFiles/Cache/cache.txt',
            'MyFiles/Tmp/tmp.txt',
            'MyFilesExtra/keep.txt',
            'Dinamic/dinamic.php',
            'locked/secret.txt',
        ];
        foreach ($files as $file) {
            $path = $this->root . '/' . $file;
            mkdir(dirname($path), 0777, true);
            file_put_contents($path, 'contenido ' . $file);
        }
    }

    protected function tearDown(): void
    {
        // restauramos los permisos para poder borrar la carpeta bloqueada
        @chmod($this->root . '/locked', 0777);

        $base = dirname($this->root);
        if (is_dir($base)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($base);
        }
    }

    /**
     * Motores de compresión con los que se prueba el recorrido de carpetas.
     *
     * @return array<string, array{class-string<BackupFile>}>
     */
    public static function engineProvider(): array
    {
        return [
            'ZipStream' => [BackupFile::class],
            'ZipArchive' => [BackupFileUnreadableZipArchive::class],
        ];
    }

    /**
     * @dataProvider engineProvider
     *
     * @param class-string<BackupFile> $className Clase que genera el zip.
     */
    public function testBrokenSymlinkIsSkipped(string $className): void
    {
        // un enlace roto no es un archivo normal: antes rompía toda la copia
        if (false === @symlink($this->root . '/no-existe.txt', $this->root . '/roto.txt')) {
            $this->markTestSkipped('No se pueden crear enlaces simbólicos en este entorno.');
        }

        $this->assertTrue($this->zipFolder($className), 'zipFolder() debería devolver true con un enlace roto');

        $names = $this->zipNames();
        $this->assertContains('visible.txt', $names);
        $this->assertNotContains('roto.txt', $names);
    }

    /**
     * @dataProvider engineProvider
     *
     * @param class-string<BackupFile> $className Clase que genera el zip.
     */
    public function testExcludedFoldersAreNotZipped(string $className): void
    {
        $this->assertTrue($this->zipFolder($className), 'zipFolder() debería devolver true');

        $names = $this->zipNames();
        $this->assertContains('visible.txt', $names);
        $this->assertContains('sub/visible2.txt', $names);
        $this->assertContains('MyFiles/user.txt', $names);
        $this->assertContains('MyFilesExtra/keep.txt', $names, 'No debe excluirse una carpeta con el mismo prefijo');

        foreach ($names as $name) {
            $this->assertStringStartsNotWith('MyFiles/Backups/', $name);
            $this->assertStringStartsNotWith('MyFiles/Cache/', $name);
            $this->assertStringStartsNotWith('MyFiles/Tmp/', $name);
            $this->assertStringStartsNotWith('Dinamic/', $name);
        }
    }

    /**
     * @dataProvider engineProvider
     *
     * @param class-string<BackupFile> $className Clase que genera el zip.
     */
    public function testUnreadableFolderIsSkipped(string $className): void
    {
        chmod($this->root . '/locked', 0000);

        // en Windows o ejecutando como root chmod no impide leer: no se puede simular
        if (is_readable($this->root . '/locked')) {
            $this->markTestSkipped('No se pueden quitar los permisos de lectura en este entorno.');
        }

        $this->assertTrue($this->zipFolder($className), 'zipFolder() debería devolver true con una carpeta sin permisos');

        $names = $this->zipNames();
        $this->assertContains('visible.txt', $names);
        $this->assertNotContains('locked/secret.txt', $names);
    }

    /**
     * Ejecuta zipFolder() de la clase indicada sobre la carpeta temporal.
     *
     * @param class-string<BackupFile> $className Clase que genera el zip.
     */
    private function zipFolder(string $className): bool
    {
        $method = new ReflectionMethod($className, 'zipFolder');
        return $method->invoke(null, $this->zipPath, 'test', $this->root);
    }

    /**
     * Devuelve los nombres de las entradas del zip generado.
     *
     * @return string[]
     */
    private function zipNames(): array
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->zipPath), 'No se pudo abrir el zip');
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        return $names;
    }
}

/**
 * BackupFile que simula un PHP de 32 bits: nunca usa ZipStream, solo ZipArchive.
 */
class BackupFileUnreadableZipArchive extends BackupFile
{
    protected static function canUseZipStream(): bool
    {
        return false;
    }
}
