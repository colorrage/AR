<?php

namespace ColorrageAR\Autoresponder\Tests\Support;

use ColorrageAR\Autoresponder\Contracts\Subscribable;

class SubscriberMocker
{
    private int|string $id = 1;

    private string $email = 'test@example.com';

    private string $name = 'Test User';

    private ?string $locale = 'en';

    public function withId(int|string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function withEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function withName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function withLocale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function make(): Subscribable
    {
        $id = $this->id;
        $email = $this->email;
        $name = $this->name;
        $locale = $this->locale;

        return new class($id, $email, $name, $locale) implements Subscribable
        {
            public function __construct(
                private readonly int|string $id,
                private readonly string $email,
                private readonly string $name,
                private readonly ?string $locale,
            ) {}

            public function getSubscribableId(): int|string
            {
                return $this->id;
            }

            public function getSubscribableEmail(): string
            {
                return $this->email;
            }

            public function getSubscribableName(): string
            {
                return $this->name;
            }

            public function getSubscribableLocale(): ?string
            {
                return $this->locale;
            }
        };
    }

    /**
     * Shorthand for default subscriber.
     */
    public static function default(): Subscribable
    {
        return (new self)->make();
    }
}
