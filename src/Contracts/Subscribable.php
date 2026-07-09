<?php

namespace ColorrageAR\Autoresponder\Contracts;

interface Subscribable
{
    public function getSubscribableId(): int|string;

    public function getSubscribableEmail(): string;

    public function getSubscribableName(): string;

    public function getSubscribableLocale(): ?string;
}
