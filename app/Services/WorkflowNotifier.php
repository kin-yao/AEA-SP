<?php

namespace App\Services;

use App\Mail\WorkflowNotificationMail;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A thin wrapper so every workflow trigger point (request assigned, job
 * scheduled, quotation approved, invoice issued, and so on) sends mail the
 * same way: never throws back into the action that triggered it, and
 * always logs the real reason when a send fails.
 */
class WorkflowNotifier
{
    public static function customer(?Customer $customer, string $subject, array $lines, ?string $ctaUrl = null, ?string $ctaLabel = null): void
    {
        if (! $customer || ! $customer->main_contact_email) {
            return;
        }

        self::send($customer->main_contact_email, $customer->main_contact_name ?: $customer->name, $subject, $lines, $ctaUrl, $ctaLabel);
    }

    public static function user(?User $user, string $subject, array $lines, ?string $ctaUrl = null, ?string $ctaLabel = null): void
    {
        if (! $user) {
            return;
        }

        self::send($user->email, $user->name, $subject, $lines, $ctaUrl, $ctaLabel);
    }

    /**
     * Notifies every account holding the given role, e.g. every Supervisor
     * when a quotation routes to them for approval.
     */
    public static function role(string $role, string $subject, array $lines, ?string $ctaUrl = null, ?string $ctaLabel = null): void
    {
        foreach (User::role($role)->get() as $user) {
            self::send($user->email, $user->name, $subject, $lines, $ctaUrl, $ctaLabel);
        }
    }

    private static function send(string $email, string $name, string $subject, array $lines, ?string $ctaUrl, ?string $ctaLabel): void
    {
        try {
            Mail::to($email)->send(new WorkflowNotificationMail($name, $subject, $lines, $ctaUrl, $ctaLabel));
        } catch (\Throwable $e) {
            Log::warning('Workflow notification email failed', [
                'to' => $email,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
