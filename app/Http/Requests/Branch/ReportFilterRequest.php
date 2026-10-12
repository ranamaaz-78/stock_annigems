<?php

namespace App\Http\Requests\Branch;

use App\Enums\JewelleryItemMovementType;
use App\Enums\JewelleryItemStatus;
use App\Enums\PaymentMethod;
use App\Enums\QuotationStatus;
use App\Enums\ReportDatePreset;
use App\Enums\ReportGrouping;
use App\Enums\StoneMovementType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\JewelleryItem;
use App\Models\Purity;
use App\Models\Stone;
use App\Models\User;
use App\Support\Reports\ReportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ReportFilterRequest
 *
 * Validates the filter bar for every branch-level (Admin) report and converts
 * it into an immutable ReportFilter.
 *
 * Two deliberate choices:
 *
 * 1. Record references arrive as uuids and are translated to primary keys here.
 *    A sequential id in a query string invites enumeration; a uuid does not, and
 *    the translation means no request value ever reaches a WHERE clause.
 * 2. The branch is read from the authenticated user, never from the payload.
 *    There is no branch field in these rules at all, so no amount of tampering
 *    can point a branch Admin's report at another branch.
 */
class ReportFilterRequest extends FormRequest
{
    /**
     * Only a branch Admin holding reports.view may run a detailed report.
     *
     * Delegated to ReportPolicy, which asserts both the permission and the role
     * so a SuperAdmin (who holds every permission) is refused.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAdminReports') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Preset drives the dates; "custom" means the operator typed them.
            'preset' => ['nullable', Rule::enum(ReportDatePreset::class)],
            'from' => ['nullable', 'date', 'required_if:preset,'.ReportDatePreset::CUSTOM->value],
            'to' => ['nullable', 'date', 'required_if:preset,'.ReportDatePreset::CUSTOM->value],
            'grouping' => ['nullable', Rule::enum(ReportGrouping::class)],

            // Secondary filters. Each record reference is a uuid that must
            // resolve to a real row; anything else fails validation rather than
            // silently returning an empty report.
            'category' => ['nullable', 'uuid', 'exists:categories,uuid'],
            'purity' => ['nullable', 'uuid', 'exists:purities,uuid'],
            'staff' => ['nullable', 'uuid', 'exists:users,uuid'],
            'customer' => ['nullable', 'uuid', 'exists:customers,uuid'],
            'stone' => ['nullable', 'uuid', 'exists:stones,uuid'],
            'item' => ['nullable', 'uuid', 'exists:jewellery_items,uuid'],

            // Enum-backed filters validate against the enum, never a free string.
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'item_status' => ['nullable', Rule::enum(JewelleryItemStatus::class)],
            'quotation_status' => ['nullable', Rule::enum(QuotationStatus::class)],
            // Movement logs cover two different ledgers, so the accepted values
            // are the union of both movement enums rather than one of them.
            'movement_type' => ['nullable', 'string', Rule::in($this->movementTypeValues())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from.required_if' => 'Enter a start date for the custom range.',
            'to.required_if' => 'Enter an end date for the custom range.',
            'from.date' => 'Enter a valid start date.',
            'to.date' => 'Enter a valid end date.',
            'category.exists' => 'That category no longer exists. Choose another.',
            'purity.exists' => 'That purity no longer exists. Choose another.',
            'staff.exists' => 'That staff member no longer exists. Choose another.',
            'customer.exists' => 'That customer no longer exists. Choose another.',
            'stone.exists' => 'That stone no longer exists. Choose another.',
            'payment_method.Illuminate\Validation\Rules\Enum' => 'Choose a valid payment method.',
        ];
    }

    /**
     * Cross-field date checks that no single rule can express.
     *
     * - end before start is a mistake, not an empty report
     * - a future start date can only be a typo
     * - an absurdly wide range is refused with a hint, because scanning years
     *   of invoices to render one page is how a report screen becomes an outage
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->resolveRange();

            if ($from->greaterThan($to)) {
                $validator->errors()->add('to', 'The end date must be on or after the start date.');

                return;
            }

            if ($from->greaterThan(CarbonImmutable::now($this->displayTimezone())->endOfDay())) {
                $validator->errors()->add('from', 'The start date cannot be in the future.');

                return;
            }

            $maxDays = (int) config('annigems.reports.max_range_days', 731);

            if ($from->diffInDays($to) + 1 > $maxDays) {
                $validator->errors()->add(
                    'to',
                    'That range covers more than '.$maxDays.' days. Choose a narrower range — for example one month or one quarter at a time.'
                );
            }
        });
    }

    /**
     * Build the immutable filter the report services consume.
     *
     * Runs after validation, so every uuid lookup below is guaranteed to hit.
     */
    public function toFilter(): ReportFilter
    {
        [$from, $to] = $this->resolveRange();

        return new ReportFilter(
            from: $from,
            to: $to,
            preset: $this->resolvePreset(),
            grouping: ReportGrouping::tryFrom((string) $this->input('grouping')) ?? $this->defaultGrouping($from, $to),
            // Branch comes from the actor. This is the tenant boundary.
            branchId: $this->user()?->branch_id,
            options: $this->resolveOptions(),
        );
    }

    /**
     * Translate submitted uuids into primary keys and pass enum values through.
     *
     * @return array<string, mixed>
     */
    private function resolveOptions(): array
    {
        $options = [];

        foreach ($this->uuidFilterMap() as $field => [$model, $optionKey, $nameColumn]) {
            $uuid = $this->input($field);

            if (! is_string($uuid) || $uuid === '') {
                continue;
            }

            $record = $model::query()->where('uuid', $uuid)->first();

            if ($record === null) {
                continue;
            }

            $options[$optionKey] = (int) $record->getKey();
            // Keep the uuid so the filter bar can re-select the option without
            // another lookup, and so the export record stays readable.
            $options[$field.'_uuid'] = $uuid;
            // Sales reports match against invoice_item snapshot *names* rather
            // than foreign keys, because a sale must keep reporting the
            // category and purity it was actually sold under. The resolved name
            // is carried alongside the id for exactly that reason.
            $options[$optionKey.'_name'] = (string) $record->{$nameColumn};
        }

        foreach (['payment_method', 'item_status', 'quotation_status', 'movement_type'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && $value !== '') {
                $options[$field] = $value;
            }
        }

        return $options;
    }

    /**
     * Submitted field => [model, resolved option key, display-name column].
     *
     * @return array<string, array{0: class-string<\Illuminate\Database\Eloquent\Model>, 1: string, 2: string}>
     */
    private function uuidFilterMap(): array
    {
        return [
            'category' => [Category::class, 'category_id', 'name'],
            'purity' => [Purity::class, 'purity_id', 'name'],
            'staff' => [User::class, 'staff_id', 'name'],
            'customer' => [Customer::class, 'customer_id', 'name'],
            'stone' => [Stone::class, 'stone_id', 'name'],
            'item' => [JewelleryItem::class, 'jewellery_item_id', 'item_code'],
        ];
    }

    /**
     * Accepted movement_type values: both movement ledgers' enum values.
     *
     * @return list<string>
     */
    private function movementTypeValues(): array
    {
        return array_merge(
            array_map(fn (JewelleryItemMovementType $case): string => $case->value, JewelleryItemMovementType::cases()),
            array_map(fn (StoneMovementType $case): string => $case->value, StoneMovementType::cases()),
        );
    }

    /**
     * The preset in play, defaulting to the current month.
     */
    private function resolvePreset(): ReportDatePreset
    {
        $preset = ReportDatePreset::tryFrom((string) $this->input('preset'));

        if ($preset !== null) {
            return $preset;
        }

        // Explicit dates without a preset is a custom range by definition.
        return $this->filled('from') || $this->filled('to')
            ? ReportDatePreset::CUSTOM
            : ReportDatePreset::THIS_MONTH;
    }

    /**
     * Concrete inclusive range for the submitted preset or dates.
     *
     * Dates are interpreted in the display timezone so "today" means the
     * operator's today, not the server's.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(): array
    {
        $preset = $this->resolvePreset();
        $tz = $this->displayTimezone();

        if ($preset !== ReportDatePreset::CUSTOM) {
            return $preset->range(CarbonImmutable::now($tz));
        }

        $fallback = ReportDatePreset::THIS_MONTH->range(CarbonImmutable::now($tz));

        $from = $this->filled('from')
            ? CarbonImmutable::parse((string) $this->input('from'), $tz)->startOfDay()
            : $fallback[0];

        $to = $this->filled('to')
            ? CarbonImmutable::parse((string) $this->input('to'), $tz)->endOfDay()
            : $fallback[1];

        return [$from, $to];
    }

    /**
     * Sensible time bucket when the operator did not choose one.
     *
     * A year of daily bars is unreadable, so the bucket widens with the range.
     */
    private function defaultGrouping(CarbonImmutable $from, CarbonImmutable $to): ReportGrouping
    {
        $days = $from->diffInDays($to) + 1;

        return match (true) {
            $days <= 31 => ReportGrouping::DAY,
            $days <= 182 => ReportGrouping::WEEK,
            default => ReportGrouping::MONTH,
        };
    }

    /**
     * Application display timezone.
     */
    private function displayTimezone(): string
    {
        return (string) config('annigems.timezone', 'Asia/Karachi');
    }
}
