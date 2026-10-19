<?php

namespace App\Support\Settings;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use App\Enums\TaxType;

/**
 * SettingCatalog
 *
 * The only place a global settings key is born. Controllers, seeders and
 * Form Requests look keys up here; a payload that names an unknown key is
 * rejected. Allowing a form to invent keys would be a code-injection surface.
 *
 * Defaults match what the shop already runs in config/annigems.php so the
 * first deploy does not silently change tax, numbering or the currency symbol.
 */
class SettingCatalog
{
    /**
     * Every global key, in display order within its group.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $keys = [];

        foreach (self::definitions() as $definition) {
            $keys[$definition['key']] = $definition;
        }

        return $keys;
    }

    /**
     * One key, or null when the slug is unknown.
     *
     * @return ?array<string, mixed>
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Keys that belong to one SuperAdmin save group.
     *
     * @return list<array<string, mixed>>
     */
    public static function forGroup(SettingGroup $group): array
    {
        return array_values(array_filter(
            self::definitions(),
            fn (array $definition): bool => $definition['group'] === $group
        ));
    }

    /**
     * Catalog entries in the order the SuperAdmin screen stacks the cards.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::string('company_name', SettingGroup::COMPANY, 'Aanigemas', 'Appears on invoices, labels and public pages.', ['required', 'string', 'max:80']),
            self::string('company_tax_id', SettingGroup::COMPANY, '', 'NIF / CIF printed under the company name on documents.', ['nullable', 'string', 'max:30']),

            self::toggle('stone_barcode_enabled', SettingGroup::FEATURES, true, 'Generate a barcode when a stone is registered.'),
            self::toggle('jewellery_barcode_enabled', SettingGroup::FEATURES, true, 'Generate a barcode when a jewellery piece is created.'),
            self::toggle('jewellery_qr_enabled', SettingGroup::FEATURES, true, 'Issue a public QR token on new jewellery pieces.'),
            self::toggle('invoice_qr_enabled', SettingGroup::FEATURES, true, 'Issue a verification QR and token on new invoices.'),
            self::toggle('public_product_page_enabled', SettingGroup::FEATURES, true, 'New jewellery pieces become reachable on the public product page.'),
            self::toggle('public_invoice_verification_enabled', SettingGroup::FEATURES, true, 'New invoices become reachable on the public verification page.'),

            // The rates themselves (IVA, Recargo de equivalencia) are managed
            // under Tax Rates; this switch turns tax on or off everywhere.
            self::toggle('tax_enabled', SettingGroup::TAX, true, 'Apply tax on quotations and invoices. The IVA and Recargo rates are managed under Tax Rates. Existing invoices keep the tax they stored.'),

            self::toggle('discount_enabled', SettingGroup::DISCOUNT, true, 'Show the discount control on quotations and invoices.'),
            self::decimal('max_discount_percentage', SettingGroup::DISCOUNT, '100', 'Maximum discount on any quotation or invoice, including Admin.', ['required', 'numeric', 'min:0', 'max:100'], parent: 'discount_enabled'),

            self::prefix('numbering_jewellery_prefix', 'JWL-', 'Prefix on new jewellery item codes.'),
            self::prefix('numbering_stone_prefix', 'STN-', 'Prefix on new stone codes.'),
            self::prefix('numbering_customer_prefix', 'CUS-', 'Prefix on new customer codes.'),
            self::prefix('numbering_quotation_prefix', 'QTN-', 'Prefix on new quotation numbers.'),
            self::prefix('numbering_invoice_prefix', 'INV-', 'Prefix on new invoice numbers.'),
            self::prefix('numbering_performa_prefix', 'PRF-', 'Prefix on new performa numbers.'),
            self::prefix('numbering_delivery_note_prefix', 'ALB-', 'Prefix on new delivery note (albarán) numbers.'),
            self::prefix('numbering_creditor_prefix', 'CRD-', 'Prefix on new creditor codes.'),
            self::prefix('numbering_intake_prefix', 'SIN-', 'Prefix on new stone intake numbers.'),
            self::integer('numbering_padding', SettingGroup::NUMBERING, 6, 'Zero-padded width of the numeric part.', ['required', 'integer', 'min:3', 'max:10']),
            self::integer('numbering_jewellery_start', SettingGroup::NUMBERING, 1, 'Raise to skip ahead. A value at or below the highest issued number is ignored.', ['required', 'integer', 'min:1']),
            self::integer('numbering_stone_start', SettingGroup::NUMBERING, 1, 'Raise to skip ahead. A value at or below the highest issued number is ignored.', ['required', 'integer', 'min:1']),
            self::integer('numbering_customer_start', SettingGroup::NUMBERING, 1, 'Raise to skip ahead. A value at or below the highest issued number is ignored.', ['required', 'integer', 'min:1']),
            self::integer('numbering_quotation_start', SettingGroup::NUMBERING, 1, 'Raise to skip ahead. A value at or below the highest issued number is ignored.', ['required', 'integer', 'min:1']),
            self::integer('numbering_invoice_start', SettingGroup::NUMBERING, 1, 'Raise to skip ahead. A value at or below the highest issued number is ignored.', ['required', 'integer', 'min:1']),
            self::integer('numbering_performa_start', SettingGroup::NUMBERING, 1, 'Raise to skip ahead. A value at or below the highest issued number is ignored.', ['required', 'integer', 'min:1']),
            self::integer('numbering_delivery_note_start', SettingGroup::NUMBERING, 1, 'Raise to skip ahead. A value at or below the highest issued number is ignored.', ['required', 'integer', 'min:1']),
            self::integer('numbering_creditor_start', SettingGroup::NUMBERING, 1, 'Raise to skip ahead. A value at or below the highest issued number is ignored.', ['required', 'integer', 'min:1']),
            self::integer('numbering_intake_start', SettingGroup::NUMBERING, 1, 'Raise to skip ahead. A value at or below the highest issued number is ignored.', ['required', 'integer', 'min:1']),

            self::string('currency_symbol', SettingGroup::LOCALE, 'Rs', 'Symbol shown before every money figure.', ['required', 'string', 'max:8']),
            self::string('currency_code', SettingGroup::LOCALE, 'PKR', 'ISO currency code. The system is single-currency.', ['required', 'string', 'size:3']),
            self::integer('currency_decimals', SettingGroup::LOCALE, 2, 'Decimal places on money figures.', ['required', 'integer', 'min:0', 'max:4']),
            self::select('date_format', SettingGroup::LOCALE, 'd-m-Y', 'How dates render on screens and documents.', [
                'd-m-Y' => '31-12-2026',
                'd M Y' => '31 Dec 2026',
                'Y-m-d' => '2026-12-31',
            ], ['required', 'in:d-m-Y,d M Y,Y-m-d']),
            self::string('timezone', SettingGroup::LOCALE, 'Asia/Karachi', 'Display timezone. Must be a valid identifier.', ['required', 'timezone'], widget: 'timezone'),
        ];
    }

    /**
     * Code-sequence key used to validate a numbering start.
     *
     * @return array<string, string>
     */
    public static function numberingStartMap(): array
    {
        return [
            'numbering_jewellery_start' => 'jewellery',
            'numbering_stone_start' => 'stone',
            'numbering_customer_start' => 'customer',
            'numbering_quotation_start' => 'quotation',
            'numbering_invoice_start' => 'invoice',
            'numbering_performa_start' => 'performa',
            'numbering_delivery_note_start' => 'delivery_note',
            'numbering_creditor_start' => 'creditor',
            'numbering_intake_start' => 'stone_intake',
        ];
    }

    /**
     * Prefix key for each sequence.
     *
     * @return array<string, string>
     */
    public static function numberingPrefixMap(): array
    {
        return [
            'jewellery' => 'numbering_jewellery_prefix',
            'stone' => 'numbering_stone_prefix',
            'customer' => 'numbering_customer_prefix',
            'quotation' => 'numbering_quotation_prefix',
            'invoice' => 'numbering_invoice_prefix',
            'performa' => 'numbering_performa_prefix',
            'delivery_note' => 'numbering_delivery_note_prefix',
            'creditor' => 'numbering_creditor_prefix',
            'stone_intake' => 'numbering_intake_prefix',
        ];
    }

    /**
     * @param  array<string, string>  $options
     * @param  list<string>  $rules
     * @return array<string, mixed>
     */
    private static function select(string $key, SettingGroup $group, string $default, string $description, array $options, array $rules, ?string $parent = null): array
    {
        return self::entry($key, SettingType::STRING, $group, $default, $description, $rules, 'select', $parent, $options);
    }

    /**
     * @param  list<string>  $rules
     * @return array<string, mixed>
     */
    private static function string(string $key, SettingGroup $group, string $default, string $description, array $rules, ?string $parent = null, string $widget = 'text'): array
    {
        return self::entry($key, SettingType::STRING, $group, $default, $description, $rules, $widget, $parent);
    }

    /**
     * @return array<string, mixed>
     */
    private static function prefix(string $key, string $default, string $description): array
    {
        return self::string($key, SettingGroup::NUMBERING, $default, $description, [
            'required', 'string', 'max:16', 'regex:/^[A-Z0-9]+-?$/',
        ]);
    }

    /**
     * @param  list<string>  $rules
     * @return array<string, mixed>
     */
    private static function integer(string $key, SettingGroup $group, int $default, string $description, array $rules, ?string $parent = null): array
    {
        return self::entry($key, SettingType::INTEGER, $group, $default, $description, $rules, 'number', $parent);
    }

    /**
     * @param  list<string>  $rules
     * @return array<string, mixed>
     */
    private static function decimal(string $key, SettingGroup $group, string $default, string $description, array $rules, ?string $parent = null): array
    {
        return self::entry($key, SettingType::DECIMAL, $group, $default, $description, $rules, 'number', $parent);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toggle(string $key, SettingGroup $group, bool $default, string $description): array
    {
        return self::entry($key, SettingType::BOOLEAN, $group, $default, $description, ['required', 'boolean'], 'toggle');
    }

    /**
     * @param  list<string>  $rules
     * @param  array<string, string>  $options
     * @return array<string, mixed>
     */
    private static function entry(
        string $key,
        SettingType $type,
        SettingGroup $group,
        mixed $default,
        string $description,
        array $rules,
        string $widget,
        ?string $parent = null,
        array $options = [],
    ): array {
        return [
            'key' => $key,
            'type' => $type,
            'group' => $group,
            'default' => $default,
            'description' => $description,
            'rules' => $rules,
            'widget' => $widget,
            'parent' => $parent,
            'options' => $options,
            'feature_note' => $group === SettingGroup::FEATURES,
        ];
    }
}
