@extends('layouts.app')

@section('title', 'إعدادات النظام')

@section('content')
<div class="container-fluid py-3">

    {{-- العنوان --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-gear-fill"></i>
                إعدادات النظام
            </h4>
            <small class="text-muted">
                إدارة النسخ الاحتياطي، الإعدادات العامة، وهوية الشركة
            </small>
        </div>
    </div>

    {{-- التابات --}}
    @include('settings.system.tabs')

    {{-- محتوى التابات --}}
    <div class="tab-content">

        <div class="tab-pane fade {{ ($activeTab ?? 'backup') === 'backup' ? 'show active' : '' }}" id="tab-backup" role="tabpanel">

            @include('settings.system.backup.header')
            @include('settings.system.backup.actions')
            @include('settings.system.backup.table')

        </div>

        {{-- المستوى الثاني — تاب المستخدمين والأدوار (نُقل من القائمة الجانبية) --}}
        @include('settings.system.users-tab')

    </div>

</div>

{{-- القوالب + المودالات --}}
@include('settings.system.backup.templates')
@include('settings.system.backup.modals.export')
@include('settings.system.backup.modals.import')

@endsection

@push('scripts')
    @vite(['resources/js/pages/system-settings.js'])
    {{-- يُحمّل دائماً في صفحة الإعدادات: عند الدخول عبر السايدبار (تاب النسخ)
    لا يُضبط activeTab=users، وكان هذا يمنع تحميل users.js فيبقى الجدول
    عالقاً على "جارٍ التحميل…". السكربت آمن لوجوده على كل التابات. --}}
    @vite(['resources/js/pages/users.js'])
@endpush
