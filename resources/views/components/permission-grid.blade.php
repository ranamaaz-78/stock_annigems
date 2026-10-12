{{-- Permission grid: module-grouped checkboxes for staff user forms. --}}
<div
    class="permission-grid border border-outline-variant rounded-lg p-4"
    data-permission-grid
    data-role-defaults='@json($roleDefaultsMap)'
    data-initial-role="{{ $initialRole }}"
>
    <div class="flex items-center justify-between mb-3">
        <p class="font-label-caps text-on-surface">Permissions</p>
        <p class="text-body-sm text-on-surface-variant">Adjust individual permissions or select a role to load defaults.</p>
    </div>

    @foreach($permissionsByModule as $module => $permissions)
        <div class="mb-4 last:mb-0">
            <p class="font-label-caps text-on-surface-variant border-b border-outline-variant pb-1 mb-2">{{ ucfirst($module) }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                @foreach($permissions as $permission)
                    @php
                        $isDisabled = in_array($permission->name, $disabledPermissions, true);
                        $isAdminOnly = $permission->name === 'reports.view';
                        $checked = in_array($permission->name, $selectedPermissions, true);
                    @endphp
                    <label class="inline-flex items-start gap-2 text-body-sm {{ $isDisabled ? 'opacity-60' : '' }}">
                        <input
                            type="checkbox"
                            name="permissions[]"
                            value="{{ $permission->name }}"
                            class="mt-0.5 rounded border-outline-variant text-primary focus:ring-primary permission-grid__checkbox"
                            @checked($checked)
                            @disabled($isDisabled)
                            data-permission-name="{{ $permission->name }}"
                        >
                        <span>
                            {{ $permission->display_name }}
                            @if($isAdminOnly)
                                <span class="block text-xs text-on-surface-variant">Admin only</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
