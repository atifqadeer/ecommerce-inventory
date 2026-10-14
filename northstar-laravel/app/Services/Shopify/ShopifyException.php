<?php

namespace App\Services\Shopify;

use RuntimeException;

class ShopifyException extends RuntimeException
{
    /** @param  bool  $authFailed  true when the credentials themselves need fixing (bad token, missing scopes). */
    public function __construct(string $message, public readonly bool $authFailed = false)
    {
        parent::__construct($message);
    }
}
