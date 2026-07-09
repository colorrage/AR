<?php

namespace App\Models;

use ColorrageAR\Autoresponder\Contracts\Subscribable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements Subscribable
{
    protected $guarded = [];

    public function getSubscribableId(): int|string
    {
        return $this->id ?? 0;
    }

    public function getSubscribableEmail(): string
    {
        return $this->email ?? '';
    }

    public function getSubscribableName(): string
    {
        return $this->name ?? '';
    }

    public function getSubscribableLocale(): ?string
    {
        return $this->locale ?? null;
    }
}
