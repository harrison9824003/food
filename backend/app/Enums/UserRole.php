<?php

namespace App\Enums;

enum UserRole: string
{
    case HqAdmin = 'hq_admin';
    case StoreManager = 'store_manager';
    case Staff = 'staff';
}
