// resources/js/pages/purchase-distribution.js

let BootstrapModal = null;
async function ensureBootstrap() {
    if (BootstrapModal) return BootstrapModal;
    if (window.bootstrap?.Modal) { BootstrapModal = window.bootstrap.Modal; return BootstrapModal; }
    try { const m = await import('bootstrap'); BootstrapModal = m.Modal; return BootstrapModal; }
    catch (e) { console.error('[dist] Bootstrap غير متاح:', e); return null; }
}

document.addEventListener('DOMContentLoaded', () => {

    const ROUTES = {
        invoices: '/operation/purchases/distribution/invoices',
        items: (id) => `/operation/purchases/distribution/invoices/${id}/items`,
        customers: '/operation/purchases/distribution/customers',
        save: '/operation/purchases/distribution/save',
        show: (id) => `/operation/purchases/distribution/${id}`,
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    let rowSeq = 0;
    const nextRowId = () => `temp-${++rowSeq}`;

    const state = {
        distributionId: null,
        invoices: {},
        activeInvoiceId: null,
        customerInfo: {},
        rowsByItem: {},   // { [movementDetailId]: Array<{ rowId, customerId, typeId, unitId, unitWeightGrams, quantity, quantityBase, price, isInvoiced, savedDetailId }> }
        unitsCache: null, // كاش وحدات القياس من /setting/inventory/units/list?full=1
        typesCache: null, // كاش أنواع الأصناف من /setting/inventory/types/list?full=1
        pieceUnitMissingWarned: false,
        modalItemId: null,
        modalInvoiceId: null,
    };

    // ============================================================
    // الوحدات: التعرف النصي (نفس منهج movements.js)
    // ============================================================
    const isKiloUnitName = (n) => {
        const s = String(n || '').trim().toLowerCase();
        return !!s && (s.includes('كيلو') || s.includes('كجم') || s === 'kg' || s.includes('kilogram'));
    };
    const isGramUnitName = (n) => {
        const s = String(n || '').trim().toLowerCase();
        return !!s && (s.includes('جرام') || s.includes('جم') || s === 'g' || s.includes('gram'));
    };
    const isPieceUnitName = (n) =>
        ['الحبة', 'حبة', 'حبه', 'حب', 'قطعة', 'قطعه'].includes(String(n || '').trim());

    const unitNameById = (unitId) => {
        const u = (state.unitsCache || []).find(x => String(x.UnitID) === String(unitId));
        return u ? u.UnitName : '';
    };

    /**
     * وزن الوحدة بالجرام — نفس منهج الخادم (unitFactorGrams في
     * PurchaseDistributionController): كيلو=1000، جرام=1،
     * غير ذلك = conversion_factor المخزن في شاشة الوحدات.
     * الحبة تعالج في مسارها الخاص (تحتاج وزن الحبة) ← null.
     */
    const unitGrams = (unitId) => {
        const u = (state.unitsCache || []).find(x => String(x.UnitID) === String(unitId));
        if (!u) return null;
        const name = u.UnitName;
        if (isPieceUnitName(name)) return null;
        if (isKiloUnitName(name)) return 1000;
        if (isGramUnitName(name)) return 1;
        const f = parseFloat(u.conversion_factor);
        return f > 0 ? f : null;
    };

    /**
     * معامل التحويل من الوحدة "from" إلى الوحدة "to" (بالكمية).
     * كيلو↔جرام كما كانت، وأي وحدة أخرى عبر conversion_factor —
     * مطابقة للتحويل الخادمي (grams من ÷ grams إلى).
     */
    function unitFactor(fromId, toId) {
        if (String(fromId) === String(toId)) return 1;
        const gf = unitGrams(fromId);
        const gt = unitGrams(toId);
        if (gf === null || gt === null || gt <= 0) return null;
        return gf / gt;
    }

    const isPieceUnitId = (unitId) => isPieceUnitName(unitNameById(unitId));

    /** جلب وحدات القياس مرة واحدة */
    async function ensureUnitsCache() {
        if (state.unitsCache) return state.unitsCache;
        try {
            const res = await fetch('/setting/inventory/units/list?full=1', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            state.unitsCache = Array.isArray(json.data) ? json.data : [];
        } catch (e) {
            state.unitsCache = [];
        }
        return state.unitsCache;
    }

    /** جلب أنواع الأصناف مرة واحدة (عمود النوع في نافذة التوزيع إلزامي) */
    async function ensureTypesCache() {
        if (state.typesCache) return state.typesCache;
        try {
            const res = await fetch('/setting/inventory/types/list?full=1', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();
            state.typesCache = Array.isArray(json.data) ? json.data : [];
        } catch (e) {
            state.typesCache = [];
        }
        return state.typesCache;
    }

    /** الوحدة الافتراضية (المرجعية) للدفعة = وحدة الصنف في فاتورة الشراء */
    function batchUnitId(item) {
        return item?.unit_id ?? null;
    }

    /** اكتشاف معرف وحدة "حبة" النشطة ديناميكياً من قاعدة البيانات */
    function defaultPieceUnitId(item) {
        const activePiece = (state.unitsCache || []).find(
            u => (u.is_active === undefined || parseInt(u.is_active, 10) === 1 || u.is_active === true)
                && isPieceUnitName(u.UnitName)
        );
        if (activePiece) return activePiece.UnitID;

        if (!state.pieceUnitMissingWarned) {
            state.pieceUnitMissingWarned = true;
            toastWarning('لا توجد وحدة «حبة» نشطة في شاشة الوحدات — تم استخدام وحدة الدفعة مؤقتاً.');
        }
        return batchUnitId(item);
    }

    /** الوحدة المختارة حاليًا لصف معين — الافتراضي "حبة" */
    function chosenUnitIdForRow(row, item) {
        return row?.unitId ?? defaultPieceUnitId(item) ?? batchUnitId(item) ?? null;
    }

    /** الوحدات المتاحة للعرض في القائمة */
    function availableUnitOptions(item) {
        const base = batchUnitId(item);
        const all = state.unitsCache || [];
        const out = [];
        (all || []).forEach(u => {
            const uid = u.UnitID;
            if (String(uid) === String(base)) { out.push(u); return; }
            const f = unitFactor(uid, base);
            if (f !== null || isPieceUnitName(u.UnitName)) out.push(u);
        });
        if (!out.length && base !== null) {
            out.push({ UnitID: base, UnitName: item.unit_name || '' });
        }
        return out;
    }

    const $ = (id) => document.getElementById(id);
    const num = (v) => { const n = parseFloat(v); return isNaN(n) ? 0 : n; };
    const fmt = (v, d = 2) => num(v).toFixed(d);
    const escapeHtml = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

    async function api(url, opts = {}) {
        const res = await fetch(url, {
            ...opts,
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
                ...(opts.headers || {}),
            },
        });
        const text = await res.text();
        let data;
        try { data = text ? JSON.parse(text) : {}; }
        catch { data = { message: text || `HTTP ${res.status}` }; }
        if (!res.ok) throw new Error(data.message || `HTTP ${res.status}`);
        return data;
    }

    function showToast(msg, type = 'info') {
        if (typeof window.showSystemToast === 'function') {
            window.showSystemToast(msg, type);
            return;
        }
        console.log(`[dist:${type}] ${msg}`);
    }
    const toastSuccess = (m) => showToast(m, 'success');
    const toastError = (m) => showToast(m, 'danger');
    const toastWarning = (m) => showToast(m, 'warning');
    const toastInfo = (m) => showToast(m, 'info');

    async function confirmDialog(msg, opts = {}) {
        if (typeof window.appConfirm === 'function') {
            try { return await window.appConfirm(msg, opts); }
            catch (e) { console.warn('[dist] appConfirm فشل، استخدام confirm الأصلي', e); }
        }
        return window.confirm(msg);
    }

    function updateModeBadge() {
        const badge = $('distributionModeBadge');
        if (!badge) return;
        if (state.distributionId) {
            badge.className = 'badge bg-warning-subtle text-warning';
            badge.innerHTML = `<i class="bi bi-pencil-square"></i> وضع التعديل`;
        } else {
            badge.className = 'badge bg-success-subtle text-success';
            badge.innerHTML = `<i class="bi bi-plus-circle"></i> توزيع جديد`;
        }
    }

    const mainTabsEl = document.getElementById('mainTabs');
    const mainTabsPanes = mainTabsEl ? {
        distribute: document.getElementById('tab-distribute'),
        issue: document.getElementById('tab-issue'),
        history: document.getElementById('tab-history'),
    } : null;

    function activateMainTab(name) {
        if (!mainTabsEl || !mainTabsPanes?.[name]) return;
        mainTabsEl.querySelectorAll('.nav-link').forEach(t => {
            t.classList.toggle('active', t.dataset.tab === name);
        });
        Object.entries(mainTabsPanes).forEach(([k, el]) => {
            el?.classList.toggle('d-none', k !== name);
        });
        try {
            const url = new URL(window.location);
            url.searchParams.set('tab', name);
            window.history.replaceState({}, '', url);
        } catch (e) { }
    }

    if (mainTabsEl) {
        mainTabsEl.querySelectorAll('.nav-link').forEach(btn => {
            btn.addEventListener('click', () => activateMainTab(btn.dataset.tab));
        });
        const urlTab = new URLSearchParams(location.search).get('tab');
        if (urlTab && mainTabsPanes[urlTab]) activateMainTab(urlTab);
    }

    function findEmptyAllocations() {
        const empty = [];
        Object.entries(state.rowsByItem).forEach(([itemId, rows]) => {
            (rows || []).forEach(r => {
                if (num(r.quantity) <= 0 || num(r.quantityBase) <= 0) {
                    empty.push({ itemId: parseInt(itemId, 10), custId: parseInt(r.customerId, 10), rowId: r.rowId });
                }
            });
        });
        return empty;
    }

    function getEmptyCustomersForItem(itemId) {
        const rows = state.rowsByItem[itemId] || [];
        const names = [];
        rows.forEach(r => {
            if (num(r.quantity) <= 0 || num(r.quantityBase) <= 0) {
                const info = state.customerInfo[r.customerId];
                names.push(info?.name || `#${r.customerId}`);
            }
        });
        return names;
    }

    function removeEmptyCustomersForItem(itemId) {
        const rows = state.rowsByItem[itemId] || [];
        const before = rows.length;
        const kept = rows.filter(r => r.isInvoiced || (num(r.quantity) > 0 && num(r.quantityBase) > 0));
        const removedCount = before - kept.length;
        if (kept.length === 0) {
            delete state.rowsByItem[itemId];
        } else {
            state.rowsByItem[itemId] = kept;
        }
        pruneOrphanCustomers();
        return removedCount;
    }

    function pruneOrphanCustomers() {
        const inUse = new Set();
        Object.values(state.rowsByItem).forEach(rows =>
            (rows || []).forEach(r => inUse.add(String(r.customerId))));
        Object.keys(state.customerInfo).forEach(cid => {
            if (!inUse.has(String(cid))) delete state.customerInfo[cid];
        });
    }

    function findItem(movementDetailId) {
        for (const invId in state.invoices) {
            const found = (state.invoices[invId].items || []).find(it => it.id == movementDetailId);
            if (found) return found;
        }
        return null;
    }

    function findInvoiceOfItem(movementDetailId) {
        for (const invId in state.invoices) {
            if ((state.invoices[invId].items || []).some(it => it.id == movementDetailId)) {
                return state.invoices[invId];
            }
        }
        return null;
    }

    // ============================================================
    // مودال الفواتير
    // ============================================================
    let invoiceModalInstance = null;

    async function openInvoiceModal() {
        const modalEl = $('invoicePickerModal');
        if (!modalEl) return;
        const Modal = await ensureBootstrap();
        if (Modal) {
            if (!invoiceModalInstance) invoiceModalInstance = new Modal(modalEl);
            invoiceModalInstance.show();
        } else {
            modalEl.style.display = 'block';
            modalEl.classList.add('show');
            document.body.classList.add('modal-open');
        }
        loadInvoices();
    }
    function hideInvoiceModal() {
        const modalEl = $('invoicePickerModal');
        if (invoiceModalInstance) invoiceModalInstance.hide();
        else if (modalEl) {
            modalEl.style.display = 'none';
            modalEl.classList.remove('show');
            document.body.classList.remove('modal-open');
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        }
    }

    $('btnPickInvoice')?.addEventListener('click', openInvoiceModal);
    $('btnAddInvoice')?.addEventListener('click', openInvoiceModal);
    document.querySelectorAll('#invoicePickerModal [data-bs-dismiss="modal"]').forEach(btn =>
        btn.addEventListener('click', hideInvoiceModal));

    if ($('invoiceFilterDate')) $('invoiceFilterDate').value = new Date().toISOString().slice(0, 10);
    $('invoiceFilterDate')?.addEventListener('change', loadInvoices);
    $('invoiceFilterSupplier')?.addEventListener('input', debounce(loadInvoices, 400));
    $('invoiceClearBtn')?.addEventListener('click', () => {
        if ($('invoiceFilterDate')) $('invoiceFilterDate').value = '';
        if ($('invoiceFilterSupplier')) $('invoiceFilterSupplier').value = '';
        loadInvoices();
    });

    async function loadInvoices() {
        $('invoiceLoading')?.classList.remove('d-none');
        $('invoiceEmpty')?.classList.add('d-none');
        $('invoiceError')?.classList.add('d-none');
        const wrap = $('invoiceTableWrapper');
        if (wrap) wrap.style.opacity = '0.4';
        try {
            const params = new URLSearchParams({
                date: $('invoiceFilterDate')?.value || '',
                supplier: $('invoiceFilterSupplier')?.value.trim() || '',
            });
            const res = await api(`${ROUTES.invoices}?${params}`);
            renderInvoiceRows(res.data || []);
        } catch (e) {
            const errEl = $('invoiceError');
            if (errEl) {
                errEl.innerHTML = `<i class="bi bi-exclamation-triangle"></i> ${escapeHtml('فشل: ' + e.message)}`;
                errEl.classList.remove('d-none');
            }
        } finally {
            $('invoiceLoading')?.classList.add('d-none');
            if (wrap) wrap.style.opacity = '1';
        }
    }

    function renderInvoiceRows(rows) {
        const body = $('invoiceTableBody');
        if (!body) return;
        body.innerHTML = '';
        if (!rows.length) { $('invoiceEmpty')?.classList.remove('d-none'); return; }
        rows.forEach((inv, i) => {
            const already = !!state.invoices[inv.id];
            const tr = document.createElement('tr');
            tr.style.cursor = already ? 'default' : 'pointer';
            if (already) tr.classList.add('table-secondary');
            tr.innerHTML = `
                <td class="text-muted">${i + 1}</td>
                <td class="fw-semibold">${escapeHtml(inv.invoice_number ?? inv.id)}</td>
                <td class="text-muted">${escapeHtml(inv.invoice_date ?? '—')}</td>
                <td>${escapeHtml(inv.supplier ?? '—')}</td>
                <td class="text-end fw-semibold">${fmt(inv.total)}</td>
            `;
            if (!already) tr.addEventListener('click', () => selectInvoice(inv));
            body.appendChild(tr);
        });
    }

    async function selectInvoice(inv) {
        if (!inv || state.invoices[inv.id]) return;
        hideInvoiceModal();
        toastInfo(`جارٍ تحميل أصناف الفاتورة ${inv.invoice_number}...`);
        try {
            const url = state.distributionId
                ? `${ROUTES.items(inv.id)}?exclude_distribution_id=${encodeURIComponent(state.distributionId)}`
                : ROUTES.items(inv.id);
            const res = await api(url);
            if (!res.success) { toastError(res.message || 'تعذر التحميل.'); return; }
            state.invoices[inv.id] = {
                id: inv.id, number: inv.invoice_number, date: inv.invoice_date,
                supplier: inv.supplier, items: res.data || [],
            };
            state.activeInvoiceId = inv.id;
            renderTabs();
            renderItems();
            renderSummary();
            toastSuccess(`أُضيفت الفاتورة ${inv.invoice_number}.`);
        } catch (e) {
            toastError('فشل: ' + e.message);
        }
    }

    async function removeInvoice(invoiceId) {
        const inv = state.invoices[invoiceId];
        if (!inv) return;

        const hasAlloc = hasAllocationForInvoice(invoiceId);
        const msg = hasAlloc
            ? `الفاتورة ${inv.number} تحتوي على توزيعات. حذفها سيحذف كل توزيعاتها. متابعة؟`
            : `حذف الفاتورة ${inv.number}؟`;
        const ok = await confirmDialog(msg, {
            title: hasAlloc ? 'حذف فاتورة تحتوي توزيعات' : 'حذف فاتورة',
            okText: 'حذف', danger: true,
        });
        if (!ok) return;

        delete state.invoices[invoiceId];
        Object.keys(state.rowsByItem).forEach(mdId => {
            if ((inv.items || []).some(it => it.id == mdId)) {
                delete state.rowsByItem[mdId];
            }
        });
        pruneOrphanCustomers();

        if (state.activeInvoiceId == invoiceId) {
            const remaining = Object.keys(state.invoices);
            state.activeInvoiceId = remaining[0] || null;
        }

        renderTabs();
        renderItems();
        renderSummary();
        toastInfo(`حُذفت الفاتورة ${inv.number}.`);
    }

    function hasAllocationForInvoice(invoiceId) {
        const inv = state.invoices[invoiceId];
        if (!inv) return false;
        return (inv.items || []).some(it => getAllocatedQty(it.id) > 0);
    }

    function renderTabs() {
        const box = $('invoicesTabsBox');
        if (!box) return;
        box.innerHTML = '';

        const invoices = Object.values(state.invoices);

        if (!invoices.length) {
            box.innerHTML = `
                <div class="text-muted small text-center w-100 py-2">
                    <i class="bi bi-inbox fs-4 d-block mb-1 opacity-50"></i>
                    لم تُضف فواتير بعد — اضغط «إضافة فاتورة» للبدء.
                </div>`;
            return;
        }

        invoices.forEach(inv => {
            const distCount = (inv.items || []).filter(it => getAllocatedQty(it.id) > 0).length;
            const totalCount = (inv.items || []).length;

            let badgeClass = 'bg-secondary-subtle text-secondary';
            if (distCount === totalCount && totalCount > 0) badgeClass = 'bg-success-subtle text-success';
            else if (distCount > 0) badgeClass = 'bg-warning-subtle text-warning';

            const isActive = state.activeInvoiceId == inv.id;
            const supplier = inv.supplier || '—';

            const wrapper = document.createElement('div');
            wrapper.className = 'd-flex align-items-stretch';
            wrapper.innerHTML = `
                <button type="button"
                        class="btn btn-sm ${isActive ? 'btn-primary' : 'btn-outline-secondary'} d-flex align-items-center gap-2 rounded-end-0"
                        data-tab-id="${inv.id}">
                    <i class="bi bi-file-earmark-text"></i>
                    <span class="fw-semibold">${escapeHtml(inv.number)}</span>
                    <span class="badge ${isActive ? 'bg-white text-primary' : badgeClass}">
                        ${escapeHtml(supplier)}
                    </span>
                </button>
                <button type="button"
                        class="btn btn-sm ${isActive ? 'btn-primary' : 'btn-outline-secondary'} rounded-start-0 border-start-0 px-2"
                        data-close-id="${inv.id}" title="حذف الفاتورة">
                    <i class="bi bi-x-lg"></i>
                </button>
            `;
            box.appendChild(wrapper);
        });

        box.querySelectorAll('button[data-tab-id]').forEach(btn => {
            btn.addEventListener('click', () => {
                state.activeInvoiceId = parseInt(btn.dataset.tabId, 10);
                renderTabs();
                renderItems();
            });
        });
        box.querySelectorAll('button[data-close-id]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                removeInvoice(btn.dataset.closeId);
            });
        });
    }

    $('itemsSearch')?.addEventListener('input', renderItems);
    $('itemsClearBtn')?.addEventListener('click', () => {
        if ($('itemsSearch')) {
            $('itemsSearch').value = '';
            renderItems();
        }
    });

    function renderItems() {
        const body = $('itemsTableBody');
        if (!body) return;
        body.innerHTML = '';

        const activeInv = state.activeInvoiceId ? state.invoices[state.activeInvoiceId] : null;

        if ($('activeInvoiceLabel')) {
            $('activeInvoiceLabel').textContent = activeInv
                ? `${activeInv.number} — ${activeInv.supplier}`
                : '—';
        }

        if (!activeInv || !activeInv.items.length) {
            $('itemsEmpty')?.classList.remove('d-none');
            if ($('itemsTableWrapper')) $('itemsTableWrapper').style.display = 'none';
            if ($('itemsEmpty')) {
                $('itemsEmpty').innerHTML = activeInv
                    ? '<i class="bi bi-inbox fs-3 d-block mb-1 opacity-50"></i><div class="small">لا توجد أصناف في هذه الفاتورة.</div>'
                    : '<i class="bi bi-inbox fs-3 d-block mb-1 opacity-50"></i><div class="small">اختر فاتورة أو أضف واحدة.</div>';
            }
            return;
        }

        $('itemsEmpty')?.classList.add('d-none');
        if ($('itemsTableWrapper')) $('itemsTableWrapper').style.display = '';

        const filter = ($('itemsSearch')?.value || '').trim().toLowerCase();

        activeInv.items.forEach((item) => {
            const searchStr = `${item.item_name} ${item.code} ${item.type_name} ${item.unit_name}`.toLowerCase();
            if (filter && !searchStr.includes(filter)) return;

            const alloc = getAllocatedQty(item.id);
            const remaining = Math.max(0, num(item.available) - alloc);
            const hasAlloc = alloc > 0;

            const tr = document.createElement('tr');
            if (hasAlloc) tr.classList.add('table-success');
            tr.innerHTML = `
                <td style="width:36px">
                    <button type="button"
                            class="btn btn-sm ${hasAlloc ? 'btn-primary' : 'btn-outline-primary'}"
                            data-open-modal="${item.id}" title="توزيع">
                        <i class="bi bi-sliders"></i>
                    </button>
                </td>
                <td class="fw-semibold">${escapeHtml(item.item_name)}</td>
                <td>${escapeHtml(item.type_name || '—')}</td>
                <td>${escapeHtml(item.unit_name || '—')}</td>
                <td class="small text-muted">${escapeHtml(item.code || '—')}</td>
                <td class="text-end">${fmt(item.quantity, 3)}</td>
                <td class="text-end fw-semibold ${remaining <= 0 ? 'text-danger' : 'text-success'}">
                    ${fmt(remaining, 3)}
                </td>
                <td class="text-end text-muted">${fmt(item.unit_cost)}</td>
                <td class="text-end text-muted">${fmt(item.sale_price)}</td>
            `;
            body.appendChild(tr);
        });

        body.querySelectorAll('button[data-open-modal]').forEach(btn =>
            btn.addEventListener('click', () => openAllocationModal(btn.dataset.openModal)));
    }

    let allocationModalInstance = null;
    let allowAllocationModalClose = false;

    $('allocationModal')?.addEventListener('hide.bs.modal', async (e) => {
        if (allowAllocationModalClose) return;

        const itemId = state.modalItemId;
        if (!itemId) return;

        const item = findItem(itemId);
        if (!item) return;

        const rows = state.rowsByItem[itemId] || [];

        let invalidPrices = [];
        rows.forEach(r => {
            if (num(r.quantity) > 0 && num(r.price) <= 0) {
                invalidPrices.push(state.customerInfo[r.customerId]?.name || `#${r.customerId}`);
            }
        });

        if (invalidPrices.length > 0) {
            e.preventDefault();
            e.stopPropagation();

            document.querySelectorAll('#allocationTableBody .alloc-price-input').forEach(inp => {
                const rid = inp.dataset.rowId;
                const r = rows.find(x => x.rowId === rid);
                if (r && num(r.quantity) > 0 && num(r.price) <= 0) {
                    inp.classList.add('border-danger');
                } else {
                    inp.classList.remove('border-danger');
                }
            });

            const preview = invalidPrices.slice(0, 3).join('، ');
            const more = invalidPrices.length > 3 ? ` و${invalidPrices.length - 3} آخرين` : '';
            toastError(`لا يمكن إغلاق المودال — يوجد صف بسعر فارغ أو صفر: «${preview}»${more}. يرجى إدخال السعر أو مسح الكمية.`);
            return;
        }

        const emptyNames = getEmptyCustomersForItem(itemId);
        if (emptyNames.length === 0) return;

        e.preventDefault();
        e.stopPropagation();

        const preview = emptyNames.slice(0, 3).join('، ');
        const more = emptyNames.length > 3 ? ` و${emptyNames.length - 3} آخرين` : '';

        const ok = await confirmDialog(
            `يوجد ${emptyNames.length} صف بحقل فارغ: «${preview}»${more}.\n\n` +
            `هل تريد حذفها وإغلاق المودال؟\n` +
            `إن رفضت، سيبقى المودال مفتوحًا لإكمال الكميات.`,
            { title: 'صفوف بدون كمية', okText: 'حذفها وإغلاق', cancelText: 'إلغاء', danger: true }
        );

        if (!ok) {
            document.querySelectorAll('#allocationTableBody .alloc-input').forEach(inp => {
                inp.classList.remove('is-invalid');
                const r = rows.find(x => x.rowId === inp.dataset.rowId);
                if (r && num(r.quantity) <= 0 && !inp.readOnly) inp.classList.add('is-invalid');
            });
            return;
        }

        const removed = removeEmptyCustomersForItem(itemId);

        renderAllocationModal();
        renderItems();
        renderSummary();
        renderTabs();

        if (removed > 0) {
            toastInfo(`حُذف ${removed} عميل بدون كمية.`);
        }

        allowAllocationModalClose = true;
        if (allocationModalInstance) allocationModalInstance.hide();
        else if ($('allocationModal')) {
            $('allocationModal').style.display = 'none';
            $('allocationModal').classList.remove('show');
            document.body.classList.remove('modal-open');
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        }
    });

    async function openAllocationModal(itemId) {
        const modalEl = $('allocationModal');
        if (!modalEl) return;

        const inv = findInvoiceOfItem(itemId);
        if (!inv) return;

        state.modalItemId = itemId;
        state.modalInvoiceId = inv.id;
        allowAllocationModalClose = false;

        await ensureUnitsCache();
        await ensureTypesCache();

        renderAllocationModal();

        const Modal = await ensureBootstrap();
        if (Modal) {
            if (!allocationModalInstance) allocationModalInstance = new Modal(modalEl);
            allocationModalInstance.show();
        } else {
            modalEl.style.display = 'block';
            modalEl.classList.add('show');
            document.body.classList.add('modal-open');
        }
    }

    function getAllocatedQty(movementDetailId) {
        const rows = state.rowsByItem[movementDetailId] || [];
        return rows.reduce((s, r) => s + num(r.quantityBase), 0);
    }

    /** تحويل كمية صف بالوحدة المختارة إلى وحدة الدفعة */
    function convertRowToBatchQty(row, item) {
        const qtyEntered = Math.max(0, num(row.quantity));
        const unitId = chosenUnitIdForRow(row, item);
        const batch = batchUnitId(item);

        if (qtyEntered <= 0) return 0;
        if (unitId === null || batch === null || String(unitId) === String(batch)) return qtyEntered;

        if (isPieceUnitId(unitId)) {
            const wGrams = num(row.unitWeightGrams);
            if (wGrams <= 0) return 0;
            const kgTotal = (qtyEntered * wGrams) / 1000;
            return kgToBatchUnit(kgTotal, batch, item);
        }

        const f = unitFactor(unitId, batch);
        return f === null ? qtyEntered : qtyEntered * f;
    }

    /** حساب السعر التقديري للوحدة المختارة انطلاقاً من سعر الدفعة */
    function defaultPriceForUnit(unitId, weightGrams, item) {
        const batchPrice = num(item.sale_price);
        const batch = batchUnitId(item);
        if (unitId === null || batch === null || String(unitId) === String(batch)) return batchPrice;

        if (isPieceUnitId(unitId)) {
            const wGrams = num(weightGrams);
            if (wGrams <= 0) return 0;
            const wKg = wGrams / 1000;
            const batchName = unitNameById(batch) || item.unit_name || '';
            let weightInBatch = wKg;
            if (isGramUnitName(batchName)) weightInBatch = wGrams;
            else if (!isKiloUnitName(batchName)) {
                const g = unitGrams(batch);
                if (g === null || g <= 0) return batchPrice;
                weightInBatch = wGrams / g;
            }
            return batchPrice * weightInBatch;
        }

        const f = unitFactor(batch, unitId);
        return (f === null || f <= 0) ? batchPrice : batchPrice / f;
    }

    /** إضافة صف جديد للعميل نفسه على الصنف المفتوح */
    function addRowForCustomer(itemId, customerId) {
        const item = findItem(itemId);
        if (!item) return;

        const unitId = defaultPieceUnitId(item);
        const defaultPrice = defaultPriceForUnit(unitId, '', item);

        state.rowsByItem[itemId] = state.rowsByItem[itemId] || [];
        state.rowsByItem[itemId].push({
            rowId: nextRowId(),
            customerId: parseInt(customerId, 10),
            typeId: null,
            unitId: unitId,
            unitWeightGrams: '',
            quantity: '',
            quantityBase: 0,
            price: defaultPrice > 0 ? Number(defaultPrice.toFixed(2)) : '',
            isInvoiced: false,
            savedDetailId: null,
        });
    }

    function renderAllocationModal() {
        const itemId = state.modalItemId;
        const item = findItem(itemId);
        const inv = state.modalInvoiceId ? state.invoices[state.modalInvoiceId] : null;
        if (!item || !inv) return;

        const alloc = getAllocatedQty(itemId);
        const remaining = Math.max(0, num(item.available) - alloc);
        const noRemaining = remaining <= 0;

        if ($('allocItemName')) $('allocItemName').textContent = item.item_name;
        if ($('allocInvoiceNumber')) $('allocInvoiceNumber').textContent = inv.number;
        if ($('allocAvailable')) $('allocAvailable').textContent = fmt(item.available, 3);
        if ($('allocAllocated')) $('allocAllocated').textContent = fmt(alloc, 3);
        if ($('allocRemaining')) $('allocRemaining').textContent = fmt(remaining, 3);
        if ($('allocUnitCost')) $('allocUnitCost').textContent = fmt(item.unit_cost);

        const idx = inv.items.findIndex(it => it.id == itemId);
        const total = inv.items.length;
        if ($('allocItemCounter')) $('allocItemCounter').textContent = `${idx + 1} من ${total}`;
        if ($('btnPrevItem')) $('btnPrevItem').disabled = idx <= 0;
        if ($('btnNextItem')) $('btnNextItem').disabled = idx >= total - 1;

        const tbody = $('allocationTableBody');
        if (!tbody) return;
        tbody.innerHTML = '';

        const rows = state.rowsByItem[itemId] || [];

        if (!rows.length) {
            tbody.innerHTML = `
                <tr><td colspan="8" class="text-center text-muted py-3 small">
                    <i class="bi bi-person-plus fs-4 d-block mb-1 opacity-50"></i>
                    أضف عميلًا لتوزيع الكميات.
                </td></tr>`;
            return;
        }

        const unitList = availableUnitOptions(item);
        const typeList = state.typesCache || [];

        rows.forEach(r => {
            const info = state.customerInfo[r.customerId] || { name: '—', phone: '' };
            const curUnit = chosenUnitIdForRow(r, item);
            const pieceUnitSelected = curUnit !== null && isPieceUnitId(curUnit);
            const curQty = num(r.quantity);
            const curWeight = num(r.unitWeightGrams);
            const curPrice = num(r.price);
            const curTotal = curQty * curPrice;

            const isEmpty = curQty <= 0;
            const locked = !!r.isInvoiced;
            const editable = !locked && (!isEmpty || !noRemaining);

            const unitOptions = unitList
                .map(u => `<option value="${escapeHtml(String(u.UnitID))}" ${String(u.UnitID) === String(curUnit) ? 'selected' : ''}>${escapeHtml(u.UnitName || '')}</option>`)
                .join('');

            const curType = r.typeId ?? '';
            const typeOptions = typeList.length === 0
                ? '<option value="">جارٍ تحميل الأنواع…</option>'
                : '<option value="">اختر النوع</option>' + typeList
                    .filter(t => parseInt(t.is_active, 10) === 1 || String(t.id) === String(curType))
                    .map(t => `<option value="${escapeHtml(String(t.id))}" ${String(t.id) === String(curType) ? 'selected' : ''}>${escapeHtml(t.name || '')}</option>`)
                    .join('');

            const isWeightMissing = pieceUnitSelected && String(curUnit) !== String(batchUnitId(item)) && curWeight <= 0;
            const isPriceInvalid = curQty > 0 && curPrice <= 0;
            const showPrice = curQty > 0;

            const tr = document.createElement('tr');
            tr.dataset.rowId = r.rowId;
            if (locked) tr.classList.add('table-light');

            tr.innerHTML = `
                <td>
                    <div class="fw-semibold small">
                        ${escapeHtml(info.name)}
                        ${locked ? '<span class="badge bg-info-subtle text-info ms-1">مفوتر</span>' : ''}
                        ${!locked && isEmpty ? '<i class="bi bi-exclamation-circle-fill text-danger ms-1" title="لم تُدخل كمية"></i>' : ''}
                    </div>
                    ${info.phone ? `<div class="text-muted" style="font-size:0.7rem;">${escapeHtml(info.phone)}</div>` : ''}
                </td>
                <td class="text-center">
                    <select class="form-select form-select-sm alloc-type-input ${curType ? '' : 'border-danger'}" data-row-id="${r.rowId}" ${editable ? '' : 'disabled'}>
                        ${typeOptions}
                    </select>
                </td>
                <td class="text-center">
                    <select class="form-select form-select-sm alloc-unit-input" data-row-id="${r.rowId}" ${editable ? '' : 'disabled'}>
                        ${unitOptions}
                    </select>
                </td>
                <td class="text-center">
                    ${pieceUnitSelected ? `
                        <input type="text"
                               inputmode="decimal"
                               autocomplete="off"
                               dir="ltr"
                               class="form-control form-control-sm text-center alloc-weight-input ${editable ? '' : 'bg-body-secondary'} ${isWeightMissing ? 'border-danger' : ''}"
                               data-row-id="${r.rowId}"
                               value="${r.unitWeightGrams !== '' && r.unitWeightGrams !== null ? r.unitWeightGrams : ''}"
                               ${editable ? '' : 'readonly'}
                               placeholder="جرام">
                    ` : '<span class="text-muted small">—</span>'}
                </td>
                <td>
                    <input type="text"
                           inputmode="decimal"
                           autocomplete="off"
                           dir="ltr"
                           class="form-control form-control-sm text-center alloc-input ${editable ? '' : 'bg-body-secondary'} ${isEmpty && editable ? 'border-danger' : ''}"
                           data-row-id="${r.rowId}"
                           value="${curQty > 0 ? curQty : ''}"
                           ${editable ? '' : 'readonly'}
                           placeholder="${editable ? 'الكمية' : '—'}">
                </td>
                <td class="text-center">
                    <span class="price-placeholder text-muted small ${showPrice ? 'd-none' : ''}">أدخل الكمية</span>
                    <input type="text"
                           inputmode="decimal"
                           autocomplete="off"
                           dir="ltr"
                           class="form-control form-control-sm text-center alloc-price-input ${showPrice ? '' : 'd-none'} ${editable ? '' : 'bg-body-secondary'} ${isPriceInvalid ? 'border-danger' : ''}"
                           data-row-id="${r.rowId}"
                           value="${curPrice > 0 ? curPrice : ''}"
                           ${editable ? '' : 'readonly'}>
                </td>
                <td class="text-center">
                    <input type="text"
                           class="form-control form-control-sm text-center bg-light fw-bold text-success alloc-total-input ${showPrice ? '' : 'd-none'}"
                           value="${curTotal > 0 ? fmt(curTotal) : ''}"
                           readonly
                           tabindex="-1"
                           dir="ltr">
                </td>
                <td class="text-center text-nowrap">
                    <button type="button" class="btn btn-sm btn-link text-success p-0 me-1 alloc-add-row"
                            data-customer="${r.customerId}" title="إضافة صف آخر لنفس العميل">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                    ${locked ? '' : `
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 alloc-remove"
                            data-row-id="${r.rowId}" title="حذف الصف">
                        <i class="bi bi-x-lg"></i>
                    </button>`}
                </td>
            `;
            tbody.appendChild(tr);
        });

        const bindDecimalKeys = (inp) => {
            inp.addEventListener('keydown', (e) => {
                const ch = e.key;
                if (ch === 'Enter') { e.preventDefault(); inp.blur(); return; }
                if (['Backspace', 'Delete', 'Tab', 'Escape', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(ch)) return;
                if ((e.ctrlKey || e.metaKey) && ['a', 'c', 'v', 'x', 'z', 'y'].includes(String(ch).toLowerCase())) return;
                if (/[0-9]/.test(ch)) return;
                if (ch === '.') {
                    if (inp.value.includes('.')) e.preventDefault();
                    return;
                }
                e.preventDefault();
            });
        };

        tbody.querySelectorAll('.alloc-input').forEach(inp => {
            if (inp.readOnly) {
                inp.addEventListener('click', (e) => {
                    e.preventDefault();
                    toastWarning('هذا الصف غير قابل للتعديل.');
                    inp.blur();
                });
                inp.addEventListener('focus', () => inp.blur());
            } else {
                bindDecimalKeys(inp);
                inp.addEventListener('input', () => handleModalAllocLiveInput(inp));
                inp.addEventListener('blur', () => handleModalAllocInput(inp));
            }
        });

        tbody.querySelectorAll('.alloc-weight-input').forEach(inp => {
            if (inp.readOnly) return;
            bindDecimalKeys(inp);
            inp.addEventListener('input', () => handleWeightInput(inp));
            inp.addEventListener('blur', () => renderAllocationModal());
        });

        tbody.querySelectorAll('.alloc-price-input').forEach(inp => {
            if (inp.readOnly) return;
            bindDecimalKeys(inp);
            inp.addEventListener('input', () => handleModalPriceInput(inp));
        });

        tbody.querySelectorAll('.alloc-unit-input').forEach(sel => {
            if (sel.disabled) return;
            sel.addEventListener('change', () => handleUnitChange(sel));
        });

        tbody.querySelectorAll('.alloc-type-input').forEach(sel => {
            if (sel.disabled) return;
            sel.addEventListener('change', () => handleTypeChange(sel));
        });

        tbody.querySelectorAll('.alloc-add-row').forEach(btn => {
            btn.addEventListener('click', () => {
                addRowForCustomer(itemId, btn.dataset.customer);
                renderAllocationModal();
            });
        });

        tbody.querySelectorAll('.alloc-remove').forEach(btn =>
            btn.addEventListener('click', async () => {
                const rid = btn.dataset.rowId;
                const list = state.rowsByItem[itemId] || [];
                const target = list.find(x => x.rowId === rid);
                if (!target) return;
                if (target.isInvoiced) {
                    toastError('لا يمكن حذف صف مفوتر بالفعل.');
                    return;
                }

                const info = state.customerInfo[target.customerId];
                if (num(target.quantity) > 0) {
                    const ok = await confirmDialog(
                        `حذف هذا الصف للعميل «${info?.name || ''}» (الكمية: ${fmt(target.quantity, 3)})؟`,
                        { title: 'حذف صف', okText: 'حذف', danger: true }
                    );
                    if (!ok) return;
                }

                const next = list.filter(x => x.rowId !== rid);
                if (next.length === 0) delete state.rowsByItem[itemId];
                else state.rowsByItem[itemId] = next;

                pruneOrphanCustomers();
                renderAllocationModal();
                renderItems();
                renderSummary();
                renderTabs();
            }));
    }

    function handleModalAllocLiveInput(input) {
        const itemId = state.modalItemId;
        const rowId = input.dataset.rowId;
        const item = findItem(itemId);
        const rows = state.rowsByItem[itemId] || [];
        const r = rows.find(x => x.rowId === rowId);
        if (!item || !r) return;

        r.quantity = input.value;
        const qtyEntered = Math.max(0, num(input.value));
        let qtyBase = convertRowToBatchQty(r, item);

        const others = rows
            .filter(x => x.rowId !== rowId)
            .reduce((s, x) => s + num(x.quantityBase), 0);

        const maxForField = Math.max(0, num(item.available) - others);

        if (qtyBase > maxForField + 0.000001) {
            qtyBase = maxForField;
            toastWarning(`الكمية المطلوبة تتجاوز المتاح (${fmt(maxForField, 3)} ${item.unit_name || ''}).`);
        }

        r.quantityBase = qtyBase;

        const totalAlloc = getAllocatedQty(itemId);
        const remaining = Math.max(0, num(item.available) - totalAlloc);
        if ($('allocAllocated')) $('allocAllocated').textContent = fmt(totalAlloc, 3);
        if ($('allocRemaining')) $('allocRemaining').textContent = fmt(remaining, 3);

        const tr = input.closest('tr');
        if (tr) {
            const priceInput = tr.querySelector('.alloc-price-input');
            const totalInput = tr.querySelector('.alloc-total-input');
            const placeholder = tr.querySelector('.price-placeholder');

            if (qtyEntered > 0) {
                if (priceInput) priceInput.classList.remove('d-none');
                if (totalInput) totalInput.classList.remove('d-none');
                if (placeholder) placeholder.classList.add('d-none');

                if (num(r.price) <= 0) {
                    const defP = defaultPriceForUnit(r.unitId, r.unitWeightGrams, item);
                    if (defP > 0) {
                        r.price = Number(defP.toFixed(2));
                        if (priceInput) priceInput.value = r.price;
                    }
                }
                const priceShown = num(priceInput?.value ?? r.price);
                if (totalInput) totalInput.value = (qtyEntered * priceShown) > 0 ? fmt(qtyEntered * priceShown) : '';
            } else {
                if (priceInput) priceInput.classList.add('d-none');
                if (totalInput) totalInput.classList.add('d-none');
                if (placeholder) placeholder.classList.remove('d-none');
            }
        }

        renderSummary();
        renderTabs();
    }

    function handleModalAllocInput(input) {
        const itemId = state.modalItemId;
        const rowId = input.dataset.rowId;
        const item = findItem(itemId);
        const rows = state.rowsByItem[itemId] || [];
        const r = rows.find(x => x.rowId === rowId);
        if (!item || !r) return;

        const raw = String(input.value ?? '').trim();
        if (raw === '' || num(raw) <= 0) {
            r.quantity = '';
            r.quantityBase = 0;
        } else {
            r.quantity = num(raw);
            r.quantityBase = convertRowToBatchQty(r, item);
        }

        renderAllocationModal();
        renderItems();
        renderSummary();
        renderTabs();
    }

    function handleModalPriceInput(input) {
        const itemId = state.modalItemId;
        const rowId = input.dataset.rowId;
        const rows = state.rowsByItem[itemId] || [];
        const r = rows.find(x => x.rowId === rowId);
        if (!r) return;

        r.price = input.value;
        const price = Math.max(0, num(input.value));
        input.classList.toggle('border-danger', price <= 0 && num(r.quantity) > 0);

        const tr = input.closest('tr');
        if (tr) {
            const totalInput = tr.querySelector('.alloc-total-input');
            const qtyEntered = num(r.quantity);
            if (totalInput) totalInput.value = (qtyEntered * price) > 0 ? fmt(qtyEntered * price) : '';
        }

        renderSummary();
    }

    function kgToBatchUnit(kg, batch, item) {
        const batchName = unitNameById(batch) || item.unit_name || '';
        if (isKiloUnitName(batchName)) return kg;
        if (isGramUnitName(batchName)) return kg * 1000;
        const g = unitGrams(batch);
        return (g !== null && g > 0) ? (kg * 1000) / g : kg;
    }

    function handleUnitChange(select) {
        const itemId = state.modalItemId;
        const rowId = select.dataset.rowId;
        const item = findItem(itemId);
        const rows = state.rowsByItem[itemId] || [];
        const r = rows.find(x => x.rowId === rowId);
        if (!item || !r) return;

        r.unitId = select.value;
        if (!isPieceUnitId(r.unitId)) {
            r.unitWeightGrams = '';
        }
        r.quantityBase = convertRowToBatchQty(r, item);

        const defP = defaultPriceForUnit(r.unitId, r.unitWeightGrams, item);
        if (defP > 0 && num(r.price) <= 0) {
            r.price = Number(defP.toFixed(2));
        }

        renderAllocationModal();
        renderItems();
        renderSummary();
        renderTabs();
    }

    function handleTypeChange(sel) {
        const itemId = state.modalItemId;
        const rowId = sel.dataset.rowId;
        const rows = state.rowsByItem[itemId] || [];
        const r = rows.find(x => x.rowId === rowId);
        if (!r) return;

        r.typeId = sel.value ? parseInt(sel.value, 10) : null;
        sel.classList.toggle('border-danger', !sel.value);
    }

    function handleWeightInput(input) {
        const itemId = state.modalItemId;
        const rowId = input.dataset.rowId;
        const item = findItem(itemId);
        const rows = state.rowsByItem[itemId] || [];
        const r = rows.find(x => x.rowId === rowId);
        if (!item || !r) return;

        r.unitWeightGrams = input.value;
        r.quantityBase = convertRowToBatchQty(r, item);

        const others = rows
            .filter(x => x.rowId !== rowId)
            .reduce((s, x) => s + num(x.quantityBase), 0);
        const maxForField = Math.max(0, num(item.available) - others);

        if (r.quantityBase > maxForField + 0.000001) {
            toastWarning(`المكافئ الوزني (${fmt(r.quantityBase, 3)} ${item.unit_name || ''}) يتجاوز المتاح (${fmt(maxForField, 3)}).`);
        }

        const defP = defaultPriceForUnit(r.unitId, r.unitWeightGrams, item);
        if (defP > 0 && num(r.price) <= 0) {
            r.price = Number(defP.toFixed(2));
            const priceInput = input.closest('tr')?.querySelector('.alloc-price-input');
            if (priceInput) priceInput.value = r.price;
        }

        const totalAlloc = getAllocatedQty(itemId);
        const remaining = Math.max(0, num(item.available) - totalAlloc);
        if ($('allocAllocated')) $('allocAllocated').textContent = fmt(totalAlloc, 3);
        if ($('allocRemaining')) $('allocRemaining').textContent = fmt(remaining, 3);

        renderSummary();
        renderTabs();
    }

    function navigateItem(direction) {
        if (!state.modalInvoiceId) return;

        const itemId = state.modalItemId;
        const emptyNames = getEmptyCustomersForItem(itemId);
        if (emptyNames.length > 0) {
            const preview = emptyNames.slice(0, 3).join('، ');
            const more = emptyNames.length > 3 ? ` و${emptyNames.length - 3} آخرين` : '';
            toastError(
                `أكمل أو احذف العملاء ذوي الحقول الفارغة قبل التنقل: «${preview}»${more}.`
            );
            return;
        }

        const inv = state.invoices[state.modalInvoiceId];
        if (!inv) return;
        const idx = inv.items.findIndex(it => it.id == state.modalItemId);
        if (idx === -1) return;
        const newIdx = idx + direction;
        if (newIdx < 0 || newIdx >= inv.items.length) return;
        state.modalItemId = inv.items[newIdx].id;
        renderAllocationModal();
    }

    $('btnPrevItem')?.addEventListener('click', () => navigateItem(-1));
    $('btnNextItem')?.addEventListener('click', () => navigateItem(+1));

    let accountPickerInstance = null;

    function ensureMultiSelectUI() {
        const results = $('AccountPickerResults');
        const table = results?.closest('table');
        if (!table) return;

        const headerRow = table.querySelector('thead tr');
        if (headerRow && !headerRow.querySelector('.multi-check-col')) {
            const th = document.createElement('th');
            th.className = 'multi-check-col';
            th.style.width = '40px';
            th.innerHTML = `<input type="checkbox" class="form-check-input" id="AccountPickerSelectAll" title="تحديد الكل">`;
            headerRow.insertBefore(th, headerRow.firstChild);

            th.querySelector('#AccountPickerSelectAll')?.addEventListener('change', (e) => {
                document.querySelectorAll('.account-checkbox:not(:disabled)').forEach(cb => {
                    cb.checked = e.target.checked;
                });
                updateSelectedCount();
            });
        }

        const body = document.querySelector('#AccountPickerModal .modal-body');
        if (body && !$('AccountPickerFooter')) {
            const footer = document.createElement('div');
            footer.id = 'AccountPickerFooter';
            footer.className = 'd-flex justify-content-between align-items-center mt-3 pt-3 border-top';
            footer.innerHTML = `
                <div class="text-muted small">
                    <i class="bi bi-check2-square"></i>
                    المحدد: <span class="fw-bold text-primary" id="AccountPickerSelectedCount">0</span>
                </div>
                <button type="button" class="btn btn-success" id="AccountPickerAddSelected" disabled>
                    <i class="bi bi-check2-circle"></i> إضافة المحددين
                </button>
            `;
            body.appendChild(footer);

            $('AccountPickerAddSelected')?.addEventListener('click', () => {
                const selected = [];
                document.querySelectorAll('#AccountPickerResults .account-checkbox:checked:not(:disabled)').forEach(cb => {
                    selected.push({
                        id: cb.dataset.id,
                        name: cb.dataset.name,
                        phone: cb.dataset.phone || '',
                        code: cb.dataset.code || '',
                    });
                });
                if (!selected.length) {
                    toastWarning('لم تُحدد أي عميل.');
                    return;
                }
                addCustomersToItem(selected);
                closeAccountPicker();
            });
        }
    }

    function updateSelectedCount() {
        const count = document.querySelectorAll('#AccountPickerResults .account-checkbox:checked:not(:disabled)').length;
        const el = $('AccountPickerSelectedCount');
        const btn = $('AccountPickerAddSelected');
        if (el) el.textContent = count;
        if (btn) btn.disabled = count === 0;
    }

    async function openAccountPicker() {
        if (!state.modalItemId) { toastError('افتح مودال صنف أولًا.'); return; }
        const modalEl = $('AccountPickerModal');
        if (!modalEl) { toastError('مودال الحسابات غير محمّل.'); return; }

        window.__accountPickerOwner = 'allocation';

        const Modal = await ensureBootstrap();
        if (Modal) {
            if (!accountPickerInstance) accountPickerInstance = new Modal(modalEl);
            accountPickerInstance.show();
        } else {
            modalEl.style.display = 'block';
            modalEl.classList.add('show');
            document.body.classList.add('modal-open');
        }

        document.querySelectorAll('#AccountPickerTabs .nav-link').forEach(tab => {
            if (tab.dataset.type === 'customer') {
                tab.classList.remove('d-none'); tab.classList.add('active');
            } else {
                tab.classList.add('d-none'); tab.classList.remove('active');
            }
        });
        const balanceCol = $('AccountPickerColBalance');
        if (balanceCol) balanceCol.classList.add('d-none');

        ensureMultiSelectUI();

        if ($('AccountPickerSearch')) $('AccountPickerSearch').value = '';
        if ($('AccountPickerResults')) {
            $('AccountPickerResults').innerHTML = `
                <tr><td colspan="6" class="text-center text-muted py-4">ابدأ البحث</td></tr>`;
        }
        if ($('AccountPickerTitle')) $('AccountPickerTitle').textContent = 'اختيار عملاء (متعدد)';
        updateSelectedCount();
        if ($('AccountPickerSearch')) $('AccountPickerSearch').focus();

        loadAccounts();
    }

    function closeAccountPicker() {
        const modalEl = $('AccountPickerModal');
        if (accountPickerInstance) accountPickerInstance.hide();
        else if (modalEl) {
            modalEl.style.display = 'none';
            modalEl.classList.remove('show');
            document.body.classList.remove('modal-open');
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        }

        window.__accountPickerOwner = null;
    }

    $('btnAddCustomerToItem')?.addEventListener('click', openAccountPicker);
    document.querySelectorAll('#AccountPickerModal [data-bs-dismiss="modal"]').forEach(btn =>
        btn.addEventListener('click', closeAccountPicker));

    $('AccountPickerSearch')?.addEventListener('input', debounce(() => {
        if (window.__accountPickerOwner !== 'allocation') return;
        loadAccounts();
    }, 400));

    $('AccountPickerClearBtn')?.addEventListener('click', () => {
        if (window.__accountPickerOwner !== 'allocation') return;
        if ($('AccountPickerSearch')) $('AccountPickerSearch').value = '';
        loadAccounts();
    });

    async function loadAccounts() {
        const term = ($('AccountPickerSearch')?.value || '').trim();
        const results = $('AccountPickerResults');
        if (!results) return;

        if (term.length > 0 && term.length < 2) {
            results.innerHTML = `
                <tr><td colspan="6" class="text-center text-muted py-4">
                    اكتب حرفين على الأقل للبحث.
                </td></tr>`;
            return;
        }

        results.innerHTML = `
            <tr><td colspan="6" class="text-center text-muted py-4">
                <div class="spinner-border spinner-border-sm text-primary"></div>
                <span class="ms-2">جارٍ التحميل...</span>
            </td></tr>`;

        try {
            const res = await api(`${ROUTES.customers}?q=${encodeURIComponent(term)}`);
            const rows = res.data || [];
            if (!rows.length) {
                results.innerHTML = `
                    <tr><td colspan="6" class="text-center text-muted py-4">
                        لا نتائج مطابقة.
                    </td></tr>`;
                return;
            }

            const currentRows = state.modalItemId
                ? (state.rowsByItem[state.modalItemId] || [])
                : [];

            results.innerHTML = '';
            rows.forEach(c => {
                const alreadyAdded = currentRows.some(r => String(r.customerId) === String(c.id));

                const tr = document.createElement('tr');
                tr.style.cursor = alreadyAdded ? 'default' : 'pointer';
                if (alreadyAdded) tr.classList.add('table-secondary');

                tr.innerHTML = `
                    <td class="text-center">
                        <input type="checkbox"
                               class="form-check-input account-checkbox"
                               data-id="${c.id}"
                               data-name="${escapeHtml(c.name)}"
                               data-phone="${escapeHtml(c.phone || '')}"
                               data-code="${escapeHtml(c.code || '')}"
                               ${alreadyAdded ? 'disabled checked' : ''}>
                    </td>
                    <td class="text-center text-muted">${escapeHtml(c.code ?? c.id)}</td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-person-circle text-success"></i>
                            <span class="fw-semibold">${escapeHtml(c.name)}</span>
                            ${alreadyAdded ? '<span class="badge bg-secondary-subtle text-secondary ms-1">مضاف</span>' : ''}
                        </div>
                    </td>
                    <td class="d-none"></td>
                    <td>${escapeHtml(c.phone || '—')}</td>
                `;

                if (!alreadyAdded) {
                    tr.addEventListener('click', (e) => {
                        if (e.target.tagName === 'INPUT') return;
                        const cb = tr.querySelector('.account-checkbox');
                        if (cb) {
                            cb.checked = !cb.checked;
                            updateSelectedCount();
                        }
                    });
                }

                const cb = tr.querySelector('.account-checkbox');
                cb?.addEventListener('change', updateSelectedCount);

                results.appendChild(tr);
            });

            updateSelectedCount();
        } catch (e) {
            results.innerHTML = `
                <tr><td colspan="6" class="text-center text-danger py-4">
                    فشل: ${escapeHtml(e.message)}
                </td></tr>`;
        }
    }

    function addCustomersToItem(customers) {
        const itemId = state.modalItemId;
        if (!itemId) return;

        const item = findItem(itemId);
        if (!item) return;

        const added = [];
        state.rowsByItem[itemId] = state.rowsByItem[itemId] || [];

        customers.forEach(c => {
            if (!state.customerInfo[c.id]) {
                state.customerInfo[c.id] = {
                    id: c.id, name: c.name, phone: c.phone || '', code: c.code || '',
                };
            }

            addRowForCustomer(itemId, c.id);
            added.push(c);
        });

        renderAllocationModal();
        renderItems();
        renderSummary();
        renderTabs();

        const itemName = item.item_name || '';

        if (added.length === 1) {
            toastSuccess(`أضيف العميل «${added[0].name}» إلى الصنف «${itemName}». أدخل الكمية الآن.`);
        } else if (added.length > 1) {
            toastSuccess(`أضيف ${added.length} عملاء إلى الصنف «${itemName}». أدخل كمياتهم الآن.`);
        }
    }

    function renderSummary() {
        let itemsCount = 0, cost = 0, sale = 0;
        const customersSet = new Set();

        Object.values(state.invoices).forEach(inv => {
            (inv.items || []).forEach(item => {
                const rows = state.rowsByItem[item.id] || [];
                const alloc = getAllocatedQty(item.id);

                if (alloc > 0) {
                    itemsCount++;
                    cost += alloc * num(item.unit_cost);

                    rows.forEach(r => {
                        if (num(r.quantity) > 0) {
                            customersSet.add(String(r.customerId));
                            sale += num(r.quantity) * num(r.price);
                        }
                    });
                }
            });
        });

        if ($('sumItemsCount')) $('sumItemsCount').textContent = itemsCount;
        if ($('sumCustomersCount')) $('sumCustomersCount').textContent = customersSet.size;
        if ($('sumCost')) $('sumCost').textContent = fmt(cost);
        if ($('sumSale')) $('sumSale').textContent = fmt(sale);
    }

    $('btnSaveDraft')?.addEventListener('click', () => save());

    async function save() {
        if (!Object.keys(state.invoices).length) {
            toastError('اختر فاتورة واحدة على الأقل.');
            return;
        }

        const empties = findEmptyAllocations();
        if (empties.length > 0) {
            const first = empties[0];
            const item = findItem(first.itemId);
            const cust = state.customerInfo[first.custId];
            const invOfItem = findInvoiceOfItem(first.itemId);

            toastError(
                `لا يمكن الحفظ — يوجد ${empties.length} عميل بحقل فارغ. ` +
                `أكمل الكميات أولًا: الصنف «${item?.item_name || ''}» — العميل «${cust?.name || ''}».`
            );

            if (invOfItem) {
                state.activeInvoiceId = invOfItem.id;
                renderTabs();
                renderItems();

                state.modalItemId = first.itemId;
                state.modalInvoiceId = invOfItem.id;
                allowAllocationModalClose = false;
                renderAllocationModal();

                const Modal = await ensureBootstrap();
                const modalEl = $('allocationModal');
                if (Modal && modalEl) {
                    if (!allocationModalInstance) allocationModalInstance = new Modal(modalEl);
                    allocationModalInstance.show();
                }
            }
            return;
        }

        let invalidPrice = null;
        for (const [mdId, rows] of Object.entries(state.rowsByItem)) {
            const item = findItem(mdId);
            if (!item) continue;

            for (const r of (rows || [])) {
                if (num(r.quantity) > 0 && num(r.price) <= 0) {
                    invalidPrice = { mdId, custId: r.customerId, item, cust: state.customerInfo[r.customerId] };
                    break;
                }
            }
            if (invalidPrice) break;
        }

        if (invalidPrice) {
            toastError(`لا يمكن الحفظ — السعر فارغ أو صفر للعميل «${invalidPrice.cust?.name || ''}» على الصنف «${invalidPrice.item.item_name}».`);

            const invOfItem = findInvoiceOfItem(invalidPrice.mdId);
            if (invOfItem) {
                state.activeInvoiceId = invOfItem.id;
                renderTabs();
                renderItems();

                state.modalItemId = invalidPrice.mdId;
                state.modalInvoiceId = invOfItem.id;
                allowAllocationModalClose = false;
                renderAllocationModal();

                const Modal = await ensureBootstrap();
                const modalEl = $('allocationModal');
                if (Modal && modalEl) {
                    if (!allocationModalInstance) allocationModalInstance = new Modal(modalEl);
                    allocationModalInstance.show();
                }
            }
            return;
        }

        // تأكد من توفر قوائم الوحدات والأنواع قبل البناء
        await ensureUnitsCache();
        await ensureTypesCache();

        const errors = [];
        const details = [];
        Object.entries(state.rowsByItem).forEach(([mdId, rows]) => {
            const item = findItem(mdId);
            if (!item) return;
            const batch = batchUnitId(item);

            (rows || []).forEach(r => {
                if (num(r.quantity) <= 0) return;

                const unitId = chosenUnitIdForRow(r, item);
                const typeId = r.typeId;
                const isPiece = unitId !== null && isPieceUnitId(unitId);
                const custName = state.customerInfo[r.customerId]?.name || `#${r.customerId}`;
                const label = `«${item.item_name}» للعميل «${custName}»`;

                if (!typeId) errors.push(`${label}: النوع إلزامي.`);
                if (unitId === null) errors.push(`${label}: الوحدة مطلوبة.`);
                if (isPiece && batch !== null && String(unitId) !== String(batch) && !(num(r.unitWeightGrams) > 0)) {
                    errors.push(`${label}: وزن الحبة بالجرام إلزامي عند اختيار وحدة «حبة».`);
                }

                // إرسال الصفوف غير المفوترة للحفظ (الخادم يحمي الصفوف المفوترة)
                if (r.isInvoiced) return;

                details.push({
                    movement_detail_id: parseInt(mdId, 10),
                    purchase_invoice_id: parseInt(item.purchase_invoice_id, 10),
                    movement_id: parseInt(item.movement_id, 10),
                    customer_id: parseInt(r.customerId, 10),
                    type_id: typeId ? parseInt(typeId, 10) : null,
                    unit_id: unitId !== null ? parseInt(String(unitId), 10) : null,
                    unit_weight: isPiece && num(r.unitWeightGrams) > 0 ? num(r.unitWeightGrams) : null,
                    quantity: num(r.quantity),
                    sale_price: num(r.price),
                });
            });
        });

        if (errors.length) {
            toastError(errors.slice(0, 3).join(' ') + (errors.length > 3 ? ` (+${errors.length - 3} أخطاء أخرى)` : ''));
            return;
        }

        if (!details.length) {
            toastError('لا توجد كميات موزعة للحفظ.');
            return;
        }

        const payload = {
            id:                state.distributionId,
            distribution_date: $('distributionDate')?.value,
            notes:             $('distributionNotes')?.value || null,
            details,
        };

        if ($('btnSaveDraft')) $('btnSaveDraft').disabled = true;
        try {
            const res = await api(ROUTES.save, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            toastSuccess(res.message + ` (${res.code})`);
            state.distributionId = res.id;
            updateModeBadge();
            const url = new URL(window.location);
            url.searchParams.set('id', res.id);
            window.history.replaceState({}, '', url);

            await loadExisting(res.id);
        } catch (e) {
            toastError('فشل الحفظ: ' + e.message);
        } finally {
            if ($('btnSaveDraft')) $('btnSaveDraft').disabled = false;
        }
    }

    async function loadExisting(id) {
        try {
            const res = await api(ROUTES.show(id));
            if (!res.success) return;

            state.invoices = {};
            state.activeInvoiceId = null;
            state.customerInfo = {};
            state.rowsByItem = {};
            state.modalItemId = null;
            state.modalInvoiceId = null;

            state.distributionId = res.header.id;
            updateModeBadge();
            if ($('distributionDate')) $('distributionDate').value = res.header.distribution_date || '';
            if ($('distributionNotes')) $('distributionNotes').value = res.header.notes || '';

            await ensureUnitsCache();
            await ensureTypesCache();

            const invoiceIds = [...new Set((res.details || []).map(d => d.purchase_invoice_id).filter(Boolean))];
            for (const invId of invoiceIds) {
                try {
                    const invRes = await api(ROUTES.items(invId) + `?exclude_distribution_id=${encodeURIComponent(id)}`);
                    if (invRes.success) {
                        state.invoices[invId] = {
                            id: invId,
                            number: invRes.invoice.invoice_number,
                            date: invRes.invoice.invoice_date,
                            supplier: invRes.invoice.supplier,
                            items: invRes.data || [],
                        };
                    }
                } catch (e) { /* تخطي */ }
            }

            (res.details || []).forEach(d => {
                if (num(d.quantity) <= 0) return;

                state.customerInfo[d.customer_id] = {
                    id: d.customer_id,
                    name: d.customer_name || `#${d.customer_id}`,
                    phone: d.customer_phone || '',
                    code: d.customer_code || '',
                };

                const item = findItem(d.movement_detail_id);
                const batch = item ? batchUnitId(item) : null;
                const chosen = d.unit_id ?? batch;
                const baseQty = num(d.quantity_base) > 0 ? num(d.quantity_base) : num(d.quantity);

                state.rowsByItem[d.movement_detail_id] = state.rowsByItem[d.movement_detail_id] || [];
                state.rowsByItem[d.movement_detail_id].push({
                    rowId: nextRowId(),
                    customerId: parseInt(d.customer_id, 10),
                    typeId: d.type_id ? parseInt(d.type_id, 10) : null,
                    unitId: chosen,
                    unitWeightGrams: num(d.unit_weight) > 0 ? num(d.unit_weight) : '',
                    quantity: num(d.quantity),
                    quantityBase: baseQty,
                    price: num(d.sale_price),
                    isInvoiced: !!d.is_invoiced,
                    savedDetailId: d.id,
                });
            });

            pruneOrphanCustomers();

            const firstInvId = Object.keys(state.invoices)[0];
            state.activeInvoiceId = firstInvId ? parseInt(firstInvId, 10) : null;

            renderTabs();
            renderItems();
            renderSummary();
            toastInfo(`تم تحميل التوزيع ${res.header.code} — وضع التعديل.`);
        } catch (e) {
            toastError('فشل تحميل التوزيع: ' + e.message);
        }
    }

    $('btnCancelAll')?.addEventListener('click', resetScreen);
    $('btnNew')?.addEventListener('click', async () => {
        const ok = await confirmDialog('بدء توزيع جديد؟ سيتم فقدان البيانات الحالية إن لم تُحفظ.', {
            title: 'توزيع جديد', okText: 'بدء جديد', danger: true,
        });
        if (!ok) return;
        window.location.href = window.location.pathname;
    });

    async function resetScreen() {
        const ok = await confirmDialog('تفريغ الشاشة من كل البيانات؟', {
            title: 'تفريغ الشاشة', okText: 'تفريغ', danger: true,
        });
        if (!ok) return;

        state.distributionId = null;
        state.invoices = {};
        state.activeInvoiceId = null;
        state.customerInfo = {};
        state.rowsByItem = {};
        state.modalItemId = null;
        state.modalInvoiceId = null;
        updateModeBadge();
        if ($('distributionNotes')) $('distributionNotes').value = '';
        if ($('itemsSearch')) $('itemsSearch').value = '';
        renderTabs();
        renderItems();
        renderSummary();
        toastInfo('تم تفريغ الشاشة.');
    }

    if ($('distributionDate') && !$('distributionDate').value) {
        $('distributionDate').value = new Date().toISOString().slice(0, 10);
    }

    const urlId = new URLSearchParams(window.location.search).get('id');
    if (urlId) loadExisting(urlId);

    updateModeBadge();
    renderTabs();
    renderItems();
    renderSummary();
});
