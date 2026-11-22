<?php

namespace App\Services;

use App\Models\Accounting\AccountBalance;
use App\Models\Accounting\CharAccount;
use App\Models\Accounting\Coin;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalEntryLine;
use RuntimeException;

class AccountBalanceService
{
    // =========================================================
    // RECALCULATE ACCOUNT
    // =========================================================

    public static function recalculate(int $accountID): void
    {
        if ($accountID <= 0) {
            return;
        }

        $account = CharAccount::query()
            ->select([
                'accountID',
                'nature',
            ])
            ->find($accountID);

        if (!$account) {
            return;
        }

        $totals = JournalEntryLine::query()
            ->where(
                'accountID',
                $accountID
            )
            ->select([
                'accountID',
            ])
            ->selectRaw(
                'COALESCE(SUM(localDebit), 0) AS total_debit'
            )
            ->selectRaw(
                'COALESCE(SUM(localCredit), 0) AS total_credit'
            )
            ->groupBy('accountID')
            ->first();

        $totalDebit =
            (float) (
                $totals->total_debit ?? 0
            );

        $totalCredit =
            (float) (
                $totals->total_credit ?? 0
            );

        self::upsertBalances([
            [
                'accountID' =>
                    $accountID,

                'coinsID' =>
                    self::getSystemCurrencyId(),

                'debitTotal' =>
                    $totalDebit,

                'creditTotal' =>
                    $totalCredit,

                'balance' =>
                    self::calculateBalance(
                        $totalDebit,
                        $totalCredit,
                        (int) $account->nature
                    ),

                'lastUpdatedAt' =>
                    now(),
            ],
        ]);
    }

    // =========================================================
    // RECALCULATE BATCH
    // =========================================================

    public static function recalculateBatch(
        array $accountIDs
    ): void {
        $accountIDs =
            self::normalizeAccountIDs(
                $accountIDs
            );

        if (empty($accountIDs)) {
            return;
        }

        $totals = JournalEntryLine::query()
            ->whereIn(
                'accountID',
                $accountIDs
            )
            ->select([
                'accountID',
            ])
            ->selectRaw(
                'COALESCE(SUM(localDebit), 0) AS total_debit'
            )
            ->selectRaw(
                'COALESCE(SUM(localCredit), 0) AS total_credit'
            )
            ->groupBy('accountID')
            ->get()
            ->keyBy('accountID');

        $natures = CharAccount::query()
            ->whereIn(
                'accountID',
                $accountIDs
            )
            ->pluck(
                'nature',
                'accountID'
            )
            ->all();

        $now = now();

        $balances = [];

        foreach ($accountIDs as $accountID) {

            $row =
                $totals->get(
                    $accountID
                );

            $totalDebit =
                (float) (
                    $row->total_debit ?? 0
                );

            $totalCredit =
                (float) (
                    $row->total_credit ?? 0
                );

            $nature =
                (int) (
                    $natures[$accountID] ?? 0
                );

            $balances[] = [

                'accountID' =>
                    $accountID,

                'coinsID' =>
                    self::getSystemCurrencyId(),

                'debitTotal' =>
                    $totalDebit,

                'creditTotal' =>
                    $totalCredit,

                'balance' =>
                    self::calculateBalance(
                        $totalDebit,
                        $totalCredit,
                        $nature
                    ),

                'lastUpdatedAt' =>
                    $now,
            ];
        }

        self::upsertBalances(
            $balances
        );
    }

    // =========================================================
    // RECALCULATE ALL
    // =========================================================

    public static function recalculateAll(): void
    {
        $totals = JournalEntryLine::query()
            ->select([
                'accountID',
            ])
            ->selectRaw(
                'COALESCE(SUM(localDebit), 0) AS total_debit'
            )
            ->selectRaw(
                'COALESCE(SUM(localCredit), 0) AS total_credit'
            )
            ->groupBy('accountID')
            ->get();

        if ($totals->isEmpty()) {
            return;
        }

        $accountIDs =
            $totals
                ->pluck('accountID')
                ->filter()
                ->map(
                    fn ($id) => (int) $id
                )
                ->unique()
                ->values()
                ->all();

        $natures = CharAccount::query()
            ->whereIn(
                'accountID',
                $accountIDs
            )
            ->pluck(
                'nature',
                'accountID'
            )
            ->all();

        $now = now();

        $balances = [];

        foreach ($totals as $row) {

            $accountID =
                (int) $row->accountID;

            $totalDebit =
                (float) (
                    $row->total_debit ?? 0
                );

            $totalCredit =
                (float) (
                    $row->total_credit ?? 0
                );

            $nature =
                (int) (
                    $natures[$accountID] ?? 0
                );

            $balances[] = [

                'accountID' =>
                    $accountID,

                'coinsID' =>
                    self::getSystemCurrencyId(),

                'debitTotal' =>
                    $totalDebit,

                'creditTotal' =>
                    $totalCredit,

                'balance' =>
                    self::calculateBalance(
                        $totalDebit,
                        $totalCredit,
                        $nature
                    ),

                'lastUpdatedAt' =>
                    $now,
            ];
        }

        self::upsertBalances(
            $balances
        );
    }

    // =========================================================
    // GET
    // =========================================================

    public static function get(
        int $accountID
    ): ?AccountBalance {
        if ($accountID <= 0) {
            return null;
        }

        return AccountBalance::query()
            ->where(
                'accountID',
                $accountID
            )
            ->where(
                'coinsID',
                self::getSystemCurrencyId()
            )
            ->first();
    }

    // =========================================================
    // GET BALANCE
    // =========================================================

    public static function getBalance(
        int $accountID
    ): float {
        return (float) (
            self::get($accountID)?->balance
            ?? 0
        );
    }

    // =========================================================
    // GET BALANCES
    // =========================================================

    public static function getBalances(
        array $accountIDs
    ): array {
        $accountIDs =
            self::normalizeAccountIDs(
                $accountIDs
            );

        if (empty($accountIDs)) {
            return [];
        }

        return AccountBalance::query()
            ->whereIn(
                'accountID',
                $accountIDs
            )
            ->where(
                'coinsID',
                self::getSystemCurrencyId()
            )
            ->pluck(
                'balance',
                'accountID'
            )
            ->map(
                fn ($value) =>
                    (float) $value
            )
            ->all();
    }

    // =========================================================
    // BALANCE AROUND ENTRY
    // =========================================================

    public static function getBalanceAroundEntry(
        int $accountID,
        ?int $entryNo
    ): array {
        if ($accountID <= 0) {
            return [
                'before' => 0.0,
                'after'  => 0.0,
                'nature' => 0,
            ];
        }

        /*
         * لا يوجد قيد محدد:
         * نعيد الرصيد الحالي.
         */
        if (!$entryNo) {

            $nature =
                (int) (
                    CharAccount::query()
                        ->where(
                            'accountID',
                            $accountID
                        )
                        ->value('nature')
                    ?? 0
                );

            $balance =
                self::getBalance(
                    $accountID
                );

            return [
                'before' => $balance,
                'after'  => $balance,
                'nature' => $nature,
            ];
        }

        /*
         * Query واحدة فقط:
         *
         * before = قبل القيد
         * after  = بعد القيد
         *
         * بدون أي حسابات في Controller.
         */

        $totals = CharAccount::query()
            ->from(
                (new CharAccount)->getTable() . ' as a'
            )
            ->leftJoin(
                (new JournalEntryLine)->getTable() . ' as l',
                'l.accountID',
                '=',
                'a.accountID'
            )
            ->leftJoin(
                (new JournalEntry)->getTable() . ' as e',
                'e.entryID',
                '=',
                'l.entryID'
            )
            ->where(
                'a.accountID',
                $accountID
            )
            ->select([
                'a.nature',
            ])
            ->selectRaw(
                'COALESCE(
                    SUM(
                        CASE
                            WHEN e.entryNo < ?
                            THEN l.localDebit
                            ELSE 0
                        END
                    ),
                    0
                ) AS before_debit',
                [$entryNo]
            )
            ->selectRaw(
                'COALESCE(
                    SUM(
                        CASE
                            WHEN e.entryNo < ?
                            THEN l.localCredit
                            ELSE 0
                        END
                    ),
                    0
                ) AS before_credit',
                [$entryNo]
            )
            ->selectRaw(
                'COALESCE(
                    SUM(
                        CASE
                            WHEN e.entryNo <= ?
                            THEN l.localDebit
                            ELSE 0
                        END
                    ),
                    0
                ) AS after_debit',
                [$entryNo]
            )
            ->selectRaw(
                'COALESCE(
                    SUM(
                        CASE
                            WHEN e.entryNo <= ?
                            THEN l.localCredit
                            ELSE 0
                        END
                    ),
                    0
                ) AS after_credit',
                [$entryNo]
            )
            ->groupBy(
                'a.accountID',
                'a.nature'
            )
            ->first();

        if (!$totals) {
            return [
                'before' => 0.0,
                'after'  => 0.0,
                'nature' => 0,
            ];
        }

        $nature =
            (int) (
                $totals->nature ?? 0
            );

        $before =
            self::calculateBalance(
                (float) (
                    $totals->before_debit ?? 0
                ),
                (float) (
                    $totals->before_credit ?? 0
                ),
                $nature
            );

        $after =
            self::calculateBalance(
                (float) (
                    $totals->after_debit ?? 0
                ),
                (float) (
                    $totals->after_credit ?? 0
                ),
                $nature
            );

        return [
            'before' => $before,
            'after'  => $after,
            'nature' => $nature,
        ];
    }

    // =========================================================
    // AVAILABLE PAYMENT BALANCE
    // =========================================================

    public static function getAvailableBalanceForPayment(
        int $accountID,
        ?float $oldLocalAmount = null,
        ?int $oldPaymentAccountID = null
    ): float {
        $balance =
            self::getBalance(
                $accountID
            );

        /*
         * عند تعديل سند موجود:
         *
         * إذا كان حساب الدفع نفسه،
         * نعيد مبلغ السند القديم مؤقتًا
         * حتى لا يمنع التعديل نفسه.
         */
        if (
            $oldLocalAmount !== null
            && $oldPaymentAccountID !== null
            && $accountID === $oldPaymentAccountID
        ) {
            $balance += $oldLocalAmount;
        }

        return $balance;
    }

    // =========================================================
    // BALANCE CALCULATION
    // =========================================================

    private static function calculateBalance(
        float $debit,
        float $credit,
        int $nature
    ): float {
        return $nature === 1
            ? $credit - $debit
            : $debit - $credit;
    }

    // =========================================================
    // UPSERT
    // =========================================================

    private static function upsertBalances(
        array $balances
    ): void {
        if (empty($balances)) {
            return;
        }

        AccountBalance::upsert(
            $balances,
            [
                'accountID',
                'coinsID',
            ],
            [
                'debitTotal',
                'creditTotal',
                'balance',
                'lastUpdatedAt',
            ]
        );
    }

    // =========================================================
    // NORMALIZE IDS
    // =========================================================

    private static function normalizeAccountIDs(
        array $accountIDs
    ): array {
        return collect($accountIDs)
            ->filter(
                fn ($id) =>
                    is_numeric($id)
                    && (int) $id > 0
            )
            ->map(
                fn ($id) =>
                    (int) $id
            )
            ->unique()
            ->values()
            ->all();
    }

    // =========================================================
    // SYSTEM CURRENCY
    // =========================================================

    private static function getSystemCurrencyId(): int
    {
        /*
         * سلسلة الحل للعملة النظامية:
         *  1) العملة المعلّمة coinsSystem=1 (المسار الصحيح).
         *  2) أول عملة في الجدول (بيئات قديمة لم تعلّم عملة نظامية).
         *
         * ⛔ لا قيمة افتراضية مكتوبة باليد (الكود القديم كان يعيد 1 عند
         *    الغياب فتُكتب أرصدة بعملة غير موجودة ويُسقطها قيد FK)،
         *  ⛔ ولا تخزين مؤقت static: كان يُعاد استخدامه بين الطلبات في
         *    عمال PHP-FPM الدائمين، فإذا حُذفت العملة أو تغيّرت استمر
         *    الكتابة بمعرّف عملة قديم (أثبتته سلسلة الاختبارات: فشل FK
         *    بين اختبارين متتاليين بسبب الكاش الساكن نفسه).
         * إذا لم توجد أي عملة نرمي استثناءً واضحاً بدلاً من تخريب البيانات.
         */
        $systemCoinID =
            (int) (
                Coin::query()
                    ->where(
                        'coinsSystem',
                        1
                    )
                    ->value('coinsID')
            );

        if ($systemCoinID <= 0) {
            $systemCoinID =
                (int) Coin::query()
                    ->orderBy('coinsID')
                    ->value('coinsID');
        }

        if ($systemCoinID <= 0) {
            throw new RuntimeException(
                'لا توجد عملة معرّفة في النظام. أضف العملة الأساسية (coinsSystem=1) من شاشة العملات قبل تنفيذ أي عملية محاسبية.'
            );
        }

        return $systemCoinID;
    }
}