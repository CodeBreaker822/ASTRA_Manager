<!-- Delete Permission Modal -->
<div id="deletePermissionModal" class="fixed hidden inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-xs sm:max-w-md md:max-w-lg p-4 sm:p-6 md:p-8 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center gap-3 mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Delete Permission</h3>
        </div>
        <form method="POST" id="deleteForm">
            @csrf
            <div class="mb-8">
                <span class="block text-gray-500 dark:text-gray-400 text-xs font-medium uppercase tracking-wide mb-2">Are you sure you want to delete</span>
                <span class="inline-block bg-red-100 text-red-800 text-lg font-bold px-4 py-2 rounded-lg border border-red-200 shadow-sm" id="deletePosition"></span>
            </div>
            <input type="hidden" id="deleteId">
            <div class="flex justify-end gap-2">
                <button type="button" class="px-5 py-2 deleteclose bg-gray-300 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded-lg hover:bg-gray-400 dark:hover:bg-gray-500 text-base font-semibold shadow transition">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 text-base font-semibold shadow transition">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
    $(document).ready(function () {
        $(document).on('click', '.delete', function(event) {
            $('#deletePermissionModal').toggleClass('hidden');
            var permissions = $(this).data('permissions');
            $('#deleteId').val(permissions.id);
            $('#deletePosition').text(permissions.position_name);
        });
        $('.deleteclose').click(function () {
            $('#deletePermissionModal').toggleClass('hidden');
        });

        $('#deleteForm').submit(function (e) { 
            e.preventDefault();
            
            var deleteButton = $(this).find('button[type="submit"]');
            toggleLoading(deleteButton, true);
            
            var id = $('#deleteId').val();
            
            var formData = $(this).serialize() + '&_method=DELETE';
            
            $.ajax({
                url: "{{ route('user_manage.permission.destroy', ['id' => ':id']) }}".replace(':id', id),
                type: "POST",
                data: formData,
                success: function(response) {
                    $('#deletePermissionModal').toggleClass('hidden');
                    fetchPermissions();
                    showNotification(response.message || 'Permissions deleted successfully', 'success');
                },
                error: function(xhr) {
                    const errorMsg = xhr.responseJSON?.message || 'Error deleting permissions';
                    showNotification(errorMsg, 'error');
                },
                complete: function() {
                    toggleLoading(deleteButton, false);
                }
            });
        });
    });
</script>