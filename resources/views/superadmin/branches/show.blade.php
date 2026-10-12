{{-- Branch detail: info card + users of this branch. --}}
@extends('layouts.superadmin')

@section('title', $branch->name)
@section('page-title', $branch->name)

@section('content')
<x-page-header :title="$branch->name" :subtitle="$branch->code . ' · ' . $branch->city">
    <x-slot:actions>
        <x-btn href="{{ route('superadmin.branches.edit', $branch) }}" variant="secondary">Edit</x-btn>
        @if($branch->status === \App\Enums\BranchStatus::ACTIVE)
            <form method="POST" action="{{ route('superadmin.branches.deactivate', $branch) }}" id="deactivate-branch-form" data-loading-form>
                @csrf
                <x-btn type="submit" variant="danger" loading-text="Deactivating...">Deactivate</x-btn>
            </form>
        @else
            <form method="POST" action="{{ route('superadmin.branches.activate', $branch) }}" data-loading-form>
                @csrf
                <x-btn type="submit" loading-text="Activating...">Activate</x-btn>
            </form>
        @endif
    </x-slot:actions>
</x-page-header>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
    <div class="lg:col-span-2 bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
        <p class="font-label-caps text-on-surface-variant mb-3">Branch information</p>
        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-body-sm">
            <div><dt class="text-on-surface-variant">Status</dt><dd class="mt-0.5"><x-status-badge :status="$branch->status" /></dd></div>
            <div><dt class="text-on-surface-variant">Code</dt><dd class="font-data-mono mt-0.5">{{ $branch->code }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-on-surface-variant">Address</dt><dd class="mt-0.5">{{ $branch->address }}</dd></div>
            <div><dt class="text-on-surface-variant">City</dt><dd class="mt-0.5">{{ $branch->city }}</dd></div>
            <div><dt class="text-on-surface-variant">Contact</dt><dd class="font-data-mono mt-0.5">{{ $branch->contact_number }}</dd></div>
            <div><dt class="text-on-surface-variant">Email</dt><dd class="mt-0.5">{{ $branch->email ?: '—' }}</dd></div>
            <div><dt class="text-on-surface-variant">Admin</dt><dd class="mt-0.5">{{ $branch->activeAdmins->pluck('name')->join(', ') ?: 'Unassigned' }}</dd></div>
        </dl>
        <x-attribution :record="$branch" />
    </div>
    <div class="bg-surface-container-lowest border border-outline-variant rounded-lg p-4">
        <p class="font-label-caps text-on-surface-variant mb-2">Quick actions</p>
        <p class="font-label-caps text-on-surface-variant mb-2">Quick actions</p>
        @if($branch->activeAdmins->isEmpty())
            <x-btn href="{{ route('superadmin.users.create-admin', ['branch' => $branch->uuid]) }}" class="w-full">Assign Branch Admin</x-btn>
        @else
            <x-btn href="{{ route('superadmin.users.create-admin', ['branch' => $branch->uuid]) }}" variant="secondary" class="w-full">Create replacement Admin</x-btn>
            <p class="text-body-sm text-on-surface-variant mt-2">Create the replacement first, then deactivate the outgoing Admin. No data is lost when an Admin is replaced.</p>
        @endif
    </div>
</div>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg overflow-hidden">
    <div class="px-4 py-3 border-b border-outline-variant">
        <h2 class="font-headline-sm text-headline-sm">Users at this branch</h2>
    </div>
    @if($branch->users->isEmpty())
        <div class="p-4">
            <x-empty-state title="No users yet" message="Create a Branch Admin to get started." icon="group" />
        </div>
    @else
        <table class="table-admin">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last login</th>
                </tr>
            </thead>
            <tbody>
                @foreach($branch->users as $user)
                    <tr>
                        <td>
                            <div class="font-medium"><a href="{{ route('superadmin.users.show', $user) }}" class="form-link">{{ $user->name }}</a>@if($user->trashed() || $user->status === \App\Enums\UserStatus::INACTIVE) <span class="attribution-actor">(inactive)</span>@endif</div>
                            <div class="text-xs text-on-surface-variant">{{ $user->email }}</div>
                        </td>
                        <td>{{ $user->role->label() }}</td>
                        <td><x-status-badge :status="$user->status" /></td>
                        <td class="font-data-mono">{{ $user->last_login_at ? setting_datetime($user->last_login_at) : 'Never' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="bg-surface-container-lowest border border-outline-variant rounded-lg overflow-hidden mt-4">
    <div class="px-4 py-3 border-b border-outline-variant">
        <h2 class="font-headline-sm text-headline-sm">Activity</h2>
    </div>
    <div class="px-4 py-2">
        <x-audit-log :logs="$auditLogs" />
    </div>
</div>
@endsection

@push('scripts')
<script>
const deactivateForm = document.getElementById('deactivate-branch-form');
if (deactivateForm) {
    deactivateForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const ok = await window.AnnigemsAlert.confirmAction({
            title: 'Deactivate this branch?',
            text: 'This will block all users of this branch from logging in. This cannot be undone without reactivating.',
            confirmButtonText: 'Deactivate branch',
        });
        if (ok) deactivateForm.submit();
    });
}
</script>
@endpush
