{{-- $notification is composed on by AppServiceProvider. --}}
@if ($notification)
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            window.showNotification(@json($notification['message']), @json($notification['type']));
        });
    </script>
@endif
