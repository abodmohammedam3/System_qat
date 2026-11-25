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

        $inventory = app(InventoryService::class);
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
}
