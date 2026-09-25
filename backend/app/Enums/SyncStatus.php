<?php

namespace App\Enums;

enum SyncStatus: string
{
    /** Installed, first sync not started yet. */
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
