<?php

namespace Bokun\Bookings\Infrastructure\Validation;

class DataSanitizer
{
    /**
     * Sanitize a string value.
     *
     * @param mixed  $value
     * @param string $default
     */
    public function text($value, $default = '')
    {
        $value = $this->prepareScalar($value);

        if ('' === $value) {
            return $default;
        }

        return sanitize_text_field($value);
    }

    /**
     * Sanitize a key-like identifier.
     *
     * @param mixed  $value
     * @param string $default
     */
    public function key($value, $default = '')
    {
        $value = $this->prepareScalar($value);

        if ('' === $value) {
            return $default;
        }

        $sanitized = sanitize_key($value);

        return ('' === $sanitized) ? $default : $sanitized;
    }

    /**
     * Sanitize an email address.
     *
     * @param mixed  $value
     * @param string $default
     */
    public function email($value, $default = '')
    {
        $value = $this->prepareScalar($value);

        if ('' === $value) {
            return $default;
        }

        $email = sanitize_email($value);

        return ('' === $email) ? $default : $email;
    }

    /**
     * Sanitize a boolean flag.
     *
     * @param mixed $value
     * @param bool  $default
     */
    public function boolean($value, $default = false)
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        $filtered = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if (null === $filtered) {
            return (bool) $default;
        }

        return (bool) $filtered;
    }

    /**
     * Sanitize a numeric value to an integer.
     *
     * @param mixed    $value
     * @param int      $default
     * @param int|null $min
     * @param int|null $max
     */
    public function integer($value, $default = 0, $min = null, $max = null)
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (! is_numeric($value)) {
            return (int) $default;
        }

        $number = (int) $value;

        if (null !== $min && $number < $min) {
            $number = (int) $min;
        }

        if (null !== $max && $number > $max) {
            $number = (int) $max;
        }

        return $number;
    }

    /**
     * Sanitize a value that should match an enum of allowed keys.
     *
     * @param mixed  $value
     * @param array  $allowed
     * @param string $default
     */
    public function enum($value, array $allowed, $default = '')
    {
        $sanitized = $this->key($value, $default);

        if (in_array($sanitized, $allowed, true)) {
            return $sanitized;
        }

        return $default;
    }

    /**
     * Sanitize an associative array of credentials.
     *
     * @param array<string, mixed> $credentials
     * @param array<string, string> $map
     */
    public function credentials(array $credentials, array $map)
    {
        $sanitized = [];

        foreach ($map as $target => $source) {
            $value = isset($credentials[$source]) ? $credentials[$source] : '';
            $sanitized[$target] = $this->text($value);
        }

        return $sanitized;
    }

    /**
     * Prepare a scalar value from mixed input.
     *
     * @param mixed $value
     */
    private function prepareScalar($value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                $value = (string) $value;
            } else {
                return '';
            }
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return wp_unslash($value);
        }

        return '';
    }
}
