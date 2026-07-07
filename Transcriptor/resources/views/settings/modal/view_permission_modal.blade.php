<!-- View Permission Modal -->
<div id="viewPermissionModal" class="fixed hidden inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40 p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-xs sm:max-w-md md:max-w-lg p-4 sm:p-6 md:p-8 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center gap-3 mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405M19 13V7a2 2 0 00-2-2H7a2 2 0 00-2 2v6M7 17h10M7 17v2a2 2 0 002 2h2a2 2 0 002-2v-2" /></svg>
            <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Permission Details</h3>
        </div>
        <div class="mb-5">
            <span class="block text-gray-500 dark:text-gray-400 text-xs font-medium uppercase tracking-wide mb-1">Position</span>
            <div id="viewSelectedPosition" class="flex items-center gap-2 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800 text-blue-800 dark:text-blue-300 font-semibold text-lg shadow-sm">
                <!-- Position will be injected here -->
            </div>
        </div>
        <div class="mb-8 overflow-y-auto max-h-60">
            <span class="block text-gray-500 dark:text-gray-400 text-xs font-medium uppercase tracking-wide mb-1">Permissions</span>
            <div id="viewSelectedPermissions" class="flex flex-wrap gap-2">
                <!-- Permissions will be injected here as badges -->
            </div>
        </div>
        <div class="flex justify-end">
            <button class="px-5 py-2 viewclose bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-base font-semibold shadow transition">Close</button>
        </div>
    </div>
</div>

<script>
    $(document).ready(function () {
        $(document).on('click', '.view', function(event) {
            event.preventDefault();
            var permissionsData = $(this).data('permissions');
           
            $('#viewSelectedPosition').text(permissionsData.position_name);
   
            var permHtml = '';
            var permissionsList = permissionsData.permissions;
            
            if (permissionsList && Array.isArray(permissionsList)) {
                permissionsList.forEach(function(p) {
                    permHtml += '<span class="inline-block bg-green-100 text-green-800 text-xs font-semibold px-3 py-1 rounded-full border border-green-200 m-1">' + p + '</span>';
                });
            }
            
            if (!permHtml) {
                permHtml = '<span class="text-gray-400 italic">No permissions assigned</span>';
            }
            
            $('#viewSelectedPermissions').html(permHtml);
            
            $('#viewPermissionModal').toggleClass('hidden');
        });

        $('.viewclose').click(function () {
            $('#viewPermissionModal').toggleClass('hidden');
        });
    });
</script>
