<?php

namespace App\Http\Requests\SuperAdmin;

use App\Enums\PaymentMethod;
use App\Enums\ReportDatePreset;
use App\Enums\ReportGrouping;
use App\Models\Branch;
use App\Support\Reports\ReportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * SuperAdmin\ReportFilterRequest
 *
 * Filter bar for the SuperAdmin aggregate reports and dashboard.
 *
 * This is a separate request class from the branch one for a structural reason,
 * not a stylistic one. The branch request has *no* branch field at all, so a
 * branch Admin cannot widen its scope. This request is the only place in the
 * system where a branch may be chosen from the payload, and it is reachable
 * only by a SuperAdmin holding reports.superadmin.
 *
 * Note what is absent: there is no customer, invoice, quotation, item or stone
 * filter here. Accepting a record uuid would be the first step towards a
 * drill-down, which spec 7.4 forbids. SuperAdmin filters by branch, date and
 * grouping only.
 */
class ReportFilterRequest extends FormRequest
{
    /**
     * SuperAdmin holding the aggregate reporting permission.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewSuperAdminReports') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Absent or empty means All Branches (consolidated).
            'branch' => ['nullable', 'uuid', 'exists:branches,uuid'],
            'preset' => ['nullable', Rule::enum(ReportDatePreset::class)],
            'from' => ['nullable', 'date', 'required_if:preset,'.ReportDatePreset::CUSTOM->value],
            'to' => ['nullable', 'date', 'required_if:preset,'.ReportDatePreset::CUSTOM->value],
            'grouping' => ['nullable', Rule::enum(ReportGrouping::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branch.exists' => __('That branch no longer exists. Choose another.'),
            'from.required_if' => __('Enter a start date for the custom range.'),
            'to.required_if' => __('Enter an end date for the custom range.'),
        ];
    }

    /**
     * Same cross-field date rules as the branch request — an inverted or
     * absurdly wide range is refused with a message, not silently accepted.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            [$from, $to] = $this->resolveRange();

            if ($from->greaterThan($to)) {
                $validator->errors()->add('to', __('The end date must be on or after the start date.'));

                return;
            }

            $maxDays = (int) config('annigems.reports.max_range_days', 731);

            if ($from->diffInDays($to) + 1 > $maxDays) {
                $validator->errors()->add(
                    'to',
                    __('That range covers more than :days days. Choose a narrower range — for example one month or one quarter at a time.', ['days' => $maxDays])
                );
            }
        });
    }

    /**
     * Build the filter. A null branchId is the All Branches mode.
     */
    public function toFilter(): ReportFilter
    {
        [$from, $to] = $this->resolveRange();

        $options = [];

        if ($this->filled('payment_method')) {
            $options['payment_method'] = (string) $this->input('payment_method');
        }

        return new ReportFilter(
            from: $from,
            to: $to,
            preset: $this->resolvePreset(),
            grouping: ReportGrouping::tryFrom((string) $this->input('grouping')) ?? $this->defaultGrouping($from, $to),
            branchId: $this->resolveBranchId(),
            options: $options,
        );
    }

    /**
     * Selected branch's primary key, or null for All Branches.
     */
    public function resolveBranchId(): ?int
    {
        $uuid = $this->input('branch');

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $id = Branch::query()->where('uuid', $uuid)->value('id');

        return $id !== null ? (int) $id : null;
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

        return $this->filled('from') || $this->filled('to')
            ? ReportDatePreset::CUSTOM
            : ReportDatePreset::THIS_MONTH;
    }

    /**
     * Concrete inclusive range for the submitted preset or dates.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(): array
    {
        $preset = $this->resolvePreset();
        $tz = (string) config('annigems.timezone', 'Asia/Karachi');

        if ($preset !== ReportDatePreset::CUSTOM) {
            return $preset->range(CarbonImmutable::now($tz));
        }

        $fallback = ReportDatePreset::THIS_MONTH->range(CarbonImmutable::now($tz));

        return [
            $this->filled('from')
                ? CarbonImmutable::parse((string) $this->input('from'), $tz)->startOfDay()
                : $fallback[0],
            $this->filled('to')
                ? CarbonImmutable::parse((string) $this->input('to'), $tz)->endOfDay()
                : $fallback[1],
        ];
    }

    /**
     * Widen the chart bucket as the range grows, so a year is not 365 bars.
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
}
