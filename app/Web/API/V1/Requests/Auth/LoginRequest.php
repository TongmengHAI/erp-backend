<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            // No min/max on password — login validates against stored hash, not policy.
            // (Strength policy belongs on register / change-password, not login.)
            'password' => ['required', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    public function email(): string
    {
        return (string) $this->validated()['email'];
    }

    public function password(): string
    {
        return (string) $this->validated()['password'];
    }
}
