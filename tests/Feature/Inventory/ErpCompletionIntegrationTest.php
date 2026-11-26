<?php

namespace Tests\Feature\Inventory;

use App\Models\Inventory\InventoryMovement;
use App\Models\Purchases\DistributionDetail;
use App\Models\Purchases\PurchaseInvoice;
use App\Reports\ReportRegistry;
use App\Services\Inventory\InventoryService;
use App\Services\Purchases\DistributionService;
use App\Services\Purchases\PurchaseInvoiceService;
use App\Services\Purchases\PurchaseReturnService;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ErpCompletionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected int $currencyId;
    protected int $supplierAId;
    protected int $supplierBId;
    protected int $customerAccountId;
    protected int $customerId;
    protected int $warehouseId;
    protected int $itemId;
    protected int $typeAmyalId;
    protected int $typeQatalId;
    protected int $kgUnitId;
    protected int $pieceUnitId;

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
            'is_active'         => 1,
        ]);

        $this->supplierAId = DB::table('characcount')->insertGetId([
            'accName'    => 'عبد السلام الحمادي',
            'accCode'    => 'S201',
            'isPostable' => 1,
        ]);

        $this->supplierBId = DB::table('characcount')->insertGetId([
            'accName'    => 'مورد آخر',
            'accCode'    => 'S202',
            'isPostable' => 1,
        ]);

        $this->customerAccountId = DB::table('characcount')->insertGetId([
            'accName'    => 'سمير',
            'accCode'    => 'C201',
            'isPostable' => 1,
        ]);

        $this->customerId = DB::table('customers')->insertGetId([
            'CustomersName2' => 'سمير',
            'accountID'      => $this->customerAccountId,
            'is_active'      => 1,
        ]);

        $warehouseAccountId = DB::table('characcount')->insertGetId([
            'accName'    => 'مخزن رئيسي',
            'accCode'    => 'W201',
            'isPostable' => 1,
        ]);

        $this->warehouseId = DB::table('stocks')->insertGetId([
            'StockName' => 'المخزن الرئيسي',
            'accountID' => $warehouseAccountId,
            'is_active' => 1,
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
            'itemName2' => 'قات',
            'is_active' => 1,
        ]);

        $this->typeAmyalId = DB::table('type')->insertGetId(['name' => 'أميال', 'is_active' => 1]);
        $this->typeQatalId = DB::table('type')->insertGetId(['name' => 'قطل',   'is_active' => 1]);

        $this->kgUnitId = DB::table('units')->insertGetId([
            'UnitName'          => 'كيلو',
            'conversion_factor' => 1000,
            'is_active'         => 1,
        ]);

        $this->pieceUnitId = DB::table('units')->insertGetId([
            'UnitName'          => 'حبة',
            'conversion_factor' => 1,
            'is_active'         => 1,
        ]);
    }

    public function test_purchase_search_show_and_print_work_without_type_id(): void
    {
        $invoice = app(PurchaseInvoiceService::class)->create([
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAId,
            'payment_method' => PurchaseInvoice::PAYMENT_CREDIT,
            'coin_id'        => $this->currencyId,
            'warehouse_id'   => $this->warehouseId,
            'exchange_rate'  => 1,
            'details'        => [[
                'item_id'  => $this->itemId,
                'unit_id'  => $this->kgUnitId,
                'quantity' => 100,
                'price'    => 50,
                'discount' => 0,
            ]],
        ]);

        $this->getJson("/operation/purchases/invoicesPurch/{$invoice->purchase_invoice_id}")
            ->assertOk()
            ->assertJsonMissingPath('data.details.0.type_id');
        $this->get("/operation/purchases/invoicesPurch/{$invoice->purchase_invoice_id}/print")->assertOk();
    }

    public function test_multiple_rows_same_customer_with_piece_weights_and_unified_distribution_service(): void
    {
        $invoice = app(PurchaseInvoiceService::class)->create([
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAId,
            'payment_method' => PurchaseInvoice::PAYMENT_CREDIT,
            'coin_id'        => $this->currencyId,
            'warehouse_id'   => $this->warehouseId,
            'exchange_rate'  => 1,
            'details'        => [[
                'item_id'  => $this->itemId,
                'unit_id'  => $this->kgUnitId,
                'quantity' => 100,
                'price'    => 100,
                'discount' => 0,
            ]],
        ]);

        $mdId = DB::table('inventory_movement_details')
            ->join('inventory_movements', 'inventory_movements.movement_id', '=', 'inventory_movement_details.movement_id')
            ->where('inventory_movements.source_type', InventoryMovement::SOURCE_PURCHASE_INVOICE)
            ->where('inventory_movements.source_id', $invoice->purchase_invoice_id)
            ->value('inventory_movement_details.movement_detail_id');

        // 50 حبة × 300 جم = 15 كجم + 40 حبة × 250 جم = 10 كجم + 10 كجم = 35 كجم
        $saveRes = $this->postJson('/operation/purchases/distribution/save', [
            'distribution_date' => '2026-11-25',
            'details' => [
                [
                    'movement_detail_id' => $mdId,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeAmyalId,
                    'unit_id'            => $this->pieceUnitId,
                    'unit_weight'        => 300,
                    'quantity'           => 50,
                    'sale_price'         => 60,
                ],
                [
                    'movement_detail_id' => $mdId,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeQatalId,
                    'unit_id'            => $this->pieceUnitId,
                    'unit_weight'        => 250,
                    'quantity'           => 40,
                    'sale_price'         => 50,
                ],
                [
                    'movement_detail_id' => $mdId,
                    'customer_id'        => $this->customerId,
                    'type_id'            => $this->typeAmyalId,
                    'unit_id'            => $this->kgUnitId,
                    'unit_weight'        => null,
                    'quantity'           => 10,
                    'sale_price'         => 150,
                ],
            ],
        ])->assertOk();

        $distId = (int) $saveRes->json('id');
        $this->assertEquals(3, DistributionDetail::where('distribution_id', $distId)->count());

        // التحقق من items() يحسب quantity_base (35 كجم وليس 100)
        $itemsRes = $this->getJson("/operation/purchases/distribution/invoices/{$invoice->purchase_invoice_id}/items")->assertOk();
        $this->assertEqualsWithDelta(35.0, (float) $itemsRes->json('data.0.distributed'), 0.001);
        $this->assertEqualsWithDelta(65.0, (float) $itemsRes->json('data.0.available'), 0.001);

        // إصدار فاتورة البيع مباشرة عبر DistributionService::issueInvoice()
        $salesInvoice = app(DistributionService::class)->issueInvoice($this->customerId, [$distId]);
        $salesInvoice->load('details');

        $this->assertCount(3, $salesInvoice->details);
        $this->assertEquals($this->typeAmyalId, $salesInvoice->details[0]->type_id);
        $this->assertEquals($this->typeQatalId, $salesInvoice->details[1]->type_id);
        $this->assertEquals($this->pieceUnitId, $salesInvoice->details[0]->unit_id);

        // حركة البيع التوثيقية فقط ولا خصم مزدوج: الرصيد = 65 كجم
        $invSvc = app(InventoryService::class);
        $this->assertEqualsWithDelta(65.0, $invSvc->availableQuantity($this->itemId, $this->warehouseId, $this->kgUnitId), 0.001);

        $movementType = InventoryMovement::where('source_type', InventoryMovement::SOURCE_SALES_INVOICE)
            ->where('source_id', $salesInvoice->sales_invoice_id)
            ->value('movement_type');
        $this->assertEquals(InventoryMovement::TYPE_SALE_DISTRIBUTION, $movementType);

        // مرتجع بيع يرث type_id و lot_source_detail_id من التوزيع
        $saleDetail = $salesInvoice->details[0];
        $salesReturn = app(SalesReturnService::class)->create([
            'return_date'               => '2026-11-25',
            'original_sales_invoice_id' => $salesInvoice->sales_invoice_id,
            'account_id'                => $this->customerAccountId,
            'coin_id'                   => $this->currencyId,
            'warehouse_id'              => $this->warehouseId,
            'exchange_rate'             => 1,
            'payment_method'            => 1,
            'details'                   => [[
                'sales_invoice_detail_id' => $saleDetail->sales_invoice_detail_id,
                'quantity'                => 5,
                'price'                   => 60,
            ]],
        ]);

        $this->assertEquals($this->typeAmyalId, $salesReturn->details->first()->type_id);
        $retLot = DB::table('inventory_movement_details')
            ->join('inventory_movements', 'inventory_movements.movement_id', '=', 'inventory_movement_details.movement_id')
            ->where('inventory_movements.source_type', InventoryMovement::SOURCE_SALES_RETURN)
            ->where('inventory_movements.source_id', $salesReturn->sales_return_id)
            ->value('inventory_movement_details.lot_source_detail_id');
        $this->assertEquals($mdId, (int) $retLot);

        // التحقق من التقارير الجديدة + ItemLedgerReport يستثني قيمة TYPE_SALE_DISTRIBUTION
        foreach (['purchase-returns', 'distributions', 'sales-returns', 'source-trace', 'item-ledger'] as $key) {
            $res = ReportRegistry::get($key)->run([]);
            $this->assertArrayHasKey('rows', $res);
            $this->assertArrayHasKey('totals', $res);
        }
        $distReport = ReportRegistry::get('distributions')->run([]);
        $this->assertCount(3, $distReport['rows']);
    }

    public function test_purchase_return_respects_supplier_stock_after_partial_distribution(): void
    {
        $purchaseSvc = app(PurchaseInvoiceService::class);
        $invA = $purchaseSvc->create([
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAId,
            'payment_method' => PurchaseInvoice::PAYMENT_CREDIT,
            'coin_id'        => $this->currencyId,
            'warehouse_id'   => $this->warehouseId,
            'exchange_rate'  => 1,
            'details'        => [[
                'item_id' => $this->itemId, 'unit_id' => $this->kgUnitId, 'quantity' => 100, 'price' => 50, 'discount' => 0,
            ]],
        ]);
        // شراء 100 كجم إضافية من مورد آخر للتأكد من عدم تداخل الأرصدة
        $purchaseSvc->create([
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierBId,
            'payment_method' => PurchaseInvoice::PAYMENT_CREDIT,
            'coin_id'        => $this->currencyId,
            'warehouse_id'   => $this->warehouseId,
            'exchange_rate'  => 1,
            'details'        => [[
                'item_id' => $this->itemId, 'unit_id' => $this->kgUnitId, 'quantity' => 100, 'price' => 50, 'discount' => 0,
            ]],
        ]);

        $mdA = DB::table('inventory_movement_details')
            ->join('inventory_movements', 'inventory_movements.movement_id', '=', 'inventory_movement_details.movement_id')
            ->where('inventory_movements.source_type', InventoryMovement::SOURCE_PURCHASE_INVOICE)
            ->where('inventory_movements.source_id', $invA->purchase_invoice_id)
            ->value('inventory_movement_details.movement_detail_id');

        $this->postJson('/operation/purchases/distribution/save', [
            'distribution_date' => '2026-11-25',
            'details' => [[
                'movement_detail_id' => $mdA,
                'customer_id'        => $this->customerId,
                'type_id'            => $this->typeAmyalId,
                'unit_id'            => $this->kgUnitId,
                'quantity'           => 60,
                'sale_price'         => 80,
            ]],
        ])->assertOk();

        $detailA = $invA->details->first();
        $returnSvc = app(PurchaseReturnService::class);
        $this->assertEqualsWithDelta(40.0, $returnSvc->availableForReturn($detailA->purchase_invoice_detail_id), 0.001);

        $this->expectException(ValidationException::class);
        $returnSvc->create([
            'return_date'                  => '2026-11-25',
            'original_purchase_invoice_id' => $invA->purchase_invoice_id,
            'account_id'                   => $this->supplierAId,
            'coin_id'                      => $this->currencyId,
            'warehouse_id'                 => $this->warehouseId,
            'exchange_rate'                => 1,
            'payment_method'               => 1,
            'details'                      => [[
                'purchase_invoice_detail_id' => $detailA->purchase_invoice_detail_id,
                'quantity'                   => 50,
                'price'                      => 50,
            ]],
        ]);
    }

    public function test_sales_invoice_create_avoids_n_plus_one_queries_for_multiple_rows(): void
    {
        app(PurchaseInvoiceService::class)->create([
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->supplierAId,
            'payment_method' => PurchaseInvoice::PAYMENT_CREDIT,
            'coin_id'        => $this->currencyId,
            'warehouse_id'   => $this->warehouseId,
            'exchange_rate'  => 1,
            'details'        => [[
                'item_id' => $this->itemId, 'unit_id' => $this->kgUnitId, 'quantity' => 500, 'price' => 50, 'discount' => 0,
            ]],
        ]);

        $details = [];
        for ($i = 0; $i < 10; $i++) {
            $details[] = [
                'item_id'             => $this->itemId,
                'type_id'             => $this->typeAmyalId,
                'unit_id'             => $this->kgUnitId,
                'warehouse_id'        => $this->warehouseId,
                'supplier_account_id' => $this->supplierAId,
                'quantity'            => 1,
                'price'               => 80,
                'cost_price'          => 50,
                'discount'            => 0,
            ];
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(SalesInvoiceService::class)->create([
            'invoice_date'   => '2026-11-25',
            'account_id'     => $this->customerAccountId,
            'payment_method' => 1,
            'coin_id'        => $this->currencyId,
            'exchange_rate'  => 1,
            'details'        => $details,
        ]);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // التحقق أن استعلامات تجميع المخزون لا تتكرر لكل صف (فقط 2 استعلامين: in و out)
        $stockSumQueries = collect($queries)->filter(
            fn ($q) => str_contains($q['query'], 'inventory_movement_details') && str_contains($q['query'], 'sum(')
        )->count();

        $this->assertLessThanOrEqual(4, $stockSumQueries);
    }
}
