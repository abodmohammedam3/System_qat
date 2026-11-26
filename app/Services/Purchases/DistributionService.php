<?php

namespace App\Services\Purchases;

use App\Models\Accounting\CharAccount;
use App\Models\Customer;
use App\Models\Purchases\Distribution;
use App\Models\Purchases\DistributionDetail;
use App\Models\Purchases\DistributionLog;
use App\Models\Sales\SalesInvoice;
use App\Services\Sales\SalesInvoiceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * خدمة توزيع أصناف المشتريات
 * ---------------------------------------------------------
 * - المسار الموحّد لإصدار فاتورة بيع حقيقية (SalesInvoice) لكل عميل
 *   من صفوف التوزيع المحددة، عبر SalesInvoiceService::create()
 *   مع تمرير distribution_detail_ids قبل الترحيل، فتُنشأ حركة
 *   "بيع توزيع" توثيقية (TYPE_SALE_DISTRIBUTION) دون خصم مزدوج.
 */
class DistributionService
{
    public function __construct(protected SalesInvoiceService $salesInvoiceService)
    {
    }

    // =========================================================
    // إصدار فاتورة بيع حقيقية لعميل من توزيعات محددة (المسار الموحّد)
    // =========================================================

    /**
     * @param  int   $customerId        عميل جدول customers
     * @param  array $distributionIds   معرفات توزيعات (distributions.id)
     * @param  array $options           خيارات إضافية (detail_ids, payment_method, payment_account_id, statement, reference)
     * @return SalesInvoice             فاتورة البيع المنشأة (مرحّلة تلقائيًا مع حركة توثيقية + قيد)
     */
    public function issueInvoice(int $customerId, array $distributionIds, array $options = []): SalesInvoice
    {
        $paymentMethod = (int) ($options['payment_method'] ?? SalesInvoice::PAYMENT_CREDIT);
        $paymentAccountId = !empty($options['payment_account_id']) ? (int) $options['payment_account_id'] : null;
        $detailIds = !empty($options['detail_ids']) ? array_map('intval', (array) $options['detail_ids']) : null;

        if ($paymentMethod !== SalesInvoice::PAYMENT_CREDIT) {
            if (empty($paymentAccountId)) {
                throw ValidationException::withMessages([
                    'payment_account_id' => 'حساب الدفع مطلوب لهذه الطريقة (نقدي / بنك).',
                ]);
            }
            if (!CharAccount::find($paymentAccountId)) {
                throw ValidationException::withMessages([
                    'payment_account_id' => 'حساب الدفع المحدد غير موجود.',
                ]);
            }
        }

        $customer = Customer::query()->findOrFail($customerId);
        if (empty($customer->accountID)) {
            throw ValidationException::withMessages([
                'customer_id' => 'العميل «' . $customer->CustomersName2
                    . '» غير مرتبط بحساب محاسبي في دليل الحسابات.',
            ]);
        }

        $lockKey = "distribution_issue_customer_{$customerId}";
        $lock = Cache::lock($lockKey, 20);

        if (!$lock->get()) {
            throw new \RuntimeException(
                'عملية إصدار فاتورة أخرى لنفس العميل قيد التنفيذ. حاول بعد قليل.',
                409
            );
        }

        try {
            return DB::transaction(function () use ($customerId, $distributionIds, $detailIds, $paymentMethod, $paymentAccountId, $customer, $options) {
                $details = $this->lockDetailsForInvoicing($customerId, $distributionIds, $detailIds);

                if ($details->isEmpty()) {
                    throw ValidationException::withMessages([
                        'distribution_ids' => 'لا توجد تفاصيل قابلة للفوترة (قد تكون فُوترت بالفعل من عملية أخرى).',
                    ]);
                }

                // 1) حماية: التوزيع الذي يحتوي صفًّا غير مفوتر يجب أن يكون مسودة
                $this->guardDistributionsAreDraft($details);

                foreach ($details as $d) {
                    if (!$d->movementDetail) {
                        throw ValidationException::withMessages([
                            'details' => "صف التوزيع رقم {$d->id} غير مرتبط بحركة مخزون صحيحة.",
                        ]);
                    }
                    if (!$d->purchaseInvoice) {
                        throw ValidationException::withMessages([
                            'details' => "صف التوزيع رقم {$d->id} غير مرتبط بفاتورة شراء صحيحة.",
                        ]);
                    }
                }

                // 2) التحقق من توحيد العملة عبر جميع صفوف الفاتورة
                $coins = $details->pluck('purchaseInvoice.coin_id')->unique();
                if ($coins->count() > 1) {
                    throw ValidationException::withMessages([
                        'distribution_ids' => 'لا يمكن إصدار فاتورة واحدة بتوزيعات من فواتير بعملات مختلفة. يرجى تحديد أصناف عملة واحدة وإصدار فاتورة لها، ثم إصدار فاتورة لبقية الأصناف بالعملة الأخرى.',
                    ]);
                }

                $firstInvoice = $details->first()->purchaseInvoice;
                $coinId       = (int) $firstInvoice->coin_id;
                $exchangeRate = (float) ($firstInvoice->exchange_rate ?: 1);

                // 3) تجميع أكواد التوزيعات المشاركة للبيان
                $distCodes = $details->map(fn ($d) => $d->distribution?->code)
                    ->filter()->unique()->values()->implode('، ');

                // 4) تجهيز تفاصيل فاتورة البيع من صفوف التوزيع (النوع والوحدة من صف التوزيع نفسه)
                $invoiceDetails = $details->map(function (DistributionDetail $d) {
                    $md = $d->movementDetail;

                    return [
                        'distribution_detail_id' => (int) $d->id,
                        'item_id'                => (int) $md->item_id,
                        'type_id'                => $d->type_id !== null
                            ? (int) $d->type_id
                            : ($md->type_id !== null ? (int) $md->type_id : null),
                        'unit_id'                => $d->unit_id !== null
                            ? (int) $d->unit_id
                            : ($md->unit_id !== null ? (int) $md->unit_id : null),
                        'warehouse_id'           => (int) $md->warehouse_id,
                        'supplier_account_id'    => $md->supplier_account_id !== null
                            ? (int) $md->supplier_account_id
                            : null,
                        'code'                   => $md->code ?? null,
                        'quantity'               => (float) $d->quantity,
                        'price'                  => (float) $d->sale_price,
                        'cost_price'             => (float) $d->unit_cost,
                        'discount'               => 0,
                    ];
                })->all();

                $statement = $options['statement'] ?? null;
                if ($statement === null || trim((string) $statement) === '') {
                    $statement = 'توزيع أصناف مشتريات' . ($distCodes !== '' ? ' — ' . $distCodes : '');
                }

                $reference = $options['reference'] ?? ($distCodes !== '' ? $distCodes : null);

                // 5) إنشاء فاتورة البيع مع تمرير distribution_detail_ids قبل الترحيل
                //    (يمنع الخصم المزدوج وينشئ حركة TYPE_SALE_DISTRIBUTION التوثيقية)
                $salesInvoice = $this->salesInvoiceService->create([
                    'invoice_date'            => now()->toDateString(),
                    'account_id'              => (int) $customer->accountID,
                    'payment_account_id'      => $paymentMethod === SalesInvoice::PAYMENT_CREDIT ? null : $paymentAccountId,
                    'coin_id'                 => $coinId,
                    'exchange_rate'           => $exchangeRate,
                    'payment_method'          => $paymentMethod,
                    'statement'               => $statement,
                    'reference'               => $reference,
                    'details'                 => $invoiceDetails,
                    'skip_stock_validation'   => true,
                    'distribution_detail_ids' => $details->pluck('id')->all(),
                ]);

                // 6) تحديث حالة التوزيعات وتسجيل سجل التتبع
                $remainingByDist = DistributionDetail::query()
                    ->whereIn('distribution_id', $distributionIds)
                    ->whereNull('sales_invoice_id')
                    ->groupBy('distribution_id')
                    ->selectRaw('distribution_id, COUNT(*) as c')
                    ->pluck('c', 'distribution_id');

                $details->pluck('distribution_id')->unique()->each(function ($distId) use ($details, $salesInvoice, $customer, $customerId, $remainingByDist) {
                    $dist = Distribution::find($distId);
                    if (!$dist) return;

                    $distRows = $details->where('distribution_id', $distId);
                    $partial  = ($remainingByDist[$distId] ?? 0) > 0;

                    if (!$partial && $dist->status === Distribution::STATUS_DRAFT) {
                        $dist->update([
                            'status'      => Distribution::STATUS_INVOICED,
                            'invoiced_at' => now(),
                            'invoiced_by' => auth()->id(),
                        ]);
                    }

                    $dist->log(
                        $partial
                            ? DistributionLog::ACTION_PARTIALLY_ISSUED
                            : DistributionLog::ACTION_ISSUED,
                        "إصدار " . ($partial ? 'جزئي من ' : '')
                            . "فاتورة البيع {$salesInvoice->invoice_number} للعميل "
                            . ($customer->CustomersName2 ?? $customerId)
                            . " — " . $distRows->count() . " صف",
                        [
                            'sales_invoice_id'     => $salesInvoice->sales_invoice_id,
                            'sales_invoice_number' => $salesInvoice->invoice_number,
                            'invoice_number'       => $salesInvoice->invoice_number,
                            'total'                => (float) $salesInvoice->total_in_invoice_currency,
                            'customer_id'          => $customerId,
                            'details_count'        => $distRows->count(),
                            'items_count'          => $distRows->count(),
                            'partial'              => $partial,
                        ],
                    );
                });

                return $salesInvoice;
            });
        } finally {
            $lock->release();
        }
    }

    // =========================================================
    // قفل صفوف التوزيع من التزامن قبل الفوترة
    // =========================================================
    protected function lockDetailsForInvoicing(int $customerId, array $distributionIds, ?array $detailIds = null)
    {
        return DistributionDetail::query()
            ->whereIn('distribution_id', $distributionIds)
            ->where('customer_id', $customerId)
            ->whereNull('sales_invoice_id')
            ->whereNull('invoiced_at')
            ->when(!empty($detailIds), fn($q) => $q->whereIn('id', $detailIds))
            ->with([
                'distribution:id,code,status',
                'movementDetail.item:itemID,itemName2',
                'purchaseInvoice.coin',
            ])
            ->lockForUpdate()
            ->get();
    }

    // =========================================================
    // حماية من إصدار صفوف ضمن توزيع مفوتر جزئيًّا
    // =========================================================
    protected function guardDistributionsAreDraft($details): void
    {
        $distIds = $details->pluck('distribution_id')->unique();

        $bad = Distribution::query()
            ->whereIn('id', $distIds)
            ->where('status', '!=', Distribution::STATUS_DRAFT)
            ->get();

        if ($bad->isNotEmpty()) {
            throw ValidationException::withMessages([
                'distribution_ids' => 'التوزيعات التالية مفوترة مسبقًا ولا يمكن إصدارها: '
                    . $bad->pluck('code')->implode('، '),
            ]);
        }
    }
}