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

use Coderatio\SimpleBackup\SimpleBackup;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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

            default:
                $this->defaultChecks();
                break;
        }
    }

    private function defaultChecks(): void
    {
        // obtenemos el límite de memoria
        $memoryLimit = ini_get('memory_limit');
        switch (substr($memoryLimit, -1)) {
            case 'G':
                $memoryMb = substr($memoryLimit, 0, -1) * 1024;
                break;

            case 'M':
                $memoryMb = substr($memoryLimit, 0, -1);
                break;

            case 'K':
                $memoryMb = round(substr($memoryLimit, 0, -1) / 1024, 2);
                break;

            case '-1':
                // no hay límite de memoria
                return;

            default:
                $memoryMb = (int)$memoryLimit;
                break;
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
        }

        // si el puerto no es el por defecto, mostramos un aviso
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
            ->downloadAfterExport(FS_DB_NAME . '_' . date('Y-m-d_H-i-s'));
    }

    private function downloadFilesAction(): void
    {
        $filePath = FS_FOLDER . '/' . FS_DB_NAME . '.zip';
        if (false === $this->zipFolder($filePath)) {
            Tools::log()->error('record-save-error');
            return;
        }

        $this->setTemplate(false);
        $this->response = new BinaryFileResponse($filePath);
        $this->response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            FS_DB_NAME . '_' . date('Y-m-d_H-i-s') . '.zip'
        );
    }

    private function restoreBackupAction(): void
    {
        if (false === $this->validateFormToken()) {
            return;
        }

        $dbFile = $this->request->files->get('dbfile');
        if (empty($dbFile)) {
            return;
        }

        $this->dataBase->close();
        $backup = SimpleBackup::setDatabase([FS_DB_NAME, FS_DB_USER, FS_DB_PASS, FS_DB_HOST])->importFrom($dbFile->getPathname());
        if (false === $backup->getResponse()->status) {
            Tools::log()->error('record-save-error');
            $this->dataBase->connect();
            return;
        }

        Tools::log()->notice('record-updated-correctly');
        $this->dataBase->connect();
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
            $relativePath = substr($filePath, strlen(FS_FOLDER) + 1);

            $zip->addFile($filePath, $relativePath);
        }

        return $zip->close();
    }
}
