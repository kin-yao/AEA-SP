<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\InboxNotification;
use App\Models\User;
use App\Services\WorkflowNotifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** The notification bell and the profile page. Run against a seeded database. */
class ProfileAndBellTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_role_notification_reaches_only_that_role_and_the_bell_shows_it(): void
    {
        Mail::fake();
        $title = 'Test notice '.uniqid();
        WorkflowNotifier::role('Supervisor', $title, ['Line one'], url('/approvals'), 'Open');

        $sup = User::role('Supervisor')->firstOrFail();
        $tech = User::role('Technician')->firstOrFail();
        $this->assertSame(1, InboxNotification::where('user_id', $sup->id)->where('title', $title)->count());
        $this->assertSame(0, InboxNotification::where('user_id', $tech->id)->where('title', $title)->count());
        $this->assertSame('/approvals', InboxNotification::where('title', $title)->first()->url);

        $this->actingAs($sup);
        Livewire::test('notification-bell')->assertSee($title);
        $this->actingAs($tech);
        Livewire::test('notification-bell')->assertDontSee($title);
    }

    public function test_opening_marks_read_and_nobody_can_touch_someone_elses(): void
    {
        $a = User::role('Supervisor')->firstOrFail();
        $b = User::role('Technician')->firstOrFail();
        $mine = InboxNotification::create(['user_id' => $a->id, 'title' => 'Mine', 'url' => '/approvals']);
        $theirs = InboxNotification::create(['user_id' => $b->id, 'title' => 'Theirs']);

        $this->actingAs($a);
        Livewire::test('notification-bell')->call('open', $mine->id)->assertRedirect('/approvals');
        $this->assertNotNull($mine->fresh()->read_at);

        Livewire::test('notification-bell')->call('mark', $theirs->id)->call('markAll');
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_a_customer_portal_user_gets_their_customers_notifications_and_links_stay_on_site(): void
    {
        Mail::fake();
        $portal = User::whereNotNull('customer_id')->firstOrFail();
        WorkflowNotifier::customer(Customer::findOrFail($portal->customer_id), 'Your quote is ready', ['x'], 'https://evil.example.com/steal', 'Go');

        $n = InboxNotification::where('user_id', $portal->id)->where('title', 'Your quote is ready')->firstOrFail();
        $this->assertNull($n->url);
    }

    public function test_email_copy_can_be_switched_off_but_the_bell_still_gets_it(): void
    {
        Mail::fake();
        $u = User::role('Supervisor')->firstOrFail();
        $u->update(['email_notifications' => false]);
        WorkflowNotifier::user($u, 'Quiet one', ['x']);
        Mail::assertNothingSent();
        $this->assertSame(1, InboxNotification::where('user_id', $u->id)->where('title', 'Quiet one')->count());
    }

    public function test_profile_details_and_password_change(): void
    {
        $u = User::role('Service Admin')->firstOrFail();
        $u->update(['password' => 'OldPassw0rd!', 'must_change_password' => false]);
        $this->actingAs($u);

        $this->get('/profile')->assertOk()->assertSee('My profile');

        Livewire::test('profile')->set('name', 'Hilda M Test')->set('phone', '+254700111222')->set('emailNotifications', false)
            ->call('saveDetails')->assertHasNoErrors();
        $u->refresh();
        $this->assertSame('Hilda M Test', $u->name);
        $this->assertFalse($u->email_notifications);

        $c = Livewire::test('profile');
        $c->set('currentPassword', 'wrong')->set('newPassword', 'NewPassw0rd!2')->set('newPassword_confirmation', 'NewPassw0rd!2')
            ->call('changePassword')->assertHasErrors(['currentPassword']);
        $c->set('currentPassword', 'OldPassw0rd!')->set('newPassword', 'short')->set('newPassword_confirmation', 'short')
            ->call('changePassword')->assertHasErrors(['newPassword']);
        $c->set('currentPassword', 'OldPassw0rd!')->set('newPassword', 'NewPassw0rd!2')->set('newPassword_confirmation', 'NewPassw0rd!2')
            ->call('changePassword')->assertHasNoErrors();
        $this->assertTrue(Hash::check('NewPassw0rd!2', $u->fresh()->password));
    }

    public function test_notifications_page_lists_only_my_items(): void
    {
        $a = User::role('Supervisor')->firstOrFail();
        $b = User::role('Technician')->firstOrFail();
        InboxNotification::create(['user_id' => $a->id, 'title' => 'ForA-'.($t = uniqid())]);
        InboxNotification::create(['user_id' => $b->id, 'title' => 'ForB-'.$t]);
        $this->actingAs($a)->get('/notifications')->assertOk()->assertSee('ForA-'.$t)->assertDontSee('ForB-'.$t);
    }
}
