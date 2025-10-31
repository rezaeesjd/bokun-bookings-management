<?php

namespace Bokun\Bookings\Infrastructure;

interface ServiceProviderInterface
{
    /**
     * Register service bindings on the provided container.
     *
     * @param Container $container
     *
     * @return void
     */
    public function register(Container $container);
}
