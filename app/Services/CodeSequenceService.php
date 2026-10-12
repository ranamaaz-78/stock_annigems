<?php

namespace App\Services;

use App\Support\Settings\SettingCatalog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CodeSequenceService
 *
 * Issues the next business code under a row lock so two concurrent
 * registrations cannot receive the same number. Prefix and padding come
 * from settings; codes already issued are never rewritten. Codes are
 * never accepted from HTTP input.
 */
class CodeSequenceService
{
    /**
     * Next stone code, e.g. STN-000125. Must run inside a DB transaction
     * (the caller typically already opened one).
     */
    public function nextStoneCode(): string
    {
        return $this->next('stone');
    }

    /**
     * Next intake number, e.g. SIN-000125.
     */
    public function nextIntakeNumber(): string
    {
        return $this->next('stone_intake');
    }

    /**
     * Next jewellery item code, e.g. JWL-000125.
     */
    public function nextJewelleryCode(): string
    {
        return $this->next('jewellery');
    }

    /**
     * Next customer code, e.g. CUS-000125.
     */
    public function nextCustomerCode(): string
    {
        return $this->next('customer');
    }

    /**
     * Next quotation number, e.g. QTN-000125.
     */
    public function nextQuotationNumber(): string
    {
        return $this->next('quotation');
    }

    /**
     * Next invoice number, e.g. INV-000125.
     */
    public function nextInvoiceNumber(): string
    {
        return $this->next('invoice');
    }

    /**
     * The quotation number the next save would receive, without taking it.
     *
     * For showing "next no." on screen only. Nothing is reserved, so another
     * till can save first; the number a document really gets is the one
     * nextQuotationNumber() returns inside the save.
     */
    public function peekQuotationNumber(): string
    {
        return $this->peek('quotation');
    }

    /**
     * The invoice number the next save would receive, without taking it.
     *
     * @see self::peekQuotationNumber()
     */
    public function peekInvoiceNumber(): string
    {
        return $this->peek('invoice');
    }

    /**
     * Next delivery note (albarán) number, e.g. ALB-000125.
     */
    public function nextDeliveryNoteNumber(): string
    {
        return $this->next('delivery_note');
    }

    /**
     * The delivery note number the next save would receive, without taking it.
     *
     * @see self::peekQuotationNumber()
     */
    public function peekDeliveryNoteNumber(): string
    {
        return $this->peek('delivery_note');
    }

    /**
     * The performa number the next issue would receive, without taking it.
     *
     * @see self::peekQuotationNumber()
     */
    public function peekPerformaNumber(): string
    {
        return $this->peek('performa');
    }

    /**
     * Next performa number, e.g. PRF-000125.
     */
    public function nextPerformaNumber(): string
    {
        return $this->next('performa');
    }

    /**
     * Next creditor code, e.g. CRD-000125.
     */
    public function nextCreditorCode(): string
    {
        return $this->next('creditor');
    }

    /**
     * Lock the sequence row, increment, and format.
     *
     * Prefix and padding come from settings so a SuperAdmin rename does
     * not rewrite codes already printed on labels and invoices.
     */
    private function next(string $key): string
    {
        $start = $this->configuredStart($key);

        $row = DB::table('code_sequences')->where('key', $key)->lockForUpdate()->first();

        if ($row === null) {
            DB::table('code_sequences')->insert([
                'key' => $key,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('code_sequences')->where('key', $key)->lockForUpdate()->first();
        }

        if ($row === null) {
            throw new RuntimeException("Code sequence [{$key}] could not be initialised.");
        }

        $next = max(((int) $row->last_number) + 1, $start);

        DB::table('code_sequences')->where('key', $key)->update([
            'last_number' => $next,
            'updated_at' => now(),
        ]);

        return $this->format($key, $next);
    }

    /**
     * Read the number that next() would issue, with no lock and no write.
     */
    private function peek(string $key): string
    {
        $last = (int) DB::table('code_sequences')->where('key', $key)->value('last_number');

        return $this->format($key, max($last + 1, $this->configuredStart($key)));
    }

    /**
     * Prefix and zero-padding around a sequence number, from settings.
     */
    private function format(string $key, int $number): string
    {
        $prefixKey = SettingCatalog::numberingPrefixMap()[$key] ?? null;
        $prefix = $prefixKey ? (string) setting($prefixKey) : '';
        $pad = max(3, (int) setting('numbering_padding', 6));

        return $prefix.str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
    }

    /**
     * SuperAdmin starting number for this sequence, or 1.
     */
    private function configuredStart(string $key): int
    {
        $settingKey = array_flip(SettingCatalog::numberingStartMap())[$key] ?? null;

        if ($settingKey === null) {
            return 1;
        }

        return max(1, (int) setting($settingKey, 1));
    }
}
