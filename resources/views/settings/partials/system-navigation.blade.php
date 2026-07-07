<!-- System Settings Navigation -->
<div class="mb-6 border-b border-gray-200 dark:border-gray-700">
    <nav class="flex flex-wrap gap-4 md:gap-8" aria-label="Tabs">
        <a href="{{ route('dashboard') }}" class="border-b-2 {{ request()->routeIs('dashboard') ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }} py-4 px-1 text-sm font-medium whitespace-nowrap">
            {{ __('Dashboard') }}
        </a>
        <a href="{{ route('profile.edit') }}" class="border-b-2 {{ request()->routeIs('profile.*') ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }} py-4 px-1 text-sm font-medium whitespace-nowrap">
            {{ __('Profile') }}
        </a>
        <a href="{{ route('security.edit') }}" class="border-b-2 {{ request()->routeIs('security.*') || request()->routeIs('user-password.*') ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }} py-4 px-1 text-sm font-medium whitespace-nowrap">
            {{ __('Security') }}
        </a>
        <a href="{{ route('appearance.edit') }}" class="border-b-2 {{ request()->routeIs('appearance.*') ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }} py-4 px-1 text-sm font-medium whitespace-nowrap">
            {{ __('Appearance') }}
        </a>
        @can('API-manage_api')
        <a href="{{ route('api.manager') }}" class="border-b-2 {{ request()->routeIs('api.*') ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }} py-4 px-1 text-sm font-medium whitespace-nowrap">
            {{ __('API') }}
        </a>
        @endcan
    </nav>
</div>
