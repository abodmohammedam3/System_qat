/**
 * إدارة المستخدمين (الفحص F-01) — واجهة شاشة المستخدمين.
 * تحميل القائمة، إضافة/تعديل، تفعيل/تعطيل، إعادة تعيين كلمة المرور، حذف.
 */
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);

    const ROLE_LABELS = { admin: 'مدير النظام', accountant: 'محاسب', viewer: 'مشاهد فقط' };
    const ROLE_BADGES = { admin: 'bg-danger', accountant: 'bg-success', viewer: 'bg-secondary' };

    const tbody = document.getElementById('usersTbody');
    const userModal = new bootstrap.Modal(document.getElementById('userModal'));
    const resetModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
    let editingUserId = null;

    // ─── تحميل القائمة ───────────────────────────────
    async function loadUsers() {
        try {
            const res = await fetch('/settings/users/list', {
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            if (res.status === 403) { tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-3">هذه الشاشة متاحة لمدير النظام فقط</td></tr>`; return; }
            const data = await res.json();

            tbody.innerHTML = (data.users || []).map((u) => `
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
                            <button class="btn btn-outline-primary" onclick="editUser(${u.id}, '${esc(u.name)}', '${esc(u.email)}', '${esc(u.role)}')" title="تعديل"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-outline-warning" onclick="resetUserPassword(${u.id})" title="إعادة تعيين كلمة المرور"><i class="bi bi-key"></i></button>
                            <button class="btn btn-outline-${u.is_active ? 'secondary' : 'success'}" onclick="toggleUser(${u.id})" title="${u.is_active ? 'تعطيل' : 'تفعيل'}"><i class="bi bi-power"></i></button>
                            <button class="btn btn-outline-danger" onclick="deleteUser(${u.id})" title="حذف"><i class="bi bi-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `).join('') || `<tr><td colspan="7" class="text-center text-muted py-3">لا يوجد مستخدمون</td></tr>`;
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" class="text-center text-danger py-3">تعذر تحميل القائمة: ${esc(e.message)}</td></tr>`;
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

    window.editUser = function (id, name, email, role) {
        editingUserId = id;
        document.getElementById('userModalTitle').textContent = 'تعديل مستخدم';
        document.getElementById('userId').value = id;
        document.getElementById('userName').value = name;
        document.getElementById('userEmail').value = email;
        document.getElementById('userPassword').closest('#passwordGroup').style.display = 'none';
        document.getElementById('userRole').value = role;
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
            window.showToast?.('تم الحفظ بنجاح', 'success') || alert('تم الحفظ بنجاح');
            loadUsers();
        } catch (e) {
            errBox.textContent = 'خطأ اتصال: ' + e.message;
            errBox.classList.remove('d-none');
        }
    });

    // ─── تفعيل/تعطيل ───────────────────────────────
    window.toggleUser = async function (id) {
        if (!confirm('تغيير حالة الحساب؟')) return;
        const res = await fetch(`/settings/users/${id}/toggle`, {
            method: 'PATCH',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) { alert(data.message || 'فشلت العملية'); return; }
        loadUsers();
    };

    // ─── إعادة تعيين كلمة المرور ─────────────────────
    window.resetUserPassword = async function (id) {
        if (!confirm('إعادة تعيين كلمة المرور وإصدار كلمة عشوائية جديدة؟')) return;
        const res = await fetch(`/settings/users/${id}/reset-password`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) { alert(data.message || 'فشلت العملية'); return; }
        document.getElementById('generatedPassword').value = data.password;
        resetModal.show();
    };

    document.getElementById('copyPasswordBtn').addEventListener('click', async () => {
        const input = document.getElementById('generatedPassword');
        try { await navigator.clipboard.writeText(input.value); } catch (_) { input.select(); document.execCommand('copy'); }
    });

    // ─── حذف ───────────────────────────────
    window.deleteUser = async function (id) {
        if (!confirm('حذف المستخدم نهائياً؟')) return;
        const res = await fetch(`/settings/users/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const data = await res.json();
        if (!res.ok || !data.success) { alert(data.message || 'فشل الحذف'); return; }
        loadUsers();
    };

    loadUsers();
});
