<?php

namespace App\Support\Settings;

use App\Enums\InvoicePrintFormat;
use App\Enums\LabelSize;
use App\Enums\QuotationPrintFormat;
use App\Enums\SettingGroup;
use App\Enums\SettingType;

/**
 * BranchSettingCatalog
 *
 * The short list of keys that are physically local to one shop. Contact
 * details are deliberately absent: those edit the Branch record so an
 * invoice cannot print a stale copy of the address.
 */
class BranchSettingCatalog
{
    /**
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
     * @return ?array<string, mixed>
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
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
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'label_size',
                'type' => SettingType::STRING,
                'group' => SettingGroup::PRINT,
                'default' => LabelSize::MEDIUM->value,
                'description' => 'Label stock this branch prints on.',
                'rules' => ['required', 'in:'.implode(',', array_column(LabelSize::cases(), 'value'))],
                'widget' => 'select',
                'options' => self::enumOptions(LabelSize::cases()),
            ],
            [
                'key' => 'labels_per_sheet',
                'type' => SettingType::INTEGER,
                'group' => SettingGroup::PRINT,
                'default' => 1,
                'description' => 'How many labels print on one sheet for bulk runs.',
                'rules' => ['required', 'integer', 'min:1', 'max:48'],
                'widget' => 'number',
                'options' => [],
            ],
            [
                'key' => 'invoice_print_format',
                'type' => SettingType::STRING,
                'group' => SettingGroup::PRINT,
                'default' => InvoicePrintFormat::A4->value,
                'description' => 'Paper the invoice printer holds.',
                'rules' => ['required', 'in:'.implode(',', array_column(InvoicePrintFormat::cases(), 'value'))],
                'widget' => 'select',
                'options' => self::enumOptions(InvoicePrintFormat::cases()),
            ],
            [
                'key' => 'quotation_print_format',
                'type' => SettingType::STRING,
                'group' => SettingGroup::PRINT,
                'default' => QuotationPrintFormat::A4->value,
                'description' => 'Quotations print as A4 estimates, not till receipts.',
                'rules' => ['required', 'in:'.implode(',', array_column(QuotationPrintFormat::cases(), 'value'))],
                'widget' => 'select',
                'options' => self::enumOptions(QuotationPrintFormat::cases()),
            ],
            [
                'key' => 'include_qr_on_label',
                'type' => SettingType::BOOLEAN,
                'group' => SettingGroup::PRINT,
                'default' => true,
                'description' => 'Print the product QR next to the barcode on labels.',
                'rules' => ['required', 'boolean'],
                'widget' => 'toggle',
                'options' => [],
            ],
            [
                'key' => 'invoice_footer_note',
                'type' => SettingType::STRING,
                'group' => SettingGroup::INVOICE,
                'default' => 'All sales are final. Exchanges are accepted within 7 days against this invoice, provided the item is unworn and in its original condition.',
                'description' => 'Printed at the foot of every invoice from this branch.',
                'rules' => ['nullable', 'string', 'max:500'],
                'widget' => 'textarea',
                'options' => [],
            ],
            [
                'key' => 'quotation_footer_note',
                'type' => SettingType::STRING,
                'group' => SettingGroup::INVOICE,
                'default' => 'This is a price estimate, not a tax invoice. The final amount is confirmed at purchase and follows the gold rate on that day.',
                'description' => 'Printed at the foot of every quotation from this branch.',
                'rules' => ['nullable', 'string', 'max:500'],
                'widget' => 'textarea',
                'options' => [],
            ],
        ];
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = method_exists($case, 'label') ? $case->label() : $case->value;
        }

        return $options;
    }
}
