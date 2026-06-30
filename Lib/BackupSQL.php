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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use PDO;

/**
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class BackupSQL
{
    public static function generate(string $channel = ''): bool
    {
        $folder = Tools::folder('MyFiles', 'Backups');
        if (false === Tools::folderCheckOrCreate($folder)) {
            Tools::log($channel)->error('folder-create-error');
            return false;
        }

        $db = new DataBase();
        $type = $db->type();
        if (false === in_array($type, ['mysql', 'postgresql'], true)) {
            Tools::log($channel)->error('mysql-support-only');
            return false;
        }

        $file_path = Tools::folder('MyFiles', 'Backups', date('Y-m-d_H-i-s') . '.sql');
        $handle = fopen($file_path, 'w');
        if (false === $handle) {
            Tools::log($channel)->error('record-save-error');
            return false;
        }

        // cabecera del volcado
        fwrite($handle, static::dumpHeader($db, $type));

        // las claves foráneas se emiten al final (necesario en postgresql para evitar
        // problemas de orden entre tablas; en mysql van inline en el SHOW CREATE TABLE)
        $deferredForeignKeys = [];

        foreach ($db->getTables() as $table) {
            // estructura de la tabla
            fwrite($handle, static::tableStructure($db, $type, $table, $deferredForeignKeys));

            // datos de la tabla (en streaming, paginado para no agotar memoria)
            static::tableData($db, $table, $handle);
        }

        // claves foráneas diferidas
        foreach ($deferredForeignKeys as $fkSql) {
            fwrite($handle, $fkSql . "\n");
        }

        fclose($handle);

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

        // arranque de sesión dependiente del motor. Todas las sentencias van protegidas:
        // si el servidor no permite cambiar la variable (falta de privilegios) lo ignoramos.
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            // desactivamos el modo estricto de InnoDB (red de seguridad ante errores de
            // formato de fila) y la comprobación de claves foráneas durante la importación
            try {
                $db->exec('SET SESSION innodb_strict_mode = OFF');
            } catch (\Throwable $e) {
                // sin privilegios para cambiar la variable; continuamos igualmente
            }
            try {
                $db->exec('SET FOREIGN_KEY_CHECKS = 0');
            } catch (\Throwable $e) {
                // continuamos igualmente
            }
        } elseif ($driver === 'pgsql') {
            // desactivamos los disparadores de claves foráneas (las FK se crean al final
            // del volcado, así que normalmente no es necesario, pero es una red de seguridad)
            try {
                $db->exec("SET session_replication_role = 'replica'");
            } catch (\Throwable $e) {
                // sin privilegios; continuamos igualmente
            }
        }

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
     * Devuelve la cabecera del volcado SQL, dependiente del motor.
     */
    private static function dumpHeader(DataBase $db, string $type): string
    {
        $header = '-- FacturaScripts SQL backup' . "\n"
            . '-- Database: ' . Tools::config('db_name') . "\n"
            . '-- Engine: ' . $type . ' (' . $db->version() . ')' . "\n"
            . '-- Generated: ' . Tools::dateTime() . "\n\n";

        if ($type === 'mysql') {
            // SET NAMES y desactivar comprobación de FK (sentencias sin privilegios especiales)
            $header .= 'SET NAMES ' . Tools::config('mysql_charset', 'utf8') . ";\n"
                . "SET FOREIGN_KEY_CHECKS = 0;\n\n";
        }

        return $header;
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

    /**
     * Formatea un valor para SQL según el tipo de su columna, con escapado seguro.
     */
    private static function formatValue(DataBase $db, array $column, $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $type = strtolower($column['type'] ?? '');

        // tipos numéricos: valor crudo (sin comillas)
        if (preg_match('/^(int|integer|bigint|smallint|mediumint|tinyint|decimal|numeric|float|double|real|serial|bigserial)/', $type) && is_numeric($value)) {
            return (string)$value;
        }

        // tipos binarios: literal hexadecimal
        if (preg_match('/(blob|binary|bytea)/', $type)) {
            $hex = bin2hex($value);
            if ($hex === '') {
                return "''";
            }
            return $db->type() === 'postgresql' ? "'\\x" . $hex . "'" : '0x' . $hex;
        }

        // resto: cadena escapada según el motor
        return "'" . $db->escapeString($value) . "'";
    }

    /**
     * Estructura de tabla en mysql/mariadb mediante SHOW CREATE TABLE (exacto).
     */
    private static function mysqlTableStructure(DataBase $db, string $table): string
    {
        $rows = $db->select('SHOW CREATE TABLE ' . $db->escapeColumn($table));
        if (empty($rows) || false === isset($rows[0]['Create Table'])) {
            // podría ser una vista u otro objeto que no es tabla: lo ignoramos
            return '';
        }

        $create = $rows[0]['Create Table'];

        // forzamos ROW_FORMAT=DYNAMIC si no lo trae, para evitar el error 1118
        // "Row size too large" al restaurar (habitual con utf8mb4 y filas anchas)
        if (stripos($create, 'ENGINE=InnoDB') !== false && stripos($create, 'ROW_FORMAT') === false) {
            $create .= ' ROW_FORMAT=DYNAMIC';
        }

        return '--' . "\n" . '-- Estructura de la tabla `' . $table . '`' . "\n" . '--' . "\n"
            . 'DROP TABLE IF EXISTS ' . $db->escapeColumn($table) . ";\n"
            . $create . ";\n\n";
    }

    /**
     * Define una columna para el CREATE TABLE de postgresql.
     */
    private static function postgresqlColumnDef(array $col): string
    {
        $type = $col['type'];

        // columnas serie: si el default es nextval(...), usamos SERIAL/BIGSERIAL
        $isSerial = isset($col['default']) && is_string($col['default']) && stripos($col['default'], 'nextval(') !== false;
        if ($isSerial) {
            $def = (stripos($type, 'big') !== false) ? 'BIGSERIAL' : 'SERIAL';
        } elseif (!empty($col['character_maximum_length']) && stripos($type, 'char') !== false) {
            $def = $type . '(' . $col['character_maximum_length'] . ')';
        } else {
            $def = $type;
        }

        if (($col['is_nullable'] ?? 'YES') === 'NO') {
            $def .= ' NOT NULL';
        }

        if (false === $isSerial && isset($col['default']) && $col['default'] !== null && $col['default'] !== '') {
            $def .= ' DEFAULT ' . $col['default'];
        }

        return $def;
    }

    /**
     * Estructura de tabla en postgresql reconstruida desde la introspección del core.
     * Las claves foráneas se acumulan en $deferredForeignKeys para emitirlas al final.
     */
    private static function postgresqlTableStructure(DataBase $db, string $table, array &$deferredForeignKeys): string
    {
        $columns = $db->getColumns($table);
        if (empty($columns)) {
            return '';
        }

        $defs = [];
        foreach ($columns as $col) {
            $defs[] = '  ' . $db->escapeColumn($col['name']) . ' ' . static::postgresqlColumnDef($col);
        }

        $constraints = $db->getConstraints($table, true);

        // clave primaria
        $pkColumns = [];
        foreach ($constraints as $con) {
            if (strtoupper($con['type'] ?? '') === 'PRIMARY KEY' && !empty($con['column_name'])) {
                $pkColumns[$con['column_name']] = $db->escapeColumn($con['column_name']);
            }
        }
        if ($pkColumns) {
            $defs[] = '  PRIMARY KEY (' . implode(', ', $pkColumns) . ')';
        }

        $sql = '--' . "\n" . '-- Estructura de la tabla "' . $table . '"' . "\n" . '--' . "\n"
            . 'DROP TABLE IF EXISTS ' . $db->escapeColumn($table) . " CASCADE;\n"
            . 'CREATE TABLE ' . $db->escapeColumn($table) . " (\n"
            . implode(",\n", $defs)
            . "\n);\n";

        // índices que no sean la clave primaria
        foreach ($db->getAllIndexes($table) as $idx) {
            if (empty($idx['name']) || empty($idx['column']) || isset($pkColumns[$idx['column']])) {
                continue;
            }
            $sql .= 'CREATE INDEX ' . $idx['name'] . ' ON ' . $db->escapeColumn($table)
                . ' (' . $db->escapeColumn($idx['column']) . ");\n";
        }

        // recogemos las claves foráneas para emitirlas al final
        foreach ($constraints as $con) {
            if (strtoupper($con['type'] ?? '') === 'FOREIGN KEY' && !empty($con['foreign_table_name'])) {
                $deferredForeignKeys[] = 'ALTER TABLE ' . $db->escapeColumn($table)
                    . ' ADD CONSTRAINT ' . $con['name']
                    . ' FOREIGN KEY (' . $db->escapeColumn($con['column_name']) . ')'
                    . ' REFERENCES ' . $db->escapeColumn($con['foreign_table_name'])
                    . ' (' . $db->escapeColumn($con['foreign_column_name']) . ');';
            }
        }

        return $sql . "\n";
    }

    /**
     * Vuelca los datos de una tabla al archivo en streaming, paginando para no agotar memoria.
     */
    private static function tableData(DataBase $db, string $table, $handle): void
    {
        $columns = $db->getColumns($table);
        if (empty($columns)) {
            return;
        }

        $colNames = array_keys($columns);
        $escapedCols = [];
        foreach ($colNames as $name) {
            $escapedCols[] = $db->escapeColumn($name);
        }
        $intoPrefix = 'INSERT INTO ' . $db->escapeColumn($table)
            . ' (' . implode(', ', $escapedCols) . ') VALUES ';

        $pageSize = 1000;
        $offset = 0;
        $maxBuffer = 1000000;

        while (true) {
            $rows = $db->selectLimit('SELECT * FROM ' . $db->escapeColumn($table), $pageSize, $offset);
            if (empty($rows)) {
                break;
            }

            $buffer = '';
            $bufferRows = 0;
            foreach ($rows as $row) {
                $values = [];
                foreach ($colNames as $name) {
                    $values[] = static::formatValue($db, $columns[$name], $row[$name] ?? null);
                }
                $tuple = '(' . implode(', ', $values) . ')';

                $buffer .= ($bufferRows === 0) ? ($intoPrefix . $tuple) : (',' . $tuple);
                $bufferRows++;

                // cerramos el INSERT si el buffer crece demasiado (límite max_allowed_packet)
                if (strlen($buffer) >= $maxBuffer) {
                    fwrite($handle, $buffer . ";\n");
                    $buffer = '';
                    $bufferRows = 0;
                }
            }

            if ($bufferRows > 0) {
                fwrite($handle, $buffer . ";\n");
            }

            $offset += $pageSize;
        }

        fwrite($handle, "\n");
    }

    /**
     * Devuelve el DDL de la estructura de una tabla. En mysql/mariadb usa SHOW CREATE TABLE;
     * en postgresql lo reconstruye desde la introspección del core.
     */
    private static function tableStructure(DataBase $db, string $type, string $table, array &$deferredForeignKeys): string
    {
        if ($type === 'mysql') {
            return static::mysqlTableStructure($db, $table);
        }

        return static::postgresqlTableStructure($db, $table, $deferredForeignKeys);
    }
}