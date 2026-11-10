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
use Generator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use ZipArchive;
use ZipStream\Option\Archive;
use ZipStream\ZipStream;

/**
 * Genera la copia de seguridad de los archivos en un zip dentro de MyFiles/Backups.
 * Usa ZipStream y, en PHP de 32 bits o si ZipStream falla, ZipArchive.
 * Salta las carpetas excluidas sin entrar en ellas y las carpetas o archivos que no se pueden leer
 * (open_basedir, permisos), para que la copia no falle por ellos.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class BackupFile
{
    /**
     * Carpetas que no se incluyen en la copia (con '/' final para no excluir otras con el mismo prefijo).
     *
     * @var array<string>
     */
    protected const EXCLUDED_FOLDERS = ['MyFiles/Backups/', 'MyFiles/Cache/', 'MyFiles/Tmp/', 'Dinamic/'];

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
     * Indica si se puede usar ZipStream. ZipStream 3.x exige un PHP de 64 bits,
     * así que en PHP de 32 bits se usa ZipArchive.
     */
    protected static function canUseZipStream(): bool
    {
        return PHP_INT_SIZE >= 8 && class_exists(ZipStream::class);
    }

    /**
     * Recorre los archivos a incluir en la copia y devuelve, uno a uno, su ruta relativa dentro
     * del zip (clave) y su ruta absoluta (valor). Es un generador para no cargar en memoria la
     * lista completa de archivos de la instalación.
     *
     * - SKIP_DOTS evita visitar '.' y '..': con open_basedir, comprobar '..' lanzaba un error.
     * - Las carpetas excluidas se podan sin entrar en ellas.
     * - Se saltan las carpetas y archivos sin permiso de lectura y lo que no sea un archivo
     *   normal (sockets, enlaces rotos...).
     * - CATCH_GET_CHILD salta las subcarpetas que, aun así, lancen una excepción al abrirlas.
     *
     * @param string|null $sourceFolder Carpeta a comprimir. Por defecto, FS_FOLDER.
     *
     * @return Generator<string, string>
     */
    protected static function getFilesToZip(?string $sourceFolder = null): Generator
    {
        $root = str_replace(DIRECTORY_SEPARATOR, '/', rtrim($sourceFolder ?? FS_FOLDER, '/\\'));

        $filtered = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $current) use ($root): bool {
                if (false === is_readable($current->getPathname())) {
                    return false;
                }

                if ($current->isDir()) {
                    $relative = static::getRelativePath($root, $current->getPathname());
                    return false === in_array($relative . '/', static::EXCLUDED_FOLDERS, true);
                }

                return $current->isFile();
            }
        );

        $files = new RecursiveIteratorIterator(
            $filtered,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($files as $file) {
            // los enlaces a carpetas no se recorren y llegan aquí como hojas
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getPathname();
            yield static::getRelativePath($root, $filePath) => $filePath;
        }
    }

    /**
     * Devuelve la ruta de un archivo relativa a la raíz de la copia, con '/' como separador.
     *
     * @param string $root Raíz de la copia, con '/' como separador y sin '/' final.
     * @param string $path Ruta absoluta del archivo o carpeta.
     */
    protected static function getRelativePath(string $root, string $path): string
    {
        return substr(str_replace(DIRECTORY_SEPARATOR, '/', $path), strlen($root) + 1);
    }

    /**
     * Comprime los archivos de FacturaScripts en el zip indicado: ZipStream si es posible
     * y ZipArchive en PHP de 32 bits o si ZipStream falla.
     *
     * @param string $fileName Ruta completa del zip a crear.
     * @param string $channel Canal de log donde registrar los errores.
     * @param string|null $sourceFolder Carpeta a comprimir. Por defecto, FS_FOLDER.
     */
    protected static function zipFolder(string $fileName, string $channel = '', ?string $sourceFolder = null): bool
    {
        // en PHP de 32 bits no se puede usar ZipStream 3.x
        if (false === static::canUseZipStream()) {
            return static::zipFolderWithZipArchive($fileName, $channel, $sourceFolder);
        }

        if (static::zipFolderWithZipStream($fileName, $channel, $sourceFolder)) {
            return true;
        }

        // si falla ZipStream, lo intentamos con ZipArchive
        return static::zipFolderWithZipArchive($fileName, $channel, $sourceFolder);
    }

    /**
     * Genera el zip con la extensión ZipArchive (requisito del plugin).
     * Es la alternativa a ZipStream para PHP de 32 bits o cuando ZipStream falla.
     * Si falla, registra el motivo como error.
     *
     * @param string $fileName Ruta completa del zip a crear.
     * @param string $channel Canal de log donde registrar los errores.
     * @param string|null $sourceFolder Carpeta a comprimir. Por defecto, FS_FOLDER.
     */
    protected static function zipFolderWithZipArchive(string $fileName, string $channel = '', ?string $sourceFolder = null): bool
    {
        // ZipArchive no puede abrir un archivo vacío ya creado, así que lo borramos antes
        if (file_exists($fileName)) {
            unlink($fileName);
        }

        $zip = new ZipArchive();
        $result = $zip->open($fileName, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            Tools::log($channel)->error('backup-files-error', ['%error%' => 'ZipArchive::open() ' . $result]);
            return false;
        }

        try {
            foreach (static::getFilesToZip($sourceFolder) as $relativePath => $filePath) {
                if (false === $zip->addFile($filePath, $relativePath)) {
                    Tools::log($channel)->error('backup-files-error', ['%error%' => $zip->getStatusString() . ': ' . $filePath]);
                    $zip->close();
                    return false;
                }
            }
        } catch (Throwable $e) {
            Tools::log($channel)->error('backup-files-error', ['%error%' => $e->getMessage()]);
            $zip->close();
            return false;
        }

        // close() escribe el archivo; si falla, la copia no es válida
        if (false === $zip->close()) {
            Tools::log($channel)->error('backup-files-error', ['%error%' => $zip->getStatusString()]);
            return false;
        }

        return true;
    }

    /**
     * Genera el zip con ZipStream (2.x o 3.x) escribiendo directamente al archivo, sin cargarlo en memoria.
     * Si falla, registra el motivo como aviso, porque a continuación se reintenta con ZipArchive.
     *
     * @param string $fileName Ruta completa del zip a crear.
     * @param string $channel Canal de log donde registrar los errores.
     * @param string|null $sourceFolder Carpeta a comprimir. Por defecto, FS_FOLDER.
     */
    protected static function zipFolderWithZipStream(string $fileName, string $channel = '', ?string $sourceFolder = null): bool
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

            foreach (static::getFilesToZip($sourceFolder) as $relativePath => $filePath) {
                $zip->addFileFromPath($relativePath, $filePath);
            }

            $zip->finish();
        } catch (Throwable $e) {
            Tools::log($channel)->warning('backup-files-error', ['%error%' => $e->getMessage()]);
            fclose($outputStream);
            return false;
        }

        fclose($outputStream);
        return true;
    }
}
