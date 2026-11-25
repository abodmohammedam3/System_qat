<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\InventoryMovementDetail;
use App\Models\Purchases\DistributionDetail;
use App\Models\Sales\SalesInvoice;
use App\Reports\Inventory\ItemLedgerReport;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * اختبارات تكامل دورة التوزيع -> فاتورة البيع -> حركة بيع توزيع التوثيقية:
 *  1. استثناء sale_distribution من أرصدة المخزون ودفعات الفرز وتقرير حركة الصنف.
 *  2. تنفيذ التدفق الكامل عبر HTTP: فاتورة شراء -> حفظ توزيع -> إصدار فاتورة بيع
 *     والتحقق من عدم الخصم المزدوج.
 */
class DistributionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected int $currencyId;
    protected int $supplierAccountId;
    protected int $customerAccountId;
    protected int $customerId;
    protected int $warehouseId;
    protected int $itemId;
    protected int $typeId;
    protected int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        $this->seedBaseData();
    }

    protected function seedBaseData(): void
    {
        $this->currencyId = DB::table('coins')->insertGetId([
            'coinsName'         => 'ريال يمني',
            'coinsCode'         => 'YER',
            'coinsExchangeRate' => 1,
            'coinsSystem'       => 1,
        ]);

        $this->supplierAccountId = DB::table('characcount')->insertGetId([
            'accName'    => 'مورد توزيع',
            'accCode'    => 'S100',
            'isPostable' => 1,
        ]);

        $this->customerAccountId = DB::table('characcount')->insertGetId([
            'accName'    => 'عميل توزيع',
            'accCode'    => 'C100',
            'isPostable' => 1,
        ]);

        $this->customerId = DB::table('customers')->insertGetId([
            'CustomersName2' => 'عميل توزيع',
            'accountID'      => $this->customerAccountId,
        ]);

        $warehouseAccountId = DB::table('characcount')->insertGetId([
            'accName'    => 'حساب مخزن التوزيع',
            'accCode'    => 'W100',
            'isPostable' => 1,
        ]);

        $this->warehouseId = DB::table('stocks')->insertGetId([
            'StockName' => 'مخزن التوزيع',
            'accountID' => $warehouseAccountId,
        ]);

        foreach ([
            ['accCode' => '4100', 'accName' => 'المبيعات',             'nature' => 1, 'system_key' => 'sales_revenue'],
            ['accCode' => '5100', 'accName' => 'تكلفة البضاعة المباعة', 'nature' => 0, 'system_key' => 'cost_of_goods_sold'],
            ['accCode' => '4200', 'accName' => 'مردود المبيعات',        'nature' => 0, 'system_key' => 'sales_returns'],
            ['accCode' => '2300', 'accName' => 'مصاريف مشتريات مستحقة', 'nature' => 1, 'system_key' => 'AccruedPurchaseCosts'],
        ] as $acc) {
            DB::table('characcount')->insertGetId([
                'accCode'    => $acc['accCode'],
                'accName'    => $acc['accName'],
                'accLevel'   => 2,
                'nature'     => $acc['nature'],
                'isPostable' => 1,
                'is_system'  => 1,
                'system_key' => $acc['system_key'],
            ]);
        }

        $this->itemId = DB::table('items')->insertGetId([
            'itemName2' => 'صنف توزيع',
            'is_active' => 1,
        ]);

        $this->typeId = DB::table('type')->insertGetId([
            'name'      => 'نوع توزيع',
            'is_active' => 1,
        ]);

        $this->unitId = DB::table('units')->insertGetId([
            'UnitName'          => 'حبة',
            'conversion_factor' => 1,
            'is_active'         => 1,
        ]);
    }

    public function test_sale_distribution_excluded_from_balance_sorting_batches_and_item_ledger(): void
    {
        $inMovement = InventoryMovement::create([
            'display_id'    => '1',
            'movement_type' => InventoryMovement::TYPE_PURCHASE,
            'direction'     => InventoryMovement::DIRECTION_IN,
            'movement_date' => '2026-11-25',
            'warehouse_id'  => $this->warehouseId,
            'total'         => 1000,
        ]);

        InventoryMovementDetail::create([
            'movement_id'         => $inMovement->movement_id,
            'item_id'             => $this->itemId,
            'type_id'             => null,
            'unit_id'             => $this->unitId,
            'warehouse_id'        => $this->warehouseId,
            'supplier_account_id' => $this->supplierAccountId,
            'quantity'            => 10,
            'unit_cost'           => 100,
            'total'               => 1000,
        ]);

        $distMovement = InventoryMovement::create([
            'display_id'    => '2',
            'movement_type' => InventoryMovement::TYPE_DISTRIBUTION,
            'direction'     => InventoryMovement::DIRECTION_OUT,
            'movement_date' => '2026-11-25',
            'warehouse_id'  => $this->warehouseId,
            'total'         => 400,
        ]);

        InventoryMovementDetail::create([
            'movement_id'         => $distMovement->movement_id,
            'item_id'             => $this->itemId,
            'type_id'             => null,
            'unit_id'             => $this->unitId,
            'warehouse_id'        => $this->warehouseId,
            'supplier_account_id' => $this->supplierAccountId,
            'quantity'            => 4,
            'unit_cost'           => 100,
            'total'               => 400,
        ]);

        $saleDistMovement = InventoryMovement::create([
            'display_id'    => '3',
            'movement_type' => InventoryMovement::TYPE_SALE_DISTRIBUTION,
            'direction'     => InventoryMovement::DIRECTION_OUT,
            'movement_date' => '2026-11-25',
            'warehouse_id'  => $this->warehouseId,
            'total'         => 400,
        ]);

        InventoryMovementDetail::create([
            'movement_id'         => $saleDistMovement->movement_id,
            'item_id'             => $this->itemId,
            'type_id'             => null,
            'unit_id'             => $this->unitId,
            'warehouse_id'        => $this->warehouseId,
            'supplier_account_id' => $this->supplierAccountId,
            'quantity'            => 4,
            'unit_cost'           => 100,
            'total'               => 400,
        ]);

        // 1. رصيد InventoryService: 10 - 4 = 6 (بدون خصم sale_distribution)
        $available = app(InventoryService::class)->availableQuantity(
            $this->itemId,
            $this->warehouseId,
            $this->unitId
        );
        $this->assertEqualsWithDelta(6.0, $available, 0.0001);

        // 2. دفعات الفرز: total_in - total_out = 10 - 4 = 6
        $batchesResponse = $this->getJson(route('movements.helpers.stockBalances'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $batchRow = collect($batchesResponse->json('data'))
            ->firstWhere('item_id', $this->itemId);
        $this->assertNotNull($batchRow);
        $this->assertEqualsWithDelta(6.0, (float) $batchRow['quantity'], 0.0001);

        // 3. تقرير حركة الصنف: out_qty = 4 (بدون احتساب sale_distribution التوثيقية)
        $ledger = app(ItemLedgerReport::class)->run(['item_id' => $this->itemId]);
        $this->assertEqualsWithDelta(10.0, $ledger['totals']['in_qty'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $ledger['totals']['out_qty'], 0.0001);

        $docRow = collect($ledger['rows'])->firstWhere('id', $saleDistMovement->movement_id);
        $this->assertNotNull($docRow);
        $this->assertSame('بيع توزيع (توثيقي)', $docRow['movement_type']);
        $this->assertEqualsWithDelta(0.0, $docRow['out_qty'], 0.0001);
    }

    public function test_distribution_save_and_issue_invoice_full_flow_without_double_deduction(): void
    {
        // 1. إنشاء فاتورة شراء بكمية 10 وتكلفة 100
        $purchaseRes = $this->postJson(route('invoicesPurch.store'), [
            'invoice_number' => 'PINV-DIST-001',
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAccountId,
            'warehouse_id'   => $this->warehouseId,
            'coin_id'        => $this->currencyId,
            'exchange_rate'  => 1,
            'payment_method' => 1,
            'details'        => [
                [
                    'item_id'  => $this->itemId,
                    'unit_id'  => $this->unitId,
                    'quantity' => 10,
                    'price'    => 100,
                    'discount' => 0,
                ],
            ],
        ])->assertStatus(201);

        $inventory = app(InventoryService::class);
        $this->assertEqualsWithDelta(
            10.0,
            $inventory->availableQuantity($this->itemId, $this->warehouseId, $this->unitId),
            0.0001
        );

        $purchaseDetail = InventoryMovementDetail::query()
            ->with('movement')
            ->where('item_id', $this->itemId)
            ->orderByDesc('movement_detail_id')
            ->firstOrFail();

        $this->assertSame(
            InventoryMovement::SOURCE_PURCHASE_INVOICE,
            $purchaseDetail->movement?->source_type
        );
        $this->assertNotNull(
            InventoryMovementDetail::with('movement:movement_id,source_type,source_id')
                ->find($purchaseDetail->movement_detail_id)
                ?->movement
        );

        // 2. حفظ توزيع للعميل بكمية 4 بسعر بيع 150
        $distRes = $this->postJson(route('purchases.distribution.save'), [
            'distribution_date' => '2026-11-25',
            'details'           => [
                [
                    'movement_detail_id' => $purchaseDetail->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $this->unitId,
                    'quantity'           => 4,
                    'sale_price'         => 150,
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $distributionId = (int) $distRes->json('id');
        $this->assertGreaterThan(0, $distributionId);

        $distDetail = DistributionDetail::where('distribution_id', $distributionId)->firstOrFail();
        $this->assertSame($this->typeId, (int) $distDetail->type_id);
        $this->assertSame($this->unitId, (int) $distDetail->unit_id);
        $this->assertEqualsWithDelta(4.0, (float) $distDetail->quantity_base, 0.0001);
        $this->assertEqualsWithDelta(600.0, (float) $distDetail->total, 0.0001);

        $this->assertEqualsWithDelta(
            6.0,
            $inventory->availableQuantity($this->itemId, $this->warehouseId, $this->unitId),
            0.0001
        );

        // 3. إصدار فاتورة بيع من التوزيع
        $issueRes = $this->postJson(route('purchases.distribution.issue-invoice'), [
            'customer_id'      => $this->customerId,
            'distribution_ids' => [$distributionId],
            'payment_method'   => SalesInvoice::PAYMENT_CREDIT,
        ])->assertOk()->assertJsonPath('success', true);

        $salesInvoiceId = (int) $issueRes->json('sales_invoice_id');
        $this->assertGreaterThan(0, $salesInvoiceId);

        // التحقق من إنشاء حركة sale_distribution التوثيقية
        $this->assertDatabaseHas('inventory_movements', [
            'source_type'   => InventoryMovement::SOURCE_SALES_INVOICE,
            'source_id'     => $salesInvoiceId,
            'movement_type' => InventoryMovement::TYPE_SALE_DISTRIBUTION,
            'direction'     => InventoryMovement::DIRECTION_OUT,
        ]);

        // 4. الرصيد يبقى 6 (لا خصم مزدوج بعد إصدار فاتورة البيع)
        $this->assertEqualsWithDelta(
            6.0,
            $inventory->availableQuantity($this->itemId, $this->warehouseId, $this->unitId),
            0.0001
        );

        // 5. تقرير حركة الصنف ودفعات الفرز يعكسان 4 خروج و6 متاح
        $ledger = app(ItemLedgerReport::class)->run(['item_id' => $this->itemId]);
        $this->assertEqualsWithDelta(10.0, $ledger['totals']['in_qty'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $ledger['totals']['out_qty'], 0.0001);
    }

    public function test_distribution_unit_conversion_with_conversion_factor_and_piece_weight(): void
    {
        $kiloUnitId = DB::table('units')->insertGetId([
            'UnitName'          => 'كيلو',
            'conversion_factor' => 1000,
            'is_active'         => 1,
        ]);
        $boxUnitId = DB::table('units')->insertGetId([
            'UnitName'          => 'علبة',
            'conversion_factor' => 250,
            'is_active'         => 1,
        ]);

        // شراء 10 كيلو بسعر 100 للكيلو
        $this->postJson(route('invoicesPurch.store'), [
            'invoice_number' => 'PINV-CONV-001',
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAccountId,
            'warehouse_id'   => $this->warehouseId,
            'coin_id'        => $this->currencyId,
            'exchange_rate'  => 1,
            'payment_method' => 1,
            'details'        => [
                [
                    'item_id'  => $this->itemId,
                    'unit_id'  => $kiloUnitId,
                    'quantity' => 10,
                    'price'    => 100,
                    'discount' => 0,
                ],
            ],
        ])->assertStatus(201);

        $inventory = app(InventoryService::class);
        $this->assertEqualsWithDelta(
            10.0,
            $inventory->availableQuantity($this->itemId, $this->warehouseId, $kiloUnitId),
            0.0001
        );

        $purchaseDetail = InventoryMovementDetail::query()
            ->where('item_id', $this->itemId)
            ->where('unit_id', $kiloUnitId)
            ->orderByDesc('movement_detail_id')
            ->firstOrFail();

        // توزيع: 2 علبة (2 * 250 / 1000 = 0.5 كيلو) + 3 حبة بوزن 500 جم (3 * 500 / 1000 = 1.5 كيلو)
        $distRes = $this->postJson(route('purchases.distribution.save'), [
            'distribution_date' => '2026-11-25',
            'details'           => [
                [
                    'movement_detail_id' => $purchaseDetail->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $boxUnitId,
                    'quantity'           => 2,
                    'sale_price'         => 40,
                ],
                [
                    'movement_detail_id' => $purchaseDetail->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $this->unitId, // حبة
                    'unit_weight'        => 500,
                    'quantity'           => 3,
                    'sale_price'         => 75,
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $distributionId = (int) $distRes->json('id');
        $rows = DistributionDetail::where('distribution_id', $distributionId)->get()->keyBy('unit_id');

        $boxRow = $rows->get($boxUnitId);
        $this->assertNotNull($boxRow);
        $this->assertEqualsWithDelta(0.5, (float) $boxRow->quantity_base, 0.0001);
        $this->assertEqualsWithDelta(25.0, (float) $boxRow->unit_cost, 0.0001);

        $pieceRow = $rows->get($this->unitId);
        $this->assertNotNull($pieceRow);
        $this->assertEqualsWithDelta(1.5, (float) $pieceRow->quantity_base, 0.0001);
        $this->assertEqualsWithDelta(500.0, (float) $pieceRow->unit_weight, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $pieceRow->unit_cost, 0.0001);

        // الرصيد المتبقي بالكيلو: 10 - (0.5 + 1.5) = 8.0
        $available = app(InventoryService::class)->availableQuantity(
            $this->itemId,
            $this->warehouseId,
            $kiloUnitId
        );
        $this->assertEqualsWithDelta(8.0, $available, 0.0001);
    }

    public function test_second_invoice_for_same_distribution_and_over_quantity_are_rejected(): void
    {
        $this->postJson(route('invoicesPurch.store'), [
            'invoice_number' => 'PINV-REJ-001',
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAccountId,
            'warehouse_id'   => $this->warehouseId,
            'coin_id'        => $this->currencyId,
            'exchange_rate'  => 1,
            'payment_method' => 1,
            'details'        => [
                [
                    'item_id'  => $this->itemId,
                    'unit_id'  => $this->unitId,
                    'quantity' => 5,
                    'price'    => 100,
                    'discount' => 0,
                ],
            ],
        ])->assertStatus(201);

        $purchaseDetail = InventoryMovementDetail::query()
            ->where('item_id', $this->itemId)
            ->orderByDesc('movement_detail_id')
            ->firstOrFail();

        // محاولة توزيع كمية أكبر من المتاح (6 > 5) -> رفض 422
        $this->postJson(route('purchases.distribution.save'), [
            'distribution_date' => '2026-11-25',
            'details'           => [
                [
                    'movement_detail_id' => $purchaseDetail->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $this->unitId,
                    'quantity'           => 6,
                    'sale_price'         => 150,
                ],
            ],
        ])->assertStatus(422);

        // توزيع صحيح بكمية 3
        $distRes = $this->postJson(route('purchases.distribution.save'), [
            'distribution_date' => '2026-11-25',
            'details'           => [
                [
                    'movement_detail_id' => $purchaseDetail->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $this->unitId,
                    'quantity'           => 3,
                    'sale_price'         => 150,
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $distributionId = (int) $distRes->json('id');
        $distDetailId   = (int) DistributionDetail::where('distribution_id', $distributionId)->value('id');

        // إصدار فاتورة بيع أولى -> نجاح
        $this->postJson(route('purchases.distribution.issue-invoice'), [
            'customer_id'      => $this->customerId,
            'distribution_ids' => [$distributionId],
            'detail_ids'       => [$distDetailId],
            'payment_method'   => SalesInvoice::PAYMENT_CREDIT,
        ])->assertOk()->assertJsonPath('success', true);

        // محاولة إصدار فاتورة ثانية لنفس صف التوزيع -> رفض 422
        $this->postJson(route('purchases.distribution.issue-invoice'), [
            'customer_id'      => $this->customerId,
            'distribution_ids' => [$distributionId],
            'detail_ids'       => [$distDetailId],
            'payment_method'   => SalesInvoice::PAYMENT_CREDIT,
        ])->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_sorted_batch_distribution_links_to_root_purchase_invoice(): void
    {
        $kiloUnitId = DB::table('units')->insertGetId([
            'UnitName'          => 'كيلو فرز',
            'conversion_factor' => 1000,
            'is_active'         => 1,
        ]);
        $bagUnitId = DB::table('units')->insertGetId([
            'UnitName'          => 'كيس فرز',
            'conversion_factor' => 500,
            'is_active'         => 1,
        ]);

        $purchaseRes = $this->postJson(route('invoicesPurch.store'), [
            'invoice_number' => 'PINV-SORT-001',
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAccountId,
            'warehouse_id'   => $this->warehouseId,
            'coin_id'        => $this->currencyId,
            'exchange_rate'  => 1,
            'payment_method' => 1,
            'details'        => [
                [
                    'item_id'  => $this->itemId,
                    'unit_id'  => $kiloUnitId,
                    'quantity' => 10,
                    'price'    => 100,
                    'discount' => 0,
                ],
            ],
        ])->assertStatus(201);

        $rootInvoiceId = (int) $purchaseRes->json('purchase_invoice_id');
        $purchaseDetail = InventoryMovementDetail::query()
            ->where('item_id', $this->itemId)
            ->where('unit_id', $kiloUnitId)
            ->orderByDesc('movement_detail_id')
            ->firstOrFail();

        // فرز 4 كيلو إلى 8 أكياس
        $this->postJson(route('movements.sort'), [
            'item_id'             => $this->itemId,
            'warehouse_id'        => $this->warehouseId,
            'input_unit_id'       => $kiloUnitId,
            'output_unit_id'      => $bagUnitId,
            'input_quantity'      => 4,
            'output_quantity'     => 8,
            'supplier_account_id' => $this->supplierAccountId,
            'movement_date'       => '2026-11-25',
            'batch_id'            => $purchaseDetail->movement_detail_id,
        ])->assertStatus(201)->assertJsonPath('success', true);

        $sortedInDetail = InventoryMovementDetail::query()
            ->join('inventory_movements as im', 'im.movement_id', '=', 'inventory_movement_details.movement_id')
            ->where('im.movement_type', InventoryMovement::TYPE_SORTING_IN)
            ->where('inventory_movement_details.item_id', $this->itemId)
            ->where('inventory_movement_details.unit_id', $bagUnitId)
            ->select('inventory_movement_details.*')
            ->orderByDesc('inventory_movement_details.movement_detail_id')
            ->firstOrFail();

        // توزيع الدفعة الناتجة عن الفرز
        $distRes = $this->postJson(route('purchases.distribution.save'), [
            'distribution_date' => '2026-11-25',
            'details'           => [
                [
                    'movement_detail_id' => $sortedInDetail->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $bagUnitId,
                    'quantity'           => 3,
                    'sale_price'         => 80,
                ],
            ],
        ])->assertOk()->assertJsonPath('success', true);

        $distributionId = (int) $distRes->json('id');
        $distDetail = DistributionDetail::where('distribution_id', $distributionId)->firstOrFail();

        // يجب أن يرتبط صف التوزيع بفاتورة الشراء الجذرية الأصلية
        $this->assertSame($rootInvoiceId, (int) $distDetail->purchase_invoice_id);
    }

    public function test_forged_ui_cost_is_ignored_and_server_cost_is_enforced(): void
    {
        $this->postJson(route('invoicesPurch.store'), [
            'invoice_number' => 'PINV-COST-001',
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAccountId,
            'warehouse_id'   => $this->warehouseId,
            'coin_id'        => $this->currencyId,
            'exchange_rate'  => 1,
            'payment_method' => 1,
            'details'        => [
                [
                    'item_id'  => $this->itemId,
                    'unit_id'  => $this->unitId,
                    'quantity' => 5,
                    'price'    => 120,
                    'discount' => 0,
                ],
            ],
        ])->assertStatus(201);

        $purchaseDetail = InventoryMovementDetail::query()
            ->where('item_id', $this->itemId)
            ->orderByDesc('movement_detail_id')
            ->firstOrFail();

        // محاولة تمرير unit_cost مزوّر (1) في حفظ التوزيع
        $distRes = $this->postJson(route('purchases.distribution.save'), [
            'distribution_date' => '2026-11-25',
            'details'           => [
                [
                    'movement_detail_id' => $purchaseDetail->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $this->unitId,
                    'quantity'           => 2,
                    'unit_cost'          => 1,
                    'sale_price'         => 200,
                ],
            ],
        ])->assertOk();

        $distributionId = (int) $distRes->json('id');
        $distDetail = DistributionDetail::where('distribution_id', $distributionId)->firstOrFail();
        $this->assertEqualsWithDelta(120.0, (float) $distDetail->unit_cost, 0.0001);

        // محاولة تمرير cost_price مزوّر (1) إلى SalesInvoiceService لصف توزيع
        $invoice = app(\App\Services\Sales\SalesInvoiceService::class)->create([
            'invoice_date'            => '2026-11-25',
            'account_id'              => $this->customerAccountId,
            'coin_id'                 => $this->currencyId,
            'exchange_rate'           => 1,
            'payment_method'          => SalesInvoice::PAYMENT_CREDIT,
            'skip_stock_validation'   => true,
            'distribution_detail_ids' => [$distDetail->id],
            'details'                 => [
                [
                    'item_id'                => $this->itemId,
                    'type_id'                => $this->typeId,
                    'unit_id'                => $this->unitId,
                    'warehouse_id'           => $this->warehouseId,
                    'supplier_account_id'    => $this->supplierAccountId,
                    'distribution_detail_id' => $distDetail->id,
                    'quantity'               => 2,
                    'price'                  => 200,
                    'cost_price'             => 1, // مزوّر من الواجهة
                    'discount'               => 0,
                ],
            ],
        ]);

        $invoiceDetail = $invoice->details()->firstOrFail();
        $this->assertEqualsWithDelta(120.0, (float) $invoiceDetail->cost_price, 0.0001);
    }

    public function test_multiple_suppliers_preserve_each_batch_supplier_source(): void
    {
        $supplier2Id = DB::table('characcount')->insertGetId([
            'accName'    => 'مورد توزيع ثانٍ',
            'accCode'    => 'S200',
            'isPostable' => 1,
        ]);

        // شراء من المورد الأول
        $this->postJson(route('invoicesPurch.store'), [
            'invoice_number' => 'PINV-SUP-1',
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAccountId,
            'warehouse_id'   => $this->warehouseId,
            'coin_id'        => $this->currencyId,
            'exchange_rate'  => 1,
            'payment_method' => 1,
            'details'        => [
                [
                    'item_id'  => $this->itemId,
                    'unit_id'  => $this->unitId,
                    'quantity' => 5,
                    'price'    => 100,
                    'discount' => 0,
                ],
            ],
        ])->assertStatus(201);

        $batch1 = InventoryMovementDetail::where('supplier_account_id', $this->supplierAccountId)
            ->orderByDesc('movement_detail_id')
            ->firstOrFail();

        // شراء من المورد الثاني
        $this->postJson(route('invoicesPurch.store'), [
            'invoice_number' => 'PINV-SUP-2',
            'invoice_date'   => '2026-11-25',
            'account_id'     => $supplier2Id,
            'warehouse_id'   => $this->warehouseId,
            'coin_id'        => $this->currencyId,
            'exchange_rate'  => 1,
            'payment_method' => 1,
            'details'        => [
                [
                    'item_id'  => $this->itemId,
                    'unit_id'  => $this->unitId,
                    'quantity' => 5,
                    'price'    => 110,
                    'discount' => 0,
                ],
            ],
        ])->assertStatus(201);

        $batch2 = InventoryMovementDetail::where('supplier_account_id', $supplier2Id)
            ->orderByDesc('movement_detail_id')
            ->firstOrFail();

        // توزيع الدفعتين في عملية واحدة
        $distRes = $this->postJson(route('purchases.distribution.save'), [
            'distribution_date' => '2026-11-25',
            'details'           => [
                [
                    'movement_detail_id' => $batch1->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $this->unitId,
                    'quantity'           => 2,
                    'sale_price'         => 150,
                ],
                [
                    'movement_detail_id' => $batch2->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $this->unitId,
                    'quantity'           => 3,
                    'sale_price'         => 160,
                ],
            ],
        ])->assertOk();

        $distributionId = (int) $distRes->json('id');

        // التحقق من حفظ supplier_account_id في حركة التوزيع لكل صف
        $distMovement = InventoryMovement::where('source_type', InventoryMovement::SOURCE_DISTRIBUTION)
            ->where('source_id', $distributionId)
            ->firstOrFail();

        $distMovSuppliers = InventoryMovementDetail::where('movement_id', $distMovement->movement_id)
            ->pluck('supplier_account_id')
            ->map(fn($v) => (int) $v)
            ->all();

        $this->assertContains($this->supplierAccountId, $distMovSuppliers);
        $this->assertContains($supplier2Id, $distMovSuppliers);

        // إصدار فاتورة بيع والتحقق من حفظ المورد على تفاصيل الفاتورة وحركة sale_distribution
        $issueRes = $this->postJson(route('purchases.distribution.issue-invoice'), [
            'customer_id'      => $this->customerId,
            'distribution_ids' => [$distributionId],
            'payment_method'   => SalesInvoice::PAYMENT_CREDIT,
        ])->assertOk();

        $salesInvoiceId = (int) $issueRes->json('sales_invoice_id');
        $saleSuppliers = DB::table('sales_invoice_details')
            ->where('sales_invoice_id', $salesInvoiceId)
            ->pluck('supplier_account_id')
            ->map(fn($v) => (int) $v)
            ->all();

        $this->assertContains($this->supplierAccountId, $saleSuppliers);
        $this->assertContains($supplier2Id, $saleSuppliers);
    }

    public function test_mixed_currencies_distribution_invoice_is_rejected(): void
    {
        $foreignCurrencyId = DB::table('coins')->insertGetId([
            'coinsName'         => 'ريال سعودي',
            'coinsCode'         => 'SAR',
            'coinsExchangeRate' => 140,
            'coinsSystem'       => 0,
        ]);

        // فاتورة بالعملة المحلية
        $this->postJson(route('invoicesPurch.store'), [
            'invoice_number' => 'PINV-CUR-1',
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAccountId,
            'warehouse_id'   => $this->warehouseId,
            'coin_id'        => $this->currencyId,
            'exchange_rate'  => 1,
            'payment_method' => 1,
            'details'        => [
                [
                    'item_id'  => $this->itemId,
                    'unit_id'  => $this->unitId,
                    'quantity' => 5,
                    'price'    => 100,
                    'discount' => 0,
                ],
            ],
        ])->assertStatus(201);

        $yerBatch = InventoryMovementDetail::orderByDesc('movement_detail_id')->firstOrFail();

        // فاتورة بعملة أجنبية
        $this->postJson(route('invoicesPurch.store'), [
            'invoice_number' => 'PINV-CUR-2',
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAccountId,
            'warehouse_id'   => $this->warehouseId,
            'coin_id'        => $foreignCurrencyId,
            'exchange_rate'  => 140,
            'payment_method' => 1,
            'details'        => [
                [
                    'item_id'  => $this->itemId,
                    'unit_id'  => $this->unitId,
                    'quantity' => 5,
                    'price'    => 10,
                    'discount' => 0,
                ],
            ],
        ])->assertStatus(201);

        $sarBatch = InventoryMovementDetail::orderByDesc('movement_detail_id')->firstOrFail();

        // توزيع الدفعتين لنفس العميل
        $distRes = $this->postJson(route('purchases.distribution.save'), [
            'distribution_date' => '2026-11-25',
            'details'           => [
                [
                    'movement_detail_id' => $yerBatch->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $this->unitId,
                    'quantity'           => 2,
                    'sale_price'         => 150,
                ],
                [
                    'movement_detail_id' => $sarBatch->movement_detail_id,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeId,
                    'unit_id'            => $this->unitId,
                    'quantity'           => 2,
                    'sale_price'         => 15,
                ],
            ],
        ])->assertOk();

        $distributionId = (int) $distRes->json('id');

        // محاولة إصدار فاتورة واحدة لعملتين مختلفتين -> رفض 422
        $this->postJson(route('purchases.distribution.issue-invoice'), [
            'customer_id'      => $this->customerId,
            'distribution_ids' => [$distributionId],
            'payment_method'   => SalesInvoice::PAYMENT_CREDIT,
        ])->assertStatus(422)->assertJsonPath('success', false);
    }
}
