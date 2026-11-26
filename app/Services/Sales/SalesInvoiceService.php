<?php

namespace App\Services\Sales;

use App\Models\Accounting\CharAccount;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementDetail;
use App\Models\Inventory\Stock;
use App\Models\Purchases\DistributionDetail;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesInvoiceDetail;
use App\Services\Inventory\InventoryService;
use App\Services\JournalEntryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SalesInvoiceService
{
    // =========================================================
    // CONSTANTS
    // =========================================================

    private const LOCK_KEY = 'sales_invoice_write_lock';
    private const LOCK_TTL = 15;

    private const INVENTORY_ACCOUNT_CACHE_TTL = 3600;

    private const SALES_REVENUE_SYSTEM_KEY      = 'sales_revenue';
    private const COST_OF_GOODS_SOLD_SYSTEM_KEY = 'cost_of_goods_sold';

    protected InventoryService $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    // =========================================================
    // CREATE — حفظ + ترحيل تلقائي
    // =========================================================
    public function create(array $data): SalesInvoice
    {
        return $this->withWriteLock(function () use ($data) {
            return DB::transaction(function () use ($data) {

                $details = $data['details'] ?? [];

                $this->lockStockRows($details);

                // ✅ تخطي التحقق من المخزون إذا كان الطلب قادماً من توزيع
                if (empty($data['skip_stock_validation'])) {
                    $this->validateStockAvailability($details);
                }

                $nextNumber = $this->nextInvoiceNumber();

                $header = $this->headerData($data);
                $header['invoice_number'] = (string) $nextNumber;
                $header['created_by']     = auth()->id();

                $invoice = SalesInvoice::create($header);

                $this->saveDetails($invoice, $details);
                $this->recalculateTotals($invoice);

                // ✅ جديد: ربط صفوف التوزيع غير المفوترة بهذه الفاتورة
                // يجب أن يتم قبل postInternal حتى تعرف syncInventoryMovement
                // أن الفاتورة من توزيع (تصير حركة بيع توزيع بدون خصم مزدوج).
                if (!empty($data['distribution_detail_ids'])) {
                    $this->linkDistributionDetails($invoice, $data['distribution_detail_ids']);
                }

                // ✅ ترحيل تلقائي
                $this->postInternal($invoice);

                return $invoice->fresh(['details', 'customerAccount']);
            });
        });
    }

    /**
     * ✅ جديد: ربط صفوف التوزيع (distribution_details) بفاتورة البيع.
     * يتحقق أن الصفوف غير مفوترة مسبقاً ثم يربطها.
     */
    protected function linkDistributionDetails(SalesInvoice $invoice, array $detailIds): void
    {
        $detailIds = array_map('intval', $detailIds);

        $rows = DistributionDetail::query()
            ->whereIn('id', $detailIds)
            ->lockForUpdate()
            ->get();

        $missing = collect($detailIds)->diff($rows->pluck('id'));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'distribution_detail_ids' => 'بعض صفوف التوزيع المحددة غير موجودة.',
            ]);
        }

        $alreadyInvoiced = $rows->filter(fn($r) => $r->sales_invoice_id !== null || $r->invoiced_at !== null);
        if ($alreadyInvoiced->isNotEmpty()) {
            throw ValidationException::withMessages([
                'distribution_detail_ids' => 'صفوف التوزيع التالية مفوترة مسبقاً: '
                    . $alreadyInvoiced->pluck('id')->implode(', '),
            ]);
        }

        DistributionDetail::whereIn('id', $detailIds)->update([
            'sales_invoice_id' => $invoice->sales_invoice_id,
            'invoiced_at'      => now(),
        ]);
    }

    // =========================================================
    // UPDATE — يمنع تعديل المرحّلة أو التي لها مرتجعات
    // =========================================================
public function update(int $id, array $data): SalesInvoice
{
    return $this->withWriteLock(function () use ($id, $data) {
        return DB::transaction(function () use ($id, $data) {

            $invoice = SalesInvoice::lockForUpdate()->findOrFail($id);
            $invoice->load('details');

            if ($invoice->isPosted()) {
                throw new \RuntimeException(
                    'لا يمكن تعديل فاتورة مرحّلة. قم بإلغاء الترحيل أولًا.'
                );
            }

            if ($invoice->returns()->exists()) {
                throw new \RuntimeException(
                    'لا يمكن تعديل فاتورة لها مرتجعات. احذف المرتجعات أولًا.'
                );
            }

            $details = $data['details'] ?? [];
            $this->lockStockRows($details);

            // ✅ 1) اكتشف إذا كانت الفاتورة تم إصدارها من شاشة التوزيع
            $isFromDistribution = DistributionDetail::where('sales_invoice_id', $invoice->sales_invoice_id)->exists();

            // ✅ 2) تخطي التحقق من المخزون إذا كانت من التوزيع (لأنها محجوزة مسبقاً)
            if (!$isFromDistribution && empty($data['skip_stock_validation'])) {
                // احسب الكميات القديمة
                $currentQty = [];
                foreach ($invoice->details as $old) {
                    $key = (int)$old->item_id . '|' . (int)$old->warehouse_id;
                    $currentQty[$key] = ($currentQty[$key] ?? 0) + (float) $old->quantity;
                }

                // نتحقق فقط من الكميات الإضافية (إذا زادت الكمية)
                $validateDetails = [];
                foreach ($details as $i => $row) {
                    $itemId      = (int) ($row['item_id'] ?? 0);
                    $warehouseId = (int) ($row['warehouse_id'] ?? 0);
                    $newQty      = (float) ($row['quantity'] ?? 0);

                    if (!$itemId || !$warehouseId || $newQty <= 0) continue;

                    $key    = "{$itemId}|{$warehouseId}";
                    $oldQty = $currentQty[$key] ?? 0;

                    if ($newQty > $oldQty + 0.000001) {
                        $validateDetails[$i] = array_merge($row, ['quantity' => $newQty - $oldQty]);
                    }

                    if (isset($currentQty[$key])) {
                        $currentQty[$key] = max(0, $currentQty[$key] - $newQty);
                    }
                }

                if (!empty($validateDetails)) {
                    $this->validateStockAvailability($validateDetails);
                }
            }

            $header = $this->headerData($data);
            unset($header['invoice_number']);
            unset($header['created_by']);
            $header['updated_by'] = auth()->id();

            $invoice->update($header);

            $this->deleteInventoryMovement($invoice);
            $invoice->details()->delete();
            $this->saveDetails($invoice, $details);
            $this->recalculateTotals($invoice);

            return $invoice->fresh(['details', 'customerAccount']);
        });
    });
}

    // =========================================================
    // DELETE — يمنع حذف المرحّلة أو التي لها مرتجعات
    // =========================================================
    public function delete(int $id): void
    {
        $this->withWriteLock(function () use ($id) {
            DB::transaction(function () use ($id) {

                $invoice = SalesInvoice::lockForUpdate()->findOrFail($id);

                if ($invoice->isPosted()) {
                    throw new \RuntimeException(
                        'لا يمكن حذف فاتورة مرحّلة. قم بإلغاء الترحيل أولًا.'
                    );
                }

                if ($invoice->returns()->exists()) {
                    throw new \RuntimeException(
                        'لا يمكن حذف فاتورة لها مرتجعات. احذف المرتجعات أولًا.'
                    );
                }

                $this->deleteAccountingEntry($invoice);
                $this->deleteInventoryMovement($invoice);

                // ✅ جديد: فك ربط صفوف التوزيع المرتبطة بهذه الفاتورة
                // حتى يمكن إعادة إصدار فاتورة لها لاحقاً.
                DistributionDetail::where('sales_invoice_id', $invoice->sales_invoice_id)->update([
                    'sales_invoice_id' => null,
                    'invoiced_at'      => null,
                ]);

                $invoice->details()->delete();
                $invoice->delete();
            });
        });
    }

    // =========================================================
    // POST / UNPOST
    // =========================================================
    public function post(int $id): SalesInvoice
    {
        return $this->withWriteLock(function () use ($id) {
            return DB::transaction(function () use ($id) {
                $invoice = SalesInvoice::lockForUpdate()->findOrFail($id);

                if ($invoice->isPosted()) {
                    throw new \RuntimeException('الفاتورة مرحّلة مسبقًا');
                }

                $this->postInternal($invoice);

                return $invoice->fresh(['details', 'customerAccount']);
            });
        });
    }

    public function unpost(int $id): SalesInvoice
    {
        return $this->withWriteLock(function () use ($id) {
            return DB::transaction(function () use ($id) {
                $invoice = SalesInvoice::lockForUpdate()->findOrFail($id);

                if (!$invoice->isPosted()) {
                    throw new \RuntimeException('الفاتورة غير مرحّلة');
                }

                if ($invoice->returns()->exists()) {
                    throw new \RuntimeException(
                        'لا يمكن إلغاء ترحيل فاتورة لها مرتجعات. احذف المرتجعات أولًا.'
                    );
                }

                $this->unpostInternal($invoice);

                return $invoice->fresh(['details', 'customerAccount']);
            });
        });
    }

    // =========================================================
    // POST / UNPOST INTERNAL
    // =========================================================
    private function postInternal(SalesInvoice $invoice): void
    {
        $invoice->loadMissing(['details', 'customerAccount']);

        $this->syncInventoryMovement($invoice);
        $entryId = $this->syncAccountingEntry($invoice);

        $invoice->entryID    = $entryId;
        $invoice->is_posted  = true;
        $invoice->posted_at  = now();
        $invoice->posted_by  = auth()->id();
        $invoice->updated_by = auth()->id();
        $invoice->save();
    }

    private function unpostInternal(SalesInvoice $invoice): void
    {
        $this->deleteAccountingEntry($invoice);
        $this->deleteInventoryMovement($invoice);

        $invoice->is_posted  = false;
        $invoice->posted_at  = null;
        $invoice->posted_by  = null;
        $invoice->updated_by = auth()->id();
        $invoice->save();
    }

    // =========================================================
    // WRITE LOCK
    // =========================================================
    private function withWriteLock(callable $callback)
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL);

        if (!$lock->get()) {
            throw new \RuntimeException(
                'عملية أخرى على فواتير البيع قيد التنفيذ، حاول بعد قليل'
            );
        }

        try {
            return $callback();
        } catch (\Throwable $e) {
            Log::error('Sales invoice operation failed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    // =========================================================
    // LOCK STOCK ROWS
    // =========================================================
    protected function lockStockRows(array $details): void
    {
        $pairs = [];

        foreach ($details as $row) {
            $itemId      = (int) ($row['item_id'] ?? 0);
            $warehouseId = (int) ($row['warehouse_id'] ?? 0);
            $unitId      = isset($row['unit_id']) && $row['unit_id'] !== null ? (int) $row['unit_id'] : null;
            $supplierId  = !empty($row['supplier_account_id']) ? (int) $row['supplier_account_id'] : null;

            if (!$itemId || !$warehouseId) continue;

            $key = $itemId . '|' . $warehouseId . '|' . ($unitId ?? 0) . '|' . ($supplierId ?? 0);
            $pairs[$key] = [
                'item_id'      => $itemId,
                'warehouse_id' => $warehouseId,
                'unit_id'      => $unitId,
                'supplier_account_id' => $supplierId,
            ];
        }

        if (empty($pairs)) return;

        ksort($pairs);

        DB::table('inventory_movement_details')
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $pair) {
                    $q->orWhere(function ($sub) use ($pair) {
                        $sub->where('item_id', $pair['item_id'])
                            ->where('warehouse_id', $pair['warehouse_id']);
                        if ($pair['unit_id']) $sub->where('unit_id', $pair['unit_id']);
                        if ($pair['supplier_account_id'] !== null) {
                            $sub->where('supplier_account_id', $pair['supplier_account_id']);
                        }
                    });
                }
            })
            ->lockForUpdate()
            ->pluck('movement_detail_id');
    }

    // =========================================================
    // STOCK AVAILABILITY
    // =========================================================
    protected function validateStockAvailability(array $details): void
    {
        $pairs = [];
        foreach ($details as $row) {
            $itemId      = (int) ($row['item_id'] ?? 0);
            $warehouseId = (int) ($row['warehouse_id'] ?? 0);
            $unitId      = isset($row['unit_id']) && $row['unit_id'] !== null ? (int) $row['unit_id'] : null;
            $supplierId  = !empty($row['supplier_account_id']) ? (int) $row['supplier_account_id'] : null;
            $qty         = (float) ($row['quantity'] ?? 0);

            if (!$itemId || !$warehouseId || $qty <= 0) continue;

            $key = $itemId . '|' . $warehouseId . '|' . ($unitId ?? '*') . '|' . ($supplierId ?? 'null');
            $pairs[$key] = [$itemId, $warehouseId, $unitId, $supplierId];
        }

        if (empty($pairs)) return;

        $inMap = [];
        $outMap = [];

        $buildFilter = function ($q) use ($pairs) {
            $q->where(function ($outer) use ($pairs) {
                foreach ($pairs as [$itemId, $warehouseId, $unitId, $supplierId]) {
                    $outer->orWhere(function ($sub) use ($itemId, $warehouseId, $unitId, $supplierId) {
                        $sub->where('inventory_movement_details.item_id', $itemId)
                            ->where('inventory_movement_details.warehouse_id', $warehouseId);
                        if ($unitId !== null) {
                            $sub->where('inventory_movement_details.unit_id', $unitId);
                        }
                        if ($supplierId === null) {
                            $sub->whereNull('inventory_movement_details.supplier_account_id');
                        } else {
                            $sub->where('inventory_movement_details.supplier_account_id', $supplierId);
                        }
                    });
                }
            });
        };

        $inRows = InventoryMovementDetail::query()
            ->join('inventory_movements', 'inventory_movements.movement_id', '=', 'inventory_movement_details.movement_id')
            ->tap($buildFilter)
            ->where('inventory_movements.direction', 'in')
            ->groupBy('inventory_movement_details.item_id', 'inventory_movement_details.warehouse_id', 'inventory_movement_details.unit_id', 'inventory_movement_details.supplier_account_id')
            ->selectRaw('inventory_movement_details.item_id, inventory_movement_details.warehouse_id, inventory_movement_details.unit_id, inventory_movement_details.supplier_account_id, COALESCE(SUM(inventory_movement_details.quantity), 0) as qty')
            ->get();

        foreach ($inRows as $r) {
            $kExact = $r->item_id . '|' . $r->warehouse_id . '|' . ($r->unit_id ?? 'null') . '|' . ($r->supplier_account_id ?? 'null');
            $kAnyUnit = $r->item_id . '|' . $r->warehouse_id . '|*|' . ($r->supplier_account_id ?? 'null');
            $inMap[$kExact] = ($inMap[$kExact] ?? 0.0) + (float) $r->qty;
            $inMap[$kAnyUnit] = ($inMap[$kAnyUnit] ?? 0.0) + (float) $r->qty;
        }

        $outRows = InventoryMovementDetail::query()
            ->join('inventory_movements', 'inventory_movements.movement_id', '=', 'inventory_movement_details.movement_id')
            ->tap($buildFilter)
            ->realOutflow()
            ->groupBy('inventory_movement_details.item_id', 'inventory_movement_details.warehouse_id', 'inventory_movement_details.unit_id', 'inventory_movement_details.supplier_account_id')
            ->selectRaw('inventory_movement_details.item_id, inventory_movement_details.warehouse_id, inventory_movement_details.unit_id, inventory_movement_details.supplier_account_id, COALESCE(SUM(inventory_movement_details.quantity), 0) as qty')
            ->get();

        foreach ($outRows as $r) {
            $kExact = $r->item_id . '|' . $r->warehouse_id . '|' . ($r->unit_id ?? 'null') . '|' . ($r->supplier_account_id ?? 'null');
            $kAnyUnit = $r->item_id . '|' . $r->warehouse_id . '|*|' . ($r->supplier_account_id ?? 'null');
            $outMap[$kExact] = ($outMap[$kExact] ?? 0.0) + (float) $r->qty;
            $outMap[$kAnyUnit] = ($outMap[$kAnyUnit] ?? 0.0) + (float) $r->qty;
        }

        $errors = [];
        foreach ($details as $i => $row) {
            $itemId      = $row['item_id'] ?? null;
            $warehouseId = $row['warehouse_id'] ?? null;
            $unitId      = $row['unit_id'] ?? null;
            $supplierId  = !empty($row['supplier_account_id']) ? (int) $row['supplier_account_id'] : null;
            $qty         = (float) ($row['quantity'] ?? 0);

            if (!$itemId || !$warehouseId || $qty <= 0) continue;

            $lookupKey = (int) $itemId . '|' . (int) $warehouseId . '|' . ($unitId !== null ? (int) $unitId : '*') . '|' . ($supplierId ?? 'null');
            $available = ($inMap[$lookupKey] ?? 0.0) - ($outMap[$lookupKey] ?? 0.0);

            if ($available < $qty) {
                $errors["details.{$i}.quantity"] =
                    "الكمية المتوفرة من هذه الدفعة {$available} أقل من المطلوب {$qty}";
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    // =========================================================
    // INVENTORY MOVEMENT
    // الفاتورة المرتبطة بصفوف توزيع → حركة "بيع توزيع" توثيقية
    // (الخصم الفعلي تم عبر حركة "توزيع" عند حفظ التوزيع).
    // =========================================================
    public function syncInventoryMovement(SalesInvoice $invoice): void
    {
        $invoice->loadMissing('customerAccount');
        $this->deleteInventoryMovement($invoice);

        $isFromDistribution = DistributionDetail::where(
            'sales_invoice_id',
            $invoice->sales_invoice_id
        )->exists();

        $movementType = $isFromDistribution
            ? InventoryMovement::TYPE_SALE_DISTRIBUTION
            : InventoryMovement::TYPE_SALE;

        $lastMovement = InventoryMovement::lockForUpdate()
            ->orderBy('movement_id', 'desc')
            ->first();

        $nextNumber = $lastMovement
            ? ((int) $lastMovement->display_id + 1)
            : 1;

        $firstDetail     = $invoice->details->first();
        $headerWarehouse = $firstDetail ? $firstDetail->warehouse_id : null;

        if (!$headerWarehouse) {
            throw new \RuntimeException('لا يمكن إنشاء حركة مخزون بدون مخزن');
        }

        $customerName = trim((string) optional($invoice->customerAccount)->accName);

        $statement = trim((string) ($invoice->statement ?? ''));
        if ($statement === '') {
            $statement = $isFromDistribution
                ? 'بيع توزيع — إصدار فاتورة بيع لصناف موزعة رقم ' . $invoice->invoice_number
                : 'صرف تلقائي من فاتورة البيع رقم ' . $invoice->invoice_number;
        }
        if ($customerName !== '') {
            $statement .= ' — العميل: ' . $customerName;
        }

        $movement = InventoryMovement::create([
            'display_id'      => (string) $nextNumber,
            'movement_type'   => $movementType,
            'direction'       => InventoryMovement::directionForType($movementType),
            'movement_date'   => $invoice->invoice_date,
            'document_number' => $invoice->invoice_number,
            'warehouse_id'    => $headerWarehouse,
            'statement'       => $statement,
            'source_type'     => InventoryMovement::SOURCE_SALES_INVOICE,
            'source_id'       => $invoice->sales_invoice_id,
            'total'           => 0,
        ]);

        $movementTotal = 0;

        // تجميع آخر تسعيرة لكل مجموعة (item, warehouse) دفعة واحدة لمنع N+1
        $pricingMap = [];
        $itemIds = $invoice->details->pluck('item_id')->unique()->filter()->values()->all();
        $whIds   = $invoice->details->pluck('warehouse_id')->unique()->filter()->values()->all();
        if (!empty($itemIds) && !empty($whIds)) {
            $pricingRows = InventoryMovementDetail::query()
                ->join('inventory_movements', 'inventory_movements.movement_id', '=', 'inventory_movement_details.movement_id')
                ->whereIn('inventory_movement_details.item_id', $itemIds)
                ->whereIn('inventory_movement_details.warehouse_id', $whIds)
                ->where('inventory_movements.direction', 'in')
                ->whereNotNull('inventory_movement_details.sale_price')
                ->orderByDesc('inventory_movement_details.movement_detail_id')
                ->select([
                    'inventory_movement_details.item_id',
                    'inventory_movement_details.warehouse_id',
                    'inventory_movement_details.unit_id',
                    'inventory_movement_details.supplier_account_id',
                    'inventory_movement_details.sale_price',
                    'inventory_movement_details.min_price',
                    'inventory_movement_details.max_price',
                ])
                ->get();

            foreach ($pricingRows as $pr) {
                $kExact = $pr->item_id . '|' . $pr->warehouse_id . '|' . ($pr->unit_id ?? '*') . '|' . ($pr->supplier_account_id ?? '*');
                $kNoSup = $pr->item_id . '|' . $pr->warehouse_id . '|' . ($pr->unit_id ?? '*') . '|*';
                $kAny   = $pr->item_id . '|' . $pr->warehouse_id . '|*|*';
                $val = [
                    'sale_price' => (float) $pr->sale_price,
                    'min_price'  => (float) $pr->min_price,
                    'max_price'  => (float) $pr->max_price,
                ];
                if (!isset($pricingMap[$kExact])) $pricingMap[$kExact] = $val;
                if (!isset($pricingMap[$kNoSup])) $pricingMap[$kNoSup] = $val;
                if (!isset($pricingMap[$kAny]))   $pricingMap[$kAny]   = $val;
            }
        }

        foreach ($invoice->details as $detail) {
            $quantity  = (float) $detail->quantity;
            $unitCost  = (float) $detail->cost_price;
            $lineTotal = $quantity * $unitCost;

            $uKey = $detail->unit_id !== null ? (int) $detail->unit_id : '*';
            $sKey = $detail->supplier_account_id !== null ? (int) $detail->supplier_account_id : '*';
            $lastPricing = $pricingMap["{$detail->item_id}|{$detail->warehouse_id}|{$uKey}|{$sKey}"]
                ?? $pricingMap["{$detail->item_id}|{$detail->warehouse_id}|{$uKey}|*"]
                ?? $pricingMap["{$detail->item_id}|{$detail->warehouse_id}|*|*"]
                ?? ['sale_price' => 0, 'min_price' => 0, 'max_price' => 0];

            InventoryMovementDetail::create([
                'movement_id'  => $movement->movement_id,
                'item_id'      => $detail->item_id,
                'type_id'      => $detail->type_id,
                'unit_id'      => $detail->unit_id,
                'code'         => $detail->code,
                'warehouse_id' => $detail->warehouse_id,
                'supplier_account_id' => $detail->supplier_account_id,
                'quantity'     => $quantity,
                'unit_cost'    => $unitCost,
                'min_price'    => $lastPricing['min_price'] > 0 ? $lastPricing['min_price'] : null,
                'max_price'    => $lastPricing['max_price'] > 0 ? $lastPricing['max_price'] : null,
                'sale_price'   => $detail->price,
                'total'        => $lineTotal,
            ]);

            $movementTotal += $lineTotal;
        }

        $movement->total = $movementTotal;
        $movement->save();

        $movement->load('details');
        $this->inventoryService->applyMovement($movement);
    }

    public function deleteInventoryMovement(SalesInvoice $invoice): void
    {
        $movements = InventoryMovement::where(
                'source_type',
                InventoryMovement::SOURCE_SALES_INVOICE
            )
            ->where('source_id', $invoice->sales_invoice_id)
            ->with('details')
            ->get();

        foreach ($movements as $movement) {
            $this->inventoryService->reverseMovement($movement);
            $movement->details()->delete();
            $movement->delete();
        }
    }

    // =========================================================
    // ACCOUNTING ENTRY
    // =========================================================
    protected function syncAccountingEntry(SalesInvoice $invoice): int
    {
        $this->deleteAccountingEntry($invoice);

        $rate     = (float) ($invoice->exchange_rate ?: 1);
        $itemsNet = (float) $invoice->items_total - (float) $invoice->discount_total;

        $totalFC = round($itemsNet, 6);
        $totalBC = round($totalFC * $rate, 6);

        if ($totalBC <= 0) {
            throw new \RuntimeException(
                'إجمالي فاتورة البيع يجب أن يكون أكبر من صفر لإنشاء القيد'
            );
        }

        $paymentMethod = (int) $invoice->payment_method;
        $debitAccountId = $paymentMethod === SalesInvoice::PAYMENT_CREDIT
            ? (int) $invoice->account_id
            : (int) $invoice->payment_account_id;

        if ($debitAccountId <= 0) {
            throw new \RuntimeException(
                'حساب الدفع غير محدد للفاتورة رقم ' . $invoice->invoice_number
            );
        }

        $salesRevenueAccountId = $this->getAccountIdBySystemKey(
            self::SALES_REVENUE_SYSTEM_KEY
        );

        if ($salesRevenueAccountId <= 0) {
            throw new \RuntimeException(
                'حساب المبيعات (' . self::SALES_REVENUE_SYSTEM_KEY
                . ') غير موجود في دليل الحسابات'
            );
        }

        $cogsAccountId = $this->getAccountIdBySystemKey(
            self::COST_OF_GOODS_SOLD_SYSTEM_KEY
        );

        if ($cogsAccountId <= 0) {
            throw new \RuntimeException(
                'حساب تكلفة البضاعة المباعة (' . self::COST_OF_GOODS_SOLD_SYSTEM_KEY
                . ') غير موجود في دليل الحسابات'
            );
        }

        $entryDate = $invoice->invoice_date instanceof \DateTimeInterface
            ? $invoice->invoice_date->format('Y-m-d')
            : (string) ($invoice->invoice_date ?: now()->toDateString());

        $docType = $invoice->journal_doc_type_label;
        $customerName = $invoice->customerAccount->accName ?? '';

        $description = $invoice->journal_doc_type_label
            . ' رقم ' . $invoice->invoice_number
            . ($customerName !== '' ? ' - ' . $customerName : '');

        $lines = [
            [
                'accountID'    => $debitAccountId,
                'coinsID'      => $invoice->coin_id,
                'exchangRate'  => $rate,
                'debit'        => $totalFC,
                'credit'       => 0,
                'localDebit'   => $totalBC,
                'localCredit'  => 0,
                'description2' => $description,
            ],
            [
                'accountID'    => $salesRevenueAccountId,
                'coinsID'      => $invoice->coin_id,
                'exchangRate'  => $rate,
                'debit'        => 0,
                'credit'       => $totalFC,
                'localDebit'   => 0,
                'localCredit'  => $totalBC,
                'description2' => $description . ' - إيراد المبيعات',
            ],
        ];

        $cogsByWarehouse = [];
        $cogsTotalFC = 0;
        $cogsTotalBC = 0;

        foreach ($invoice->details as $detail) {
            $qty      = (float) $detail->quantity;
            $costFC   = (float) $detail->cost_price;
            $lineFC   = $qty * $costFC;
            $lineBC   = $lineFC * $rate;

            $warehouseId = (int) $detail->warehouse_id;

            if (!isset($cogsByWarehouse[$warehouseId])) {
                $cogsByWarehouse[$warehouseId] = ['fc' => 0, 'bc' => 0];
            }

            $cogsByWarehouse[$warehouseId]['fc'] += $lineFC;
            $cogsByWarehouse[$warehouseId]['bc'] += $lineBC;

            $cogsTotalFC += $lineFC;
            $cogsTotalBC += $lineBC;
        }

        if ($cogsTotalFC > 0) {
            $lines[] = [
                'accountID'    => $cogsAccountId,
                'coinsID'      => $invoice->coin_id,
                'exchangRate'  => $rate,
                'debit'        => round($cogsTotalFC, 6),
                'credit'       => 0,
                'localDebit'   => round($cogsTotalBC, 6),
                'localCredit'  => 0,
                'description2' => $description . ' - تكلفة البضاعة المباعة',
            ];

            foreach ($cogsByWarehouse as $warehouseId => $amounts) {
                $inventoryAccountId = $this->getInventoryAccountIdFromWarehouse($warehouseId);
                if ($inventoryAccountId <= 0) {
                    throw new \RuntimeException('حساب المخزون غير مُعرّف للمخزن رقم ' . $warehouseId);
                }

                $lines[] = [
                    'accountID'    => $inventoryAccountId,
                    'coinsID'      => $invoice->coin_id,
                    'exchangRate'  => $rate,
                    'debit'        => 0,
                    'credit'       => round($amounts['fc'], 6),
                    'localDebit'   => 0,
                    'localCredit'  => round($amounts['bc'], 6),
                    'description2' => $description . ' - خروج من المخزون',
                ];
            }
        }

        $entryId = JournalEntryService::create([
            'entryDate'   => $entryDate,
            'docType'     => $docType,
            'docNumber'   => (string) $invoice->invoice_number,
            'description' => $description,
            'lines'       => $lines,
        ]);

        if (!$entryId) {
            throw new \RuntimeException(
                'فشل إنشاء القيد المحاسبي لفاتورة البيع رقم ' . $invoice->invoice_number
            );
        }

        return (int) $entryId;
    }

    protected function deleteAccountingEntry(SalesInvoice $invoice): void
    {
        if (!empty($invoice->entryID)) {
            JournalEntryService::delete((int) $invoice->entryID);
            $invoice->entryID = null;
            $invoice->saveQuietly();
            return;
        }

        if (!empty($invoice->invoice_number)) {
            JournalEntryService::deleteByDocNumber(
                (string) $invoice->invoice_number
            );
        }
    }

    // =========================================================
    // ACCOUNT RESOLVERS (Cache)
    // =========================================================
    private function getAccountIdBySystemKey(string $systemKey): int
    {
        return (int) Cache::remember(
            "accounting.{$systemKey}_id",
            self::INVENTORY_ACCOUNT_CACHE_TTL,
            function () use ($systemKey) {
                return (int) CharAccount::query()
                    ->where('system_key', $systemKey)
                    ->value('accountID');
            }
        );
    }

    private function getInventoryAccountIdFromWarehouse(int $warehouseId): int
    {
        if ($warehouseId <= 0) {
            return 0;
        }

        return (int) Cache::remember(
            "warehouse.inventory_account.{$warehouseId}",
            self::INVENTORY_ACCOUNT_CACHE_TTL,
            function () use ($warehouseId) {
                return (int) Stock::query()
                    ->where('StockID', $warehouseId)
                    ->value('accountID');
            }
        );
    }

    // =========================================================
    // HELPERS
    // =========================================================

    protected function nextInvoiceNumber(): int
    {
        // عدّاد ذرّي على مستوى قاعدة البيانات (document_counters) — لا يعود
        // للخلف بعد حذف آخر فاتورة، ولا يعتمد على مخزن الكاش (الفحص F-17).
        return \App\Services\DocumentNumbering::allocate('sales_invoices');
    }

    protected function headerData(array $data): array
    {
        return [
            'invoice_number'     => $data['invoice_number'] ?? null,
            'invoice_date'       => $data['invoice_date'] ?? null,
            'account_id'         => $data['account_id'] ?? null,
            'payment_account_id' => $data['payment_account_id'] ?? null,
            'coin_id'            => $data['coin_id'] ?? null,
            'exchange_rate'      => $data['exchange_rate'] ?? 1,
            'payment_method'     => $data['payment_method'] ?? null,
            'statement'          => $data['statement'] ?? null,
            'reference'          => $data['reference'] ?? null,
        ];
    }

    protected function saveDetails(SalesInvoice $invoice, array $details): void
    {
        // خريطة تكلفة لكل مجموعة (item|warehouse|unit|supplier) — تمنع N+1
        // عند تعدد الصفوف للمجموعة نفسها داخل الفاتورة الواحدة.
        $lastCostMap = [];

        foreach ($details as $row) {
            $itemId      = (int) $row['item_id'];
            $warehouseId = (int) $row['warehouse_id'];
            $unitId      = isset($row['unit_id']) && $row['unit_id'] !== null
                ? (int) $row['unit_id']
                : null;

            $quantity = (float) ($row['quantity'] ?? 0);
            $price    = (float) ($row['price'] ?? 0);
            $discount = (float) ($row['discount'] ?? 0);
            $total    = max(0, ($quantity * $price) - $discount);

            $supplierId = !empty($row['supplier_account_id'])
                ? (int) $row['supplier_account_id']
                : null;

            // ✅ صفوف التوزيع: التكلفة تُحتسب خادمياً حصراً — لا ثقة بحقل الواجهة
            // (تزوير cost_price كان سيفسد COGS والقيد المحاسبي وحركة المخزون).
            // distribution_details.unit_cost مخزّن بوحدة الصف نفسه من التحويل
            // الخادمي (bcmath) في PurchaseDistributionController.
            if (!empty($row['distribution_detail_id'])) {
                $costPrice = $this->distributionRowCost(
                    $invoice,
                    $row,
                    $itemId,
                    $warehouseId,
                    $unitId,
                    $supplierId
                );
            } else {
                $costKey = $itemId . '|' . $warehouseId . '|' . ($unitId ?? 0) . '|' . ($supplierId ?? 0);

                if (! array_key_exists($costKey, $lastCostMap)) {
                    $lastCostMap[$costKey] = $this->inventoryService->lastCostForSupplier(
                        $itemId,
                        $warehouseId,
                        $unitId,
                        $supplierId
                    );
                }

                $serverCost = $lastCostMap[$costKey];

                $uiCost = (float) ($row['cost_price'] ?? 0);

                if ($uiCost <= 0) {
                    $costPrice = $serverCost;
                } elseif ($serverCost <= 0) {
                    $costPrice = $uiCost;
                } else {
                    $diffRatio = abs($uiCost - $serverCost) / $serverCost;
                    $costPrice = $diffRatio > 0.1 ? $serverCost : $uiCost;
                }
            }

            SalesInvoiceDetail::create([
                'sales_invoice_id' => $invoice->sales_invoice_id,
                'item_id'          => $itemId,
                'type_id'          => $row['type_id'] ?? null,
                'unit_id'          => $unitId,
                'warehouse_id'     => $warehouseId,
                'supplier_account_id' => $supplierId,
                'code'             => $row['code'] ?? null,
                'quantity'         => $quantity,
                'price'            => $price,
                'cost_price'       => $costPrice,
                'discount'         => $discount,
                'total'            => $total,
            ]);
        }
    }

    /**
     * ✅ تكلفة صف فاتورة بيع مرتبط بصف توزيع — مصدرها الخادم حصراً.
     *
     * التسلسل:
     *  1) distribution_details.unit_cost (مخزّن خادمياً بوحدة الصف عند حفظ التوزيع).
     *  2) للصفوف القديمة بلا تكلفة مخزنة: تكلفة دفعة المصدر × (quantity_base / quantity).
     *  3) شبكة أمان أخيرة: آخر تكلفة للمورد.
     *
     * يرفض الصف إذا كان صف التوزيع غير موجود، أو لا يطابق صنف الفاتورة،
     * أو مفوتراً مسبقاً بفاتورة أخرى (حماية من الربط المزدوج).
     */
    protected function distributionRowCost(
        SalesInvoice $invoice,
        array $row,
        int $itemId,
        int $warehouseId,
        ?int $unitId,
        ?int $supplierId
    ): float {
        $dd = DistributionDetail::query()
            ->with('movementDetail')
            ->lockForUpdate()
            ->find((int) $row['distribution_detail_id']);

        if (!$dd || !$dd->movementDetail || (int) $dd->movementDetail->item_id !== $itemId) {
            throw ValidationException::withMessages([
                'details' => 'صف توزيع غير صالح أو لا يطابق صنف الفاتورة.',
            ]);
        }

        $linkedToOther = $dd->sales_invoice_id !== null
            && (int) $dd->sales_invoice_id !== (int) $invoice->sales_invoice_id;

        if ($linkedToOther || ($dd->sales_invoice_id === null && $dd->invoiced_at !== null)) {
            throw ValidationException::withMessages([
                'details' => "صف التوزيع رقم {$dd->id} مفوتر مسبقاً بفاتورة أخرى.",
            ]);
        }

        $storedCost = (float) $dd->unit_cost;
        if ($storedCost > 0) {
            return $storedCost;
        }

        // صف قديم بلا تكلفة مخزنة — احسبها من دفعة المصدر (بوحدة الصف)
        $mdCost  = (float) ($dd->movementDetail->unit_cost ?? 0);
        $ddQty   = (float) $dd->quantity;
        $qtyBase = (float) $dd->quantity_base > 0 ? (float) $dd->quantity_base : $ddQty;

        if ($mdCost > 0 && $ddQty > 0) {
            return round($mdCost * ($qtyBase / $ddQty), 6);
        }

        // شبكة الأمان الأخيرة
        return (float) $this->inventoryService->lastCostForSupplier(
            $itemId,
            $warehouseId,
            $unitId,
            $supplierId
        );
    }

    protected function recalculateTotals(SalesInvoice $invoice): void
    {
        $details = $invoice->details()->get();

        $itemsTotal    = 0;
        $discountTotal = 0;

        foreach ($details as $d) {
            $itemsTotal    += (float) $d->quantity * (float) $d->price;
            $discountTotal += (float) $d->discount;
        }

        $net  = max(0, $itemsTotal - $discountTotal);
        $rate = (float) ($invoice->exchange_rate ?: 1);

        $invoice->items_total    = $itemsTotal;
        $invoice->discount_total = $discountTotal;
        $invoice->total_in_base_currency = $net * $rate;
        $invoice->save();
    }
}
