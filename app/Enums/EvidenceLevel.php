<?php

namespace App\Enums;

/**
 * How much a stored claim can be trusted. The whole platform depends on L1
 * (a fact) and L3 (a guess) never looking alike in the database.
 */
enum EvidenceLevel: string
{
    case L1 = 'L1';   // directly observed fact — e.g. a row imported from the PMS/POS
    case L2 = 'L2';   // strong inference from data — e.g. a returning guest matched by email
    case L3 = 'L3';   // hypothesis — e.g. "divers probably also book excursions"
    case L4 = 'L4';   // unverified / operator testimony / an unattributed or ambiguous figure
}
