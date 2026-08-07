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
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipStream\Option\Archive;
use ZipStream\ZipStream;

/**
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class BackupFile
{
    /** @var array<string> Carpetas que se excluyen de la copia de seguridad. */
    private const EXCLUDED_FOLDERS = ['MyFiles/Backups/', 'MyFiles/Cache/', 'MyFiles/Tmp/', 'Dinamic/'];

    /**
     * Tamaño, en bytes, a partir del cual ZipStream deja de cargar el archivo entero en
     * memoria y lo procesa por streaming. Coincide con el valor por defecto de la librería.
     */
    private const LARGE_FILE_SIZE = 20 * 1024 * 1024;

    /**
     * @var int Bytes estimados que ocupa en memoria cada entrada del índice central del ZIP
     * (el objeto File de ZipStream más sus tres objetos Bigint internos y el nombre del archivo).
     */
    private const CDR_ENTRY_OVERHEAD = 1024;

    /** @var int Margen mínimo de memoria, en bytes, para imprevistos no medibles. */
    private const MIN_SAFETY_MARGIN = 32 * 1024 * 1024;

    /** @var float Colchón extra sobre el total calculado, para absorber gastos no modelados. */
    private const SAFETY_FACTOR = 1.5;

    public static function generate(string $channel = ''): bool
    {
        $folder = Tools::folder('MyFiles', 'Backups');
        if (false === Tools::folderCheckOrCreate($folder)) {
            Tools::log($channel)->error('folder-create-error');
            return false;
        }

        // recorremos el disco una sola vez: la misma lista sirve para estimar la
        // memoria necesaria y para generar el ZIP, así no se duplica el recorrido
        $files = iterator_to_array(static::scanFolder());

        // si no hay memoria suficiente y no se ha podido ampliar, abortamos aquí para
        // evitar el error fatal de PHP a mitad de la generación del ZIP
        if (false === static::ensureEnoughMemory($channel, $files)) {
            return false;
        }

        // creamos un archivo
        $file_path = Tools::folder('MyFiles', 'Backups', date('Y-m-d_H-i-s') . '.zip');
        if (false === static::zipFolder($file_path, $files)) {
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
     * Calcula la memoria que necesitará la generación del ZIP (lo ya consumido por la
     * petición actual, más el pico que puede provocar el mayor archivo a comprimir, más
     * un margen por el índice central que ZipStream mantiene en memoria) y, si el límite
     * de PHP no llega, intenta ampliarlo justo para esta operación.
     *
     * @param array<string, string> $files ruta absoluta => ruta relativa dentro del ZIP
     * @return bool true si hay memoria suficiente (o se ha podido ampliar) para continuar
     */
    private static function ensureEnoughMemory(string $channel, array $files): bool
    {
        $currentLimitBytes = static::memoryLimitToBytes(ini_get('memory_limit'));
        if ($currentLimitBytes === -1) {
            // sin límite de memoria, no hay nada que comprobar
            return true;
        }

        $requiredBytes = static::estimateRequiredMemory($files);
        if ($requiredBytes <= $currentLimitBytes) {
            return true;
        }

        // function_exists() evita un error fatal si el hosting ha deshabilitado ini_set()
        // por disable_functions, en cuyo caso la función deja de existir para PHP
        $increased = function_exists('ini_set') && false !== @ini_set('memory_limit', (string)$requiredBytes);
        if ($increased) {
            return true;
        }

        Tools::log($channel)->warning('backup-memory-warning', [
            '%size%' => round($requiredBytes / 1024 / 1024, 2),
            '%memory%' => round($currentLimitBytes / 1024 / 1024, 2)
        ]);
        return false;
    }

    /**
     * @param array<string, string> $files ruta absoluta => ruta relativa dentro del ZIP
     */
    private static function estimateRequiredMemory(array $files): int
    {
        $largestFileSize = 0;

        foreach (array_keys($files) as $filePath) {
            $size = filesize($filePath);
            if ($size !== false && $size < self::LARGE_FILE_SIZE && $size > $largestFileSize) {
                $largestFileSize = $size;
            }
        }

        // el archivo más grande puede llegar a ocupar varias veces su tamaño en memoria
        // mientras se comprime (original, copia comprimida y overhead del gestor de memoria)
        $safetyMargin = max(self::MIN_SAFETY_MARGIN, count($files) * self::CDR_ENTRY_OVERHEAD);
        $estimated = memory_get_usage(true) + (3 * $largestFileSize) + $safetyMargin;

        // colchón final sobre el total, para absorber cualquier otro gasto no modelado
        return (int)round($estimated * self::SAFETY_FACTOR);
    }

    private static function memoryLimitToBytes(string $memoryLimit): int
    {
        if ($memoryLimit === '-1') {
            return -1;
        }

        switch (strtoupper(substr($memoryLimit, -1))) {
            case 'G':
                return (int)substr($memoryLimit, 0, -1) * 1024 * 1024 * 1024;

            case 'M':
                return (int)substr($memoryLimit, 0, -1) * 1024 * 1024;

            case 'K':
                return (int)substr($memoryLimit, 0, -1) * 1024;

            default:
                return (int)$memoryLimit;
        }
    }

    /**
     * @param array<string, string> $files ruta absoluta => ruta relativa dentro del ZIP
     */
    protected static function zipFolder(string $fileName, array $files): bool
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

            foreach ($files as $filePath => $relativePath) {
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

    /**
     * Recorre FS_FOLDER y devuelve, para cada archivo que se incluye en la copia de
     * seguridad, su ruta absoluta como clave y su ruta relativa dentro del ZIP como valor.
     *
     * @return Generator<string, string>
     */
    private static function scanFolder(): Generator
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(FS_FOLDER),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getRealPath();
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($filePath, strlen(FS_FOLDER) + 1));

            // excluimos algunas carpetas (con '/' final para no excluir otras con el mismo prefijo)
            foreach (self::EXCLUDED_FOLDERS as $folder) {
                if (strpos($relativePath, $folder) === 0) {
                    continue 2;
                }
            }

            yield $filePath => $relativePath;
        }
    }
}
