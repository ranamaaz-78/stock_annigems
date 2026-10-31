{{--
    Stones → Images.

    A folder of branch pictures: 100×100 tiles, click to enlarge, drag-and-drop
    or the Upload files button to add, and an inline rename on the filename.
--}}
@extends('layouts.branch')

@section('title', __('Stone Images'))
@section('page-title', __('Images'))

@section('content')
<x-page-header :title="__('Images')" :subtitle="__('Names are stored without the file extension, so stone1.jpg is saved as stone1.')" />

<div
    id="stone-images"
    class="stone-folder"
    data-upload-url="{{ route('branch.stone-images.store') }}"
    data-images="{{ json_encode($images) }}"
>
    <div class="stone-folder__toolbar">
        <button type="button" class="btn btn--primary" data-upload-trigger>
            <span class="material-symbols-outlined icon icon--sm">upload_file</span>
            {{ __('Upload files') }}
        </button>
        <p class="stone-folder__hint">{{ __('Drop images on the folder, or click a name to rename it.') }}</p>
        <button type="button" class="btn btn--danger stone-folder__bulk is-hidden" data-bulk-delete>
            <span class="material-symbols-outlined icon icon--sm">delete</span>
            <span data-bulk-delete-label>{{ __('Delete selected') }}</span>
        </button>
        <input type="file" class="stone-folder__picker" data-file-input accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden>
    </div>

    <div class="stone-folder__drop" data-dropzone>
        <div class="stone-folder__selectbar">
            <label class="form-check stone-folder__select-all is-hidden" data-select-all-wrap>
                <input type="checkbox" data-select-all> {{ __('Select all') }}
            </label>
        </div>
        <div class="stone-folder__grid" data-grid></div>
        <div class="stone-folder__empty" data-empty>
            <span class="material-symbols-outlined icon icon--lg">folder_open</span>
            <p class="stone-folder__empty-title">{{ __('This folder is empty') }}</p>
            <p class="stone-folder__empty-copy">{{ __('Drag images here, or use Upload files.') }}</p>
        </div>
    </div>
</div>

<div class="stone-lightbox" data-lightbox hidden>
    <button type="button" class="stone-lightbox__backdrop" data-lightbox-close aria-label="{{ __('Close preview') }}"></button>
    <figure class="stone-lightbox__frame">
        <img data-lightbox-image alt="">
        <figcaption class="stone-lightbox__caption" data-lightbox-caption></figcaption>
    </figure>
</div>
@endsection
