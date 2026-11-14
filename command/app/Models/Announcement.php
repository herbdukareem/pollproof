<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A sentence the command room reads out loud. See App\Services\Voice\Announcer. */
class Announcement extends Model
{
    protected $guarded = ['id'];
}
