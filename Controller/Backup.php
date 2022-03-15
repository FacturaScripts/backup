<?php
/**
 * This file is part of Backup plugin for FacturaScripts
 * Copyright (C) 2021-2022 Carlos Garcia Gomez <carlos@facturascripts.com>
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

    /**
     * Returns basic page attributes
     *
     * @return array
     */
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
                return $this->downloadDbAction();

            case 'download-files':
                return $this->downloadFilesAction();

            case 'restore-backup':
                return $this->restoreBackupAction();
        }
    }

    private function downloadDbAction()
    {
        if (FS_DB_TYPE != 'mysql') {
            self::toolBox()::log()->error('mysql-support-only');
            return;
        }

        $this->setTemplate(false);
        SimpleBackup::setDatabase([FS_DB_NAME, FS_DB_USER, FS_DB_PASS, FS_DB_HOST])->downloadAfterExport(FS_DB_NAME);
    }

    private function downloadFilesAction()
    {
        $filePath = FS_FOLDER . '/' . FS_DB_NAME . '.zip';
        if (false === $this->zipFolder($filePath)) {
            $this->toolBox()->i18nLog()->error('record-save-error');
            return;
        }

        $this->setTemplate(false);
        $this->response = new BinaryFileResponse($filePath);
        $this->response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, FS_DB_NAME . '.zip');
    }

    private function restoreBackupAction()
    {
        $dbfile = $this->request->files->get('dbfile');
        if (empty($dbfile)) {
            return;
        }

        $this->dataBase->close();
        $backup = SimpleBackup::setDatabase([FS_DB_NAME, FS_DB_USER, FS_DB_PASS, FS_DB_HOST])->importFrom($dbfile->getPathname());
        if (false === $backup->getResponse()->status) {
            $this->toolBox()->i18nLog()->error('record-save-error');
            $this->dataBase->connect();
            return;
        }

        $this->toolBox()->i18nLog()->notice('record-updated-correctly');
        $this->dataBase->connect();
    }

    /**
     * @param string $fileName
     *
     * @return bool
     */
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
