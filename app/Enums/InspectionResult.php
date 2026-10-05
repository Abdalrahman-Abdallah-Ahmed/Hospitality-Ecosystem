<?php

namespace App\Enums;

enum InspectionResult: string
{
    case PASS = 'pass';
    case FAIL = 'fail';
}
