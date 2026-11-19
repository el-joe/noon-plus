<?php

namespace App\Domains\Vendors\Enums;

/**
 * Locked profile sections a store or travel agency changes through Nawy
 * (lifecycle catalogue §7.3). Each section lists the fields it carries.
 */
enum ChangeRequestSection: string
{
    case StoreName = 'store_name';
    case LegalDetails = 'legal_details';
    case Address = 'address';
    case BankDetails = 'bank_details';

    /**
     * Field => validation rules of the requested values.
     *
     * @return array<string, array<int, string>>
     */
    public function fields(): array
    {
        return match ($this) {
            self::StoreName => ['store_name' => ['required', 'string', 'min:2', 'max:120']],
            self::LegalDetails => [
                'legal_name' => ['required', 'string', 'max:160'],
                'cr_number' => ['required', 'string', 'max:64'],
                'tax_id' => ['nullable', 'string', 'max:64'],
            ],
            self::Address => [
                'building' => ['nullable', 'string', 'max:120'],
                'street' => ['required', 'string', 'max:160'],
                'area' => ['required', 'string', 'max:120'],
                'city' => ['required', 'string', 'max:120'],
            ],
            self::BankDetails => [
                'bank_name' => ['required', 'string', 'max:120'],
                'account_name' => ['required', 'string', 'max:160'],
                'iban' => ['required', 'string', 'regex:/^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$/'],
            ],
        };
    }

    /**
     * Where the section is kept on the vendor: a column or a key of `business_details`.
     */
    public function storageKey(): ?string
    {
        return match ($this) {
            self::StoreName => null,
            self::LegalDetails => 'store',
            self::Address => 'address',
            self::BankDetails => 'bank',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::StoreName => __('Store name'),
            self::LegalDetails => __('Legal details'),
            self::Address => __('Business address'),
            self::BankDetails => __('Bank details'),
        };
    }

    public static function fieldLabel(string $field): string
    {
        return match ($field) {
            'store_name' => __('Store name'),
            'legal_name' => __('Legal name'),
            'cr_number' => __('Trade licence'),
            'tax_id' => __('Tax ID'),
            'building' => __('Building'),
            'street' => __('Street'),
            'area' => __('Area'),
            'city' => __('City'),
            'bank_name' => __('Bank name'),
            'account_name' => __('Account name'),
            'iban' => __('IBAN'),
            default => $field,
        };
    }
}
