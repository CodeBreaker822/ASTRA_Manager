<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApiMethodRequest extends FormRequest
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
            'method' => ['required', 'in:post,get,put,patch,delete'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
