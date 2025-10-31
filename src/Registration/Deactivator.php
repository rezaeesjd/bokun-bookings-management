<?php

namespace Bokun\Bookings\Registration;

use Bokun\Bookings\Application\Synchronization\BookingSyncService;

class Deactivator
{
    /**
     * @var BookingSyncService
     */
    private $syncService;

    public function __construct(BookingSyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    public function register($pluginFile): void
    {
        register_deactivation_hook($pluginFile, [$this, 'deactivate']);
    }

    public function deactivate(): void
    {
        $this->syncService->clearOnDeactivation();
    }
}
