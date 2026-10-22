<?php

namespace Tests\Feature\Concerns;

/**
 * The api guard needs Passport keys; storage/*.key is gitignored, so tests use a throwaway
 * pair generated once per process.
 */
trait UsesPassportKeys
{
    /** @var array{private: string, public: string}|null */
    private static ?array $passportKeys = null;

    protected function usePassportKeys(): void
    {
        if (! self::$passportKeys) {
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            openssl_pkey_export($key, $private);
            self::$passportKeys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        config(['passport.private_key' => self::$passportKeys['private'], 'passport.public_key' => self::$passportKeys['public']]);
    }
}
