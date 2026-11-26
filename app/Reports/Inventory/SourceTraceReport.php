<?php

namespace App\Reports\Inventory;

use App\Models\Purchases\DistributionDetail;
use App\Reports\Contracts\Report;

class SourceTraceReport implements Report
{
    private const PER_PAGE = 50;

    public function key(): string
    {
        return 'source-trace';
    }

    public function title(): string
    {
        return 'تتبع المصدر';
    }

    public function description(): string
    {
        return 'تتبع سلسلة التوريد من المورد وفاتورة الشراء حتى التوزيع والعميل وفاتورة البيع';
    }

    public function icon(): string
    {
        return 'signpost-split';
    }

    public function category(): string
    {
        return 'inventory';
    }

    public function filters(): array
    {
        return [
            [
                'key'    => 'item_id',
                'label'  => 'الصنف',
                'type'   => 'item',
                'source' => 'items',
                'col'    => 'col-md-4',
            ],
            [
                'key'   => 'date_from',
                'label' => 'من تاريخ',
                'type'  => 'date',
                'col'   => 'col-md-3',
            ],
            [
                'key'   => 'date_to',
                'label' => 'إلى تاريخ',
                'type'  => 'date',
                'col'   => 'col-md-3',
            ],
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'supplier',         'label' => 'المورد',         'type' => 'text'],
            ['key' => 'purchase_invoice', 'label' => 'فاتورة الشراء',  'type' => 'text'],
            ['key' => 'item_name',        'label' => 'الصنف',          'type' => 'text'],
            ['key' => 'dist_code',        'label' => 'التوزيع',        'type' => 'text'],
            ['key' => 'customer_name',    'label' => 'العميل',         'type' => 'text'],
            ['key' => 'type_name',        'label' => 'النوع',          'type' => 'text'],
            ['key' => 'unit_name',        'label' => 'الوحدة',         'type' => 'text'],
            ['key' => 'quantity',         'label' => 'الكمية',         'type' => 'number', 'footer' => 'sum'],
            ['key' => 'quantity_base',    'label' => 'بالأساس',       'type' => 'number', 'footer' => 'sum'],
            ['key' => 'sales_invoice',    'label' => 'فاتورة البيع',   'type' => 'text'],
        ];
    }

    public function run(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $itemId = (int) ($filters['item_id'] ?? 0);

        $query = DistributionDetail::query()
            ->join('distributions as d', 'd.id', '=', 'distribution_details.distribution_id')
            ->join('inventory_movement_details as imd', 'imd.movement_detail_id', '=', 'distribution_details.movement_detail_id')
            ->when($itemId > 0, fn ($q) => $q->where('imd.item_id', $itemId))
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('d.distribution_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->where('d.distribution_date', '<=', $filters['date_to']));

        $totalsRow = (clone $query)->toBase()
            ->cloneWithout(['columns'])
            ->selectRaw('COALESCE(SUM(distribution_details.quantity), 0) as qty_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN distribution_details.quantity_base > 0 THEN distribution_details.quantity_base ELSE distribution_details.quantity END), 0) as base_sum')
            ->first();

        $totalCount = (clone $query)->count();
        $perPage = !empty($filters['_all'])
            ? max(1, min($totalCount, (int) config('reports.export_max_rows', 50000)))
            : self::PER_PAGE;
        $lastPage = max(1, (int) ceil($totalCount / $perPage));
        $page = min($page, $lastPage);

        $details = DistributionDetail::query()
            ->join('distributions as d', 'd.id', '=', 'distribution_details.distribution_id')
            ->join('inventory_movement_details as imd', 'imd.movement_detail_id', '=', 'distribution_details.movement_detail_id')
            ->select('distribution_details.*')
            ->when($itemId > 0, fn ($q) => $q->where('imd.item_id', $itemId))
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('d.distribution_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->where('d.distribution_date', '<=', $filters['date_to']))
            ->with([
                'distribution:id,code,distribution_date',
                'purchaseInvoice.supplierAccount:accountID,accName',
                'salesInvoice:sales_invoice_id,invoice_number',
                'customer:CustomersID,CustomersName2',
                'type:id,name',
                'unit:UnitID,UnitName',
                'movementDetail.item:itemID,itemName2',
                'movementDetail.type:id,name',
                'movementDetail.unit:UnitID,UnitName',
            ])
            ->orderBy('d.distribution_date')
            ->orderBy('distribution_details.id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $rows = [];
        foreach ($details as $dd) {
            $qty = (float) $dd->quantity;
            $qtyBase = (float) $dd->quantity_base > 0 ? (float) $dd->quantity_base : $qty;

            $rows[] = [
                'id'               => (int) $dd->id,
                'supplier'         => $dd->purchaseInvoice?->supplierAccount?->accName ?? '—',
                'purchase_invoice' => $dd->purchaseInvoice?->invoice_number ?? '—',
                'item_name'        => $dd->movementDetail?->item?->itemName2 ?? '—',
                'dist_code'        => $dd->distribution?->code ?? '—',
                'customer_name'    => $dd->customer?->CustomersName2 ?? '—',
                'type_name'        => $dd->type?->name ?? $dd->movementDetail?->type?->name ?? '—',
                'unit_name'        => $dd->unit?->UnitName ?? $dd->movementDetail?->unit?->UnitName ?? '—',
                'quantity'         => $qty,
                'quantity_base'    => $qtyBase,
                'sales_invoice'    => $dd->salesInvoice?->invoice_number ?? '—',
            ];
        }

        return [
            'rows'   => $rows,
            'totals' => [
                'quantity'      => round((float) ($totalsRow->qty_sum ?? 0), 3),
                'quantity_base' => round((float) ($totalsRow->base_sum ?? 0), 3),
            ],
            'meta'   => [
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => $lastPage,
                    'per_page'     => $perPage,
                    'total'        => $totalCount,
                ],
            ],
        ];
    }
}
