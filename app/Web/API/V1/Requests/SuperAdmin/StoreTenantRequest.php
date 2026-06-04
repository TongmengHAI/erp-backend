<?php

declare(strict_types=1);

namespace App\Web\API\V1\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/super-admin/tenants
 *
 * Request shape (per Q3 — tenant + initial admin in one transaction):
 *   {
 *     "slug": "acme-trading",
 *     "name": "Acme Trading Co.",
 *     "legal_name": "Acme Trading Co., Ltd.",
 *     "country_code": "KH",
 *     "default_currency": "USD",
 *     "functional_currency": "USD",
 *     "timezone": "Asia/Phnom_Penh",
 *     "company": {
 *       "slug": "acme-trading-main",
 *       "name": "Acme Trading Main",
 *       "legal_name": "Acme Trading Co., Ltd."
 *     },
 *     "initial_admin": {
 *       "name": "Sokha Chan",
 *       "email": "sokha@acme.kh"
 *     }
 *   }
 *
 * Authorisation upstream by 'super_admin' middleware (404 for non-SA).
 *
 * Slug discipline (Q2): regex enforces kebab-case [a-z0-9]+(-[a-z0-9]+)*,
 * max 63 chars (DNS subdomain limit). Global uniqueness on tenants.slug
 * via the existing migration's unique() constraint — Rule::unique here
 * surfaces a friendly 422 BEFORE the DB violation. Triple-stack §10.4
 * with future Zod refinement on the SA Create form.
 */
final class StoreTenantRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Tenant fields
            'slug' => [
                'required',
                'string',
                'max:63',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                'unique:tenants,slug',
            ],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'default_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'functional_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['required', 'string', 'max:64'],

            // Company fields (the tenant's default company)
            'company' => ['required', 'array'],
            'company.slug' => [
                'required',
                'string',
                'max:63',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // Note: companies.slug uniqueness is per-tenant; collision
                // across tenants is allowed. The Companies migration has
                // a partial unique on (tenant_id, slug) WHERE deleted_at
                // IS NULL — that's the final-layer guard. No
                // cross-tenant validation needed here.
            ],
            'company.name' => ['required', 'string', 'max:255'],
            'company.legal_name' => ['nullable', 'string', 'max:255'],

            // Initial admin user fields. Password is auto-generated
            // server-side (one-time display); SA does not supply it.
            'initial_admin' => ['required', 'array'],
            'initial_admin.name' => ['required', 'string', 'max:255'],
            'initial_admin.email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],
        ];
    }
}
