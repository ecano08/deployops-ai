<?php

namespace App\Models;

use App\Enums\WorkspaceInvitationStatus;
use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'workspace_id',
    'invited_by',
    'email',
    'role',
    'token',
    'status',
    'expires_at',
    'accepted_at',
])]
#[Hidden(['token'])]
class WorkspaceInvitation extends Model
{
    public const EXPIRES_AFTER_DAYS = 7;

    /** @use HasFactory<WorkspaceInvitationFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'status' => WorkspaceInvitationStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    #[Scope]
    protected function pending(Builder $query): Builder
    {
        return $query->where('status', WorkspaceInvitationStatus::Pending)
            ->where('expires_at', '>', now());
    }

    public function isAcceptable(): bool
    {
        return $this->status === WorkspaceInvitationStatus::Pending
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function invitationUrl(): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/invitations/'.$this->token;
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (static::query()->where('token', $token)->exists());

        return $token;
    }

    public static function inviteTo(
        Workspace $workspace,
        string $email,
        WorkspaceRole $role,
        User $invitedBy,
    ): self {
        $attributes = [
            'email' => $email,
            'role' => $role,
            'token' => static::generateToken(),
            'status' => WorkspaceInvitationStatus::Pending,
            'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
            'invited_by' => $invitedBy->id,
            'accepted_at' => null,
        ];

        $invitation = $workspace->invitations()
            ->pending()
            ->where('email', $email)
            ->first();

        if ($invitation) {
            $invitation->update($attributes);

            return $invitation->refresh();
        }

        return $workspace->invitations()->create($attributes);
    }
}
