<?php

namespace App\Enums;

enum Role: string
{
    case Dg = 'dg';
    case SituationRoom = 'situation_room';
    case Verifier = 'verifier';
    case DataOfficer = 'data_officer';
    case Legal = 'legal';
    case Finance = 'finance';
    case LgaSupervisor = 'lga_supervisor';
    case WardSupervisor = 'ward_supervisor';
    case Agent = 'agent';
    case CollationAgent = 'collation_agent';

    /** Roles that sign in to the mobile app with a phone OTP. */
    public function isField(): bool
    {
        return in_array($this, [self::Agent, self::CollationAgent, self::WardSupervisor, self::LgaSupervisor], true);
    }

    /** Roles allowed to see agent phone numbers. */
    public function canSeePhones(): bool
    {
        return in_array($this, [self::Dg, self::SituationRoom, self::LgaSupervisor, self::WardSupervisor], true);
    }
}
