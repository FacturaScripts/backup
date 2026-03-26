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

use Exception;
use FacturaScripts\Core\Tools;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipStream\Option\Archive;
use ZipStream\ZipStream;

/**
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class BackupFile
{
    public static function generate(string $channel = ''): bool
    {
        $folder = Tools::folder('MyFiles', 'Backups');
        if (false === Tools::folderCheckOrCreate($folder)) {
            Tools::log($channel)->error('folder-create-error');
            return false;
        }

        // creamos un archivo
        $file_path = Tools::folder('MyFiles', 'Backups', date('Y-m-d_H-i-s') . '.zip');
        if (false === static::zipFolder($file_path)) {
            Tools::log($channel)->error('record-save-error');
            return false;
        }

        // si el tamaño es 0, mostramos un aviso
        if (filesize($file_path) === 0) {
            Tools::log($channel)->warning('backup-empty-warning');
        }

        return true;
    }

    protected static function zipFolder(string $fileName): bool
    {
        // abrimos un stream de escritura hacia el archivo destino
        $outputStream = fopen($fileName, 'wb');
        if ($outputStream === false) {
            return false;
        }

        try {
            // configuramos ZipStream para escribir directamente al stream del archivo
            $options = new Archive();
            $options->setSendHttpHeaders(false);
            $options->setOutputStream($outputStream);

            $zip = new ZipStream(basename($fileName), $options);

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

                $zip->addFileFromPath($relativePath, $filePath);
            }

            $zip->finish();
        } catch (Exception $e) {
            fclose($outputStream);
            return false;
        }

        fclose($outputStream);
        return true;
    }
}
