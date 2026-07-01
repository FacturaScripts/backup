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

use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\BackupFile;
use FacturaScripts\Dinamic\Lib\BackupSQL;

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
            $this->applyBackupLimit();
        });
    }

    protected function applyBackupLimit(): void
    {
        $limit = (int) Tools::settings('backup', 'max_backups', 0);
        if ($limit <= 0) {
            return;
        }

        $folder = Tools::folder('MyFiles', 'Backups');
        $files = is_dir($folder) ? scandir($folder) : [];

        foreach (['sql', 'zip'] as $ext) {
            $byExt = [];
            foreach ($files as $file) {
                if (str_ends_with($file, '.' . $ext)) {
                    $byExt[] = $file;
                }
            }

            rsort($byExt); // más recientes primero (nombre = fecha)
            $toDelete = array_slice($byExt, $limit);

            foreach ($toDelete as $filename) {
                $path = $folder . DIRECTORY_SEPARATOR . $filename;
                if (file_exists($path)) {
                    unlink($path);
                    Tools::log(self::JOB_NAME)->info('backup-deleted', ['%file%' => $filename]);
                }
            }
        }
    }

    protected function createBackup(): void
    {
        if (false === BackupSQL::generate(self::JOB_NAME)) {
            Tools::log(self::JOB_NAME)->error('sql-file-error');
            return;
        }

        if (false === BackupFile::generate(self::JOB_NAME)) {
            Tools::log(self::JOB_NAME)->error('zip-file-error');
            return;
        }

        Tools::log(self::JOB_NAME)->info('backup-created');
    }
}