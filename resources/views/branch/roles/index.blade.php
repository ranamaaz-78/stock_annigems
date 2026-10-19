{{-- Read-only roles and default permissions reference (Admin). --}}
@extends('layouts.branch')

@section('title', __('Roles & Permissions'))
@section('page-title', __('Roles & Permissions'))

@section('content')
<x-page-header :title="__('Roles & Permissions')" :subtitle="__('System-defined roles and their default permissions. Read-only reference.')" />

<div class="space-y-4">
    @foreach($roles as $role)
        <div class="panel overflow-hidden">
            <div class="px-4 py-3 border-b border-outline-variant">
                <h2 class="font-headline-sm text-headline-sm">{{ $role->display_name }}</h2>
                @if($role->description)
                    <p class="text-body-sm text-on-surface-variant mt-1">{{ $role->description }}</p>
                @endif
            </div>
            <div class="p-4">
                @php
                    $grouped = $role->permissions->groupBy('module');
                @endphp
                @foreach($grouped as $module => $permissions)
                    <div class="mb-3 last:mb-0">
                        <p class="font-label-caps text-on-surface-variant mb-1">{{ ucfirst($module) }}</p>
                        <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-1 text-body-sm">
                            @foreach($permissions as $permission)
                                <li>{{ $permission->display_name }} <span class="text-on-surface-variant">({{ $permission->name }})</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
@endsection
