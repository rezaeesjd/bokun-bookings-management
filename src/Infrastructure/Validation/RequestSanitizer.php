<?php

namespace Bokun\Bookings\Infrastructure\Validation;

class RequestSanitizer
{
    /**
     * @var DataSanitizer
     */
    private $sanitizer;

    public function __construct(?DataSanitizer $sanitizer = null)
    {
        $this->sanitizer = $sanitizer ?: new DataSanitizer();
    }

    public function postText($key, $default = '')
    {
        $value = $this->getPostRaw($key, $default);

        return $this->sanitizer->text($value, $default);
    }

    public function postKey($key, $default = '')
    {
        $value = $this->getPostRaw($key, $default);

        return $this->sanitizer->key($value, $default);
    }

    public function postEnum($key, array $allowed, $default = '')
    {
        $value = $this->getPostRaw($key, $default);

        return $this->sanitizer->enum($value, $allowed, $default);
    }

    public function postBoolean($key, $default = false)
    {
        $value = $this->getPostRaw($key, $default);

        return $this->sanitizer->boolean($value, $default);
    }

    public function postInteger($key, $default = 0, $min = null, $max = null)
    {
        $value = $this->getPostRaw($key, $default);

        return $this->sanitizer->integer($value, $default, $min, $max);
    }

    public function postCredentials($apiKeyField, $secretKeyField)
    {
        return $this->sanitizer->credentials(
            [
                'api_key'    => $this->getPostRaw($apiKeyField, ''),
                'secret_key' => $this->getPostRaw($secretKeyField, ''),
            ],
            [
                'api_key'    => 'api_key',
                'secret_key' => 'secret_key',
            ]
        );
    }

    /**
     * @param string        $key
     * @param callable|null $valueSanitizer
     * @param mixed         $default
     *
     * @return array
     */
    public function postArray($key, ?callable $valueSanitizer = null, $default = [])
    {
        $value = $this->getPostRaw($key, $default);

        if (! is_array($value)) {
            return is_array($default) ? $default : [];
        }

        $sanitized = [];
        foreach ($value as $itemKey => $itemValue) {
            $sanitizedKey = $this->sanitizer->key($itemKey, (string) $itemKey);
            $sanitized[$sanitizedKey] = $this->sanitizeArrayValue($itemValue, $valueSanitizer);
        }

        return $sanitized;
    }

    public function getDataSanitizer()
    {
        return $this->sanitizer;
    }

    private function sanitizeArrayValue($value, $valueSanitizer)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $nestedKey => $nestedValue) {
                $sanitizedKey = $this->sanitizer->key($nestedKey, (string) $nestedKey);
                $sanitized[$sanitizedKey] = $this->sanitizeArrayValue($nestedValue, $valueSanitizer);
            }

            return $sanitized;
        }

        if (null === $valueSanitizer) {
            return $this->sanitizer->text($value, '');
        }

        return $valueSanitizer($value);
    }

    private function getPostRaw($key, $default = '')
    {
        if (! isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce handled by caller.
            return $default;
        }

        $value = $_POST[$key]; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce handled by caller.

        if (is_array($value)) {
            return array_map(static function ($item) {
                return is_string($item) ? wp_unslash($item) : $item;
            }, $value);
        }

        return wp_unslash($value);
    }
}
