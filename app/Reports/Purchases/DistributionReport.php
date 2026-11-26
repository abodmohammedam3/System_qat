<?php

namespace App\Reports\Purchases;

use App\Models\Purchases\DistributionDetail;
use App\Reports\Contracts\Report;

class DistributionReport implements Report
{
    private const PER_PAGE = 50;

    public function key(): string
    {
        return 'distributions';
    }

    public function title(): string
    {
        return 'تقرير التوزيع';
    }

    public function description(): string
    {
        return 'تفاصيل توزيع المشتريات على العملاء مع الأنواع والأوزان وحالة الفوترة';
    }

    public function icon(): string
    {
        return 'diagram-3';
    }

    public function category(): string
    {
        return 'purchases';
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
            ['key' => 'dist_date',        'label' => 'تاريخ التوزيع',  'type' => 'date'],
            ['key' => 'dist_code',        'label' => 'رقم التوزيع',    'type' => 'text'],
            ['key' => 'purchase_invoice', 'label' => 'فاتورة الشراء',  'type' => 'text'],
            ['key' => 'supplier',         'label' => 'المورد الأصلي',  'type' => 'text'],
            ['key' => 'item_name',        'label' => 'الصنف',          'type' => 'text'],
            ['key' => 'customer_name',    'label' => 'العميل',         'type' => 'text'],
            ['key' => 'type_name',        'label' => 'النوع',          'type' => 'text'],
            ['key' => 'unit_name',        'label' => 'الوحدة',         'type' => 'text'],
            ['key' => 'unit_weight',      'label' => 'وزن الحبة (جم)', 'type' => 'number'],
            ['key' => 'quantity',         'label' => 'الكمية المدخلة', 'type' => 'number', 'footer' => 'sum'],
            ['key' => 'quantity_base',    'label' => 'الكمية الأساسية', 'type' => 'number', 'footer' => 'sum'],
            ['key' => 'sale_price',       'label' => 'السعر',          'type' => 'money'],
            ['key' => 'total',            'label' => 'الإجمالي',       'type' => 'money', 'footer' => 'sum', 'color' => 'green'],
            ['key' => 'invoice_status',   'label' => 'حالة الفوترة',   'type' => 'text'],
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
            ->selectRaw('COALESCE(SUM(CASE WHEN distribution_details.total > 0 THEN distribution_details.total ELSE distribution_details.quantity * distribution_details.sale_price END), 0) as total_sum')
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
            $rowTotal = (float) $dd->total > 0 ? (float) $dd->total : round($qty * (float) $dd->sale_price, 6);

            $rows[] = [
                'id'               => (int) $dd->id,
                'dist_date'        => $dd->distribution?->distribution_date?->format('Y-m-d') ?? '',
                'dist_code'        => $dd->distribution?->code ?? '',
                'purchase_invoice' => $dd->purchaseInvoice?->invoice_number ?? '—',
                'supplier'         => $dd->purchaseInvoice?->supplierAccount?->accName ?? '—',
                'item_name'        => $dd->movementDetail?->item?->itemName2 ?? '—',
                'customer_name'    => $dd->customer?->CustomersName2 ?? '—',
                'type_name'        => $dd->type?->name ?? $dd->movementDetail?->type?->name ?? '—',
                'unit_name'        => $dd->unit?->UnitName ?? $dd->movementDetail?->unit?->UnitName ?? '—',
                'unit_weight'      => $dd->unit_weight !== null ? (float) $dd->unit_weight : 0,
                'quantity'         => $qty,
                'quantity_base'    => $qtyBase,
                'sale_price'       => (float) $dd->sale_price,
                'total'            => $rowTotal,
                'invoice_status'   => $dd->sales_invoice_id ? 'مفوتر' : 'غير مفوتر',
                'sales_invoice'    => $dd->salesInvoice?->invoice_number ?? '—',
            ];
        }

        return [
            'rows'   => $rows,
            'totals' => [
                'quantity'      => round((float) ($totalsRow->qty_sum ?? 0), 3),
                'quantity_base' => round((float) ($totalsRow->base_sum ?? 0), 3),
                'total'         => round((float) ($totalsRow->total_sum ?? 0), 2),
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
