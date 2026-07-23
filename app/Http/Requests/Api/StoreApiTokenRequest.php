<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('API-manage_api') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'app_name' => ['required', 'string', 'max:255', 'unique:a_p_i_s,app_name'],
            'app_token' => ['nullable', 'string', 'max:255'],
            'can_post' => ['sometimes', 'boolean'],
            'can_get' => ['sometimes', 'boolean'],
            'can_put' => ['sometimes', 'boolean'],
            'can_patch' => ['sometimes', 'boolean'],
            'can_delete' => ['sometimes', 'boolean'],
            'blacklisted_ips' => ['nullable', 'json'],
            'blacklisted_routes' => ['nullable', 'json'],
        ];
    }
}
