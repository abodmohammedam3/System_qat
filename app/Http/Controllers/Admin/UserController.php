<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
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
    public function index(): JsonResponse
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

    public function list(): JsonResponse
    {
        return $this->index();
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'string', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_ACCOUNTANT, User::ROLE_VIEWER])],
        ], [
            'name.required'     => 'الاسم مطلوب',
            'email.required'    => 'البريد الإلكتروني مطلوب',
            'email.email'       => 'صيغة البريد الإلكتروني غير صحيحة',
            'email.unique'      => 'البريد الإلكتروني مستخدم بالفعل',
            'password.required' => 'كلمة المرور مطلوبة',
            'password.min'      => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل',
            'role.required'     => 'الدور مطلوب',
        ]);

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => Hash::make($validated['password']),
            'role'      => $validated['role'],
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء المستخدم بنجاح',
            'user'    => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'role'  => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_ACCOUNTANT, User::ROLE_VIEWER])],
        ]);

        // منع تخفيض آخر مدير في النظام
        if (
            $user->isAdmin()
            && $validated['role'] !== User::ROLE_ADMIN
            && User::where('role', User::ROLE_ADMIN)->where('is_active', true)->where('id', '!=', $user->id)->count() === 0
        ) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تخفيض آخر مدير نظام — أنشئ مديراً آخر أولاً',
            ], 422);
        }

        $user->update([
            'name'  => $validated['name'],
            'email' => $validated['email'],
            'role'  => $validated['role'],
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
