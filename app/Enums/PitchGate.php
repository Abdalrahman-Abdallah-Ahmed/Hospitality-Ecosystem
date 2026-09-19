<?php

namespace App\Enums;

/**
 * Every reason a turn can be blocked from pitching, in evaluation order.
 */
enum PitchGate: string
{
    case FEATURE_DISABLED = 'feature_disabled';
    case NO_STAY = 'no_stay';
    case NOT_IN_HOUSE = 'not_in_house';
    case DEPARTING = 'departing';                       // departure day, or already departed
    case PITCH_CAP = 'pitch_cap';                       // contextual openings only
    case DECLINED_THIS_STAY = 'declined_this_stay';     // contextual openings only
    case ESCALATED_THIS_STAY = 'escalated_this_stay';
    case OPEN_SERVICE_REQUEST = 'open_service_request';
    case CLASSIFIER_FAILED = 'classifier_failed';
    case COMPLAINT_THIS_TURN = 'complaint_this_turn';
    case NO_OPENING = 'no_opening';
    case NO_CANDIDATES = 'no_candidates';
}
