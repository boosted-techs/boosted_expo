<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

class WorkspacePaginator extends LengthAwarePaginator
{
    public string $tableKey = '';

    public array $tableColumns = [];

    public function render($view = null, $data = [])
    {
        return parent::render($view ?? 'components.workspace-pagination', [...$data, 'tableKey' => $this->tableKey, 'tableColumns' => $this->tableColumns]);
    }
}
