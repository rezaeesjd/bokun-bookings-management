<?php

namespace Bokun\Bookings\Infrastructure;

use Bokun\Bookings\Infrastructure\Exception\ContainerException;
use Bokun\Bookings\Infrastructure\Exception\NotFoundException;

/**
 * Lightweight dependency injection container inspired by PSR-11.
 */
class Container
{
    /**
     * @var array<string, callable>
     */
    private $definitions = [];

    /**
     * @var array<string, bool>
     */
    private $shared = [];

    /**
     * @var array<string, mixed>
     */
    private $resolved = [];

    /**
     * Register a service definition.
     *
     * @param string   $id       Service identifier.
     * @param callable $factory  Factory that accepts the container and returns the service instance.
     * @param bool     $shared   Whether the service should be treated as a singleton.
     *
     * @return void
     */
    public function set($id, callable $factory, $shared = true)
    {
        $this->definitions[$id] = $factory;
        $this->shared[$id] = (bool) $shared;
        unset($this->resolved[$id]);
    }

    /**
     * Register a singleton service definition.
     *
     * @param string   $id
     * @param callable $factory
     *
     * @return void
     */
    public function singleton($id, callable $factory)
    {
        $this->set($id, $factory, true);
    }

    /**
     * Register a non-shared service definition.
     *
     * @param string   $id
     * @param callable $factory
     *
     * @return void
     */
    public function factory($id, callable $factory)
    {
        $this->set($id, $factory, false);
    }

    /**
     * Determine if the container knows about the given service.
     *
     * @param string $id
     *
     * @return bool
     */
    public function has($id)
    {
        return array_key_exists($id, $this->definitions) || array_key_exists($id, $this->resolved);
    }

    /**
     * Retrieve a service from the container.
     *
     * @param string $id
     *
     * @return mixed
     *
     * @throws NotFoundException   If the identifier is unknown.
     * @throws ContainerException  If instantiation fails.
     */
    public function get($id)
    {
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (! array_key_exists($id, $this->definitions)) {
            throw new NotFoundException(sprintf('Service "%s" is not registered in the container.', $id));
        }

        $factory = $this->definitions[$id];

        try {
            $service = $factory($this);
        } catch (\Throwable $exception) {
            throw new ContainerException(
                sprintf('Failed to create service "%s": %s', $id, $exception->getMessage()),
                (int) $exception->getCode(),
                $exception
            );
        }

        if (! $this->shared[$id]) {
            return $service;
        }

        $this->resolved[$id] = $service;

        return $service;
    }

    /**
     * Register service definitions through a provider.
     *
     * @param ServiceProviderInterface $provider
     *
     * @return void
     */
    public function register(ServiceProviderInterface $provider)
    {
        $provider->register($this);
    }
}
