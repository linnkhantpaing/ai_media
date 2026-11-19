<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'preferred_locale'])]
#[Hidden(['password', 'remember_token', 'account_creation_key', 'account_creation_fingerprint', 'recap_name_dictionary', 'studio_preferences', 'default_preset_id'])]
class User extends Authenticatable
{
    protected $attributes = ['account_status' => 'active', 'managed_account' => false, 'account_revision' => 1, 'auth_version' => 0];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function currentPackageAssignment(): HasOne
    {
        return $this->hasOne(CustomerPackageAssignment::class)->whereIn('customer_package_assignments.id', CustomerPackageAssignment::currentIds());
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'managed_account' => 'boolean',
            'last_login_at' => 'datetime',
            'account_revision' => 'integer',
            'auth_version' => 'integer',
            'recap_name_dictionary' => 'array',
            'studio_preferences' => 'array',
        ];
    }
}
