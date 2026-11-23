@extends('layouts.app')

@section('title', 'المستخدمون')

@section('content')

<div class="container-fluid py-3">

    {{-- عنوان الشاشة --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">
                <i class="bi bi-people-fill"></i>
                إدارة المستخدمين
            </h4>
            <small class="text-muted">إنشاء الحسابات وتحديد الأدوار — متاحة لمدير النظام فقط</small>
        </div>

        <button type="button" class="btn btn-primary" onclick="openUserModal()">
            <i class="bi bi-person-plus"></i> إضافة مستخدم
        </button>
    </div>

    <div class="card border-0 shadow-sm rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>الاسم</th>
                            <th>البريد الإلكتروني</th>
                            <th>الدور</th>
                            <th>الحالة</th>
                            <th>آخر دخول</th>
                            <th class="text-center" style="width:190px;">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody id="usersTbody">
                        <tr><td colspan="7" class="text-center py-4 text-muted">جارٍ التحميل…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

{{-- مودال إضافة/تعديل مستخدم --}}
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-3">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title" id="userModalTitle">إضافة مستخدم</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body pt-0">
                <form id="userForm" novalidate>
                    <input type="hidden" id="userId">

                    <div class="mb-3">
                        <label class="form-label">الاسم</label>
                        <input type="text" class="form-control" id="userName" required maxlength="100">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">البريد الإلكتروني</label>
                        <input type="email" class="form-control" id="userEmail" required maxlength="150">
                    </div>

                    <div class="mb-3" id="passwordGroup">
                        <label class="form-label">كلمة المرور</label>
                        <input type="password" class="form-control" id="userPassword" minlength="8" autocomplete="new-password">
                        <small class="text-muted">8 أحرف على الأقل</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">الدور</label>
                        <select class="form-select" id="userRole">
                            <option value="admin">مدير النظام — كل الصلاحيات</option>
                            <option value="accountant" selected>محاسب — العمليات التشغيلية</option>
                            <option value="viewer">مشاهد فقط — عرض وطباعة</option>
                        </select>
                    </div>

                    <div class="alert alert-danger py-2 d-none" id="userFormError"></div>
                </form>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary" id="btnSaveUser">حفظ</button>
            </div>
        </div>
    </div>
</div>

{{-- مودال عرض كلمة المرور المولدة --}}
<div class="modal fade" id="resetPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-3">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title">كلمة المرور الجديدة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body pt-0 text-center">
                <p class="text-muted small">انسخها الآن — لن تُعرض مرة أخرى</p>
                <div class="input-group mb-2">
                    <input type="text" class="form-control text-center fw-bold" id="generatedPassword" readonly>
                    <button type="button" class="btn btn-outline-secondary" id="copyPasswordBtn" title="نسخ">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- التأكيد يتم عبر المودال المشترك appConfirmModal المضمّن في layouts/app.blade.php --}}

@endsection

@push('scripts')
    @vite(['resources/js/pages/users.js'])
@endpush
