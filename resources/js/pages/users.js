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

    const ROLE_LABELS = { admin: 'مدير النظام', accountant: 'محاسب', viewer: 'مشاهد فقط' };
    const ROLE_BADGES = { admin: 'bg-danger', accountant: 'bg-success', viewer: 'bg-secondary' };

    const tbody = document.getElementById('usersTbody');
    const userModal = new bootstrap.Modal(document.getElementById('userModal'));
    const resetModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
    let editingUserId = null;

    // ذاكرة الصفوف الحالية — تُغني عن تمرير الاسم/البريد داخل onclick (ثغرة قديمة)
    const rowsById = new Map();

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

        const payload = {
            name:  document.getElementById('userName').value.trim(),
            email: document.getElementById('userEmail').value.trim(),
            role:  document.getElementById('userRole').value,
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

    loadUsers();
});
