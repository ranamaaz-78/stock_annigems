{{--
    Print barcode labels, as a dialog over the current page.

    The controls and the preview are driven by resources/js/modules/stone-labels.js,
    which fetches each chosen stone's label data from the data URL and draws the
    labels itself. Nothing here posts a form.
--}}
@if($labelAllowed ?? auth()->user()->can('printLabel', \App\Models\Stone::class))
<x-dialog
    id="label-modal"
    :title="__('Print barcode labels')"
    :subtitle="__('Choose the label stock and what to show, check the preview, then print.')"
    icon="print"
    size="xl"
    data-data-url="{{ $labelDataUrl ?? route('branch.stones.labels.data') }}"
    data-company="{{ setting('company_name') }}"
>
    <div class="dialog__body">
        <p class="label-dialog__count" data-label-count></p>
        <p class="alert alert--info is-hidden" data-label-notice></p>

        <div class="label-dialog">
            {{-- Options --}}
            <div class="label-dialog__options">
                <div class="form-field">
                    <label class="form-label" for="label-preset">{{ __('Label sheet') }}</label>
                    <select id="label-preset" class="input-field" data-label-field="preset" data-no-select2="true"></select>
                </div>

                <div class="label-dialog__custom is-hidden" data-label-custom>
                    <div class="form-field">
                        <label class="form-label" for="label-custom-w">{{ __('Width (mm)') }}</label>
                        <input id="label-custom-w" type="number" min="10" max="200" step="0.5" class="input-field" data-label-field="customW">
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="label-custom-h">{{ __('Height (mm)') }}</label>
                        <input id="label-custom-h" type="number" min="8" max="200" step="0.5" class="input-field" data-label-field="customH">
                    </div>
                </div>

                <div class="label-dialog__pair">
                    <div class="form-field">
                        <label class="form-label" for="label-text">{{ __('Text size') }}</label>
                        <select id="label-text" class="input-field" data-label-field="text" data-no-select2="true">
                            <option value="small">{{ __('Small') }}</option>
                            <option value="normal">{{ __('Normal') }}</option>
                            <option value="large">{{ __('Large') }}</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label class="form-label" for="label-bars">{{ __('Barcode height') }}</label>
                        <select id="label-bars" class="input-field" data-label-field="bars" data-no-select2="true">
                            <option value="short">{{ __('Short') }}</option>
                            <option value="normal">{{ __('Normal') }}</option>
                            <option value="tall">{{ __('Tall') }}</option>
                        </select>
                    </div>
                </div>

                <div class="form-field">
                    <label class="form-label" for="label-copies">{{ __('Copies per product') }}</label>
                    <input id="label-copies" type="number" min="1" max="500" step="1" class="input-field" data-label-field="copies">
                </div>

                <div class="label-dialog__checks">
                    <label class="form-check"><input type="checkbox" data-label-field="showPrice"> {{ __('Show price') }}</label>
                    <label class="form-check"><input type="checkbox" data-label-field="showName"> {{ __('Show product name') }}</label>
                    <label class="form-check"><input type="checkbox" data-label-field="border"> {{ __('Draw a border around each label') }}</label>
                </div>
            </div>

            {{-- Preview --}}
            <div class="label-dialog__preview">
                <span class="label-dialog__heading">{{ __('Preview') }}</span>
                <div class="label-preview" data-label-preview></div>
                <p class="label-dialog__summary" data-label-summary></p>
            </div>
        </div>
    </div>
    <div class="dialog__footer">
        <x-btn type="button" variant="secondary" data-modal-close>{{ __('Cancel') }}</x-btn>
        <x-btn type="button" data-label-print>
            <span class="material-symbols-outlined icon icon--sm">print</span>
            {{ __('Print') }}
        </x-btn>
    </div>
</x-dialog>
@endif
