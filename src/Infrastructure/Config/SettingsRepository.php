<?php

namespace Bokun\Bookings\Infrastructure\Config;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Repository for retrieving and persisting plugin configuration options.
 */
class SettingsRepository
{
    private const OPTION_API_KEY = 'bokun_api_key';
    private const OPTION_SECRET_KEY = 'bokun_secret_key';
    private const OPTION_API_KEY_UPGRADE = 'bokun_api_key_upgrade';
    private const OPTION_SECRET_KEY_UPGRADE = 'bokun_secret_key_upgrade';

    /**
     * @var array<string, string>
     */
    private $cache = [];

    /**
     * Retrieve the primary API access key.
     */
    public function getApiKey()
    {
        return $this->getOption(self::OPTION_API_KEY);
    }

    /**
     * Retrieve the primary API secret key.
     */
    public function getSecretKey()
    {
        return $this->getOption(self::OPTION_SECRET_KEY);
    }

    /**
     * Retrieve the upgrade API access key.
     */
    public function getUpgradeApiKey()
    {
        return $this->getOption(self::OPTION_API_KEY_UPGRADE);
    }

    /**
     * Retrieve the upgrade API secret key.
     */
    public function getUpgradeSecretKey()
    {
        return $this->getOption(self::OPTION_SECRET_KEY_UPGRADE);
    }

    /**
     * Retrieve the credentials for the primary connection.
     *
     * @return array{api_key: string, secret_key: string}
     */
    public function getPrimaryCredentials()
    {
        return [
            'api_key'    => $this->getApiKey(),
            'secret_key' => $this->getSecretKey(),
        ];
    }

    /**
     * Retrieve the credentials for the upgrade connection.
     *
     * @return array{api_key: string, secret_key: string}
     */
    public function getUpgradeCredentials()
    {
        return [
            'api_key'    => $this->getUpgradeApiKey(),
            'secret_key' => $this->getUpgradeSecretKey(),
        ];
    }

    /**
     * Retrieve credentials for the supplied context.
     *
     * @param string $context Either "upgrade" or any other value for the primary credentials.
     *
     * @return array{api_key: string, secret_key: string}
     */
    public function getCredentialsForContext($context)
    {
        if ('upgrade' === $this->normalizeContext($context)) {
            return $this->getUpgradeCredentials();
        }

        return $this->getPrimaryCredentials();
    }

    /**
     * Persist the primary API credentials.
     */
    public function savePrimaryCredentials($apiKey, $secretKey)
    {
        $this->setOption(self::OPTION_API_KEY, $apiKey);
        $this->setOption(self::OPTION_SECRET_KEY, $secretKey);
    }

    /**
     * Persist the upgrade API credentials.
     */
    public function saveUpgradeCredentials($apiKey, $secretKey)
    {
        $this->setOption(self::OPTION_API_KEY_UPGRADE, $apiKey);
        $this->setOption(self::OPTION_SECRET_KEY_UPGRADE, $secretKey);
    }

    /**
     * Persist credentials for the supplied context.
     *
     * @param string $context Either "upgrade" or any other value for the primary credentials.
     * @param string $apiKey
     * @param string $secretKey
     */
    public function saveCredentialsForContext($context, $apiKey, $secretKey)
    {
        if ('upgrade' === $this->normalizeContext($context)) {
            $this->saveUpgradeCredentials($apiKey, $secretKey);

            return;
        }

        $this->savePrimaryCredentials($apiKey, $secretKey);
    }

    /**
     * Retrieve an option value, caching the result for subsequent calls.
     *
     * @param string $option
     * @param string $default
     */
    private function getOption($option, $default = '')
    {
        if (array_key_exists($option, $this->cache)) {
            return $this->cache[$option];
        }

        $value = get_option($option, $default);

        if (! is_string($value)) {
            if (is_scalar($value)) {
                $value = (string) $value;
            } else {
                $value = $default;
            }
        }

        $value = sanitize_text_field($value);
        $this->cache[$option] = $value;

        return $value;
    }

    /**
     * Persist an option value and refresh the local cache.
     *
     * @param string $option
     * @param string $value
     */
    private function setOption($option, $value)
    {
        $sanitized = sanitize_text_field($value);
        update_option($option, $sanitized);
        $this->cache[$option] = $sanitized;
    }

    /**
     * Normalize the credentials context identifier.
     *
     * @param string $context
     */
    private function normalizeContext($context)
    {
        $context = is_string($context) ? strtolower($context) : '';

        return trim($context);
    }
}
