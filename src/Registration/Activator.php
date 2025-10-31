<?php

namespace Bokun\Bookings\Registration;

use Bokun\Bookings\Registration\Database\BookingHistoryTableCreator;

class Activator
{
    /**
     * @var string
     */
    private $version;

    /**
     * @var BookingHistoryTableCreator
     */
    private $tableCreator;

    public function __construct($version, BookingHistoryTableCreator $tableCreator)
    {
        $this->version = (string) $version;
        $this->tableCreator = $tableCreator;
    }

    public function register($pluginFile): void
    {
        register_activation_hook($pluginFile, [$this, 'activate']);
    }

    public function activate(): void
    {
        update_option('bokun_plugin', true);
        update_option('bokun_version', $this->version);

        $this->tableCreator->create();
    }
}
