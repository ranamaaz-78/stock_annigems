{{-- Compact audit timeline. Expects a collection of AuditLog models with user loaded. --}}
@props([
    'logs',
])

@if($logs->isEmpty())
    <p class="attribution attribution--empty">No activity recorded yet.</p>
@else
    <ul {{ $attributes->merge(['class' => 'audit-log']) }}>
        @foreach($logs as $log)
            <li class="audit-log__item">
                <span class="audit-log__action">{{ $log->actionLabel() }}</span>
                <x-audit-actor :user="$log->user" :role="$log->user_role" :at="$log->created_at" />
            </li>
        @endforeach
    </ul>
@endif
