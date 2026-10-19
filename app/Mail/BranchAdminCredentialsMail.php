<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * BranchAdminCredentialsMail
 *
 * Optional email of temporary Branch Admin credentials.
 * Used when SuperAdmin checks "send credentials via email".
 */
class BranchAdminCredentialsMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  User  $user  Newly created Branch Admin
     * @param  string  $plainPassword  One-time plain password (never stored)
     */
    public function __construct(
        public User $user,
        public string $plainPassword
    ) {}

    /**
     * Message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your :company Branch Admin account', ['company' => setting('company_name', 'Aanigemas')]),
        );
    }

    /**
     * Message content.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.branch-admin-credentials', [
                'user' => $this->user,
                'password' => $this->plainPassword,
                'loginUrl' => route('login'),
            ])->render(),
        );
    }
}
