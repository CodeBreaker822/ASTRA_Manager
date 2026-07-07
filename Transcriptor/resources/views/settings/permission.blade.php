<x-layouts.app>

    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
        <h2 class="text-2xl font-bold mb-4 md:mb-0 text-gray-800 dark:text-gray-100">Permission Manager</h2>
        
        <div class="flex gap-2 items-center">
            <div class="flex items-center">
                <input type="text" id="searchPermission" class="form-control border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 rounded px-3 py-2 h-10" placeholder="Search by User ID or Permission...">
            </div>
            <button type="button" id="add" class="inline-flex justify-center w-auto h-10 px-3 py-1 bg-green-500 text-white rounded hover:bg-green-600 font-semibold">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-full h-full">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                </svg>
            </button>
        </div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded shadow p-4 overflow-x-auto">
        <table class="table table-hover align-middle w-full" id="permissionsTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Position</th>
                    <th scope="col" class="hidden md:block">Permissions</th>
                    <th scope="col" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody id="permissionsTableBody">
                
            </tbody>
        </table>
        <div class="mt-4 flex justify-end">
            <nav>
                <ul class="pagination mb-0" id="pagination">
                </ul>
            </nav>
        </div>
    </div>


@include('settings.modal.edit_permission_modal')
@include('settings.modal.delete_permission_modal')
@include('settings.modal.view_permission_modal')
@include('settings.modal.add_permission_modal')

<script>
    function fetchPermissions(){
        $.ajax({
            type: "get",
            url: "{{ route('user_manage.permission.get') }}",
            dataType: "json",
            success: function (response) {
                let data = response.data;
                var count = 0;
                let tableBody = document.getElementById('permissionsTableBody');
                tableBody.innerHTML = '';
                data.forEach(function (item) {
                    let row = document.createElement('tr');
                    count++;
                    
                    
                    let permissionsDisplay = '';
                    if (Array.isArray(item.permissions)) {
                        const maxDisplay = 3;
                        const displayPermissions = item.permissions.slice(0, maxDisplay);
                        
                        permissionsDisplay = displayPermissions.map(perm => 
                            `<span class="inline-block bg-green-100 text-green-800 text-xs font-medium px-2 py-0.5 rounded-full border border-green-200 mr-1 mb-1">${perm}</span>`
                        ).join('');
                        
                        if (item.permissions.length > maxDisplay) {
                            permissionsDisplay += `<span class="inline-block bg-blue-100 text-blue-800 text-xs font-medium px-2 py-0.5 rounded-full border border-blue-200">+${item.permissions.length - maxDisplay} more</span>`;
                        }
                    } else {
                        permissionsDisplay = '<span class="text-gray-400 italic">No permissions</span>';
                    }

                    
                    row.innerHTML = `
                        <td class="px-4 py-2 break-all text-center text-xs">${count}</td>
                        <td class="px-4 py-2 break-all text-center text-xs">${item.position_name}</td>
                        <td class="px-4 py-2 break-all text-center hidden md:block text-xs">${permissionsDisplay}</td>
                        <td class="px-4 py-2 text-center">
                            <div class="flex justify-center space-x-2">
                                <button type="button" data-permissions='${JSON.stringify(item)}' class="view p-1.5 bg-blue-100 text-blue-600 rounded-full hover:bg-blue-200 transition-colors" title="View">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </button>
                                <button type="button" data-permissions='${JSON.stringify(item)}' class="edit p-1.5 bg-green-100 text-green-600 rounded-full hover:bg-green-200 transition-colors" title="Edit">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <button type="button" data-permissions='${JSON.stringify(item)}' class="delete p-1.5 bg-red-100 text-red-600 rounded-full hover:bg-red-200 transition-colors" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </td>
                    `;
                    tableBody.appendChild(row);
                });

                if(data.length == 0){
                    tableBody.innerHTML = `<tr><td colspan="4" class="text-center text-gray-400 py-6">No permissions found.</td></tr>`;
                }
            },
            error: function (xhr, status, error) {
                showNotification('Error fetching permissions', 'error');
            }
        });
    }

    $(document).ready(function () {
        fetchPermissions();
    });
</script>


</x-layouts.app>