<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * مصادقة النظام (الفحص F-01): تسجيل دخول محمي بمحدد معدل،
 * وتسجيل خروج بتجديد الجلسة، وتغيير كلمة المرور للشخصية.
 */
class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required'    => 'البريد الإلكتروني مطلوب',
            'email.email'       => 'صيغة البريد الإلكتروني غير صحيحة',
            'password.required' => 'كلمة المرور مطلوبة',
        ]);

        // محدد المعدل: 5 محاولات لكل دقيقة لكل بريد+IP — يمنع التخمين الآلي
        $throttleKey = strtolower($credentials['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()->withErrors([
                'email' => "محاولات كثيرة — أعد المحاولة بعد {$seconds} ثانية",
            ])->onlyInput('email');
        }

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            return back()->withErrors([
                'email' => 'بيانات الدخول غير صحيحة',
            ])->onlyInput('email');
        }

        if (!$user->is_active) {
            return back()->withErrors([
                'email' => 'تم تعطيل هذا الحساب — راجع مدير النظام',
            ])->onlyInput('email');
        }

        RateLimiter::clear($throttleKey);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // =========================================================
    // تغيير كلمة المرور الشخصية
    // =========================================================

    public function showChangePassword(): View
    {
        return view('auth.change-password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.required' => 'كلمة المرور الحالية مطلوبة',
            'password.required'         => 'كلمة المرور الجديدة مطلوبة',
            'password.min'              => 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل',
            'password.confirmed'        => 'تأكيد كلمة المرور لا يتطابق',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return back()->withErrors([
                'current_password' => 'كلمة المرور الحالية غير صحيحة',
            ]);
        }

        // منع استخدام نفس كلمة المرور الحالية
        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'كلمة المرور الجديدة يجب أن تختلف عن الحالية',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        // تجديد الجلسة بعد تغيير كلمة المرور (أمان)
        $request->session()->regenerate();

        return back()->with('success', 'تم تغيير كلمة المرور بنجاح');
    }
}
