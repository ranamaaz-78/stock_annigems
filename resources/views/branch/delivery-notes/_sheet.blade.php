{{--
    Delivery note sheet.

    The client's copy: it names the document and the client and nothing about
    the shop — no logo, company, branch, tagline, terms or QR. Passing no branch
    to the shared sheet is what drops the company block. Lines show one price
    each; the totals show the discount and never a tax row.

    Expects: $note and $a4 (true only when printing).
--}}
@php
    $a4 = $a4 ?? false;
    $pieces = $note->items->where('line_type', \App\Enums\LineType::JEWELLERY_ITEM)->count();
    $stones = (int) $note->items->where('line_type', \App\Enums\LineType::LOOSE_STONE)->sum('quantity');
@endphp

<x-document.sheet doc-type="Delivery Note" :number="$note->delivery_note_number" :a4="$a4">
    <x-slot:meta>
        <x-document.meta-row label="Date">{{ setting_date($note->delivery_date) }}</x-document.meta-row>
        <x-document.meta-row label="Items">
            {{ collect([$pieces ? $pieces.' '.Str::plural('piece', $pieces) : null, $stones ? $stones.' '.Str::plural('stone', $stones) : null])->filter()->implode(' · ') ?: '—' }}
        </x-document.meta-row>
    </x-slot:meta>

    @if($note->status === \App\Enums\DeliveryNoteStatus::CANCELLED)
        <x-slot:notice>
            <x-alert type="error">
                <strong>This delivery note has been cancelled.</strong> {{ $note->cancel_reason }}
            </x-alert>
        </x-slot:notice>
    @endif

    <x-slot:parties>
        <x-document.party label="Client">
            <x-slot:name><p class="sheet__party-name">{{ $note->customer_name_snapshot }}</p></x-slot:name>
            <p class="sheet__party-lines">
                {{ $note->customer_phone_snapshot ?? '—' }}
                @if($note->customer_address_snapshot)
                    <br>{{ $note->customer_address_snapshot }}
                @endif
            </p>
        </x-document.party>
    </x-slot:parties>

    <x-slot:items>
        <x-document.lines :items="$note->items" :split="false" />
    </x-slot:items>

    @if(filled($note->notes))
        <x-slot:terms>
            <p class="sheet__terms-label">Notes</p>
            <p class="sheet__terms">{{ $note->notes }}</p>
        </x-slot:terms>
    @endif

    <x-slot:totals>
        <x-document.totals :document="$note" grand-label="Total" />
    </x-slot:totals>
</x-document.sheet>