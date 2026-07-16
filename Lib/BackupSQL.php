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
    /**
     * Normaliza un archivo SQL antes de restaurarlo, escribiendo el resultado en un temporal
     * y devolviendo su ruta. Solo modifica líneas ESTRUCTURALES:
     *  - asegura el ';' final en las líneas SET time_zone
     *  - fuerza ROW_FORMAT=DYNAMIC en la línea de opciones de tabla (empieza por ')') para
     *    evitar el error 1118 "Row size too large" al restaurar bajo innodb_strict_mode
     *
     * Las líneas de datos se copian tal cual, para no corromper valores multilínea (HTML/CSS,
     * texto con sangría) ni valores que contengan el texto "ENGINE=InnoDB" embebido.
     */
    public static function fixSqlFile(string $filePath): string
    {
        $file = fopen($filePath, 'r');
        if (false === $file) {
            return '';
        }

        // escribimos en MyFiles/Tmp con nombre único: la raíz de la instalación puede ser
        // accesible por web, y un nombre fijo colisiona entre restauraciones concurrentes
        $tmpFolder = Tools::folder('MyFiles', 'Tmp');
        if (false === Tools::folderCheckOrCreate($tmpFolder)) {
            fclose($file);
            return $filePath;
        }

        $newFilePath = Tools::folder('MyFiles', 'Tmp', uniqid('restore-', true) . '.sql');
        $newFile = fopen($newFilePath, 'w');
        if (false === $newFile) {
            fclose($file);
            return $filePath;
        }

        while (($buffer = fgets($file)) !== false) {
            $trimmed = trim($buffer);

            // aseguramos el ';' final en las líneas SET time_zone
            if (strpos($trimmed, 'SET time_zone') === 0 && substr($trimmed, -1) !== ';') {
                fwrite($newFile, $trimmed . ';' . PHP_EOL);
                continue;
            }

            // forzamos ROW_FORMAT=DYNAMIC solo en la línea de opciones de tabla (empieza por ')'),
            // nunca en líneas de datos que puedan contener "ENGINE=InnoDB" dentro de un valor
            if (strpos($trimmed, ')') === 0 && stripos($trimmed, 'ENGINE=InnoDB') !== false && stripos($trimmed, 'ROW_FORMAT') === false) {
                $line = substr($trimmed, -1) === ';'
                    ? substr($trimmed, 0, -1) . ' ROW_FORMAT=DYNAMIC;'
                    : $trimmed . ' ROW_FORMAT=DYNAMIC';
                fwrite($newFile, $line . PHP_EOL);
                continue;
            }

            // resto de líneas: se copian sin modificar
            fwrite($newFile, $buffer);
        }

        fclose($file);
        fclose($newFile);

        return $newFilePath;
    }

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

        // sentencias que se emiten al final del volcado: claves foráneas (necesario en
        // postgresql para evitar problemas de orden entre tablas; en mysql van inline en
        // el SHOW CREATE TABLE) y setval() de las secuencias, que debe ir tras los datos
        $deferredSql = [];

        foreach ($db->getTables() as $table) {
            // estructura de la tabla; si está vacía es una vista u otro objeto que no es
            // tabla, y no volcamos sus datos (romperían la restauración)
            $structure = static::tableStructure($db, $type, $table, $deferredSql);
            if ($structure === '') {
                continue;
            }
            fwrite($handle, $structure);

            // datos de la tabla (en streaming, paginado para no agotar memoria)
            static::tableData($db, $table, $handle);
        }

        // sentencias diferidas
        foreach ($deferredSql as $sql) {
            fwrite($handle, $sql . "\n");
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
     * El archivo se lee en streaming (nunca entero en memoria): solo se mantiene en memoria
     * la sentencia en curso, para que dumps de varios GB no agoten memory_limit.
     *
     * @return bool|string true si la importación fue correcta, o el mensaje de error si falla.
     */
    public static function restore(PDO $db, string $sqlFile)
    {
        $handle = fopen($sqlFile, 'r');
        if (false === $handle) {
            return Tools::trans('no-file-received');
        }

        // detectamos la codificación del volcado: los backups generados por la librería
        // antigua guardaban los datos en latin1. Si el contenido no es UTF-8 válido asumimos
        // latin1, para que el servidor convierta los bytes al charset de las columnas y no
        // falle con el error 1366 "Incorrect string value".
        $isUtf8 = static::isUtf8File($sqlFile);

        // arranque de sesión dependiente del motor. Todas las sentencias van protegidas:
        // si el servidor no permite cambiar la variable (falta de privilegios) lo ignoramos.
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            // fijamos el charset de la conexión según la codificación detectada del volcado
            try {
                $db->exec('SET NAMES ' . ($isUtf8 ? Tools::config('mysql_charset', 'utf8mb4') : 'latin1'));
            } catch (\Throwable $e) {
                // continuamos igualmente
            }
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
            // fijamos la codificación del cliente según la detectada del volcado
            try {
                $db->exec("SET client_encoding = '" . ($isUtf8 ? 'UTF8' : 'LATIN1') . "'");
            } catch (\Throwable $e) {
                // continuamos igualmente
            }
            // desactivamos los disparadores de claves foráneas (las FK se crean al final
            // del volcado, así que normalmente no es necesario, pero es una red de seguridad)
            try {
                $db->exec("SET session_replication_role = 'replica'");
            } catch (\Throwable $e) {
                // sin privilegios; continuamos igualmente
            }
        }

        // en mysql el backslash escapa caracteres dentro de cadenas; en postgresql
        // (standard_conforming_strings) es un carácter literal y las comillas solo
        // se escapan doblándolas: tratarlo como escape rompería valores acabados en '\'
        $backslashEscapes = $driver === 'mysql';

        $statement = '';
        $inString = false;
        $inIdentifier = false;
        $inLineComment = false;
        $inBlockComment = false;
        $buffer = '';
        $pos = 0;

        while (true) {
            // rellenamos el buffer garantizando al menos 3 bytes de lookahead mientras quede archivo
            if ($pos + 3 > strlen($buffer) && false === feof($handle)) {
                $chunk = fread($handle, 65536);
                $buffer = substr($buffer, $pos) . ($chunk === false ? '' : $chunk);
                $pos = 0;
            }
            if ($pos >= strlen($buffer)) {
                break;
            }

            $char = $buffer[$pos];

            // dentro de un comentario de línea: descartamos hasta el salto de línea
            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }
                $pos++;
                continue;
            }

            // dentro de un comentario de bloque: descartamos hasta el */
            if ($inBlockComment) {
                if ($char === '*' && ($buffer[$pos + 1] ?? '') === '/') {
                    $inBlockComment = false;
                    $pos += 2;
                    continue;
                }
                $pos++;
                continue;
            }

            // dentro de una cadena '...'
            if ($inString) {
                $statement .= $char;
                if ($backslashEscapes && $char === '\\' && isset($buffer[$pos + 1])) {
                    // carácter escapado: lo añadimos tal cual sin interpretarlo
                    $statement .= $buffer[$pos + 1];
                    $pos += 2;
                    continue;
                }
                if ($char === "'") {
                    // dos comillas seguidas '' es una comilla escapada: seguimos dentro
                    if (($buffer[$pos + 1] ?? '') === "'") {
                        $statement .= "'";
                        $pos += 2;
                        continue;
                    }
                    $inString = false;
                }
                $pos++;
                continue;
            }

            // dentro de un identificador `...`
            if ($inIdentifier) {
                $statement .= $char;
                if ($char === '`') {
                    $inIdentifier = false;
                }
                $pos++;
                continue;
            }

            // comentario de línea: -- (seguido de espacio/salto) o #
            if ($char === '-' && ($buffer[$pos + 1] ?? '') === '-'
                && in_array($buffer[$pos + 2] ?? "\n", [' ', "\t", "\n", "\r"], true)) {
                $inLineComment = true;
                $pos += 2;
                continue;
            }
            if ($char === '#') {
                $inLineComment = true;
                $pos++;
                continue;
            }

            // comentario de bloque /* ... */
            if ($char === '/' && ($buffer[$pos + 1] ?? '') === '*') {
                $inBlockComment = true;
                $pos += 2;
                continue;
            }

            if ($char === "'") {
                $inString = true;
                $statement .= $char;
                $pos++;
                continue;
            }

            if ($char === '`') {
                $inIdentifier = true;
                $statement .= $char;
                $pos++;
                continue;
            }

            // fin de sentencia
            if ($char === ';') {
                $error = static::execStatement($db, $statement);
                if (null !== $error) {
                    fclose($handle);
                    return $error;
                }
                $statement = '';
                $pos++;
                continue;
            }

            $statement .= $char;
            $pos++;
        }

        fclose($handle);

        // última sentencia sin ';' final
        $error = static::execStatement($db, $statement);
        if (null !== $error) {
            return $error;
        }

        return true;
    }

    /**
     * Comprueba en streaming si un archivo es UTF-8 válido, sin cargarlo entero en memoria.
     * Entre trozo y trozo se aparta la posible secuencia multibyte incompleta del final,
     * para no dar un falso negativo por cortar un carácter por la mitad.
     */
    private static function isUtf8File(string $filePath): bool
    {
        $handle = fopen($filePath, 'r');
        if (false === $handle) {
            return true;
        }

        $carry = '';
        while (false === feof($handle)) {
            $chunk = fread($handle, 262144);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $chunk = $carry . $chunk;
            $carry = '';

            // buscamos en los últimos 3 bytes el inicio de una secuencia multibyte
            // que podría continuar en el siguiente trozo, y la apartamos
            $len = strlen($chunk);
            for ($i = 1; $i <= 3 && $i <= $len; $i++) {
                $byte = ord($chunk[$len - $i]);
                if (($byte & 0b11000000) === 0b10000000) {
                    // byte de continuación: seguimos buscando el byte inicial
                    continue;
                }
                if (($byte & 0b11000000) === 0b11000000) {
                    $carry = substr($chunk, $len - $i);
                    $chunk = substr($chunk, 0, $len - $i);
                }
                break;
            }

            if (false === mb_check_encoding($chunk, 'UTF-8')) {
                fclose($handle);
                return false;
            }
        }
        fclose($handle);

        return $carry === '' || mb_check_encoding($carry, 'UTF-8');
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
     * Devuelve las columnas (escapadas) de la clave primaria de una tabla, en ambos motores.
     */
    private static function primaryKeyColumns(DataBase $db, string $table): array
    {
        $columns = [];
        foreach ($db->getConstraints($table, true) as $con) {
            if (strtoupper($con['type'] ?? '') === 'PRIMARY KEY' && !empty($con['column_name'])) {
                $columns[$con['column_name']] = $db->escapeColumn($con['column_name']);
            }
        }

        return array_values($columns);
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
     * Indica si una columna de postgresql es de tipo serie (default nextval de una secuencia).
     */
    private static function isSerialColumn(array $col): bool
    {
        return isset($col['default']) && is_string($col['default']) && stripos($col['default'], 'nextval(') !== false;
    }

    /**
     * Define una columna para el CREATE TABLE de postgresql.
     */
    private static function postgresqlColumnDef(array $col): string
    {
        $type = $col['type'];

        // columnas serie: si el default es nextval(...), usamos SERIAL/BIGSERIAL
        $isSerial = static::isSerialColumn($col);
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
     * Las claves foráneas y los setval() de secuencias se acumulan en $deferredSql
     * para emitirlos al final del volcado (los setval deben ir tras los datos).
     */
    private static function postgresqlTableStructure(DataBase $db, string $table, array &$deferredSql): string
    {
        $columns = $db->getColumns($table);
        if (empty($columns)) {
            return '';
        }

        $defs = [];
        foreach ($columns as $col) {
            $defs[] = '  ' . $db->escapeColumn($col['name']) . ' ' . static::postgresqlColumnDef($col);

            // las columnas serie se recrean como SERIAL, cuya secuencia arranca en 1:
            // hay que avanzarla tras insertar los datos o el siguiente INSERT colisiona
            if (static::isSerialColumn($col)) {
                $deferredSql[] = "SELECT setval(pg_get_serial_sequence('" . $table . "', '" . $col['name'] . "'),"
                    . ' COALESCE((SELECT MAX(' . $db->escapeColumn($col['name']) . ') FROM ' . $db->escapeColumn($table) . '), 0) + 1, false);';
            }
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

        // índices: la introspección devuelve una fila por columna, así que agrupamos por
        // nombre para reconstruir los índices compuestos con todas sus columnas
        $indexes = [];
        foreach ($db->getAllIndexes($table) as $idx) {
            if (empty($idx['name']) || empty($idx['column'])) {
                continue;
            }
            $indexes[$idx['name']][$idx['column']] = $db->escapeColumn($idx['column']);
        }
        foreach ($indexes as $idxName => $idxColumns) {
            // omitimos el índice de la clave primaria (todas sus columnas son de la PK)
            if (empty(array_diff_key($idxColumns, $pkColumns))) {
                continue;
            }
            $sql .= 'CREATE INDEX ' . $idxName . ' ON ' . $db->escapeColumn($table)
                . ' (' . implode(', ', $idxColumns) . ");\n";
        }

        // recogemos las claves foráneas para emitirlas al final, agrupando también
        // por nombre para no emitir una constraint por columna en claves compuestas
        $foreignKeys = [];
        foreach ($constraints as $con) {
            if (strtoupper($con['type'] ?? '') !== 'FOREIGN KEY' || empty($con['foreign_table_name'])) {
                continue;
            }
            $name = $con['name'];
            if (!isset($foreignKeys[$name])) {
                $foreignKeys[$name] = ['table' => $con['foreign_table_name'], 'columns' => [], 'foreign' => []];
            }
            $foreignKeys[$name]['columns'][$con['column_name']] = $db->escapeColumn($con['column_name']);
            $foreignKeys[$name]['foreign'][$con['foreign_column_name']] = $db->escapeColumn($con['foreign_column_name']);
        }
        foreach ($foreignKeys as $name => $fk) {
            $deferredSql[] = 'ALTER TABLE ' . $db->escapeColumn($table)
                . ' ADD CONSTRAINT ' . $name
                . ' FOREIGN KEY (' . implode(', ', $fk['columns']) . ')'
                . ' REFERENCES ' . $db->escapeColumn($fk['table'])
                . ' (' . implode(', ', $fk['foreign']) . ');';
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

        // ordenamos por la clave primaria (o por todas las columnas si no hay) para que la
        // paginación sea determinista: sin ORDER BY el motor no garantiza el mismo orden
        // entre páginas y pueden duplicarse u omitirse filas en el volcado
        $orderCols = static::primaryKeyColumns($db, $table) ?: $escapedCols;
        $sql = 'SELECT * FROM ' . $db->escapeColumn($table) . ' ORDER BY ' . implode(', ', $orderCols);

        $pageSize = 1000;
        $offset = 0;
        $maxBuffer = 1000000;

        while (true) {
            $rows = $db->selectLimit($sql, $pageSize, $offset);
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
    private static function tableStructure(DataBase $db, string $type, string $table, array &$deferredSql): string
    {
        if ($type === 'mysql') {
            return static::mysqlTableStructure($db, $table);
        }

        return static::postgresqlTableStructure($db, $table, $deferredSql);
    }
}