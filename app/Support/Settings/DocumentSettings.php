<?php

namespace App\Support\Settings;

use App\Enums\DiscountType;
use App\Enums\TaxType;
use App\Models\User;
use App\Services\TaxRateService;

/**
 * DocumentSettings
 *
 * SuperAdmin tax and discount rules as they apply to a live quotation
 * (and therefore to the invoice converted from it).
 *
 * The tax rates (IVA, Recargo de equivalencia) are SuperAdmin's lists. A
 * cashier chooses one of them by uuid but never types a percentage: the
 * rate always comes from the list. Discount stays an operator control when
 * enabled, and max_discount_percentage caps every role including Admin.
 */
class DocumentSettings
{
    /**
     * The tax a new document starts with: the default IVA rate when tax is
     * switched on and a default exists, otherwise none.
     *
     * @return array{enabled: bool, type: string, rate: float, label: string, name: ?string, uuid: ?string}
     */
    public static function tax(): array
    {
        $enabled = (bool) setting('tax_enabled');
        $default = $enabled ? app(TaxRateService::class)->defaultIva() : null;

        return [
            'enabled' => $enabled,
            'type' => $default ? TaxType::PERCENTAGE->value : TaxType::NONE->value,
            'rate' => $default ? (float) $default->rate : 0.0,
            'label' => $default?->name ?? 'IVA',
            'name' => $default?->name,
            'uuid' => $default?->uuid,
        ];
    }

    /**
     * Overlay catalog tax (and a disabled discount) onto a document payload.
     *
     * Client-submitted tax_type / tax_rate are discarded so a crafted POST
     * cannot undercut the SuperAdmin rate. POS may send apply_tax=false to
     * skip GST on that ticket; omitting the key keeps the quotation-workspace
     * behaviour (catalog tax always applied when enabled).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyToPayload(array $data, ?User $actor = null): array
    {
        $applyTax = self::shouldApplyTax($data);

        if ((bool) setting('tax_enabled') && $applyTax) {
            // A tax_rate_uuid key that is present but empty means "no IVA on
            // this ticket" (the POS sends it that way); an absent key means the
            // default IVA, which is what the quotation workspace relies on.
            $withoutIva = array_key_exists('tax_rate_uuid', $data) && blank($data['tax_rate_uuid']);

            $resolved = app(TaxRateService::class)->resolve(
                $data['tax_rate_uuid'] ?? null,
                $data['recargo_rate_uuid'] ?? null,
                $withoutIva
            );
        } else {
            $resolved = [
                'tax_type' => TaxType::NONE->value,
                'tax_rate' => 0,
                'tax_name' => null,
                'recargo_rate' => 0,
                'recargo_name' => null,
            ];
        }

        // Whatever the browser sent for the figures is replaced by the list's.
        $data = array_merge($data, $resolved);

        if (! setting('discount_enabled')) {
            $data['discount_type'] = DiscountType::NONE->value;
            $data['discount_value'] = 0;
        }

        return $data;
    }

    /**
     * POS sends apply_tax explicitly. The quotation workspace omits the key
     * so catalog GST still applies whenever SuperAdmin has tax enabled.
     *
     * @param  array<string, mixed>  $data
     */
    private static function shouldApplyTax(array $data): bool
    {
        if (! array_key_exists('apply_tax', $data)) {
            return true;
        }

        $value = $data['apply_tax'];

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Percentage ceiling for every quotation / invoice discount.
     *
     * Admin is not exempt — the SuperAdmin number is the shop rule.
     */
    public static function maxDiscountPercentage(?User $actor = null): float
    {
        if (! setting('discount_enabled')) {
            return 0.0;
        }

        return (float) setting('max_discount_percentage', 100);
    }
}
