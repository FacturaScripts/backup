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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;
use ZipStream\Option\Archive;
use ZipStream\ZipStream;

/**
 * Genera la copia de seguridad de los archivos en un zip dentro de MyFiles/Backups.
 * Usa ZipStream y, en PHP de 32 bits o si ZipStream falla, ZipArchive.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class BackupFile
{
    /**
     * Crea la copia de seguridad de archivos en MyFiles/Backups.
     *
     * @param string $channel Canal de log donde registrar los errores.
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

    /**
     * Carpetas que no se incluyen en la copia (con '/' final para no excluir otras con el mismo prefijo).
     *
     * @var array<string>
     */
    protected const EXCLUDED_FOLDERS = ['MyFiles/Backups/', 'MyFiles/Cache/', 'MyFiles/Tmp/', 'Dinamic/'];

    /**
     * Indica si se puede usar ZipStream. ZipStream 3.x exige un PHP de 64 bits,
     * así que en PHP de 32 bits se usa ZipArchive.
     */
    protected static function canUseZipStream(): bool
    {
        return PHP_INT_SIZE >= 8 && class_exists(ZipStream::class);
    }

    /**
     * Devuelve las rutas (relativa dentro del zip => absoluta) de los archivos a incluir en la copia.
     *
     * @return array<string, string>
     */
    protected static function getFilesToZip(): array
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(FS_FOLDER),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $result = [];
        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen(FS_FOLDER) + 1));

            foreach (static::EXCLUDED_FOLDERS as $folder) {
                if (strpos($relativePath, $folder) === 0) {
                    continue 2;
                }
            }

            $result[$relativePath] = $filePath;
        }

        return $result;
    }

    /**
     * Genera el zip con la extensión ZipArchive (requisito del plugin).
     * Es la alternativa a ZipStream para PHP de 32 bits.
     */
    protected static function zipFolderWithZipArchive(string $fileName): bool
    {
        // ZipArchive no puede abrir un archivo vacío ya creado, así que lo borramos antes
        if (file_exists($fileName)) {
            unlink($fileName);
        }

        $zip = new ZipArchive();
        if ($zip->open($fileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        try {
            foreach (static::getFilesToZip() as $relativePath => $filePath) {
                if (false === $zip->addFile($filePath, $relativePath)) {
                    $zip->close();
                    return false;
                }
            }
        } catch (Throwable $e) {
            $zip->close();
            return false;
        }

        // close() escribe el archivo; si falla, la copia no es válida
        return $zip->close();
    }

    /**
     * Comprime los archivos de FacturaScripts en el zip indicado: ZipStream si es posible
     * y ZipArchive en PHP de 32 bits o si ZipStream falla.
     *
     * @param string $fileName Ruta completa del zip a crear.
     */
    protected static function zipFolder(string $fileName): bool
    {
        // en PHP de 32 bits no se puede usar ZipStream 3.x
        if (false === static::canUseZipStream()) {
            return static::zipFolderWithZipArchive($fileName);
        }

        if (static::zipFolderWithZipStream($fileName)) {
            return true;
        }

        // si falla ZipStream, lo intentamos con ZipArchive
        return static::zipFolderWithZipArchive($fileName);
    }

    /**
     * Genera el zip con ZipStream (2.x o 3.x) escribiendo directamente al archivo, sin cargarlo en memoria.
     */
    protected static function zipFolderWithZipStream(string $fileName): bool
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

            foreach (static::getFilesToZip() as $relativePath => $filePath) {
                $zip->addFileFromPath($relativePath, $filePath);
            }

            $zip->finish();
        } catch (Throwable $e) {
            fclose($outputStream);
            return false;
        }

        fclose($outputStream);
        return true;
    }
}
