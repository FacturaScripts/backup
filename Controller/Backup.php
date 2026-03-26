<?php
/**
 * This file is part of Backup plugin for FacturaScripts
 * Copyright (C) 2021-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use DatabaseBackupManager\MySQLBackup;
use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\UploadedFile;
use FacturaScripts\Dinamic\Lib\BackupFile;
use FacturaScripts\Dinamic\Lib\BackupSQL;
use FacturaScripts\Dinamic\Model\User;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Backup and restore database and user files of application
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class Backup extends Controller
{
	/** @var string */
	public $active_tab = 'download';

	/** @var array */
	public $backup_list = [];

	/** @var string */
	public $current_charset = '';

	/** @var string */
	public $cron_frequency = '';

	/** @var int */
	public $cron_hour = 3;

	/** @var int */
	public $cron_weekly_day = 1;

	/** @var int */
	public $cron_monthly_day = 1;

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
		$data['icon'] = 'fa-solid fa-download';
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

		$this->active_tab = $this->request->input('active_tab', 'download');
		$this->current_charset = Tools::config('mysql_charset', 'utf8');

		// cargamos la configuración del cron
		$this->cron_frequency = Tools::settings('backup', 'frequency', '1 week');
		$this->cron_hour = Tools::settings('backup', 'hour', 3);
		$this->cron_weekly_day = Tools::settings('backup', 'weekly_day', 1);
		$this->cron_monthly_day = Tools::settings('backup', 'monthly_day', 1);

		$action = $this->request->input('action', '');
		switch ($action) {
			case 'create-sql-file':
				$this->createSqlAction();
				break;

			case 'create-zip-file':
				$this->createZipAction();
				break;

			case 'delete-backup':
				$this->deleteBackupAction();
				break;

			case 'download-sql-file':
				$this->downloadSqlAction();
				break;

			case 'download-zip-file':
				$this->downloadZipAction();
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

		$this->loadBackupFiles();
	}

	private function checkDbBackupCharset(string $filePath): bool
	{
		// abrimos el archivo
		$file = fopen($filePath, 'r');
		if (false === $file) {
			return false;
		}

		// leemos las primeras 1000 líneas y recopilamos todos los charsets encontrados
		$line = 0;
		$foundCharsets = [];
		while ($line < 1000) {
			$line++;
			$buffer = fgets($file);
			if (false === $buffer) {
				break;
			}

			foreach (['utf8', 'utf8mb3', 'utf8mb4'] as $charset) {
				if (strpos($buffer, ' CHARSET=' . $charset . ' ') !== false) {
					// utf8mb3 es lo mismo que utf8
					$normalizedCharset = $charset === 'utf8mb3' ? 'utf8' : $charset;
					$foundCharsets[$normalizedCharset] = true;
				}
			}
		}
		fclose($file);

		// si no encontramos ningún charset, asumimos que es compatible
		if (empty($foundCharsets)) {
			return true;
		}

		// convertimos el array a lista de charsets únicos
		$uniqueCharsets = array_keys($foundCharsets);

		// comparamos con el charset del config.php
		$configCharset = Tools::config('mysql_charset', 'utf8');

		// si hay múltiples charsets mezclados, mostramos un aviso
		if (count($uniqueCharsets) > 1) {
			Tools::log()->warning('backup-charset-mixed-warning', [
				'%charsets%' => implode(', ', $uniqueCharsets),
				'%config-charset%' => $configCharset
			]);
			Tools::log()->info('backup-use-fixer-plugin');
		}

		// verificamos si el charset configurado está entre los encontrados
		if (in_array($configCharset, $uniqueCharsets)) {
			return true;
		}

		// si solo hay un charset y no coincide, mostramos error
		Tools::log()->error('backup-charset-error', [
			'%db-charset%' => implode(', ', $uniqueCharsets),
			'%config-charset%' => $configCharset
		]);
		return false;
	}

	protected function createSqlAction(): void
	{
		if ($this->permissions->allowExport === false) {
			Tools::log()->error('not-allowed-export');
			return;
		} elseif (false === $this->validateFormToken()) {
			return;
		}

		if (BackupSQL::generate()) {
			Tools::log()->notice('file-ready-to-download');
			return;
		}

		Tools::log()->error('record-save-error');
	}

	protected function createZipAction(): void
	{
		if ($this->permissions->allowExport === false) {
			Tools::log()->error('not-allowed-export');
			return;
		} elseif (false === $this->validateFormToken()) {
			return;
		}

		if (BackupFile::generate()) {
			Tools::log()->notice('file-ready-to-download');
			return;
		}

		Tools::log()->error('record-save-error');
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

	private function deleteBackupAction(): void
	{
		if ($this->permissions->allowDelete === false) {
			Tools::log()->error('not-allowed-delete');
			return;
		} elseif (false === $this->validateFormToken()) {
			return;
		}

		$db_file = $this->request->request->get('db_file', '');
		$zip_file = $this->request->request->get('zip_file', '');
		if (empty($db_file) && empty($zip_file)) {
			Tools::log()->warning('no-file-received');
			return;
		}

		if ($db_file) {
			$db_file_path = Tools::folder('MyFiles', 'Backups', $db_file);
			if (false === file_exists($db_file_path)) {
				Tools::log()->error('file-not-found');
				return;
			}

			unlink($db_file_path);
		}

		if ($zip_file) {
			$zip_file_path = Tools::folder('MyFiles', 'Backups', $zip_file);
			if (false === file_exists($zip_file_path)) {
				Tools::log()->error('file-not-found');
				return;
			}

			unlink($zip_file_path);
		}

		Tools::log()->notice('record-deleted-correctly');
	}

	private function downloadSqlAction(): void
	{
		if ($this->permissions->allowExport === false) {
			Tools::log()->error('not-allowed-export');
			return;
		} elseif (false === $this->validateFormToken()) {
			return;
		}

		$file_name = $this->request->request->get('file_name', '');
		if (empty($file_name)) {
			Tools::log()->warning('no-file-received');
			return;
		}

		$file_path = Tools::folder('MyFiles', 'Backups', $file_name);
		if (false === file_exists($file_path)) {
			Tools::log()->error('file-not-found');
			return;
		}

		$this->setTemplate(false);
		$this->response->file($file_path, Tools::config('db_name') . '_' . $file_name, 'attachment');
	}

	private function downloadZipAction(): void
	{
		if ($this->permissions->allowExport === false) {
			Tools::log()->error('not-allowed-export');
			return;
		} elseif (false === $this->validateFormToken()) {
			return;
		}

		$file_name = $this->request->request->get('file_name', '');
		if (empty($file_name)) {
			Tools::log()->warning('no-file-received');
			return;
		}

		$file_path = Tools::folder('MyFiles', 'Backups', $file_name);
		if (false === file_exists($file_path)) {
			Tools::log()->error('file-not-found');
			return;
		}

		$this->setTemplate(false);
		$this->response->file($file_path, Tools::config('db_name') . '_' . $file_name, 'attachment');
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

	protected function loadBackupFiles(): void
	{
		// buscamos todos los archivos sql de la carpeta MyFiles/Backups
		$folder = Tools::folder('MyFiles', 'Backups');
		if (false === Tools::folderCheckOrCreate($folder)) {
			Tools::log()->error('folder-create-error');
			return;
		}

		foreach (Tools::folderScan($folder) as $file) {
			// comprobamos si es un archivo .sql
			if (substr($file, -4) === '.sql') {
				$key = substr($file, 0, strpos($file, '_'));
				if (!isset($this->backup_list[$key])) {
					$this->backup_list[$key] = [
						'date' => $key,
						'sql_file' => $file,
						'sql_size' => filesize(Tools::folder('MyFiles', 'Backups', $file)),
						'zip_file' => '',
						'zip_size' => 0
					];
					continue;
				}

				$this->backup_list[$key]['sql_file'] = $file;
				$this->backup_list[$key]['sql_size'] = filesize(Tools::folder('MyFiles', 'Backups', $file));
				continue;
			}

			// comprobamos si es un archivo .zip
			if (substr($file, -4) === '.zip') {
				$key = substr($file, 0, strpos($file, '_'));
				if (!isset($this->backup_list[$key])) {
					$this->backup_list[$key] = [
						'date' => $key,
						'sql_file' => '',
						'sql_size' => 0,
						'zip_file' => $file,
						'zip_size' => filesize(Tools::folder('MyFiles', 'Backups', $file))
					];
					continue;
				}

				$this->backup_list[$key]['zip_file'] = $file;
				$this->backup_list[$key]['zip_size'] = filesize(Tools::folder('MyFiles', 'Backups', $file));
			}
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

		// validamos los campos de gestión del admin antes de restaurar
		$adminAction = $this->request->request->get('admin_action', 'none');
		if ($adminAction === 'update') {
			$newAdminPassword = $this->request->request->get('new_admin_password', '');
			$newAdminPassword2 = $this->request->request->get('new_admin_password2', '');
			if ($newAdminPassword !== $newAdminPassword2) {
				Tools::log()->error('restore-admin-passwords-mismatch');
				unlink($sqlFile);
				return;
			}

			if (strlen($newAdminPassword) < 8 || !preg_match('/[0-9]/', $newAdminPassword) || !preg_match('/[a-zA-Z]/', $newAdminPassword)) {
				Tools::log()->error('restore-admin-password-weak');
				unlink($sqlFile);
				return;
			}
		} elseif ($adminAction === 'create') {
			$newAdminNick = trim($this->request->request->get('new_admin_nick', ''));
			$newAdminCreatePassword = $this->request->request->get('new_admin_create_password', '');
			$newAdminCreatePassword2 = $this->request->request->get('new_admin_create_password2', '');
			if (empty($newAdminNick)) {
				Tools::log()->error('restore-admin-nick-required');
				unlink($sqlFile);
				return;
			}

			if ($newAdminCreatePassword !== $newAdminCreatePassword2) {
				Tools::log()->error('restore-admin-passwords-mismatch');
				unlink($sqlFile);
				return;
			}

			if (strlen($newAdminCreatePassword) < 8 || !preg_match('/[0-9]/', $newAdminCreatePassword) || !preg_match('/[a-zA-Z]/', $newAdminCreatePassword)) {
				Tools::log()->error('restore-admin-password-weak');
				unlink($sqlFile);
				return;
			}
		}

		// eliminamos todas las tablas
		$this->dataBase->exec('SET FOREIGN_KEY_CHECKS=0');
		foreach ($this->dataBase->getTables() as $table) {
			$this->dataBase->exec('DROP TABLE ' . $table);
		}
		$this->dataBase->close();

		// importamos el backup
		$db = new PDO('mysql:host=' . Tools::config('db_host') . ';port=' . Tools::config('db_port') . ';dbname=' . Tools::config('db_name'), Tools::config('db_user'), Tools::config('db_pass'));
		$backup = new MySQLBackup($db);

		$restore = $backup->restore($sqlFile);
		if (true !== $restore) {
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

		// gestionamos el acceso del admin tras la restauración
		if ($adminAction === 'update') {
			$newAdminPassword = $this->request->request->get('new_admin_password', '');
			$disable2fa = (bool)$this->request->request->get('disable_2fa', false);
			$this->updateAdminPasswordAfterRestore($newAdminPassword, $disable2fa);
		} elseif ($adminAction === 'create') {
			$newAdminNick = trim($this->request->request->get('new_admin_nick', ''));
			$newAdminCreatePassword = $this->request->request->get('new_admin_create_password', '');
			$this->createAdminUserAfterRestore($newAdminNick, $newAdminCreatePassword);
		}

		// eliminamos las cookies
		setcookie('fsNick', '', time() - 3600, Tools::config('route', '/'));
		setcookie('fsLogkey', '', time() - 3600, Tools::config('route', '/'));

		$this->redirect('login');
	}

	private function createAdminUserAfterRestore(string $nick, string $password): void
	{
		$user = new User();
		$user->nick = $nick;
		$user->admin = true;
		$user->enabled = true;

		if (false === $user->setPassword($password)) {
			Tools::log()->error('restore-admin-password-weak');
			return;
		}

		if (false === $user->save()) {
			Tools::log()->error('record-save-error');
			return;
		}

		Tools::log()->notice('restore-admin-user-created', ['%nick%' => $nick]);
	}

	private function updateAdminPasswordAfterRestore(string $newPassword, bool $disable2fa = false): void
	{
		// buscamos primero el usuario con nick 'admin'
		$user = new User();
		if (false === $user->load('admin')) {
			// si no existe, buscamos cualquier usuario con permisos de administrador
			$users = $user->all();
			$adminUser = null;
			foreach ($users as $u) {
				if ($u->admin) {
					$adminUser = $u;
					break;
				}
			}

			if (null === $adminUser) {
				Tools::log()->warning('restore-no-admin-found');
				return;
			}

			$user = $adminUser;
		}

		// actualizamos la contraseña
		if (false === $user->setPassword($newPassword)) {
			Tools::log()->error('restore-admin-password-weak');
			return;
		}

		// desactivamos la autenticación en dos pasos si se ha solicitado
		if ($disable2fa) {
			$user->two_factor_enabled = false;
			$user->two_factor_secret_key = null;
		}

		if (false === $user->save()) {
			Tools::log()->error('record-save-error');
			return;
		}

		Tools::log()->notice('restore-admin-password-updated');
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
		if (empty($configFile)) {
			Tools::log()->error('config-file-error');
			return;
		}

		$configCharset = Tools::config('mysql_charset');
		$configCollate = Tools::config('mysql_collate');
		if (empty($configCharset) || empty($configCollate)) {
			Tools::log()->error('config-mysql-charset-error', [
				'%config-charset%' => $configCharset,
				'%config-collate%' => $configCollate
			]);
			return;
		}

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
				Tools::log()->error('config-mysql-charset-error', [
					'%config-charset%' => $configCharset,
					'%config-collate%' => $configCollate,
					'%selected-charset%' => $selectedCharset
				]);
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
}
