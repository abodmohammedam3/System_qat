<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تسجيل الدخول — نظام ERP</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-light d-flex align-items-center" style="min-height: 100vh;">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-8 col-md-6 col-lg-5 col-xl-4">

            <div class="text-center mb-4 mt-4">
                <span class="d-inline-flex align-items-center justify-content-center bg-success rounded-3 p-3 shadow-sm mb-3" style="width:64px;height:64px;">
                    <i class="bi bi-grid-1x2-fill text-white fs-3"></i>
                </span>
                <h4 class="fw-bold mb-1">نظام ERP</h4>
                <small class="text-muted">إدارة موارد المؤسسة — المحاسبة والمخازن</small>
            </div>

            <div class="card border-0 shadow rounded-3">
                <div class="card-body p-4">

                    <h5 class="card-title text-center mb-4">
                        <i class="bi bi-shield-lock"></i>
                        تسجيل الدخول
                    </h5>

                    @if (session('error'))
                        <div class="alert alert-danger py-2 text-center" role="alert">
                            {{ session('error') }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('login.attempt') }}" novalidate>
                        @csrf

                        <div class="mb-3">
                            <label for="email" class="form-label">البريد الإلكتروني</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control border-start-0 @error('email') is-invalid @enderror"
                                       id="email" name="email" value="{{ old('email') }}"
                                       placeholder="name@company.com" required autofocus autocomplete="username">
                                @error('email')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">كلمة المرور</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0"><i class="bi bi-key"></i></span>
                                <input type="password" class="form-control border-start-0 @error('password') is-invalid @enderror"
                                       id="password" name="password" required autocomplete="current-password"
                                       placeholder="••••••••">
                                <button type="button" class="btn btn-outline-secondary" id="togglePassword" tabindex="-1"
                                        aria-label="إظهار كلمة المرور">
                                    <i class="bi bi-eye"></i>
                                </button>
                                @error('password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                            <label class="form-check-label" for="remember">تذكرني على هذا الجهاز</label>
                        </div>

                        <button type="submit" class="btn btn-success w-100 py-2 fw-semibold">
                            <i class="bi bi-box-arrow-in-left"></i>
                            دخول
                        </button>
                    </form>
                </div>
            </div>

            <p class="text-center text-muted small mt-3 mb-4">
                جميع العمليات مسجّلة باسم المستخدم لأغراض التدقيق المحاسبي
            </p>

        </div>
    </div>
</div>

<script>
    document.getElementById('togglePassword')?.addEventListener('click', function () {
        const input = document.getElementById('password');
        const icon = this.querySelector('i');
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        icon.classList.toggle('bi-eye', !isHidden);
        icon.classList.toggle('bi-eye-slash', isHidden);
    });
</script>

</body>
</html>
