<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationEmailConsistencyTest extends TestCase
{
    #[Test]
    public function approval_notification_uses_plain_language_and_includes_the_pickup_schedule(): void
    {
        $workflow = (string) file_get_contents(app_path('Services/RequestWorkflowService.php'));
        $notifications = (string) file_get_contents(app_path('Services/NotificationService.php'));

        $this->assertStringContainsString('Your borrowing request {$request->request_no} has been approved. Pickup is scheduled for', $workflow);
        $this->assertStringContainsString("['Pickup Schedule']", $notifications);
        $this->assertStringContainsString("['Pickup Deadline']", $notifications);
        $this->assertStringContainsString("=> 'Approved Items'", $notifications);
        $this->assertStringNotContainsString("['Inventory Status']", $notifications);
        $this->assertStringNotContainsString("['Claim Until']", $notifications);
        $this->assertStringNotContainsString('valid SPMU operating window', $notifications);
        $this->assertStringNotContainsString('SPMU Operational Calendar', $notifications);
        $this->assertStringNotContainsString('approved quantities are reserved', strtolower($notifications));
    }

    #[Test]
    public function automatic_pickup_activation_does_not_send_a_second_pickup_email(): void
    {
        $source = (string) file_get_contents(app_path('Services/CustodyService.php'));
        $start = strpos($source, 'public function ensurePickupRecord(');
        $end = strpos($source, 'public function expirePickupWindows(', $start ?: 0);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $method = substr($source, $start, $end - $start);

        $this->assertStringContainsString('REQUEST_APPROVED notification', $method);
        $this->assertStringNotContainsString("notifications->send(\n                    'PICKUP_SCHEDULED'", $method);
    }

    #[Test]
    public function pickup_schedule_event_is_reserved_for_schedule_updates(): void
    {
        $notifications = (string) file_get_contents(app_path('Services/NotificationService.php'));

        $this->assertStringContainsString("'PICKUP_SCHEDULED' => 'Pickup Schedule Updated'", $notifications);
        $this->assertStringContainsString('Your pickup schedule has been updated.', $notifications);
    }

    #[Test]
    public function items_released_is_an_in_system_confirmation_only(): void
    {
        $source = (string) file_get_contents(app_path('Services/CustodyService.php'));
        $needle = "'ITEMS_RELEASED'";
        $start = strpos($source, $needle);

        $this->assertNotFalse($start);

        $block = substr($source, $start, 1100);
        $this->assertStringContainsString("['SYSTEM']", $block);
        $this->assertStringNotContainsString("['SYSTEM', 'EMAIL']", $block);
        $this->assertStringContainsString('Please return them on or before', $block);
    }

    #[Test]
    public function return_reminders_use_borrower_facing_wording(): void
    {
        $command = (string) file_get_contents(app_path('Console/Commands/ProcessOperationalDeadlines.php'));
        $notifications = (string) file_get_contents(app_path('Services/NotificationService.php'));

        $this->assertStringContainsString('Reminder: the borrowed items under', $command);
        $this->assertStringContainsString("'RETURN_DUE_TODAY' => 'Return Due Today'", $notifications);
        $this->assertStringContainsString("'RETURN_DUE_TOMORROW' => 'Return Due Tomorrow'", $notifications);
        $this->assertStringNotContainsString('effective SPMU operational return date', $command);
    }

    #[Test]
    public function email_template_avoids_internal_system_phrasing(): void
    {
        $notifications = (string) file_get_contents(app_path('Services/NotificationService.php'));

        $this->assertStringContainsString('Sent {$this->escape($footerTimestamp)}.', $notifications);
        $this->assertStringNotContainsString('Notification recorded', $notifications);
        $this->assertStringNotContainsString('Relevant Items', $notifications);
    }


    #[Test]
    public function in_system_notification_titles_use_friendly_labels(): void
    {
        $view = (string) file_get_contents(resource_path('views/notifications/index.blade.php'));

        $this->assertStringContainsString("'PICKUP_SCHEDULED' => 'Pickup Schedule Updated'", $view);
        $this->assertStringContainsString("'PROPERTY_ACCOUNTABILITY_HEAD_DECISION_RECORDED' => 'Property Accountability Decision'", $view);
        $this->assertStringContainsString("'PAYMENT_VERIFIED' => 'Payment Confirmed'", $view);
        $this->assertStringNotContainsString("'A system update was recorded.'", $view);
    }

    #[Test]
    public function notification_email_has_no_pdf_attachment_layer(): void
    {
        $source = (string) file_get_contents(app_path('Services/NotificationService.php'));

        $this->assertStringContainsString('Mail::html', $source);
        $this->assertStringNotContainsString('->attach(', $source);
        $this->assertStringNotContainsString('attachData(', $source);
    }

    #[Test]
    public function late_return_notice_keeps_total_amount_out_of_the_notice(): void
    {
        $notice = (string) file_get_contents(resource_path('views/documents/accountability/late-return-notice.blade.php'));

        $this->assertStringContainsString('Official Late-Return Fee Rate', $notice);
        $this->assertStringNotContainsString('Assessed Amount', $notice);
        $this->assertStringContainsString('total amount due is intentionally not stated in this notice', $notice);
    }
}
