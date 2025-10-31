<?php

namespace Bokun\Bookings\Admin\Menu;

interface AdminPageInterface
{
    public function getSlug(): string;

    public function getTitle(): string;

    public function getCapability(): string;

    public function render(): void;
}
