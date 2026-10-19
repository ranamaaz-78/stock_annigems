<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\UpdateProfileRequest;
use App\Services\ProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * ProfileController
 *
 * My Profile — every branch user can update name, phone, and password.
 * Cannot change role, permissions, branch, or status.
 */
class ProfileController extends Controller
{
    public function __construct(
        protected ProfileService $profileService
    ) {}

    /**
     * Show profile edit form.
     */
    public function edit(): View
    {
        return view('branch.profile.edit', [
            'user' => auth()->user(),
        ]);
    }

    /**
     * Save profile changes.
     */
    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $this->profileService->updateProfile($request->user(), [
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'new_password' => $data['new_password'] ?? null,
        ]);

        return redirect()
            ->route('branch.profile.edit')
            ->with('success', __('Profile updated successfully.'));
    }
}
