<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChildProfile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['avatar_path', 'avatar_mime'];

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? '/api/child-profiles/'.$this->id.'/avatar' : null;
    }

    protected function casts(): array
    {
        return ['date_of_birth' => 'immutable_date', 'data_revision' => 'integer', 'consent_revision' => 'integer'];
    }
}
