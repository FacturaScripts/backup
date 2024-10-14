<?php
/**
 * This file is part of Backup plugin for FacturaScripts
 * Copyright (C) 2021-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Plugins\Backup\Controller;

use FacturaScripts\Plugins\Backup\Lib\SimpleBackup;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Backup and restore database and user files of application
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class Backup extends Controller
{
    /**
     * Return the max file size that can be uploaded.
     *
     * @return float
     */
    public function getMaxFileUpload()
    {
        return UploadedFile::getMaxFilesize() / 1024 / 1024;
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'backup';
        $data['icon'] = 'fas fa-download';
        return $data;
    }

    /**
     * Runs the controller's private logic.
     *
     * @param Response $response
     * @param User $user
     * @param ControllerPermissions $permissions
     */
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $action = $this->request->get('action', '');
        switch ($action) {
            case 'download-db':
                $this->downloadDbAction();
                break;

            case 'download-files':
                $this->downloadFilesAction();
                break;

            case 'restore-backup':
                $this->restoreBackupAction();
                break;

            case 'restore-files':
                $this->restoreFilesAction();
                break;

            case 'switch-db-charset':
                $this->switchDbCharsetAction();
                break;

            default:
                $this->defaultChecks();
                break;
        }
    }

    private function checkDbBackupCharset(string $filePath): bool
    {
        // abrimos el archivo
        $file = fopen($filePath, 'r');
        if (false === $file) {
            return false;
        }

        // leemos las primeras 1000 líneas, si encontramos el charset, devolvemos true
        $line = 0;
        $dbCharset = '';
        while ($line < 1000) {
            $line++;
            $buffer = fgets($file);
            if (false === $buffer) {
                break;
            }

            foreach (['utf8', 'utf8mb3', 'utf8mb4'] as $charset) {
                if (strpos($buffer, ' CHARSET=' . $charset . ' ') !== false) {
                    $dbCharset = $charset;
                    break 2;
                }
            }
        }

        // utf8mb3 es lo mismo que utf8
        if ($dbCharset === 'utf8mb3') {
            $dbCharset = 'utf8';
        }

        // comparamos con el charset del config.php
        $configCharset = Tools::config('mysql_charset', 'utf8');
        if ($dbCharset === $configCharset) {
            fclose($file);
            return true;
        }

        Tools::log()->error('backup-charset-error', [
            '%db-charset%' => $dbCharset,
            '%config-charset%' => $configCharset
        ]);
        fclose($file);
        return false;
    }

    private function defaultChecks(): void
    {
        // obtenemos el límite de memoria
        $memoryMb = $this->getMemoryLimitMb();
        if ($memoryMb === -1) {
            return;
        }

        // calculamos el tamaño de la carpeta FS_FOLDER
        $folderSize = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(FS_FOLDER),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $folderSize += $file->getSize();
        }
        $folderMb = round($folderSize / 1024 / 1024, 2);

        // si la carpeta FS_FOLDER ocupa más que el límite de memoria, mostramos un aviso
        if ($folderMb >= $memoryMb) {
            Tools::log()->warning('backup-memory-warning', [
                '%size%' => $folderMb,
                '%memory%' => $memoryMb
            ]);
        }
    }

    private function downloadDbAction(): void
    {
        if (FS_DB_TYPE != 'mysql') {
            Tools::log()->error('mysql-support-only');
            return;
        } elseif ($this->permissions->allowExport === false) {
            Tools::log()->error('not-allowed-export');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        // si el puerto no es el puerto por defecto, mostramos un aviso
        if (FS_DB_PORT != 3306) {
            Tools::log()->warning('backup-port-warning', [
                '%port%' => FS_DB_PORT
            ]);
        }

        if (false === extension_loaded('pdo_mysql')) {
            Tools::log()->error('pdo-mysql-support-only');
            return;
        }

        $this->setTemplate(false);
        SimpleBackup::setDatabase([FS_DB_NAME, FS_DB_USER, FS_DB_PASS, FS_DB_HOST])
            ->storeAfterExportTo(FS_FOLDER."/MyFiles/Backups/",FS_DB_NAME . '_' . date('Y-m-d_H-i-s'));
    }

    private function downloadFilesAction(): void
    {
        if ($this->permissions->allowExport === false) {
            Tools::log()->error('not-allowed-export');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        // creamos un archivo temporal
        $filePath = Tools::folder(FS_DB_NAME . '_' . date('Y-m-d_H-i-s') . '.zip');
        if (false === $this->zipFolder($filePath)) {
            Tools::log()->error('record-save-error');
            return;
        }

        $this->setTemplate(false);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);

        unlink($filePath);
    }

    private function fixSqlFile(string $filePath): string
    {
        // abrimos el archivo
        $file = fopen($filePath, 'r');
        if (false === $file) {
            return '';
        }

        // creamos un archivo temporal
        $newFilePath = Tools::folder('temp.sql');
        $newFile = fopen($newFilePath, 'w');
        if (false === $newFile) {
            fclose($file);
            return $filePath;
        }

        // leemos el archivo línea a línea
        while ($buffer = fgets($file)) {
            $line = trim($buffer);

            // si la línea es SET time_zone, nos aseguramos de que termine en ;
            if (strpos($line, 'SET time_zone') === 0 && substr($line, -1) !== ';') {
                $line .= ';';
            }

            // añadimos la línea al archivo temporal
            fwrite($newFile, $line . PHP_EOL);
        }

        // cerramos los archivos
        fclose($file);
        fclose($newFile);

        return $newFilePath;
    }

    private function getMemoryLimitMb(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return -1;
        }

        switch (substr($memoryLimit, -1)) {
            case 'G':
                return substr($memoryLimit, 0, -1) * 1024;

            case 'M':
                return substr($memoryLimit, 0, -1);

            case 'K':
                return round(substr($memoryLimit, 0, -1) / 1024, 2);

            default:
                return (int)$memoryLimit;
        }
    }

    private function moveFiles(): void
    {
        // si existe la carpeta Plugins, copiamos los archivos a la carpeta correspondiente
        if (is_dir(Tools::folder('zip_backup', 'Plugins'))) {
            foreach (Tools::folderScan(Tools::folder('zip_backup', 'Plugins')) as $file) {
                $dest = Tools::folder('Plugins', $file);
                if (file_exists($dest)) {
                    continue;
                }

                $src = Tools::folder('zip_backup', 'Plugins', $file);
                if (is_dir($src)) {
                    Tools::folderCopy($src, $dest);
                }
            }
        }

        // si existe la carpeta MyFiles, copiamos los archivos a la carpeta correspondiente
        if (is_dir(Tools::folder('zip_backup', 'MyFiles'))) {
            foreach (Tools::folderScan(Tools::folder('zip_backup', 'MyFiles')) as $file) {
                $dest = Tools::folder('MyFiles', $file);
                if (file_exists($dest)) {
                    continue;
                }

                $src = Tools::folder('zip_backup', 'MyFiles', $file);
                if (is_dir($src)) {
                    Tools::folderCopy($src, $dest);
                }
            }
        } else {
            // no existe la carpeta MyFiles en el xip, así que copiamos los archivos a la carpeta MyFiles
            foreach (Tools::folderScan(Tools::folder('zip_backup')) as $file) {
                $dest = Tools::folder('MyFiles', $file);
                if (file_exists($dest)) {
                    continue;
                }

                $src = Tools::folder('zip_backup', $file);
                if (is_dir($src)) {
                    Tools::folderCopy($src, $dest);
                }
            }
        }
    }

    private function restoreBackupAction(): void
    {
        if (false === $this->validateFormToken()) {
            return;
        } elseif ($this->permissions->allowImport === false) {
            Tools::log()->error('not-allowed-import');
            return;
        }

        $dbFile = $this->request->files->get('db_file');
        if (empty($dbFile)) {
            return;
        }

        // si el archivo es .sql.gz, lo convertimos a .sql
        if (substr($dbFile->getClientOriginalName(), -7) === '.sql.gz') {
            $sqlFile = $this->unzipDatabase($dbFile->getPathname());
        } else {
            $sqlFile = $this->fixSqlFile($dbFile->getPathname());
        }

        if (empty($sqlFile)) {
            Tools::log()->error('no-file-received');
            return;
        }

        // comprobamos si el charset en el backup es el mismo que en el config.php
        if (false === $this->checkDbBackupCharset($sqlFile)) {
            unlink($sqlFile);
            return;
        }

        // eliminamos todas las tablas
        $this->dataBase->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->dataBase->getTables() as $table) {
            $this->dataBase->exec('DROP TABLE ' . $table);
        }
        $this->dataBase->close();

        // importamos el backup
        $backup = SimpleBackup::setDatabase([FS_DB_NAME, FS_DB_USER, FS_DB_PASS, FS_DB_HOST])->importFrom($sqlFile);
        if (false === $backup->getResponse()->status) {
            Tools::log()->error('record-save-error');
            $this->dataBase->connect();
            Cache::clear();
            unlink($sqlFile);
            return;
        }

        Tools::log()->notice('record-updated-correctly');
        $this->dataBase->connect();
        Cache::clear();
        unlink($sqlFile);

        // eliminamos las cookies
        setcookie('fsNick', '', time() - 3600, Tools::config('route', '/'));
        setcookie('fsLogkey', '', time() - 3600, Tools::config('route', '/'));

        $this->redirect('login');
    }

    private function restoreFilesAction(): void
    {
        if (false === $this->validateFormToken()) {
            return;
        } elseif ($this->permissions->allowImport === false) {
            Tools::log()->error('not-allowed-import');
            return;
        }

        $zipFile = $this->request->files->get('zip_file');
        if (empty($zipFile)) {
            return;
        }

        $zip = new ZipArchive();
        if (false === $zip->open($zipFile->getPathname())) {
            Tools::log()->error('zip error');
            return;
        }

        // si ya existe la carpeta zip_backup, la eliminamos
        Tools::folderDelete(Tools::folder('zip_backup'));

        // extraemos el contenido dentro de la carpeta zip_backup
        if (false === $zip->extractTo(Tools::folder('zip_backup'))) {
            Tools::log()->error('zip extract error');
            return;
        }
        $zip->close();

        $this->moveFiles();

        // eliminamos la carpeta zip_backup
        Tools::folderDelete(Tools::folder('zip_backup'));

        Tools::log()->notice('record-updated-correctly');
    }

    private function switchDbCharsetAction(): void
    {
        if (false === $this->validateFormToken()) {
            return;
        } elseif ($this->permissions->allowUpdate === false) {
            Tools::log()->error('not-allowed-update');
            return;
        }

        // leemos el archivo config.php
        $configFile = file_get_contents(Tools::folder('config.php'));

        $configCharset = Tools::config('mysql_charset', 'utf8');
        $configCollate = Tools::config('mysql_collate', 'utf8_bin');

        $selectedCharset = $this->request->query->get('charset');
        switch ($selectedCharset) {
            case 'utf8':
                $configFile = str_replace("'" . $configCharset . "'", "'utf8'", $configFile);
                $configFile = str_replace("'" . $configCollate . "'", "'utf8_bin'", $configFile);
                break;

            case 'utf8mb4':
                $configFile = str_replace("'" . $configCharset . "'", "'utf8mb4'", $configFile);
                $configFile = str_replace("'" . $configCollate . "'", "'utf8mb4_unicode_520_ci'", $configFile);
                break;

            default:
                return;
        }

        // guardamos el archivo
        if (false === file_put_contents(Tools::folder('config.php'), $configFile)) {
            Tools::log()->error('record-save-error');
            return;
        }

        Tools::log()->notice('record-updated-correctly');
    }

    private function unzipDatabase(string $gzFilePath): string
    {
        // abrimos el archivo .sql.gz
        $gzFile = gzopen($gzFilePath, 'r');
        if (false === $gzFile) {
            Tools::log()->error('record-save-error');
            return '';
        }

        // creamos el archivo .sql
        $name = substr($gzFilePath, 0, -3);
        $sqlFile = fopen($name, 'w');
        if (false === $sqlFile) {
            gzclose($gzFile);
            Tools::log()->error('record-save-error');
            return '';
        }

        // copiamos el contenido del archivo .sql.gz al archivo .sql
        while ($buffer = gzread($gzFile, 4096)) {
            fwrite($sqlFile, $buffer);
        }

        // cerramos los archivos
        gzclose($gzFile);
        fclose($sqlFile);

        return $name;
    }

    private function zipFolder(string $fileName): bool
    {
        $zip = new ZipArchive();
        if (false === $zip->open($fileName, ZIPARCHIVE::CREATE | ZipArchive::OVERWRITE)) {
            return false;
        }

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

            $zip->addFile($filePath, $relativePath);
        }

        return $zip->close();
    }
}
