<?php

namespace App\Enums;

enum TicketType: string
{
    case Normal = 'normal';
    case PreventiveMaintenance = 'preventive_maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::PreventiveMaintenance => 'Preventive Maintenance',
        };
    }
}
