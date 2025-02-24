<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkspaceCreated
{
    use Dispatchable, SerializesModels;

    public $workspace;

    public function __construct($workspace)
    {
        $this->workspace = $workspace;
    }
}
