<?php

namespace FacturaScripts\Plugins\Backup\Extension\Controller;
use FacturaScripts\Core\Tools;

class ListFacturaCliente
{
    public function createViews()
    {
        return function() {
            Tools::log()->warning('backup-date-warning');
        };
    }
}