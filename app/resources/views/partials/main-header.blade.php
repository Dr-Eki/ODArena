<header class="main-header">

    <!-- Logo -->
    <a href="{{ url('') }}" class="logo">
        <span class="logo-mini">OD<b>A</b></span>
        <span class="logo-lg">OD<b>&nbsp;Arena</b></span>
    </a>

    <!-- Header Navbar -->
    <nav class="navbar navbar-static-top" role="navigation">

        <!-- Sidebar toggle button -->
        <a href="#" class="sidebar-toggle" data-toggle="push-menu" role="button">
            <span class="sr-only">Toggle navigation</span>
        </a>

        <!-- Navbar Right Menu -->
        <div class="navbar-custom-menu">
            <ul class="nav navbar-nav">
                @include('partials.staff-nav')
                @include('partials.wiki-nav')
                @include('partials.scribes-nav')
                @include('partials.notification-nav')
                @include('partials.auth-user-nav')
            </ul>
        </div>

    </nav>

</header>
