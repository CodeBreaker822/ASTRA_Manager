<!-- API Form Modal -->
<div id="apiModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:max-w-3xl shadow-lg rounded-md bg-white dark:bg-gray-800 dark:border-gray-700">
        <div class="flex justify-between items-center pb-3">
            <h3 class="text-xl font-semibold dark:text-gray-100">Add New API</h3>
            <button onclick="closeApiModal()" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
                <span class="text-2xl">&times;</span>
            </button>
        </div>
        
        <form id="apiForm" class="space-y-6 mt-4">
            @csrf
            <input type="hidden" name="id" id="api_id">
            
            <!-- App Name Field -->
            <div>
                <label for="app_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">App Name</label>
                <input type="text" name="app_name" id="app_name" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50" placeholder="Enter application name" required>
            </div>
            
            <!-- License Key Field -->
            <div>
                <label for="app_token" class="block text-sm font-medium text-gray-700 dark:text-gray-300">License Key</label>
                <div class="flex">
                    <input type="text" name="app_token" id="app_token" class="mt-1 block w-full rounded-l-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50 font-mono text-sm" placeholder="License key will be generated automatically" readonly>
                    <button type="button" id="generateToken" class="bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-800 dark:text-gray-200 font-semibold py-2 px-4 rounded-r-md">
                        Generate Key
                    </button>
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Use this value as the Bearer license key for standalone apps.</p>
            </div>
            
            <!-- HTTP Methods Toggle Buttons -->
            <div>
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-3">HTTP Methods</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- POST Toggle -->
                    <div class="flex items-center">
                        <label for="can_post" class="inline-flex relative items-center cursor-pointer mr-3">
                            <input type="checkbox" name="can_post" id="can_post" class="sr-only peer" value="1">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                        <span class="text-gray-700 dark:text-gray-300 font-medium">POST</span>
                    </div>
                    
                    <!-- GET Toggle -->
                    <div class="flex items-center">
                        <label for="can_get" class="inline-flex relative items-center cursor-pointer mr-3">
                            <input type="checkbox" name="can_get" id="can_get" class="sr-only peer" value="1">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                        <span class="text-gray-700 dark:text-gray-300 font-medium">GET</span>
                    </div>
                
                    <!-- PUT Toggle -->
                    <div class="flex items-center">
                        <label for="can_put" class="inline-flex relative items-center cursor-pointer mr-3">
                            <input type="checkbox" name="can_put" id="can_put" class="sr-only peer" value="1">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                        <span class="text-gray-700 dark:text-gray-300 font-medium">PUT</span>
                    </div>
                    
                    <!-- PATCH Toggle -->
                    <div class="flex items-center">
                        <label for="can_patch" class="inline-flex relative items-center cursor-pointer mr-3">
                            <input type="checkbox" name="can_patch" id="can_patch" class="sr-only peer" value="1">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                        <span class="text-gray-700 dark:text-gray-300 font-medium">PATCH</span>
                    </div>
                    
                    <!-- DELETE Toggle -->
                    <div class="flex items-center">
                        <label for="can_delete" class="inline-flex relative items-center cursor-pointer mr-3">
                            <input type="checkbox" name="can_delete" id="can_delete" class="sr-only peer" value="1">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                        </label>
                        <span class="text-gray-700 dark:text-gray-300 font-medium">DELETE</span>
                    </div>
                </div>
            </div>
            
            <!-- Blacklisted IPs Field -->
            <div>
                <label for="blacklisted_ips" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Blacklisted IPs</label>
                <div class="mt-1">
                    <textarea name="blacklisted_ips" id="blacklisted_ips" rows="3" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50" placeholder="Enter IP addresses separated by commas"></textarea>
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Enter IP addresses that should be blocked from accessing the API (e.g., 192.168.1.1, 10.0.0.1)</p>
            </div>
            
            <!-- Blacklisted Routes Field -->
            <div>
                <label for="blacklisted_routes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Blacklisted Routes</label>
                <div class="mt-1">
                    <textarea name="blacklisted_routes" id="blacklisted_routes" rows="3" class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50" placeholder="Enter routes separated by commas"></textarea>
                </div>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Enter routes that should be blocked from access (e.g., /api/users, /api/admin)</p>
            </div>
            
            <div class="flex justify-end space-x-3 pt-4 border-t dark:border-gray-700 mt-6">
                <button type="button" onclick="closeApiModal()" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Save API
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    $(document).ready(function () {
        $('#generateToken').on('click', function() {
            const button = $(this);
            const originalText = button.text();

            button.prop('disabled', true).text('Generating...');

            $.ajax({
                url: '{{ route('api.generate-license-key') }}',
                type: 'POST',
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    $('#app_token').val(response.license_key || '');
                    showNotification('Secure license key generated', 'success');
                },
                error: function(xhr) {
                    showNotification(xhr.responseJSON?.message || 'Failed to generate license key', 'error');
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        $('#apiForm').on('submit', function(e) {
            e.preventDefault();
            
            const form = $(this);
            const formData = new FormData(this);
            const submitBtn = form.find('button[type="submit"]');
            const originalBtnText = submitBtn.html();
            
            // Show loading state
            submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...');
            
            // Process blacklisted IPs and routes
            const blacklistedIps = $('#blacklisted_ips').val().split(',').map(ip => ip.trim()).filter(ip => ip !== '');
            const blacklistedRoutes = $('#blacklisted_routes').val().split(',').map(route => route.trim()).filter(route => route !== '');
            
            formData.set('blacklisted_ips', JSON.stringify(blacklistedIps));
            formData.set('blacklisted_routes', JSON.stringify(blacklistedRoutes));
            
            $.ajax({
                url: '{{ route('api.store') }}',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function(response) {
                    showNotification('API settings saved successfully!', 'success');
                    closeApiModal();
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                },
                error: function(xhr) {
                    let errorMessage = 'An error occurred while saving the API settings.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    } else if (xhr.status === 422) {
                        errorMessage = 'Validation error. Please check your input.';
                        // Display validation errors if any
                        const errors = xhr.responseJSON.errors;
                        if (errors) {
                            Object.keys(errors).forEach(field => {
                                const errorMessages = errors[field].join('<br>');
                                $(`#${field}-error`).html(errorMessages).removeClass('hidden');
                            });
                        }
                    }
                    showNotification(errorMessage, 'error');
                },
                complete: function() {
                    // Re-enable button and restore original text
                    submitBtn.prop('disabled', false).html(originalBtnText);
                }
            });
        });
        
    });
</script>
