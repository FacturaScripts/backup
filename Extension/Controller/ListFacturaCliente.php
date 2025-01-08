<?php
/**
 * This file is part of Backup plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\Backup\Extension\Controller;

use Closure;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Tools;

class ListFacturaCliente
{
    public function createViews(): Closure
    {
        return function () {
            $this->checkLatestBackup();
        };
    }

    public function checkLatestBackup(): Closure
    {
        return function () {
            $last_date = Cache::get('latest-backup-date');

            if (empty($last_date)) {
                // buscamos todos los archivos sql de la carpeta MyFiles/Backups
                $folder = Tools::folder('MyFiles', 'Backups');
                foreach (Tools::folderScan($folder) as $file) {
                    if (substr($file, -4) !== '.sql') {
                        continue;
                    }

                    // comprobamos si el nombre del archivo es una fecha con hora
                    $name = substr($file, 0, strlen($file) - 4);
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $name)) {
                        continue;
                    }

                    // comprobamos si es el último backup
                    $date = explode('_', $name);
                    if (empty($last_date) || strtotime($date[0]) > strtotime($last_date)) {
                        $last_date = $date[0];
                    }
                }
            }

            // comprobamos si la fecha del último backup es de hace más de 30 días
            if (empty($last_date) || strtotime($last_date) < strtotime('-30 days')) {
                Tools::log()->warning('last-backup-more-30d');
            }
        };
    }
}
