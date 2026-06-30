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

    /**
     * Importa un archivo SQL en la conexión PDO indicada, ejecutando las sentencias una a una.
     *
     * Usa un troceado propio que respeta las cadenas entrecomilladas, los identificadores con
     * acentos graves y los comentarios. Así no parte una sentencia por un ';' que esté dentro
     * de un valor (HTML, CSS, texto multilínea, etc.), cosa que sí hacía la librería vendor.
     *
     * @return bool|string true si la importación fue correcta, o el mensaje de error si falla.
     */
    public static function restore(PDO $db, string $sqlFile)
    {
        $content = file_get_contents($sqlFile);
        if (false === $content) {
            return Tools::trans('no-file-received');
        }

        // intentamos desactivar el modo estricto de InnoDB (red de seguridad ante errores de
        // formato de fila). Algunos servidores no lo permiten por falta de privilegios; en ese
        // caso lo ignoramos y continuamos.
        try {
            $db->exec('SET SESSION innodb_strict_mode = OFF');
        } catch (\Throwable $e) {
            // sin privilegios para cambiar la variable; continuamos igualmente
        }

        // desactivamos la comprobación de claves foráneas durante la importación
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');

        $statement = '';
        $length = strlen($content);
        $inString = false;
        $inIdentifier = false;
        $i = 0;

        while ($i < $length) {
            $char = $content[$i];

            // dentro de una cadena '...'
            if ($inString) {
                $statement .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    // carácter escapado: lo añadimos tal cual sin interpretarlo
                    $statement .= $content[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($char === "'") {
                    // dos comillas seguidas '' es una comilla escapada: seguimos dentro
                    if ($i + 1 < $length && $content[$i + 1] === "'") {
                        $statement .= "'";
                        $i += 2;
                        continue;
                    }
                    $inString = false;
                }
                $i++;
                continue;
            }

            // dentro de un identificador `...`
            if ($inIdentifier) {
                $statement .= $char;
                if ($char === '`') {
                    $inIdentifier = false;
                }
                $i++;
                continue;
            }

            // comentario de línea: -- (seguido de espacio/salto) o #
            if (($char === '-' && $i + 2 < $length && $content[$i + 1] === '-'
                    && in_array($content[$i + 2], [' ', "\t", "\n", "\r"], true))
                || $char === '#') {
                while ($i < $length && $content[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // comentario de bloque /* ... */
            if ($char === '/' && $i + 1 < $length && $content[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $length && !($content[$i] === '*' && $content[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }

            if ($char === "'") {
                $inString = true;
                $statement .= $char;
                $i++;
                continue;
            }

            if ($char === '`') {
                $inIdentifier = true;
                $statement .= $char;
                $i++;
                continue;
            }

            // fin de sentencia
            if ($char === ';') {
                $error = static::execStatement($db, $statement);
                if (null !== $error) {
                    return $error;
                }
                $statement = '';
                $i++;
                continue;
            }

            $statement .= $char;
            $i++;
        }

        // última sentencia sin ';' final
        $error = static::execStatement($db, $statement);
        if (null !== $error) {
            return $error;
        }

        return true;
    }

    /**
     * Ejecuta una sentencia SQL. Devuelve null si fue correcta o ignorable, o el mensaje de error.
     */
    private static function execStatement(PDO $db, string $statement): ?string
    {
        $statement = trim($statement);
        if ($statement === '') {
            return null;
        }

        try {
            $db->exec($statement);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }
}