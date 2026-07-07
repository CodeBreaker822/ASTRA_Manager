<!-- Edit Permission Modal -->
<div id="editPermissionModal" class="fixed hidden inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-4 sm:p-6 md:p-8 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center gap-3 mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
            <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Edit Permission</h3>
        </div>
        <form id="editForm">
            @csrf
            <input type="hidden" id="editId" name="id">
            <div class="mb-5">
                <span class="block text-gray-500 dark:text-gray-400 text-xs font-medium uppercase tracking-wide mb-1">Position</span>
                <input type="text" id="editPosition" class="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 focus:ring-2 focus:ring-green-200 focus:border-green-400 text-gray-700 dark:text-gray-200 font-semibold text-lg shadow-sm" readonly>
            </div>
            <div class="mb-8">
                <span class="block text-gray-500 dark:text-gray-400 text-xs font-medium uppercase tracking-wide mb-1">Permissions</span>
                
                <!-- Permissions by Category -->
                <div class="max-h-60 overflow-y-auto border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 p-4">
                    <!-- Select All Checkbox -->
                    <div class="flex items-center mb-4 pb-2 border-b border-gray-300 dark:border-gray-600">
                        <input type="checkbox" id="edit-select-all" class="edit-checkbox w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500">
                        <label for="edit-select-all" class="ml-2 font-semibold text-gray-800 dark:text-gray-200">Authorize All</label>
                    </div>
                    
                    @foreach ($gates as $category => $permissions)
                        @php($categoryKey = \Illuminate\Support\Str::slug($category, '_'))
                        <div class="mb-4">
                            <!-- Category Header with Toggle -->
                            <div class="flex items-center justify-between mb-2 cursor-pointer edit-category-header" data-category="{{ $categoryKey }}">
                                <div class="flex items-center">
                                    <input type="checkbox" id="edit-category_{{ $categoryKey }}" class="edit-category-checkbox w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500" onclick="event.stopPropagation();">
                                    <label for="edit-category_{{ $categoryKey }}" class="ml-2 font-semibold text-gray-700 dark:text-gray-300 cursor-pointer">{{ $category }}</label>
                                </div>
                                <svg class="edit-toggle-icon w-5 h-5 text-gray-500 transform transition-transform duration-200 rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </div>
                            
                            <div class="pl-6 edit-permissions-container" data-category="{{ $categoryKey }}" style="display: none;">
                                @foreach ($permissions as $permission)
                                    <div class="flex items-center mb-2">
                                        <input type="checkbox" id="edit-perm_{{ $permission['name'] }}" name="permissions[]" value="{{ $permission['name'] }}" class="edit-permission-checkbox w-4 h-4 text-green-600 border-gray-300 rounded focus:ring-green-500" data-category="{{ $categoryKey }}">
                                        <label for="edit-perm_{{ $permission['name'] }}" class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">{{ $permission['label'] ?? $permission['name'] }}</label>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="px-5 py-2 editclose bg-gray-300 dark:bg-gray-600 text-gray-800 dark:text-gray-200 rounded-lg hover:bg-gray-400 dark:hover:bg-gray-500 text-base font-semibold shadow transition">Cancel</button>
                <button type="submit" class="px-5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-base font-semibold shadow transition">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    $(document).ready(function () {
        $(document).on('click', '.edit', function(event) {
            event.preventDefault();
            $('#editPermissionModal').toggleClass('hidden');
            var permissions = $(this).data('permissions');
            
            // Reset form and checkboxes
            $('#editForm')[0].reset();
            $('.edit-permission-checkbox, .edit-category-checkbox, #edit-select-all').prop('checked', false);
            
            // Set basic info
            $('#editId').val(permissions.id);
            $('#editPosition').val(permissions.position_name);
            
            // Parse permissions array
            var permissionArray = permissions.permissions;
            
            // Check the appropriate permission checkboxes
            permissionArray.forEach(function(permission) {
                // Trim any whitespace from the permission name
                permission = permission.trim();
                $('.edit-permission-checkbox').each(function() {
                    if ($(this).val() === permission) {
                        $(this).prop('checked', true);
                    }
                });
            });
            
            updateEditCategoryCheckboxes();
            
            updateEditSelectAllCheckbox();
        });

        $('.editclose').click(function () {
            $('#editPermissionModal').toggleClass('hidden');
        });
        
        // Select All functionality
        $('#edit-select-all').change(function() {
            var isChecked = $(this).prop('checked');
            $('.edit-permission-checkbox, .edit-category-checkbox').prop('checked', isChecked);
        });
        
        // Category checkbox functionality
        $('.edit-category-checkbox').change(function() {
            var category = $(this).attr('id').replace('edit-category_', '');
            var isChecked = $(this).prop('checked');
            
            // Select/deselect all permissions in this category
            $('.edit-permission-checkbox[data-category="' + category + '"]').prop('checked', isChecked);
            
            // Update select all checkbox
            updateEditSelectAllCheckbox();
        });
        
        // Individual permission checkbox functionality
        $('.edit-permission-checkbox').change(function() {
            var category = $(this).data('category');
            
            // Check if all permissions in this category are checked
            var allChecked = true;
            $('.edit-permission-checkbox[data-category="' + category + '"]').each(function() {
                if (!$(this).prop('checked')) {
                    allChecked = false;
                    return false; // Break the loop
                }
            });
            
            // Update category checkbox
            $('#edit-category_' + category).prop('checked', allChecked);
            
            // Update select all checkbox
            updateEditSelectAllCheckbox();
        });
        
        // Function to update category checkboxes based on permission selections
        function updateEditCategoryCheckboxes() {
            $('.edit-category-checkbox').each(function() {
                var category = $(this).attr('id').replace('edit-category_', '');
                var allChecked = true;
                
                $('.edit-permission-checkbox[data-category="' + category + '"]').each(function() {
                    if (!$(this).prop('checked')) {
                        allChecked = false;
                        return false; // Break the loop
                    }
                });
                
                $(this).prop('checked', allChecked);
            });
        }
        
        // Function to update the select all checkbox
        function updateEditSelectAllCheckbox() {
            var allChecked = true;
            $('.edit-permission-checkbox').each(function() {
                if (!$(this).prop('checked')) {
                    allChecked = false;
                    return false; // Break the loop
                }
            });
            
            $('#edit-select-all').prop('checked', allChecked);
        }
        
        // Category toggle functionality
        $('.edit-category-header').click(function() {
            var category = $(this).data('category');
            var container = $('.edit-permissions-container[data-category="' + category + '"]');
            var icon = $(this).find('.edit-toggle-icon');
            
            // Toggle visibility
            container.slideToggle(200);
            
            // Rotate icon
            if (icon.hasClass('rotate-180')) {
                icon.removeClass('rotate-180');
            } else {
                icon.addClass('rotate-180');
            }
        });
        
        $('#editForm').submit(function(e) {
            e.preventDefault();
            
            var editButton = $(this).find('button[type="submit"]');
            
            toggleLoading(editButton, true);
            
            var formData = $(this).serialize();
            console.log(formData);
            var id = $('#editId').val();
            
            $.ajax({
                url: "{{ route('user_manage.permission.update', ['id' => ':id']) }}".replace(':id', id),
                type: "PUT",
                data: formData,
                success: function(response) {
                    $('#editPermissionModal').toggleClass('hidden');
                    fetchPermissions();
                    showNotification(response.message || 'Permission updated successfully', 'success');
                },
                error: function(xhr) {
                    const errorMsg = xhr.responseJSON?.message || 'Error updating permission';
                    showNotification(errorMsg, 'error');
                },
                complete: function() {
                    toggleLoading(editButton, false);
                }
            });
        });
    });
</script>

