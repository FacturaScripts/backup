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

use FacturaScripts\Core\Tools;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use ZipStream\Option\Archive;
use ZipStream\ZipStream;

/**
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class BackupFile
{
    /**
     * Carpetas (relativas a la raíz, con '/' final) que no se incluyen en la copia.
     * El '/' final evita excluir otras carpetas con el mismo prefijo.
     *
     * @var string[]
     */
    protected const EXCLUDED_FOLDERS = ['MyFiles/Backups/', 'MyFiles/Cache/', 'MyFiles/Tmp/', 'Dinamic/'];

    /**
     * Genera una copia de seguridad de los archivos en MyFiles/Backups.
     *
     * @param string $channel Canal de log donde se registran los mensajes.
     *
     * @return bool
     */
    public static function generate(string $channel = ''): bool
    {
        $folder = Tools::folder('MyFiles', 'Backups');
        if (false === Tools::folderCheckOrCreate($folder)) {
            Tools::log($channel)->error('folder-create-error');
            return false;
        }

        // creamos un archivo
        $file_path = Tools::folder('MyFiles', 'Backups', date('Y-m-d_H-i-s') . '.zip');
        if (false === static::zipFolder($file_path, $channel)) {
            Tools::log($channel)->error('record-save-error');
            return false;
        }

        // si el tamaño es 0, mostramos un aviso
        if (filesize($file_path) === 0) {
            Tools::log($channel)->warning('backup-empty-warning');
        }

        return true;
    }

    /**
     * Comprime la carpeta de FacturaScripts (o la indicada) en un zip, saltando las carpetas
     * excluidas sin entrar en ellas y las carpetas o archivos que no se puedan leer.
     *
     * @param string $fileName Ruta del zip de destino.
     * @param string $channel Canal de log donde se registran los errores.
     * @param string|null $sourceFolder Carpeta a comprimir. Por defecto, FS_FOLDER.
     *
     * @return bool
     */
    protected static function zipFolder(string $fileName, string $channel = '', ?string $sourceFolder = null): bool
    {
        // abrimos un stream de escritura hacia el archivo destino
        $outputStream = fopen($fileName, 'wb');
        if ($outputStream === false) {
            return false;
        }

        try {
            // configuramos ZipStream para escribir directamente al stream del archivo
            if (class_exists(Archive::class)) {
                // ZipStream 2.x
                $options = new Archive();
                $options->setSendHttpHeaders(false);
                $options->setOutputStream($outputStream);
                $zip = new ZipStream(basename($fileName), $options);
            } else {
                // ZipStream 3.x
                $zip = new ZipStream(
                    outputName: basename($fileName),
                    sendHttpHeaders: false,
                    outputStream: $outputStream
                );
            }

            $root = str_replace(DIRECTORY_SEPARATOR, '/', rtrim($sourceFolder ?? FS_FOLDER, '/\\'));

            // SKIP_DOTS evita visitar '.' y '..' (que con open_basedir provoca un error)
            $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);

            // podamos las carpetas excluidas sin entrar en ellas, y saltamos lo que no se pueda leer
            $filtered = new RecursiveCallbackFilterIterator(
                $directory,
                function (SplFileInfo $current) use ($root): bool {
                    if (false === is_readable($current->getPathname())) {
                        return false;
                    }

                    if ($current->isDir()) {
                        $relative = substr(str_replace(DIRECTORY_SEPARATOR, '/', $current->getPathname()), strlen($root) + 1);
                        return false === in_array($relative . '/', static::EXCLUDED_FOLDERS, true);
                    }

                    return true;
                }
            );

            // CATCH_GET_CHILD salta las subcarpetas que lancen excepción al abrirlas
            $files = new RecursiveIteratorIterator(
                $filtered,
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    continue;
                }

                $filePath = $file->getPathname();
                $relativePath = substr(str_replace(DIRECTORY_SEPARATOR, '/', $filePath), strlen($root) + 1);
                $zip->addFileFromPath($relativePath, $filePath);
            }

            $zip->finish();
        } catch (Throwable $e) {
            Tools::log($channel)->error('backup-files-error', ['%error%' => $e->getMessage()]);
            fclose($outputStream);
            return false;
        }

        fclose($outputStream);
        return true;
    }
}
