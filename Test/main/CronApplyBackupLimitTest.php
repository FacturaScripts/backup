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
use FacturaScripts\Plugins\Backup\Cron;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @author Esteban Sanchez <esteban@factura.city>
 */
final class CronApplyBackupLimitTest extends TestCase
{
    use LogErrorsTrait;

    private string $folder = '';
    private int $sqlPrevios = 0;
    private int $zipPrevios = 0;

    protected function setUp(): void
    {
        $this->folder = Tools::folder('MyFiles', 'Backups');
        Tools::folderCheckOrCreate($this->folder);
        $this->sqlPrevios = count(glob($this->folder . DIRECTORY_SEPARATOR . '*.sql') ?: []);
        $this->zipPrevios = count(glob($this->folder . DIRECTORY_SEPARATOR . '*.zip') ?: []);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . DIRECTORY_SEPARATOR . '2000-01-01_*') ?: [] as $archivo) {
            if (is_file($archivo)) {
                unlink($archivo);
            }
        }

        $this->logErrors();
    }

    public function testSinLimiteNoElimina(): void
    {
        $archivos = $this->crearArchivos(['sql'], 5);
        Tools::settingsSet('backup', 'max_backups', 0);

        $this->ejecutarLimite();

        foreach ($archivos as $archivo) {
            $this->assertFileExists($archivo, 'Con limite 0 no debe eliminar ningun archivo');
        }
    }

    public function testEliminaLosMasAntiguosSql(): void
    {
        $archivos = $this->crearArchivos(['sql'], 5);
        Tools::settingsSet('backup', 'max_backups', $this->sqlPrevios + 3);

        $this->ejecutarLimite();

        $this->assertFileDoesNotExist($archivos[0], 'El sql mas antiguo debe eliminarse');
        $this->assertFileDoesNotExist($archivos[1], 'El segundo sql mas antiguo debe eliminarse');
        $this->assertFileExists($archivos[2], 'El tercer sql debe conservarse');
        $this->assertFileExists($archivos[3], 'El cuarto sql debe conservarse');
        $this->assertFileExists($archivos[4], 'El quinto sql debe conservarse');
    }

    public function testEliminaLosMasAntiguosZip(): void
    {
        $archivos = $this->crearArchivos(['zip'], 5);
        Tools::settingsSet('backup', 'max_backups', $this->zipPrevios + 3);

        $this->ejecutarLimite();

        $this->assertFileDoesNotExist($archivos[0], 'El zip mas antiguo debe eliminarse');
        $this->assertFileDoesNotExist($archivos[1], 'El segundo zip mas antiguo debe eliminarse');
        $this->assertFileExists($archivos[2], 'El tercer zip debe conservarse');
        $this->assertFileExists($archivos[3], 'El cuarto zip debe conservarse');
        $this->assertFileExists($archivos[4], 'El quinto zip debe conservarse');
    }

    public function testLimiteSeAplicaASqlYZipPorSeparado(): void
    {
        $archivosSql = $this->crearArchivos(['sql'], 5);
        $archivosZip = $this->crearArchivos(['zip'], 5);
        $limite = max($this->sqlPrevios, $this->zipPrevios) + 3;
        Tools::settingsSet('backup', 'max_backups', $limite);

        $this->ejecutarLimite();

        $restantesSql = array_filter($archivosSql, 'file_exists');
        $restantesZip = array_filter($archivosZip, 'file_exists');

        $esperadosSql = min(count($archivosSql), max(0, $limite - $this->sqlPrevios));
        $esperadosZip = min(count($archivosZip), max(0, $limite - $this->zipPrevios));

        $this->assertCount($esperadosSql, $restantesSql, 'El limite de archivos sql no se aplico correctamente');
        $this->assertCount($esperadosZip, $restantesZip, 'El limite de archivos zip no se aplico correctamente');
    }

    /** @return array<string> */
    private function crearArchivos(array $extensiones, int $cantidad): array
    {
        $archivos = [];
        for ($i = 1; $i <= $cantidad; $i++) {
            foreach ($extensiones as $ext) {
                $nombre = sprintf('2000-01-01_00-00-%02d.%s', $i, $ext);
                $ruta = $this->folder . DIRECTORY_SEPARATOR . $nombre;
                file_put_contents($ruta, '');
                $archivos[] = $ruta;
            }
        }
        return $archivos;
    }

    private function ejecutarLimite(): void
    {
        $cron = new Cron('Backup');
        $metodo = new ReflectionMethod(Cron::class, 'applyBackupLimit');
        $metodo->setAccessible(true);
        $metodo->invoke($cron);
    }
}
