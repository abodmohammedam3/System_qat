@extends('layouts.app')

@section('title', 'تغيير كلمة المرور')

@section('content')

<div class="container-fluid py-3">
    <div class="row justify-content-center">
        <div class="col-12 col-md-7 col-lg-5">

            <div class="d-flex align-items-center gap-2 mb-3">
                <h4 class="mb-0">
                    <i class="bi bi-shield-lock"></i>
                    تغيير كلمة المرور
                </h4>
            </div>

            @if (session('success'))
                <div class="alert alert-success py-2" role="alert">
                    <i class="bi bi-check-circle"></i> {{ session('success') }}
                </div>
            @endif

            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-body p-4">

                    <form method="POST" action="{{ route('profile.password.update') }}" novalidate>
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="current_password" class="form-label">كلمة المرور الحالية</label>
                            <input type="password" class="form-control @error('current_password') is-invalid @enderror"
                                   id="current_password" name="current_password" required autocomplete="current-password">
                            @error('current_password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">كلمة المرور الجديدة</label>
                            <input type="password" class="form-control @error('password') is-invalid @enderror"
                                   id="password" name="password" required minlength="8" autocomplete="new-password">
                            <small class="text-muted">8 أحرف على الأقل — يُفضَّل مزج حروف وأرقام ورموز</small>
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-4">
                            <label for="password_confirmation" class="form-label">تأكيد كلمة المرور الجديدة</label>
                            <input type="password" class="form-control"
                                   id="password_confirmation" name="password_confirmation" required minlength="8"
                                   autocomplete="new-password">
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg"></i> حفظ التغييرات
                            </button>
                            <a href="{{ url('/dashboard') }}" class="btn btn-outline-secondary">إلغاء</a>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

@endsection
