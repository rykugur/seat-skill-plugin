<?php

namespace Fside\SkillNotifications\Models;

use Illuminate\Database\Eloquent\Model;

class SkillSnapshot extends Model
{
    protected $table = 'skillnotify_skill_snapshots';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['character_id', 'skill_id', 'trained_skill_level'];

    protected $casts = [
        'character_id' => 'integer',
        'skill_id' => 'integer',
        'trained_skill_level' => 'integer',
    ];
}
