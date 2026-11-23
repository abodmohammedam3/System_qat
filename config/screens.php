<?php

/**
 * سجل الشاشات الديناميكي — المصدر الوحيد لقائمة الشاشات في النظام.
 *
 * يُستخدم في:
 *  - RolesPermissionsSeeder: يزرع جدول screens.
 *  - EnsureScreenAccess: يطابق المسار الحالي بنمط الشاشة (منع خلفي 403).
 *  - layout: يخفي روابط الشاشات المخفية عن دور المستخدم من السايدبار.
 *
 * الحقول:
 *  key     — معرّف فريد (يطابق اسم موديول الصلاحيات حيث يوجد).
 *  name    — الاسم المعروض.
 *  group   — المجموعة في القائمة الجانبية.
 *  patterns— أنماط مسارات الشاشة للمنع الخلفي.
 *  locked  — شاشة أساسية لا يمكن إخفاؤها (اللوحة/الإعدادات/كلمة المرور).
 */
return [

    ['key' => 'dashboard',        'name' => 'لوحة المعلومات',       'group' => 'عام',        'locked' => true,
     'patterns' => ['/', 'dashboard*']],

    ['key' => 'coins',            'name' => 'العملات',              'group' => 'المحاسبة',
     'patterns' => ['setting/accounting/coins*']],
    ['key' => 'boxes',            'name' => 'الصناديق',             'group' => 'المحاسبة',
     'patterns' => ['setting/accounting/boxes*']],
    ['key' => 'banks',            'name' => 'البنوك',               'group' => 'المحاسبة',
     'patterns' => ['setting/accounting/banks*']],
    ['key' => 'chartOfAccounts',  'name' => 'دليل الحسابات',        'group' => 'المحاسبة',
     'patterns' => ['settings/accounting/chartOfAccounts*']],
    ['key' => 'openingBalances',  'name' => 'الأرصدة الافتتاحية',   'group' => 'المحاسبة',
     'patterns' => ['setting/accounting/openingBalances*']],

    ['key' => 'suppliers',        'name' => 'المورّدون (الرعية)',   'group' => 'البيانات الأساسية',
     'patterns' => ['setting/suppliers*']],
    ['key' => 'customers',        'name' => 'العملاء',              'group' => 'البيانات الأساسية',
     'patterns' => ['setting/customers*']],

    ['key' => 'items',            'name' => 'الأصناف',              'group' => 'المخزون',
     'patterns' => ['setting/inventory/items*']],
    ['key' => 'types',            'name' => 'الأنواع',              'group' => 'المخزون',
     'patterns' => ['setting/inventory/types*']],
    ['key' => 'units',            'name' => 'الوحدات',              'group' => 'المخزون',
     'patterns' => ['setting/inventory/units*']],
    ['key' => 'warehouses',       'name' => 'المخازن',              'group' => 'المخزون',
     'patterns' => ['setting/inventory/warehouses*']],

    ['key' => 'purchase_invoices','name' => 'فواتير الشراء',        'group' => 'المشتريات',
     'patterns' => ['operation/purchases/invoicesPurch*']],
    ['key' => 'purchase_returns', 'name' => 'مردود المشتريات',      'group' => 'المشتريات',
     'patterns' => ['operation/purchases/returns*']],
    ['key' => 'purchase_distribution', 'name' => 'توزيع أصناف المشتريات', 'group' => 'المشتريات',
     'patterns' => ['operation/purchases/distribution*']],

    ['key' => 'sales_invoices',   'name' => 'فواتير البيع',         'group' => 'المبيعات',
     'patterns' => ['operation/sales/invoices*']],
    ['key' => 'sales_returns',    'name' => 'مردود المبيعات',       'group' => 'المبيعات',
     'patterns' => ['operation/sales/returns*']],

    ['key' => 'journalEntries',   'name' => 'قيود اليومية',         'group' => 'الحسابات',
     'patterns' => ['operation/accounting/journalEntries*']],
    ['key' => 'payment_vouchers', 'name' => 'سند صرف',              'group' => 'الحسابات',
     'patterns' => ['operation/accounting/paymentVouchers*']],
    ['key' => 'receipt_vouchers', 'name' => 'سند قبض',              'group' => 'الحسابات',
     'patterns' => ['operation/accounting/receiptVouchers*']],
    ['key' => 'movements',        'name' => 'حركة المخزون',         'group' => 'الحسابات',
     'patterns' => ['operation/movements*']],

    ['key' => 'reports',          'name' => 'مركز التقارير',        'group' => 'أدوات',
     'patterns' => ['reports*']],

    ['key' => 'systemSettings',   'name' => 'إعدادات النظام + المستخدمون والأدوار', 'group' => 'أدوات', 'locked' => true,
     'patterns' => ['settings/system*']],
];
