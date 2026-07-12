<?php

namespace Fside\SkillNotifications\Models;

use Illuminate\Database\Eloquent\Model;

class SkillCompletion extends Model
{
    protected $table = 'skillnotify_skill_completions';

    protected $fillable = ['character_id', 'skill_id', 'from_level', 'to_level', 'notified_at'];

    protected $casts = [
        'character_id' => 'integer',
        'skill_id' => 'integer',
        'from_level' => 'integer',
        'to_level' => 'integer',
        'notified_at' => 'datetime',
    ];
}
