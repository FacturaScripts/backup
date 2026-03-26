<?php
/**
 * This file is part of Backup plugin for FacturaScripts
 * Copyright (C) 2025-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\Backup;

use DatabaseBackupManager\MySQLBackup;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Core\Tools;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class Cron extends CronClass
{
    const JOB_NAME = 'monthly-backup';

    public function run(): void
    {
        // si está activado el plugin SpaceInstance o CityInstance, no hacemos backup automático
        if (Plugins::isEnabled('SpaceInstance') || Plugins::isEnabled('CityInstance')) {
            return;
        }

        $frequency = Tools::settings('backup', 'frequency', '1 week');
        $dayOfWeek = Tools::settings('backup', 'weekly_day', 1);
        $dayOfMonth = Tools::settings('backup', 'monthly_day', 1);
        $hour = Tools::settings('backup', 'hour', 3);

        $job = $this->job(self::JOB_NAME);

        if ($frequency === '1 month') {
            $job->everyDay($dayOfMonth, $hour);
        } elseif ($frequency === '1 week') {
            switch ($dayOfWeek) {
                case 1:
                    $job->everyMondayAt($hour);
                    break;

                case 2:
                    $job->everyTuesdayAt($hour);
                    break;

                case 3:
                    $job->everyWednesdayAt($hour);
                    break;

                case 4:
                    $job->everyThursdayAt($hour);
                    break;

                case 5:
                    $job->everyFridayAt($hour);
                    break;

                case 6:
                    $job->everySaturdayAt($hour);
                    break;

                case 7:
                    $job->everySundayAt($hour);
                    break;

                default:
                    $job->everyMondayAt($hour);
            }
        } elseif ($frequency === '1 day') {
            $job->everyDayAt($hour);
        } else {
            // selección no válida
            return;
        }

        $job->run(function () {
            $this->createBackup();
        });
    }

    protected function createBackup(): void
    {
        if (false === $this->createSqlFile()) {
            Tools::log(self::JOB_NAME)->error('sql-file-error');
            return;
        }

        if (false === $this->createZipFile()) {
            Tools::log(self::JOB_NAME)->error('zip-file-error');
            return;
        }

        Tools::log(self::JOB_NAME)->info('backup-created');
    }

    protected function createSqlFile(): bool
    {
        // si el puerto no es el puerto por defecto, mostramos un aviso
        if (Tools::config('db_port') != 3306) {
            Tools::log(self::JOB_NAME)->warning('backup-port-warning', [
                '%port%' => Tools::config('db_port')
            ]);
            return false;
        }

        if (false === extension_loaded('pdo_mysql')) {
            Tools::log(self::JOB_NAME)->error('pdo-mysql-support-only');
            return false;
        }

        $folder = Tools::folder('MyFiles', 'Backups');
        if (false === Tools::folderCheckOrCreate($folder)) {
            Tools::log(self::JOB_NAME)->error('folder-create-error');
            return false;
        }

        $file_name = date('Y-m-d_H-i-s') . '.sql';

        //definimos la configurcion de la base de datos y el directorio de backup
        $db = new PDO('mysql:host=' . Tools::config('db_host') . ';port=' . Tools::config('db_port') . ';dbname=' . Tools::config('db_name'), Tools::config('db_user'), Tools::config('db_pass'));
        $backupDir = Tools::folder('MyFiles', 'Backups');

        $backup = new MySQLBackup($db, $backupDir);

        //exportamos la base de datos a un archivo y le cambiamos el nombre para que tenga el formato correcto
        $file = $backup->backup();
        rename($file, Tools::folder('MyFiles', 'Backups', $file_name));

        $file_path = Tools::folder('MyFiles', 'Backups', $file_name);
        if (false === file_exists($file_path)) {
            Tools::log(self::JOB_NAME)->error('record-save-error');
            return false;
        }

        return true;
    }

    protected function createZipFile(): bool
    {
        $folder = Tools::folder('MyFiles', 'Backups');
        if (false === Tools::folderCheckOrCreate($folder)) {
            Tools::log(self::JOB_NAME)->error('folder-create-error');
            return false;
        }

        // creamos un archivo
        $file_path = Tools::folder('MyFiles', 'Backups', date('Y-m-d_H-i-s') . '.zip');
        if (false === $this->zipFolder($file_path)) {
            Tools::log(self::JOB_NAME)->error('record-save-error');
            return false;
        }

        return true;
    }

    protected function zipFolder(string $fileName): bool
    {
        $zip = new ZipArchive();
        if (false === $zip->open($fileName, ZIPARCHIVE::CREATE | ZipArchive::OVERWRITE)) {
            return false;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(FS_FOLDER),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if ($file->isDir() || substr($name, -4) === '.zip') {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen(FS_FOLDER) + 1));

            // excluimos algunas carpetas
            $exclude = ['MyFiles/Backups', 'MyFiles/Cache', 'MyFiles/Tmp', 'Dinamic'];
            foreach ($exclude as $folder) {
                if (strpos($relativePath, $folder) === 0) {
                    continue 2;
                }
            }

            $zip->addFile($filePath, $relativePath);
        }

        return $zip->close();
    }
}
