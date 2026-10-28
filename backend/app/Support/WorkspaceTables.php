<?php

namespace App\Support;

use App\Models\StockBatch;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class WorkspaceTables
{
    private array $columns = [];

    public function paginate(Builder|QueryBuilder $query, int $perPage = 25, array $columns = ['*'], string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        if (! $query instanceof Builder) {
            return $query->paginate($perPage, $columns, $pageName, $page);
        }
        $model = $query->getModel();
        $table = $model->getTable();
        $key = $table.'__'.$pageName;
        $allowedNames = ['id', 'reference', 'name', 'code', 'sku', 'staff_code', 'batch_code', 'carton_code', 'asset_code', 'vendor_name', 'purpose', 'description', 'status', 'kind', 'currency', 'location', 'market_location', 'source_reference', 'received_on', 'purchased_on', 'event_on', 'due_on', 'required_on', 'starts_at', 'ends_at', 'clocked_in_at', 'clocked_out_at', 'created_at', 'quantity', 'amount', 'estimated_total', 'unit_price', 'active', 'is_quarantined'];
        $existing = $this->columns[$table] ??= $model->getConnection()->getSchemaBuilder()->getColumnListing($table);
        $allowed = array_values(array_intersect($allowedNames, $existing));
        if ($model instanceof StockBatch) {
            array_push($allowed, 'unpacked_balance', 'available_balance');
        }
        $relations = [];
        foreach (['product', 'customer', 'supplier', 'person', 'user'] as $relationName) {
            if (method_exists($model, $relationName)) {
                $relation = $model->$relationName();
                if ($relation instanceof BelongsTo) {
                    $relations[$relationName] = $relation;
                    $allowed[] = $relationName;
                }
            }
        }
        $input = request()->input('tables.'.$key, []);
        if (! is_array($input)) {
            $input = [];
        }
        $search = is_string($input['search'] ?? null) ? mb_substr(trim($input['search']), 0, 120) : '';
        $textFields = array_intersect($allowed, ['reference', 'name', 'code', 'sku', 'staff_code', 'batch_code', 'carton_code', 'asset_code', 'vendor_name', 'purpose', 'description', 'location', 'market_location', 'source_reference']);
        if ($search !== '' && (count($textFields) || collect(['product', 'customer', 'supplier', 'person', 'user'])->contains(fn ($relation) => method_exists($model, $relation)))) {
            $query->where(function (Builder $nested) use ($textFields, $search, $model): void {
                foreach ($textFields as $field) {
                    $nested->orWhere($model->qualifyColumn($field), 'like', '%'.$search.'%');
                }
                foreach (['product', 'customer', 'supplier', 'person', 'user'] as $relation) {
                    if (method_exists($model, $relation)) {
                        $nested->orWhereHas($relation, fn (Builder $related) => $related->where('name', 'like', '%'.$search.'%'));
                    }
                }
            });
        }
        foreach (['status', 'kind', 'currency'] as $field) {
            if (in_array($field, $allowed, true) && is_string($input[$field] ?? null) && $input[$field] !== '') {
                $query->where($model->qualifyColumn($field), mb_substr($input[$field], 0, 80));
            }
        }
        $sort = $input['sort'] ?? '';
        if (is_string($sort) && in_array($sort, $allowed, true)) {
            $direction = ($input['direction'] ?? '') === 'desc' ? 'desc' : 'asc';
            if ($model instanceof StockBatch && in_array($sort, ['unpacked_balance', 'available_balance'], true)) {
                $balance = StockMovement::query()->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('stock_movements.batch_id', 'stock_batches.id')
                    ->whereColumn('stock_movements.business_id', 'stock_batches.business_id');
                $query->reorder();
                if ($sort === 'available_balance') {
                    $query->orderByRaw('CASE WHEN stock_batches.is_quarantined THEN 0 ELSE ('.$balance->toSql().') END '.$direction, $balance->getBindings());
                } else {
                    $query->orderBy($balance, $direction);
                }
            } elseif (isset($relations[$sort])) {
                $relation = $relations[$sort];
                $related = $relation->getRelated();
                $query->reorder()->orderBy($related->newQuery()->select('name')->whereColumn($relation->getQualifiedOwnerKeyName(), $relation->getQualifiedForeignKeyName())->limit(1), $direction);
            } else {
                $query->reorder($model->qualifyColumn($sort), $direction);
            }
            if ($sort !== 'id' && in_array('id', $existing, true)) {
                $query->orderBy($model->qualifyColumn('id'));
            }
        }
        $size = is_scalar($input['size'] ?? null) ? (int) $input['size'] : $perPage;
        $size = in_array($size, [10, 15, 20, 24, 25, 30, 40, 50, 100], true) ? $size : $perPage;
        $result = $query->paginate($size, $columns, $pageName, $page);
        $paginator = new WorkspacePaginator($result->getCollection(), $result->total(), $result->perPage(), $result->currentPage(), $result->getOptions());
        $paginator->tableKey = $key;
        $paginator->tableColumns = array_map(fn (string $field): array => ['value' => $field, 'label' => Str::headline($field)], $allowed);

        return $paginator->withQueryString();
    }
}
