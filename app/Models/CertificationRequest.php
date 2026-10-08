<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CertificationRequest extends Model
{
    use HasUuids;

    public const OPEN = ['submitted', 'assigned', 'in_review', 'needs_info'];

    // Allowed status changes: from => [to, ...]. Every transition goes through moveTo().
    public const TRANSITIONS = [
        'submitted' => ['assigned', 'cancelled'],
        'assigned' => ['assigned', 'in_review', 'approved', 'needs_info', 'rejected', 'cancelled'],
        'in_review' => ['assigned', 'approved', 'needs_info', 'rejected'],
        'needs_info' => ['assigned', 'cancelled'],
        'approved' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    protected $fillable = ['inspection_method', 'owner_note'];
    protected $casts = ['assigned_at' => 'datetime', 'decided_at' => 'datetime'];

    public function animal()
    {
        return $this->belongsTo(Animal::class)->withTrashed();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function officer()
    {
        return $this->belongsTo(User::class, 'assigned_officer_id');
    }

    public function certificate()
    {
        return $this->hasOne(Certificate::class, 'request_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /** Change status (+ extra fields), save, and write the audit log. Throws 422 if not allowed. */
    public function moveTo(string $status, array $fields = [], array $audit = []): self
    {
        abort_unless($this->canMoveTo($status), 422, "A request that is {$this->status} can't become {$status}.");

        $from = $this->status;
        $this->forceFill(['status' => $status] + $fields)->save();
        AuditLog::record("request.$status", $this, ['from' => $from] + $audit);

        return $this;
    }
}
