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
 * Comprueba la alternativa con ZipArchive de BackupFile, usada en PHP de 32 bits,
 * donde ZipStream 3.x no funciona.
 *
 * @author Esteban Sánchez Martínez <esteban@factura.city>
 */
final class BackupFileZipArchiveTest extends TestCase
{
    use LogErrorsTrait;

    /** @var string Nombre de la carpeta marcador dentro de MyFiles. */
    private string $markerDir = '';

    /** @var string Ruta del zip generado durante el test. */
    private string $zipFile = '';

    protected function setUp(): void
    {
        // carpeta marcador que debe aparecer en la copia
        $this->markerDir = 'BackupZipArchiveTest_' . substr(md5(uniqid('', true)), 0, 8);
        $markerPath = Tools::folder('MyFiles', $this->markerDir);
        Tools::folderCheckOrCreate($markerPath);
        file_put_contents($markerPath . DIRECTORY_SEPARATOR . 'marker.txt', 'zip-archive-marker');
    }

    protected function tearDown(): void
    {
        if ($this->zipFile && file_exists($this->zipFile)) {
            unlink($this->zipFile);
        }

        Tools::folderDelete(Tools::folder('MyFiles', $this->markerDir));
        $this->logErrors();
    }

    public function testComposerDoesNotRequire64Bits(): void
    {
        // el plugin no debe tumbar la instalación en PHP de 32 bits
        $platformCheck = dirname(__DIR__, 2) . '/vendor/composer/platform_check.php';
        if (false === file_exists($platformCheck)) {
            $this->assertFileDoesNotExist($platformCheck);
            return;
        }

        $this->assertStringNotContainsString(
            'PHP_INT_SIZE',
            file_get_contents($platformCheck),
            'platform_check.php exige un PHP de 64 bits'
        );
    }

    public function testGenerateWithZipArchive(): void
    {
        // forzamos el camino de ZipArchive, como en PHP de 32 bits
        $this->assertValidBackup(BackupFileForceZipArchive::class);
    }

    public function testGenerateFallsBackToZipArchiveWhenZipStreamFails(): void
    {
        // si ZipStream falla y deja un archivo a medias, se debe rehacer con ZipArchive
        $this->assertValidBackup(BackupFileZipStreamFails::class);
    }

    /**
     * Genera la copia con la clase indicada y comprueba que el zip es válido, que incluye
     * el archivo marcador y que respeta las carpetas excluidas.
     *
     * @param class-string<BackupFile> $className Clase que genera la copia.
     */
    private function assertValidBackup(string $className): void
    {
        $folder = Tools::folder('MyFiles', 'Backups');
        $before = is_dir($folder) ? (glob($folder . DIRECTORY_SEPARATOR . '*.zip') ?: []) : [];

        $this->assertTrue($className::generate('test'), 'generate() con ZipArchive ha fallado');

        $after = glob($folder . DIRECTORY_SEPARATOR . '*.zip') ?: [];
        $new = array_diff($after, $before);
        $this->assertNotEmpty($new, 'No se generó ningún zip');
        $this->zipFile = reset($new);

        $this->assertGreaterThan(0, filesize($this->zipFile), 'El zip generado está vacío');

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->zipFile, ZipArchive::CHECKCONS), 'El zip generado no es válido');

        // incluye el archivo marcador con su contenido
        $entry = 'MyFiles/' . $this->markerDir . '/marker.txt';
        $this->assertNotFalse($zip->locateName($entry), 'El zip no contiene el archivo marcador');
        $this->assertSame('zip-archive-marker', $zip->getFromName($entry));

        // respeta las carpetas excluidas
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            foreach (['MyFiles/Backups/', 'MyFiles/Cache/', 'MyFiles/Tmp/', 'Dinamic/'] as $excluded) {
                $this->assertStringStartsNotWith($excluded, $name, "El zip contiene una carpeta excluida: $name");
            }
        }

        $zip->close();
    }
}

/**
 * BackupFile que simula un PHP de 32 bits: nunca usa ZipStream.
 */
class BackupFileForceZipArchive extends BackupFile
{
    protected static function canUseZipStream(): bool
    {
        return false;
    }
}

/**
 * BackupFile en el que ZipStream falla tras dejar un archivo a medias.
 */
class BackupFileZipStreamFails extends BackupFile
{
    protected static function canUseZipStream(): bool
    {
        return true;
    }

    protected static function zipFolderWithZipStream(string $fileName): bool
    {
        file_put_contents($fileName, 'zip-incompleto');
        return false;
    }
}
