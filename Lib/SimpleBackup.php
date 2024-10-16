<?php

namespace FacturaScripts\Plugins\Backup\Lib;

use Coderatio\SimpleBackup\Foundation\Configurator;
use Coderatio\SimpleBackup\Foundation\Provider;
use Coderatio\SimpleBackup\SimpleBackup as BaseSimpleBackup;

class SimpleBackup extends BaseSimpleBackup
{
    /**
     * Set up mysql database connection details
     * @param array $config
     * @return $this
     */
    public static function setDatabase($config = [])
    {
        $self = new self();

        $self->parseConfig($config);

        return $self;
    }

    /**
     * This method allows you store the exported db to a directory
     *
     * @param $path_to_store
     * @param null $name
     * @return $this
     */
    public function storeAfterExportTo($path_to_store, $name = null)
    {
        $this->abortIfEmptyTables();

        $export_name = $this->config['db_name'] . '_db_backup_(' . date('H-i-s') . '_' . date('d-m-Y') . ').sql';

        if ($name !== null) {
            $export_name = str_replace('.sql', '', $name) . '.sql';
        }

        $this->export_name = $export_name;

        if (!file_exists($path_to_store) && !mkdir($path_to_store) && !is_dir($path_to_store)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $path_to_store));
        }

        $file_path = $path_to_store . '/' . $export_name;

        $this->prepareExportContentsFrom($file_path);

        $this->response['message'] = 'Export finished successfully.';

        return $this;
    }

    protected function prepareExportContentsFrom($file_path)
    {
        try {
            $this->provider = Provider::init($this->config);

            if($this->include_only_some_tables && !empty($this->tables_to_include)) {
                $this->includeOnly($this->tables_to_include);
            }

            if($this->exclude_only_some_tables && !empty($this->tables_to_exclude)) {
                $this->excludeOnly($this->tables_to_exclude);
            }

            if ($this->condition_tables && !empty($this->tables_to_set_conditions)) {
                $this->provider->setTableWheres($this->tables_to_set_conditions);
            }

            if ($this->set_table_limits && !empty($this->tables_to_set_limits)) {
                $this->provider->setTableLimits($this->tables_to_set_limits);
            }

            $this->provider->start($file_path);

            $this->contents = Configurator::insertDumpHeader(
                $this->connection,
                $this->config
            );

        } catch (\Exception $e) {
            $this->response = [
                'status' => false,
                'message' => $e->getMessage()
            ];
        }

        return $this;
    }
}