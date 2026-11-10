<?php

namespace App\Support;

// The organisation (workspace) this request works in, set by the `org` middleware from the
// X-Organisation header. Null = no workspace chosen: farm-membership rules alone decide access,
// which is also how officer/admin routes, the console and queued jobs run.
class CurrentOrganisation
{
    private ?string $id = null;

    public function id(): ?string
    {
        return $this->id;
    }

    public function set(?string $id): void
    {
        $this->id = $id;
    }
}
