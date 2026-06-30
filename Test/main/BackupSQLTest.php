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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Pais;
use FacturaScripts\Plugins\Backup\Lib\BackupSQL;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PDO;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class BackupSQLTest extends TestCase
{
    use LogErrorsTrait;

    /** @var array<string> */
    private array $filesBeforeTest = [];

    public function testGenerateCreatesBackupsFolder(): void
    {
        if (Tools::config('db_type') !== 'mysql') {
            $this->markTestSkipped('BackupSQL solo funciona con MySQL');
        }

        BackupSQL::generate('test');

        $folder = Tools::folder('MyFiles', 'Backups');
        $this->assertDirectoryExists($folder, 'La carpeta MyFiles/Backups no existe tras ejecutar generate()');
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

    public function testGenerateReturnsFalseOnNonMySQL(): void
    {
        if (Tools::config('db_type') === 'mysql') {
            $this->markTestSkipped('Test solo aplica cuando db_type != mysql');
        }

        $result = BackupSQL::generate('test');
        $this->assertFalse($result);
    }

    /**
     * ATENCIÓN: este test es destructivo. Replica el flujo real del controlador
     * (elimina todas las tablas y restaura la copia), por lo que la base de datos
     * de pruebas se sustituye por el contenido de la copia recién generada.
     */
    public function testRestoreRemovesDataAddedAfterBackup(): void
    {
        if (Tools::config('db_type') !== 'mysql') {
            $this->markTestSkipped('La restauración SQL solo funciona con MySQL');
        }

        // 1. generamos una copia de seguridad de la base de datos
        $this->assertTrue(BackupSQL::generate('test'), 'No se pudo generar el backup SQL');

        // localizamos el archivo .sql recién creado
        $folder = Tools::folder('MyFiles', 'Backups');
        $filesAfter = glob($folder . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $newFiles = array_diff($filesAfter, $this->filesBeforeTest);
        $this->assertNotEmpty($newFiles, 'No se ha creado ningún archivo .sql');
        $sqlFile = reset($newFiles);

        // 2. añadimos a la base de datos un dato que NO está en la copia
        $codpais = 'BK' . strtoupper(substr(md5(uniqid('', true)), 0, 10));
        $pais = new Pais();
        $pais->codpais = $codpais;
        $pais->nombre = 'Backup Restore Test';
        $this->assertTrue($pais->save(), 'No se pudo crear el país marcador');

        // confirmamos que el dato existe antes de restaurar
        $before = new Pais();
        $this->assertTrue($before->loadFromCode($codpais), 'El país marcador debería existir antes de restaurar');

        // 3. restauramos la copia replicando el flujo del controlador (drop all + restore)
        $database = new DataBase();
        $database->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($database->getTables() as $table) {
            $database->exec('DROP TABLE ' . $table);
        }
        $database->close();

        $restore = false;
        try {
            $db = new PDO('mysql:host=' . Tools::config('db_host') . ';port=' . Tools::config('db_port') . ';dbname=' . Tools::config('db_name'), Tools::config('db_user'), Tools::config('db_pass'));
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $restore = BackupSQL::restore($db, $sqlFile);
        } catch (Throwable $e) {
            $restore = $e->getMessage();
        } finally {
            // reconectamos siempre para no dejar la base de datos sin conexión
            $database->connect();
            Cache::clear();
        }

        $this->assertTrue($restore, 'La restauración no finalizó correctamente: ' . (is_string($restore) ? $restore : ''));

        // 4. comprobamos que el dato añadido tras la copia ha desaparecido
        $after = new Pais();
        $this->assertFalse($after->loadFromCode($codpais), 'El país marcador debería haber desaparecido tras restaurar la copia');
    }

    protected function setUp(): void
    {
        // capturamos los archivos .sql existentes antes de cada test
        $folder = Tools::folder('MyFiles', 'Backups');
        if (is_dir($folder)) {
            $this->filesBeforeTest = glob($folder . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        }
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
