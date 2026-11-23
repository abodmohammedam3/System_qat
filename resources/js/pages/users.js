/**
 * إدارة المستخدمين (الفحص F-01) — واجهة شاشة المستخدمين.
 * تحميل القائمة، إضافة/تعديل، تفعيل/تعطيل، إعادة تعيين كلمة المرور، حذف.
 *
 * الإصلاحات:
 * 1) كل الرسائل عبر showSystemToast (نظام الرسائل الجاهز في system.js)
 *    بدل alert/confirm الخاصين بالمتصفح.
 * 2) تأكيد الحذف/التعطيل عبر مودال Bootstrap موحد (confirmDialog) بدل confirm().
 * 3) إصلاح حقن الاقتباس: editUser تستقبل id فقط وتجلب البيانات من ذاكرة الصفوف.
 */
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);

    // رسالة موحدة عبر نظام الرسائل الجاهز — مع تراجع آمن لو لم يُحمّل بعد
    const toast = (msg, type = 'info') =>
        (window.showSystemToast || ((m, t) => console.log(`[${t}] ${m}`)))(msg, type);

    const ROLE_LABELS = {};
    const ROLE_BADGES = {};

    const tbody = document.getElementById('usersTbody');
    const userModal = new bootstrap.Modal(document.getElementById('userModal'));
    const resetModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
    let editingUserId = null;

    // ذاكرة الصفوف الحالية — تُغني عن تمرير الاسم/البريد داخل onclick (ثغرة قديمة)
    const rowsById = new Map();

    // الأدوار المتاحة — تُجلب من السيرفر وتغذي combobox الدور + المصفوفة
    let availableRoles = [];

    /**
     * تأكيد موحد عبر نظام المشروع الجاهز (app-confirm.js — مودال مشترك
     * appConfirmModal مضمّن في كل الصفحات عبر layouts/app.blade.php).
     * يعيد Promise<boolean> — بديل كامل لـ confirm() الخاص بالمتصفح.
     */
    const confirmDialog = (message, opts = {}) =>
        (window.appConfirm || ((m) => Promise.resolve(window.confirm(m))))(message, opts);

    // ─── تحميل القائمة ───────────────────────────────
    async function loadUsers() {
        try {
            const res = await fetch('/settings/users/list', {
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            if (res.status === 403) { tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-3">هذه الشاشة متاحة لمدير النظام فقط</td></tr>`; return; }
            const data = await res.json();

            tbody.innerHTML = (data.users || []).map((u) => {
                rowsById.set(u.id, u); // تخزين الصف للجلب الآمن عند التعديل

                return `
                <tr>
                    <td>${esc(u.id)}</td>
                    <td class="fw-semibold">${esc(u.name)}</td>
                    <td dir="ltr" class="text-start">${esc(u.email)}</td>
                    <td><span class="badge ${ROLE_BADGES[u.role] || 'bg-secondary'}">${esc(ROLE_LABELS[u.role] || u.role)}</span></td>
                    <td>
                        ${u.is_active
                            ? '<span class="badge bg-success-subtle text-success">نشط</span>'
                            : '<span class="badge bg-danger-subtle text-danger">معطل</span>'}
                    </td>
                    <td class="text-muted small" dir="ltr">${esc(u.last_login_at || '—')}</td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" onclick="editUser(${u.id})" title="تعديل"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-outline-warning" onclick="resetUserPassword(${u.id})" title="إعادة تعيين كلمة المرور"><i class="bi bi-key"></i></button>
                            <button class="btn btn-outline-${u.is_active ? 'secondary' : 'success'}" onclick="toggleUser(${u.id})" title="${u.is_active ? 'تعطيل' : 'تفعيل'}"><i class="bi bi-power"></i></button>
                            <button class="btn btn-outline-danger" onclick="deleteUser(${u.id})" title="حذف"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>`;
            }).join('') || `<tr><td colspan="7" class="text-center text-muted py-3">لا يوجد مستخدمون</td></tr>`;
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-3">تعذر تحميل القائمة: ${esc(e.message)}</td></tr>`;
            toast('تعذر تحميل قائمة المستخدمين', 'danger');
        }
    }

    /**
     * جلب الأدوار الديناميكية من السيرفر وتغذية:
     *  - قائمة الدور في نموذج المستخدم (combobox ببحث فوري).
     *  - خرائط ROLE_LABELS / ROLE_BADGES لأسماء الأدوار الديناميكية.
     */
    async function loadRoles() {
        try {
            const res = await fetch('/settings/users/roles', {
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            if (!res.ok) return;
            const data = await res.json();

            availableRoles = data.roles || [];
            availableRoles.forEach((r, i) => {
                ROLE_LABELS[r.slug] = r.name;
                ROLE_BADGES[r.slug] = r.slug === 'admin' ? 'bg-danger'
                    : (i % 2 ? 'bg-success' : 'bg-secondary');
            });

            refreshRolesDatalist();
        } catch (_) { /* الأدوار اختيارية للعرض */ }
    }

    /** تحديث قائمة الاقتراحات تحت حقل الدور */
    function refreshRolesDatalist() {
        const dl = document.getElementById('rolesDatalist');
        if (dl) dl.innerHTML = availableRoles.map((r) => `<option value="${esc(r.name)}">`).join('');
    }

    // ─── إضافة دور جديد (زر «دور جديد» في نموذج المستخدم) ─────
    const roleModal = new bootstrap.Modal(document.getElementById('roleModal'));
    // نمط المودال: create (دور جديد) / rename (تعديل اسم) — يمنع تنفيذ
    // معالجي الحفظ معاً على نفس الزر.
    let roleModalMode = 'create';

    window.openRoleModal = function () {
        roleModalMode = 'create';
        document.getElementById('roleModalTitle').textContent = 'إضافة دور جديد';
        document.getElementById('newRoleName').value = '';
        document.getElementById('roleFormError').classList.add('d-none');
        roleModal.show();
    };

    document.getElementById('btnSaveRole').addEventListener('click', async () => {
        if (roleModalMode !== 'create') return;
        const errBox = document.getElementById('roleFormError');
        errBox.classList.add('d-none');
        const name = document.getElementById('newRoleName').value.trim();

        if (!name) {
            errBox.textContent = 'اسم الدور مطلوب';
            errBox.classList.remove('d-none');
            return;
        }

        try {
            const res = await fetch('/settings/users/roles', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify({ name }),
            });
            const data = await res.json();

            if (!res.ok || !data.success) {
                errBox.textContent = data.message || 'فشل إنشاء الدور';
                errBox.classList.remove('d-none');
                return;
            }

            roleModal.hide();
            availableRoles.push(data.role);
            refreshRolesDatalist();
            document.getElementById('userRole').value = data.role.name;
            toast(data.message, 'success');
            if (matrixLoaded) loadRolesMatrix(true);
        } catch (e) {
            errBox.textContent = 'خطأ اتصال: ' + e.message;
            errBox.classList.remove('d-none');
        }
    });

    // ─── إضافة / تعديل ───────────────────────────────
    window.openUserModal = function () {
        editingUserId = null;
        document.getElementById('userModalTitle').textContent = 'إضافة مستخدم';
        document.getElementById('userId').value = '';
        document.getElementById('userName').value = '';
        document.getElementById('userEmail').value = '';
        document.getElementById('userPassword').value = '';
        document.getElementById('userPassword').closest('#passwordGroup').style.display = '';
        document.getElementById('userRole').value = 'accountant';
        document.getElementById('userFormError').classList.add('d-none');
        userModal.show();
    };

    // الإصلاح: تستقبل id فقط وتجلب البيانات من ذاكرة الصفوف —
    // سابقاً كانت تستقبل الاسم/البريد كنص داخل onclick (كسر مع علامة ' و XSS مصغّر).
    window.editUser = function (id) {
        const u = rowsById.get(id);
        if (!u) { toast('تعذر العثور على بيانات المستخدم', 'danger'); return; }

        editingUserId = id;
        document.getElementById('userModalTitle').textContent = 'تعديل مستخدم';
        document.getElementById('userId').value = id;
        document.getElementById('userName').value = u.name;
        document.getElementById('userEmail').value = u.email;
        document.getElementById('userPassword').closest('#passwordGroup').style.display = 'none';
        document.getElementById('userRole').value = u.role;
        document.getElementById('userFormError').classList.add('d-none');
        userModal.show();
    };

    document.getElementById('btnSaveUser').addEventListener('click', async () => {
        const errBox = document.getElementById('userFormError');
        errBox.classList.add('d-none');

        // الدور الآن نص حر (combobox) — اسم الدور يُرسل كما هو
        const roleName = document.getElementById('userRole').value.trim();
        if (!roleName) {
            errBox.textContent = 'الدور مطلوب — اختر من القائمة أو اكتب اسم دور جديد';
            errBox.classList.remove('d-none');
            return;
        }
        if (!availableRoles.some((r) => r.name === roleName)) {
            errBox.textContent = "لا يوجد دور بهذا الاسم — أنشئه أولاً بزر «دور جديد»";
            errBox.classList.remove('d-none');
            return;
        }

        const payload = {
            name:  document.getElementById('userName').value.trim(),
            email: document.getElementById('userEmail').value.trim(),
            role:  roleName,
        };

        if (!editingUserId) payload.password = document.getElementById('userPassword').value;

        const url = editingUserId ? `/settings/users/${editingUserId}` : '/settings/users';
        const method = editingUserId ? 'PUT' : 'POST';

        try {
            const res = await fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();

            if (!res.ok || !data.success) {
                const msg = data.message || Object.values(data.errors || {}).flat().join('، ') || 'فشل الحفظ';
                errBox.textContent = msg;
                errBox.classList.remove('d-none');
                return;
            }

            userModal.hide();
            toast(data.message || 'تم الحفظ بنجاح', 'success');
            loadUsers();
        } catch (e) {
            errBox.textContent = 'خطأ اتصال: ' + e.message;
            errBox.classList.remove('d-none');
            toast('خطأ اتصال بالخادم: ' + e.message, 'danger');
        }
    });

    // ─── تفعيل/تعطيل (تأكيد عبر المودال الموحد بدل confirm) ───────────
    window.toggleUser = async function (id) {
        const u = rowsById.get(id);
        const label = u?.is_active ? 'تعطيل' : 'تفعيل';

        if (!await confirmDialog(`هل تريد بالتأكيد ${label} هذا الحساب؟`, { title: 'تغيير حالة الحساب', okText: label, danger: u?.is_active })) return;

        const res = await fetch(`/settings/users/${id}/toggle`, {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) { toast(data.message || 'فشلت العملية', 'danger'); return; }
        toast(data.message || 'تم تحديث الحالة', 'success');
        loadUsers();
    };

    // ─── إعادة تعيين كلمة المرور ─────────────────────
    window.resetUserPassword = async function (id) {
        if (!await confirmDialog(
            'سيتم توليد كلمة مرور عشوائية جديدة. هل تريد المتابعة؟',
            { title: 'إعادة تعيين كلمة المرور', okText: 'إعادة تعيين', danger: true }
        )) return;

        const res = await fetch(`/settings/users/${id}/reset-password`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) { toast(data.message || 'فشلت العملية', 'danger'); return; }
        document.getElementById('generatedPassword').value = data.password;
        resetModal.show();
        toast('تم إعادة تعيين كلمة المرور — انسخها الآن', 'warning');
    };

    document.getElementById('copyPasswordBtn').addEventListener('click', async () => {
        const input = document.getElementById('generatedPassword');
        try { await navigator.clipboard.writeText(input.value); toast('تم النسخ إلى الحافظة', 'success'); }
        catch (_) { input.select(); document.execCommand('copy'); toast('تم النسخ إلى الحافظة', 'success'); }
    });

    // ─── حذف (تأكيد عبر المودال الموحد بدل confirm) ───────────
    window.deleteUser = async function (id) {
        const u = rowsById.get(id);
        if (!await confirmDialog(
            `سيتم حذف المستخدم «${u?.name || id}» نهائياً. لا يمكن التراجع.`,
            { title: 'حذف مستخدم', okText: 'حذف نهائي', danger: true }
        )) return;

        const res = await fetch(`/settings/users/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) { toast(data.message || 'فشل الحذف', 'danger'); return; }
        toast(data.message || 'تم حذف المستخدم', 'success');
        loadUsers();
    };

    // =====================================================
    // المستوى الثاني — مصفوفة الشاشات × الأدوار (ديناميكية بالكامل)
    // الشكل: كل شاشة عنوان بولد، وأسفلها صف لكل دور، والأعمدة هي
    // الصلاحيات: إضافة/حذف/تعديل/طباعة/عرض + إخفاء (الوصول).
    // إخفاء مفعّل لدور = الشاشة تختفي من السايدبار له + منع خلفي 403.
    // =====================================================

    const MATRIX_FIELDS = [
        { field: 'create', label: 'إضافة' },
        { field: 'delete', label: 'حذف'   },
        { field: 'update', label: 'تعديل' },
        { field: 'print',  label: 'طباعة' },
        { field: 'view',   label: 'عرض'   },
        { field: 'hidden', label: 'إخفاء' },
    ];

    let matrixRoles = [];
    let matrixGroups = [];
    let matrixLoaded = false;

    // تحميل عند فتح التاب الداخلي (مرة واحدة) — وزر التحديث يعيد الجلب دائماً
    document.querySelector('#usersInnerTabs [data-bs-target="#tab-users-roles"]')
        ?.addEventListener('shown.bs.tab', () => { if (!matrixLoaded) loadRolesMatrix(); });

    window.loadRolesMatrix = async function (force = false) {
        const head = document.getElementById('rolesMatrixHead');
        const body = document.getElementById('rolesMatrixBody');

        try {
            const res = await fetch('/settings/users/roles', {
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            if (res.status === 403) {
                body.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-3">هذه الصفحة متاحة لمدير النظام فقط</td></tr>`;
                return;
            }
            const data = await res.json();
            matrixRoles  = data.roles || [];
            matrixGroups = data.groups || [];
            matrixLoaded = true;

            // شريط أدوات إدارة الأدوار فوق المصفوفة (إضافة دور / تعديل / حذف)
            renderRoleToolbar();

            // الرأس ثابت: عمود الدور + أعمدة الصلاحيات الست
            head.innerHTML = `<tr>
                <th class="ps-3" style="min-width:220px;">الدور</th>
                ${MATRIX_FIELDS.map((f) => `<th class="text-center fw-bold" style="min-width:80px;">${f.label}</th>`).join('')}
            </tr>`;

            // الجسم: عنوان شاشة بولد ثم صف لكل دور أسفلها
            let html = '';
            for (const group of matrixGroups) {
                for (const screen of group.screens) {
                    html += `<tr class="table-light">
                        <td class="ps-3 fw-bold" colspan="7">
                            <i class="bi bi-window-desktop"></i>
                            ${esc(screen.name)}
                            ${screen.locked ? ' <i class="bi bi-lock-fill text-muted" title="شاشة أساسية لا تُخفى"></i>' : ''}
                        </td>
                    </tr>`;

                    for (const role of matrixRoles) {
                        const st = role.screens[screen.key] || {};
                        html += `<tr>
                            <td class="ps-4 pe-3" style="padding-right:2.5rem;">
                                <span class="badge ${role.locked ? 'bg-danger' : 'bg-secondary-subtle text-dark'}">${esc(role.name)}</span>
                            </td>
                            ${MATRIX_FIELDS.map((f) => {
                                const isHiddenCell = f.field === 'hidden';
                                const disabled = isHiddenCell
                                    ? Boolean(screen.locked)
                                    : Boolean(role.locked);
                                const checked  = role.locked
                                    ? (isHiddenCell ? Boolean(st.hidden) : true)
                                    : Boolean(st[f.field] ?? (f.field === 'view'));

                                return `<td class="text-center">
                                    <input class="form-check-input role-screen-cb" type="checkbox"
                                           data-role="${role.id}" data-screen="${esc(screen.key)}"
                                           data-field="${f.field}" data-screen-name="${esc(screen.name)}"
                                           ${disabled ? 'disabled' : ''} ${checked ? 'checked' : ''}>
                                </td>`;
                            }).join('')}
                        </tr>`;
                    }
                }
            }

            body.innerHTML = html || `<tr><td colspan="7" class="text-center py-4 text-muted">لا توجد شاشات معرّفة</td></tr>`;

            // حفظ تلقائي عند أي تغيير (ديناميكي بالكامل)
            body.querySelectorAll('.role-screen-cb:not(:disabled)')
                .forEach((cb) => cb.addEventListener('change', () =>
                    saveRoleScreen(Number(cb.dataset.role), cb.dataset.screen, cb.dataset.field, cb.checked, cb.dataset.screenName)));

        } catch (e) {
            body.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-3">تعذر تحميل المصفوفة: ${esc(e.message)}</td></tr>`;
            toast('تعذر تحميل مصفوفة الأدوار', 'danger');
        }
    };

    /** شريط أدوات الأدوار فوق المصفوفة — إضافة/تعديل/حذف الدور */
    function renderRoleToolbar() {
        let bar = document.getElementById('roleToolbar');
        if (!bar) {
            bar = document.createElement('div');
            bar.id = 'roleToolbar';
            bar.className = 'd-flex flex-wrap gap-2 align-items-center mb-2';
            document.getElementById('rolesMatrixWrap').prepend(bar);
        }

        bar.innerHTML = `
            <button type="button" class="btn btn-primary btn-sm" onclick="openRoleModal('matrix')">
                <i class="bi bi-plus-lg"></i> إضافة دور جديد
            </button>
            ${matrixRoles.filter((r) => !r.locked).map((r) => `
                <span class="badge bg-secondary-subtle text-dark p-2 d-inline-flex gap-2 align-items-center">
                    ${esc(r.name)}
                    <a href="javascript:void(0)" title="تعديل الاسم" onclick="renameRole(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">
                        <i class="bi bi-pencil-square text-primary"></i>
                    </a>
                    <a href="javascript:void(0)" title="حذف الدور" onclick="deleteRole(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">
                        <i class="bi bi-trash text-danger"></i>
                    </a>
                </span>`).join('')}
        `;
    }

    /** حفظ مربع واحد فوراً — دور × شاشة × حقل */
    window.saveRoleScreen = async function (roleId, screenKey, field, value, screenName = '') {
        const role = matrixRoles.find((r) => r.id === roleId);
        if (!role || (role.locked && field !== 'hidden')) return;

        try {
            const res = await fetch(`/settings/users/roles/${roleId}/screen`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify({ screen: screenKey, field, value }),
            });
            const data = await res.json();

            if (!res.ok || !data.success) {
                toast(data.message || 'فشل الحفظ', 'danger');
                // إرجاع مربع الحالة السابقة عند الفشل
                loadRolesMatrix(true);
                return;
            }

            // تحديث الذاكرة المحلية
            role.screens[screenKey] = { ...(role.screens[screenKey] || {}), [field]: value };

            const actionLabels = {
                create: 'إضافة',
                delete: 'حذف',
                update: 'تعديل',
                print:  'طباعة',
                view:   'عرض',
                hidden: 'إخفاء',
            };
            const label = actionLabels[field] || field;

            if (field === 'hidden') {
                toast(value
                    ? `ستختفي شاشة «${screenName}» من السايدبار لدور «${role.name}» مع منع الوصول الخلفي`
                    : `شاشة «${screenName}» ستعود للظهور لدور «${role.name}»`, 'warning');
            } else {
                toast(`تم ${value ? 'منح' : 'سحب'} صلاحية «${label}» لشاشة «${screenName}» لدور «${role.name}»`, 'success');
            }
        } catch (e) {
            toast('خطأ اتصال بالخادم: ' + e.message, 'danger');
        }
    };

    // ─── تعديل / حذف دور من شريط أدوات المصفوفة ─────
    window.renameRole = async function (roleId, currentName) {
        // نستخدم مودال الدور الجديد بنمط تعديل
        roleModalMode = 'rename';
        document.getElementById('roleModalTitle').textContent = 'تعديل اسم الدور';
        document.getElementById('newRoleName').value = currentName;
        document.getElementById('roleFormError').classList.add('d-none');

        const saveBtn = document.getElementById('btnSaveRole');
        const handler = async () => {
            if (roleModalMode !== 'rename') return;
            saveBtn.removeEventListener('click', handler);
            roleModalMode = 'create';
            const name = document.getElementById('newRoleName').value.trim();
            if (!name || name === currentName) { roleModal.hide(); return; }

            const res = await fetch(`/settings/users/roles/${roleId}/rename`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: JSON.stringify({ name }),
            });
            const data = await res.json();

            if (!res.ok || !data.success) {
                const errBox = document.getElementById('roleFormError');
                errBox.textContent = data.message || 'فشل التعديل';
                errBox.classList.remove('d-none');
                saveBtn.addEventListener('click', handler);
                return;
            }

            roleModal.hide();
            toast(data.message, 'success');
            await loadRoles();
            loadUsers();
            loadRolesMatrix(true);
        };

        saveBtn.addEventListener('click', handler);
        roleModal.show();
    };

    window.deleteRole = async function (roleId, roleName) {
        if (!await confirmDialog(
            `سيتم حذف الدور «${roleName}» نهائياً. لا يمكن التراجع.`,
            { title: 'حذف دور', okText: 'حذف نهائي', danger: true }
        )) return;

        const res = await fetch(`/settings/users/roles/${roleId}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) { toast(data.message || 'فشل الحذف', 'danger'); return; }
        toast(data.message || 'تم حذف الدور', 'success');
        await loadRoles();
        loadRolesMatrix(true);
    };

    loadUsers();
    loadRoles();

    // زر التحديث في تاب المستخدمين — يجلب القائمة والأدوار من جديد
    window.refreshUsers = function () { loadUsers(); loadRoles(); };
});
