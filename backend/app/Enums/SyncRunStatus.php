<?php

namespace App\Enums;

enum SyncRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
