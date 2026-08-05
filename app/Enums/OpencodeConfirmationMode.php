<?php

namespace App\Enums;

enum OpencodeConfirmationMode: string
{
    case Proceed = 'proceed';
    case Answer = 'answer';
    case DecisionOnFailure = 'decision_on_failure';
}
