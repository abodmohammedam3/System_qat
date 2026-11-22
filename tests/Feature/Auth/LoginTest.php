<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * اختبارات نظام المصادقة (الفحص F-01).
 *
 * يغطي:
 *  1. الزائر يُحوَّل من الداشبورد إلى صفحة الدخول
 *  2. صفحة الدخول متاحة للزائر
 *  3. دخول ناجح بمدير → تحويل للداشبورد
 *  4. كلمة مرور خاطئة → خطأ (لا دخول)
 *  5. حساب معطل → ممنوع من الدخول
 *  6. المدير يرى شاشة المستخدمين، والمحاسب ممنوع (403)
 *  7. النسخ الاحتياطي ممنوع لغير المدير (F-02)
 *  8. طلب JSON للزائر → 401 JSON
 */
class LoginTest extends TestCase
{
    use RefreshDatabase;

    /** اختبارات المصادقة تختبر سلوك الزائر والمسجلين — بلا دخول تلقائي */
    protected bool $autoAuthenticate = false;

    protected function makeAdmin(): User
    {
        return User::create([
            'name'      => 'Admin',
            'email'     => 'admin@erp.local',
            'password'  => 'Admin@12345',
            'role'      => User::ROLE_ADMIN,
            'is_active' => true,
        ]);
    }

    public function test_guest_is_redirected_from_dashboard_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_login_page_is_public(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_admin_can_login_with_correct_credentials(): void
    {
        $this->makeAdmin();

        $response = $this->post(route('login.attempt'), [
            'email'    => 'admin@erp.local',
            'password' => 'Admin@12345',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeAdmin();

        $this->post(route('login.attempt'), [
            'email'    => 'admin@erp.local',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_login(): void
    {
        $user = $this->makeAdmin();
        $user->update(['is_active' => false]);

        $this->post(route('login.attempt'), [
            'email'    => 'admin@erp.local',
            'password' => 'Admin@12345',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_only_admin_can_access_users_screen(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)
            ->getJson('/settings/users/list')
            ->assertOk()
            ->assertJsonPath('success', true);

        $accountant = User::create([
            'name' => 'Accountant', 'email' => 'acc@test.local',
            'password' => 'secret1234', 'role' => User::ROLE_ACCOUNTANT, 'is_active' => true,
        ]);

        $this->actingAs($accountant)
            ->getJson('/settings/users/list')
            ->assertStatus(403);
    }

    public function test_backup_screen_denied_for_non_admin(): void
    {
        $accountant = User::create([
            'name' => 'Accountant2', 'email' => 'acc2@test.local',
            'password' => 'secret1234', 'role' => User::ROLE_ACCOUNTANT, 'is_active' => true,
        ]);

        $this->actingAs($accountant)
            ->getJson('/settings/system/backup')
            ->assertStatus(403);
    }

    public function test_unauthenticated_json_request_gets_401_json(): void
    {
        $this->getJson('/settings/system/backup')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }
}
