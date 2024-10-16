<?php

namespace FacturaScripts\Plugins\Backup\Extension\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Cache;
use DateTime;

class ListFacturaCliente
{
    public function createViews()
    {
        return function() {
            $this->checkDateLastBackupDB();
        };
    }

    public function checkDateLastBackupDB(){
        return function() {
            $fileNameLastBackup = Cache::get('backup-db-last');

            if (empty($fileNameLastBackup)) {
                return false;
            }

            // Extraer la fecha del nombre del archivo
            $pattern = '/(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/';
            if (preg_match($pattern, $fileNameLastBackup, $matches)) {
                $dateString = $matches[1];

                // Convertir la cadena de fecha a un objeto DateTime
                $backupDate = DateTime::createFromFormat('Y-m-d_H-i-s', $dateString);
                $currentDate = new DateTime();

                if ($backupDate !== false) {
                    // Calcular la diferencia en días
                    $interval = $backupDate->diff($currentDate);
                    $days = $interval->days;

                    // Verificar si el respaldo es más antiguo que 30 días
                    if ($backupDate < $currentDate && $days > 30) {
                        Tools::log()->warning('El respaldo de la base de datos tiene más de 30 días');
                        return true;
                    } else {
                        // El respaldo tiene 30 días o menos
                        return false;
                    }
                } else {
                    // Manejar formato de fecha inválido
                    return false;
                }
            } else {
                // El nombre del archivo no contiene una fecha válida
                return false;
            }

        };
    }


}