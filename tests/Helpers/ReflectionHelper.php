<?php

declare(strict_types=1);

namespace Tests\Helpers;

trait ReflectionHelper
{
    /**
     * Set a private/protected property on an object (useful for #[Inject] properties).
     */
    protected function setProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($object, $value);
    }

    /**
     * Get a private/protected property from an object.
     */
    protected function getProperty(object $object, string $property): mixed
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setAccessible(true);
        return $reflection->getValue($object);
    }

    /**
     * Call a private/protected method on an object.
     */
    protected function callMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }
}
