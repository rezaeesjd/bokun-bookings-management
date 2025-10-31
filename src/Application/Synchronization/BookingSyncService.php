<?php

namespace Bokun\Bookings\Application\Synchronization;

use DateTimeImmutable;
use DateTimeZone;

class BookingSyncService
{
    /**
     * @var string
     */
    private $eventHook = 'bokun_booking_sync_event';

    /**
     * @var string
     */
    private $statusOption = 'bokun_booking_sync_status';

    /**
     * @var string
     */
    private $lockKey = 'bokun_booking_sync_lock';

    /**
     * @var int
     */
    private $lockDuration = 15;

    /**
     * Wire the service into WordPress hooks.
     */
    public function register(): void
    {
        add_filter('cron_schedules', [$this, 'registerSchedule']);
        add_action('init', [$this, 'maybeScheduleEvent']);
        add_action($this->eventHook, [$this, 'handleScheduledSync']);
    }

    /**
     * Ensure the recurring event is scheduled, optionally forcing a reschedule.
     */
    public function maybeScheduleEvent($force = false): void
    {
        if ($force) {
            $this->clearScheduledEvent();
        }

        if (! wp_next_scheduled($this->eventHook)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'bokun_booking_quarter_hour', $this->eventHook);
        }

        $this->updateNextRun();
    }

    /**
     * Register a custom quarter-hour schedule used for syncs.
     *
     * @param array<string, array<string, mixed>> $schedules
     *
     * @return array<string, array<string, mixed>>
     */
    public function registerSchedule(array $schedules): array
    {
        if (! isset($schedules['bokun_booking_quarter_hour'])) {
            $schedules['bokun_booking_quarter_hour'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __('Every 15 Minutes', BOKUN_TEXT_DOMAIN),
            ];
        }

        return $schedules;
    }

    /**
     * Handle the scheduled cron event.
     */
    public function handleScheduledSync(): void
    {
        $this->run('cron');
    }

    /**
     * Trigger a sync immediately.
     *
     * @param string $context
     *
     * @return array<string, mixed>
     */
    public function run($context = 'manual'): array
    {
        if (! $this->acquireLock()) {
            $result = [
                'status'  => 'locked',
                'message' => __('A booking sync is already running.', BOKUN_TEXT_DOMAIN),
                'context' => $context,
                'summary' => [],
            ];

            $this->persistStatus($result);

            return $result;
        }

        $status  = 'success';
        $message = __('Bookings are up to date.', BOKUN_TEXT_DOMAIN);
        $summary = [];
        $error   = '';

        try {
            $this->includeLegacyDependencies();

            $fetchContext = apply_filters('bokun_booking_sync_fetch_context', '');
            $bookings = '' !== $fetchContext ? bokun_fetch_bookings($fetchContext) : bokun_fetch_bookings();

            if (is_string($bookings)) {
                $normalized = trim($bookings);
                $status = (stripos($normalized, 'error') === 0) ? 'error' : 'empty';
                $message = $normalized !== '' ? $normalized : __('No bookings available to process.', BOKUN_TEXT_DOMAIN);

                if ('error' === $status) {
                    $error = $normalized;
                }
            } else {
                $saveContext = apply_filters('bokun_booking_sync_save_context', $context === 'cron' ? 'cron' : 'manual');
                $summary = bokun_save_bookings_as_posts($bookings, $saveContext);

                if (! is_array($summary)) {
                    $summary = [];
                }

                $message = $this->buildSuccessMessage($summary);
            }
        } catch (\Throwable $exception) {
            $status  = 'error';
            $message = __('The booking sync failed. Check the logs for more details.', BOKUN_TEXT_DOMAIN);
            $error   = $exception->getMessage();
            if (function_exists('error_log')) {
                error_log('[Bokun Booking Sync] ' . $exception->getMessage());
            }
        }

        $result = [
            'status'  => $status,
            'message' => $message,
            'context' => $context,
            'summary' => $this->normalizeSummary($summary),
        ];

        if ('' !== $error) {
            $result['error'] = $error;
        }

        $this->persistStatus($result);
        $this->releaseLock();

        return $result;
    }

    /**
     * Ensure a scheduled event exists immediately after activation.
     */
    public function scheduleOnActivation(): void
    {
        $this->maybeScheduleEvent(true);
    }

    /**
     * Clear scheduled events on deactivation.
     */
    public function clearOnDeactivation(): void
    {
        $this->clearScheduledEvent();
    }

    /**
     * Retrieve the raw status array.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        $status = get_option($this->statusOption, []);

        if (! is_array($status)) {
            $status = [];
        }

        $status += [
            'last_run'     => null,
            'last_status'  => '',
            'last_message' => '',
            'last_context' => '',
            'last_summary' => [],
            'last_error'   => '',
            'next_run'     => null,
        ];

        if (! $status['next_run']) {
            $status['next_run'] = $this->getNextScheduledTimestamp();
        }

        return $status;
    }

    /**
     * Normalize the stored status for display in the admin UI.
     *
     * @return array<string, mixed>
     */
    public function getDisplayStatus(): array
    {
        $status = $this->getStatus();
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

        $lastRun = isset($status['last_run']) ? (int) $status['last_run'] : 0;
        $lastRunDate = $lastRun > 0 ? (new DateTimeImmutable('@' . $lastRun))->setTimezone($timezone) : null;

        $status['last_run_display'] = $lastRunDate ? $lastRunDate->format(get_option('date_format') . ' ' . get_option('time_format')) : null;
        $status['last_run_relative'] = ($lastRun > 0 && function_exists('human_time_diff')) ? human_time_diff($lastRun, current_time('timestamp')) : null;

        $nextRun = isset($status['next_run']) ? (int) $status['next_run'] : 0;
        $nextRunDate = $nextRun > 0 ? (new DateTimeImmutable('@' . $nextRun))->setTimezone($timezone) : null;
        $status['next_run_display'] = $nextRunDate ? $nextRunDate->format(get_option('date_format') . ' ' . get_option('time_format')) : null;

        if ($nextRun > 0 && function_exists('human_time_diff')) {
            $status['next_run_relative'] = human_time_diff(current_time('timestamp'), $nextRun);
        } else {
            $status['next_run_relative'] = null;
        }

        return $status;
    }

    private function buildSuccessMessage(array $summary): string
    {
        $summary = $this->normalizeSummary($summary);

        $created = isset($summary['created']) ? (int) $summary['created'] : 0;
        $updated = isset($summary['updated']) ? (int) $summary['updated'] : 0;

        if (0 === $created && 0 === $updated) {
            return __('No new bookings were imported during the latest sync.', BOKUN_TEXT_DOMAIN);
        }

        return sprintf(
            __('Imported %1$d new bookings and updated %2$d bookings.', BOKUN_TEXT_DOMAIN),
            $created,
            $updated
        );
    }

    /**
     * @param array<string, mixed> $summary
     *
     * @return array<string, int>
     */
    private function normalizeSummary(array $summary): array
    {
        $defaults = [
            'total'     => 0,
            'processed' => 0,
            'created'   => 0,
            'updated'   => 0,
            'skipped'   => 0,
        ];

        foreach ($defaults as $key => $value) {
            if (isset($summary[$key]) && is_numeric($summary[$key])) {
                $defaults[$key] = (int) $summary[$key];
            }
        }

        return $defaults;
    }

    private function persistStatus(array $result): void
    {
        $changes = [
            'last_run'     => $this->currentTimestamp(),
            'last_status'  => $result['status'],
            'last_message' => $result['message'],
            'last_context' => $result['context'],
            'last_summary' => $result['summary'],
            'last_error'   => isset($result['error']) ? $result['error'] : '',
            'next_run'     => $this->getNextScheduledTimestamp(),
        ];

        $this->updateStatus($changes);
    }

    private function updateNextRun(): void
    {
        $this->updateStatus([
            'next_run' => $this->getNextScheduledTimestamp(),
        ]);
    }

    private function updateStatus(array $changes): void
    {
        $status = $this->getStatus();
        foreach ($changes as $key => $value) {
            $status[$key] = $value;
        }

        update_option($this->statusOption, $status, false);
    }

    private function getNextScheduledTimestamp(): ?int
    {
        $timestamp = wp_next_scheduled($this->eventHook);

        return $timestamp ? (int) $timestamp : null;
    }

    private function clearScheduledEvent(): void
    {
        $timestamp = wp_next_scheduled($this->eventHook);

        while ($timestamp) {
            wp_unschedule_event($timestamp, $this->eventHook);
            $timestamp = wp_next_scheduled($this->eventHook);
        }

        $this->updateStatus(['next_run' => null]);
    }

    private function includeLegacyDependencies(): void
    {
        if (! function_exists('bokun_fetch_bookings') && defined('BOKUN_INCLUDES_DIR')) {
            $managerFile = rtrim(BOKUN_INCLUDES_DIR, '/\\') . '/bokun-bookings-manager.php';
            if (file_exists($managerFile)) {
                include_once $managerFile;
            }
        }

        if (! function_exists('bokun_save_bookings_as_posts') && defined('BOKUN_INCLUDES_DIR')) {
            $managerFile = rtrim(BOKUN_INCLUDES_DIR, '/\\') . '/bokun-bookings-manager.php';
            if (file_exists($managerFile)) {
                include_once $managerFile;
            }
        }
    }

    private function acquireLock(): bool
    {
        $key = $this->lockKey;
        $duration = max(1, (int) $this->lockDuration) * MINUTE_IN_SECONDS;

        if (false !== get_transient($key)) {
            return false;
        }

        set_transient($key, 1, $duration);

        return true;
    }

    private function releaseLock(): void
    {
        delete_transient($this->lockKey);
    }

    private function currentTimestamp(): int
    {
        return function_exists('current_time') ? current_time('timestamp') : time();
    }
}
