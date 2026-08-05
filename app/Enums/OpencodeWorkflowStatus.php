<?php

namespace App\Enums;

enum OpencodeWorkflowStatus: string
{
    case Running = 'running';
    case WaitingConfirmation = 'waiting_confirmation';
    case Completed = 'completed';
    case Stopped = 'stopped';
    case Failed = 'failed';
}
