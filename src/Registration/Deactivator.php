<?php

namespace Bokun\Bookings\Registration;

class Deactivator
{
    public function register($pluginFile): void
    {
        register_deactivation_hook($pluginFile, [$this, 'deactivate']);
    }

    public function deactivate(): void
    {
        // Reserved for future cleanup logic.
    }
}
