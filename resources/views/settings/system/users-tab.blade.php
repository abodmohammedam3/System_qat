{{-- ═══ --}}
{{-- تاب المستخدمين والأدوار — نُقل من القائمة الجانبية إلى --}}
{{-- شاشة إعدادات النظام (المستوى الثاني). يحوي تابين داخليين: --}}
{{--   1) المستخدمون (الجدول والمودالات) --}}
{{--   2) الأدوار والصلاحيات (مصفوفة ديناميكية) --}}
{{-- ═══ --}}
<div class="tab-pane fade {{ ($activeTab ?? 'backup') === 'users' ? 'show active' : '' }}" id="tab-users" role="tabpanel">

    <ul class="nav nav-pills mb-3" id="usersInnerTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" type="button" data-bs-toggle="pill"
                    data-bs-target="#tab-users-list" role="tab">
                <i class="bi bi-person-lines-fill"></i> المستخدمون
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" type="button" data-bs-toggle="pill"
                    data-bs-target="#tab-users-roles" role="tab">
                <i class="bi bi-grid-3x3-gap"></i> الأدوار والصلاحيات
            </button>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ─── التاب الداخلي 1: جدول المستخدمين ─── --}}
        <div class="tab-pane fade show active" id="tab-users-list" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <small class="text-muted">إنشاء الحسابات وتحديد الأدوار وتفعيلها</small>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="refreshUsers()">
                    <i class="bi bi-arrow-clockwise"></i> تحديث
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="openUserModal()">
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

        {{-- ─── التاب الداخلي 2: مصفوفة الأدوار × الصلاحيات ─── --}}
        <div class="tab-pane fade" id="tab-users-roles" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <small class="text-muted">
                    الأدوار صفوفاً والصلاحيات أعمدة — أي تغيير يُحفظ تلقائياً.
                    مدير النظام يملك كل شيء دائماً (ثابت).
                </small>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="loadRolesMatrix(true)">
                    <i class="bi bi-arrow-clockwise"></i> تحديث
                </button>
            </div>

            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-0">
                    <div class="table-responsive" id="rolesMatrixWrap">
                        <table class="table table-sm table-hover align-middle mb-0" id="rolesMatrixTable">
                            <thead class="table-light" id="rolesMatrixHead">
                                <tr><th class="ps-3">الموديول / الصلاحية</th></tr>
                            </thead>
                            <tbody id="rolesMatrixBody">
                                <tr><td class="text-center py-4 text-muted">جارٍ التحميل…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

{{-- مودال إضافة/تعديل مستخدم --}}
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-3">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title" id="userModalTitle">إضافة مستخدم</h5>                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
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
                        <div class="input-group">
                            <input type="text" class="form-control" id="userRole" list="rolesDatalist"
                                   placeholder="اكتب للبحث أو اختر من القائمة…" required>
                            <button type="button" class="btn btn-outline-primary" onclick="openRoleModal()"
                                    title="إضافة دور جديد">
                                <i class="bi bi-plus-lg"></i> دور جديد
                            </button>
                        </div>
                        <datalist id="rolesDatalist"></datalist>
                        <small class="text-muted d-none text-danger" id="roleWarn"></small>
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

{{-- مودال إضافة دور جديد (من نموذج المستخدم مباشرة) --}}
<div class="modal fade" id="roleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow rounded-3">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title" id="roleModalTitle">إضافة دور جديد</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body pt-0">
                <label class="form-label">اسم الدور</label>
                <input type="text" class="form-control" id="newRoleName" maxlength="100"
                       placeholder="مثال: أمين مخزون، مبيعات…">
                <div class="alert alert-danger py-2 d-none mt-2" id="roleFormError"></div>
            </div>
            <div class="modal-footer border-top-0 py-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary" id="btnSaveRole">حفظ</button>
            </div>
        </div>
    </div>
</div>
