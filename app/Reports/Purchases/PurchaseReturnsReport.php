<?php

namespace App\Reports\Purchases;

use App\Models\Purchases\PurchaseReturn;
use App\Reports\Contracts\Report;

class PurchaseReturnsReport implements Report
{
    private const PER_PAGE = 50;

    public function key(): string
    {
        return 'purchase-returns';
    }

    public function title(): string
    {
        return 'مرتجعات المشتريات';
    }

    public function description(): string
    {
        return 'جميع مرتجعات المشتريات المرتبطة بفواتير الشراء الأصلية';
    }

    public function icon(): string
    {
        return 'arrow-return-left';
    }

    public function category(): string
    {
        return 'purchases';
    }

    public function filters(): array
    {
        return [
            [
                'key'         => 'supplier_id',
                'label'       => 'المورد',
                'type'        => 'account',
                'mode'        => 'all',
                'source'      => 'accounts',
                'restrict'    => 'suppliers',
                'placeholder' => 'اكتب أو اضغط Enter لاختيار المورد...',
                'col'         => 'col-md-3',
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
            [
                'key'    => 'payment_method',
                'label'  => 'طريقة الدفع',
                'type'   => 'select',
                'source' => 'paymentMethodsPurchases',
                'col'    => 'col-md-2',
            ],
        ];
    }

    public function columns(): array
    {
        return [
            ['key' => 'return_number',    'label' => 'رقم المرتجع',      'type' => 'text'],
            ['key' => 'original_invoice', 'label' => 'الفاتورة الأصلية', 'type' => 'text'],
            ['key' => 'return_date',      'label' => 'التاريخ',          'type' => 'date'],
            ['key' => 'supplier',         'label' => 'المورد',           'type' => 'text'],
            ['key' => 'currency',         'label' => 'العملة',           'type' => 'text'],
            ['key' => 'status',           'label' => 'الحالة',           'type' => 'text'],
            ['key' => 'items_total',      'label' => 'إجمالي الأصناف',   'type' => 'money', 'footer' => 'sum'],
            ['key' => 'discount_total',   'label' => 'الخصم',            'type' => 'money', 'footer' => 'sum', 'color' => 'red'],
            ['key' => 'total',            'label' => 'الإجمالي',         'type' => 'money', 'footer' => 'sum', 'color' => 'green'],
        ];
    }

    public function run(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        $paymentMethod = (int) ($filters['payment_method'] ?? 0);

        $query = PurchaseReturn::query()
            ->when($supplierId > 0, fn ($q) => $q->where('account_id', $supplierId))
            ->when($paymentMethod > 0, fn ($q) => $q->where('payment_method', $paymentMethod))
            ->when(!empty($filters['date_from']), fn ($q) => $q->where('return_date', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->where('return_date', '<=', $filters['date_to']));

        $totalsRow = (clone $query)->toBase()
            ->cloneWithout(['columns'])
            ->selectRaw('COALESCE(SUM(items_total), 0) as items_sum')
            ->selectRaw('COALESCE(SUM(discount_total), 0) as discount_sum')
            ->first();

        $itemsTotal = round((float) ($totalsRow->items_sum ?? 0), 2);
        $discountTotal = round((float) ($totalsRow->discount_sum ?? 0), 2);
        $grandTotal = round($itemsTotal - $discountTotal, 2);

        $total = (clone $query)->count();
        $perPage = !empty($filters['_all'])
            ? max(1, min($total, (int) config('reports.export_max_rows', 50000)))
            : self::PER_PAGE;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);

        $returns = $query
            ->with([
                'supplierAccount:accountID,accCode,accName',
                'coin:coinsID,coinsName',
                'originalInvoice:purchase_invoice_id,invoice_number',
            ])
            ->orderBy('return_date')
            ->orderBy('purchase_return_id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $rows = [];
        foreach ($returns as $ret) {
            $rows[] = [
                'id'               => (int) $ret->purchase_return_id,
                'return_number'    => $ret->return_number ?? '',
                'original_invoice' => $ret->originalInvoice->invoice_number ?? '—',
                'return_date'      => $ret->return_date?->format('Y-m-d'),
                'supplier'         => $ret->supplierAccount
                    ? trim(($ret->supplierAccount->accCode ?? '') . ' - ' . ($ret->supplierAccount->accName ?? ''))
                    : '',
                'currency'         => $ret->coin->coinsName ?? '',
                'status'           => $ret->is_posted ? 'مرحّل' : 'مسودة',
                'items_total'      => (float) $ret->items_total,
                'discount_total'   => (float) $ret->discount_total,
                'total'            => (float) $ret->total_in_return_currency,
            ];
        }

        return [
            'rows'   => $rows,
            'totals' => [
                'items_total'    => $itemsTotal,
                'discount_total' => $discountTotal,
                'total'          => $grandTotal,
            ],
            'meta'   => [
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => $lastPage,
                    'per_page'     => $perPage,
                    'total'        => $total,
                ],
            ],
        ];
    }
}
