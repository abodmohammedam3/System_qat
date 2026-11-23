<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Screen;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * إدارة المستخدمين (الفحص F-01 — الموديول المفقود بالكامل).
 *
 * متاح لمدير النظام فقط (ميدلوير admin على المسارات). يشمل: إنشاء،
 * تعديل الدور/الاسم/البريد، تفعيل/تعطيل، إعادة تعيين كلمة المرور، وحذف.
 * الحماية المطبقة: منع تعطيل/تخفيض آخر مدير، ومنع حذف/تعطيل النفس،
 * وإعادة تعيين كلمة مرور آلية (عشوائية) تعرض مرة واحدة.
 */
class UserController extends Controller
{
    /**
     * الإصلاح: شاشة المستخدمين أصبحت تاباً داخل شاشة إعدادات النظام
     * (تم نقلها من القائمة الجانبية بناء على الطلب) — نفس العرض
     * مع activeTab=users لتفعيل التاب الصحيح.
     */
    public function index(): \Illuminate\View\View
    {
        return view('settings.system.index', ['activeTab' => 'users']);
    }

    public function list(): JsonResponse
    {
        $users = User::query()
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'last_login_at', 'created_at']);

        return response()->json([
            'success' => true,
            'users'   => $users,
            'roles'   => [
                ['value' => User::ROLE_ADMIN,      'label' => 'مدير النظام'],
                ['value' => User::ROLE_ACCOUNTANT, 'label' => 'محاسب'],
                ['value' => User::ROLE_VIEWER,     'label' => 'مشاهد فقط'],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', 'string', 'max:100'],
        ], [
            'role.required' => 'الدور مطلوب',
        ]);

        // الدور الديناميكي: يقبل الاسم المعروض أو الـ slug (توافق خلفي)
        $role = Role::where('name', $validated['role'])->first()
            ?? Role::where('slug', $validated['role'])->first();

        if (! $role) {
            throw ValidationException::withMessages([
                'role' => 'لا يوجد دور بهذا الاسم — أنشئه أولاً بزر «دور جديد»',
            ]);
        }

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => Hash::make($validated['password']),
            'role'      => $role->slug,
            'role_id'   => $role->id, // الربط الديناميكي (المستوى الثاني)
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء المستخدم بنجاح',
            'user'    => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
        ], 201);
    }

    // =========================================================
    // المستوى الثاني — الشاشات والأدوار (ديناميكي بالكامل)
    // =========================================================

    /**
     * بيانات مصفوفة الشاشات: صفوف الشاشات (كل شاشات النظام من
     * config/screens.php) تحت كل مجموعة، ولكل دور حالته على كل شاشة
     * (view/create/update/delete/print/hidden) من جدول role_screen.
     */
    public function rolesMatrix(): JsonResponse
    {
        $roles = Role::query()
            ->with('screens:id,key')
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'is_system'])
            ->map(fn (Role $r) => [
                'id'        => $r->id,
                'slug'      => $r->slug,
                'name'      => $r->name,
                'is_system' => (bool) $r->is_system,
                'locked'    => $r->slug === 'admin',
                // خريطة screenKey => حالة pivot
                'screens'   => $r->screens->mapWithKeys(
                    fn (Screen $s) => [$s->key => [
                        'view'   => (bool) $s->pivot->view,
                        'create' => (bool) $s->pivot->create,
                        'update' => (bool) $s->pivot->update,
                        'delete' => (bool) $s->pivot->delete,
                        'print'  => (bool) $s->pivot->print,
                        'hidden' => (bool) $s->pivot->hidden,
                    ]]
                )->all(),
            ]);

        // الشاشات مجمّعة حسب group كما تظهر في السايدبار
        $screens = Screen::query()
            ->orderBy('sort')
            ->get(['id', 'key', 'name', 'group', 'locked'])
            ->groupBy('group')
            ->map(fn ($items, $group) => [
                'group'   => $group,
                'screens' => $items->map(fn (Screen $s) => [
                    'key'    => $s->key,
                    'name'   => $s->name,
                    'locked' => (bool) $s->locked,
                ]),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'roles'   => $roles,
            'groups'  => $screens,
        ]);
    }

    /**
     * حفظ حالة مربع واحد (حفظ تلقائي عند كل نقرة).
     * حماية: مدير النظام مقفل دائماً، والشاشات المقفلة لا تُخفى.
     */
    public function updateRoleScreen(Request $request, Role $role): JsonResponse
    {
        $validated = $request->validate([
            'screen' => ['required', 'string', 'exists:screens,key'],
            'field'  => ['required', Rule::in(['view', 'create', 'update', 'delete', 'print', 'hidden'])],
            'value'  => ['required', 'boolean'],
        ]);

        // صلاحيات مدير النظام التشغيلية ثابتة (يملك دائماً: عرض، إضافة، تعديل، حذف، طباعة)
        // لكن يُسمح له بالتحكم في إخفاء/إظهار الشاشة (hidden) عن القائمة إذا رغب في ذلك
        if ($role->slug === 'admin' && $validated['field'] !== 'hidden') {
            return response()->json([
                'success' => false,
                'message' => 'صلاحيات مدير النظام ثابتة — يملك كل الصلاحيات التشغيلية دائماً',
            ], 422);
        }

        $screen = Screen::where('key', $validated['screen'])->firstOrFail();

        if ($screen->locked && $validated['field'] === 'hidden') {
            return response()->json([
                'success' => false,
                'message' => "الشاشة «{$screen->name}» أساسية ولا يمكن إخفاؤها",
            ], 422);
        }

        $role->screens()->syncWithoutDetaching([
            $screen->id => [$validated['field'] => $validated['value']],
        ]);

        return response()->json(['success' => true]);
    }

    // =========================================================
    // إدارة الأدوار — إنشاء/تعديل/حذف (المستوى الثاني)
    // =========================================================

    public function storeRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
        ], [
            'name.required' => 'اسم الدور مطلوب',
            'name.unique'   => 'يوجد دور بهذا الاسم بالفعل',
        ]);

        $role = Role::create([
            'name'      => $validated['name'],
            'slug'      => str($validated['name'])->slug('_')->toString() ?: 'role_'.time(),
            'is_system' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => "تم إنشاء الدور «{$role->name}» — فعّل صلاحياته من المصفوفة",
            'role'    => ['id' => $role->id, 'slug' => $role->slug, 'name' => $role->name],
        ], 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        if ($role->slug === 'admin') {
            return response()->json(['success' => false, 'message' => 'لا يمكن تعديل دور مدير النظام'], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id)],
        ]);

        $role->update(['name' => $validated['name']]);

        return response()->json(['success' => true, 'message' => 'تم تحديث اسم الدور بنجاح']);
    }

    public function destroyRole(Role $role): JsonResponse
    {
        if ($role->is_system || $role->slug === 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف أدوار النظام الثابتة',
            ], 422);
        }

        if ($role->users()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف دور مرتبط بمستخدمين — انقلهم لدور آخر أولاً',
            ], 422);
        }

        $role->delete();

        return response()->json(['success' => true, 'message' => 'تم حذف الدور بنجاح']);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'role'  => ['required', 'string', 'max:100'],
        ]);

        // الدور الديناميكي: يقبل الاسم المعروض أو الـ slug (توافق خلفي)
        $role = Role::where('name', $validated['role'])->first()
            ?? Role::where('slug', $validated['role'])->first();

        if (! $role) {
            throw ValidationException::withMessages([
                'role' => 'لا يوجد دور بهذا الاسم — أنشئه أولاً بزر «دور جديد»',
            ]);
        }

        // منع تخفيض آخر مدير في النظام
        if (
            $user->isAdmin()
            && $role->slug !== User::ROLE_ADMIN
            && User::where('role', User::ROLE_ADMIN)->where('is_active', true)->where('id', '!=', $user->id)->count() === 0
        ) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تخفيض آخر مدير نظام — أنشئ مديراً آخر أولاً',
            ], 422);
        }

        $user->update([
            'name'    => $validated['name'],
            'email'   => $validated['email'],
            'role'    => $role->slug,
            'role_id' => $role->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث المستخدم بنجاح',
        ]);
    }

    public function toggle(User $user): JsonResponse
    {
        $requester = request()->user();

        // منع تعطيل النفس
        if ($user->id === $requester->id) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك تعطيل حسابك الشخصي',
            ], 422);
        }

        // منع تعطيل آخر مدير نشط
        if (
            $user->isAdmin()
            && $user->is_active
            && User::where('role', User::ROLE_ADMIN)->where('is_active', true)->where('id', '!=', $user->id)->count() === 0
        ) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تعطيل آخر مدير نظام',
            ], 422);
        }

        $user->update(['is_active' => !$user->is_active]);

        return response()->json([
            'success' => true,
            'message' => $user->is_active ? 'تم تفعيل الحساب' : 'تم تعطيل الحساب',
            'is_active' => (bool) $user->is_active,
        ]);
    }

    /**
     * إعادة تعيين كلمة المرور بكلمة عشوائية آمنة — تُعرض مرة واحدة
     * ويُسجل إلزام تغييرها من شاشة "تغيير كلمة المرور".
     */
    public function resetPassword(User $user): JsonResponse
    {
        $newPassword = \Illuminate\Support\Str::password(14, symbols: true);

        $user->forceFill([
            'password' => Hash::make($newPassword),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'تم إعادة تعيين كلمة المرور — انسخها الآن، لن تُعرض مرة أخرى',
            'password' => $newPassword,
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $requester = request()->user();

        if ($user->id === $requester->id) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك حذف حسابك الشخصي',
            ], 422);
        }

        if (
            $user->isAdmin()
            && User::where('role', User::ROLE_ADMIN)->where('is_active', true)->where('id', '!=', $user->id)->count() === 0
        ) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن حذف آخر مدير نظام',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المستخدم',
        ]);
    }
}
