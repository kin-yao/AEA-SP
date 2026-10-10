<?php

namespace App\Services;

use App\Mail\WorkflowNotificationMail;
use App\Models\Customer;
use App\Models\InboxNotification;
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
        if (! $customer) {
            return;
        }

        // People with a portal login for this customer see it in their bell too.
        foreach (User::where('customer_id', $customer->id)->get() as $portalUser) {
            self::inbox($portalUser, $subject, $lines, $ctaUrl, $ctaLabel);
        }

        if (! $customer->main_contact_email) {
            return;
        }

        self::send($customer->main_contact_email, $customer->main_contact_name ?: $customer->name, $subject, $lines, $ctaUrl, $ctaLabel);
    }

    public static function user(?User $user, string $subject, array $lines, ?string $ctaUrl = null, ?string $ctaLabel = null): void
    {
        if (! $user) {
            return;
        }

        self::inbox($user, $subject, $lines, $ctaUrl, $ctaLabel);

        if ($user->email_notifications) {
            self::send($user->email, $user->name, $subject, $lines, $ctaUrl, $ctaLabel);
        }
    }

    /**
     * Notifies every account holding the given role, e.g. every Supervisor
     * when a quotation routes to them for approval.
     */
    public static function role(string $role, string $subject, array $lines, ?string $ctaUrl = null, ?string $ctaLabel = null): void
    {
        foreach (User::role($role)->get() as $user) {
            self::user($user, $subject, $lines, $ctaUrl, $ctaLabel);
        }
    }

    private static function inbox(User $user, string $subject, array $lines, ?string $ctaUrl, ?string $ctaLabel): void
    {
        try {
            InboxNotification::create([
                'user_id' => $user->id,
                'title' => $subject,
                'lines' => array_values($lines),
                'url' => InboxNotification::safeUrl($ctaUrl),
                'label' => $ctaLabel,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Inbox notification failed', ['user' => $user->id, 'error' => $e->getMessage()]);
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
