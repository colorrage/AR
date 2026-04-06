<?php

namespace CmrManagement\Autoresponder\Contracts;

interface TokenResolver
{
    /**
     * Resolve a custom token value for the given subscriber.
     *
     * @param string $token The token key (e.g. 'premium_status')
     * @param Subscribable $subscriber
     * @return string|null Null if this resolver cannot handle the token.
     */
    public function resolve(string $token, Subscribable $subscriber): ?string;
}
