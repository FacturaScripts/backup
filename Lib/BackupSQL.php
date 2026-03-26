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

namespace FacturaScripts\Plugins\Backup\Lib;

use DatabaseBackupManager\MySQLBackup;
use FacturaScripts\Core\Tools;
use PDO;

/**
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class BackupSQL
{
    public static function generate(string $channel = ''): bool
    {
        if (Tools::config('db_type') != 'mysql') {
            Tools::log($channel)->error('mysql-support-only');
            return false;
        }

        // si el puerto no es el puerto por defecto, mostramos un aviso
        if (Tools::config('db_port') != 3306) {
            Tools::log($channel)->warning('backup-port-warning', [
                '%port%' => Tools::config('db_port')
            ]);
        }

        if (false === extension_loaded('pdo_mysql')) {
            Tools::log($channel)->error('pdo-mysql-support-only');
            return false;
        }

        if (false === extension_loaded('zip')) {
            Tools::log($channel)->error('php-extension-not-found', ['%extension%' => 'zip']);
            return false;
        }

        $folder = Tools::folder('MyFiles', 'Backups');
        if (false === Tools::folderCheckOrCreate($folder)) {
            Tools::log($channel)->error('folder-create-error');
            return false;
        }

        $file_name = date('Y-m-d_H-i-s') . '.sql';

        // Definimos la configuración de la base de datos y el directorio de backup
        $db = new PDO('mysql:host=' . Tools::config('db_host') . ';port=' . Tools::config('db_port') . ';dbname=' . Tools::config('db_name'), Tools::config('db_user'), Tools::config('db_pass'));
        $backupDir = Tools::folder('MyFiles', 'Backups');

        $backup = new MySQLBackup($db, $backupDir);

        // exportamos la base de datos a un archivo y le cambiamos el nombre para que tenga el formato correcto
        $file = $backup->backup();
        if (false === rename($file, Tools::folder('MyFiles', 'Backups', $file_name))) {
            Tools::log($channel)->error('record-save-error');
            return false;
        }

        $file_path = Tools::folder('MyFiles', 'Backups', $file_name);
        if (false === file_exists($file_path)) {
            Tools::log($channel)->error('record-save-error');
            return false;
        }

        // si el tamaño es 0, mostramos un aviso
        if (filesize($file_path) === 0) {
            Tools::log($channel)->warning('backup-empty-warning');
        }

        return true;
    }
}