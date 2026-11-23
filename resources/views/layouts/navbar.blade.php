<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary shadow-sm px-3 py-2">
    <div class="container-fluid p-0 d-flex justify-content-between align-items-center">

        {{-- القسم الأيسر: زر القائمة الجانبية (للجوال) + هوية النظام --}}
        <div class="d-flex align-items-center gap-2">
            {{-- زر القائمة الجانبية (يظهر للجوال فقط d-lg-none) --}}
            <button type="button" class="btn btn-dark border border-secondary d-lg-none" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" aria-label="فتح القائمة">
                <i class="bi bi-list fs-4"></i>
            </button>

            {{-- هوية النظام (المربع يمثل الأيقونة، والمستطيل يمثل الاسم) --}}
            <a href="{{ url('/dashboard') }}" class="navbar-brand text-white d-flex align-items-center gap-2 fw-bold mb-0 p-0">
                <span class="d-flex align-items-center justify-content-center bg-success rounded-2 p-2 shadow-sm">
                    <i class="bi bi-grid-1x2-fill"></i>
                </span>
                <span class="d-none d-sm-inline">وكالة هاشم عبده فارع البراق</span>
            </a>
        </div>

        {{-- القسم الأيمن: قائمة المستخدم + زر طي القائمة --}}
        <div class="d-flex align-items-center gap-2">

            {{-- زر طي/فرد القائمة الجانبية (بدون حدود، صغير الحجم) --}}
            <button type="button" class="btn btn-link text-white p-1 d-none d-lg-flex" id="sidebarToggle" aria-label="طي القائمة الجانبية">
                <i class="bi bi-layout-sidebar-inset"></i>
            </button>

            {{-- قائمة المستخدم (الفحص F-01) --}}
            <div class="dropdown">
                <button type="button" class="btn btn-link text-white text-decoration-none d-flex align-items-center gap-2 p-1"
                        id="userMenuBtn" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="d-flex align-items-center justify-content-center bg-success rounded-circle text-white"
                          style="width: 34px; height: 34px;">
                        <i class="bi bi-person-fill"></i>
                    </span>
                    <span class="d-none d-md-inline text-white">{{ auth()->user()?->name }}</span>
                    <i class="bi bi-chevron-down small"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userMenuBtn">
                    <li class="px-3 py-1">
                        <span class="d-block fw-semibold">{{ auth()->user()?->name }}</span>
                        <small class="text-muted" dir="ltr">{{ auth()->user()?->email }}</small>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="{{ route('profile.password.show') }}">
                            <i class="bi bi-shield-lock me-1"></i> تغيير كلمة المرور
                        </a>
                    </li>
                    @if(auth()->user()?->isAdmin())
                        <li>
                            <a class="dropdown-item" href="{{ url('/settings/users') }}">
                                <i class="bi bi-people me-1"></i> إدارة المستخدمين والأدوار
                            </a>
                        </li>
                    @endif
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger">
                                <i class="bi bi-box-arrow-right me-1"></i> تسجيل الخروج
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>

    </div>
</nav>
