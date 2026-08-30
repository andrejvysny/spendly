<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\Repositories\GoCardlessRequisitionRepositoryInterface;
use App\Enums\GoCardlessRequisitionStatus;
use App\Models\GoCardlessRequisition;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class GoCardlessRequisitionRepository extends BaseRepository implements GoCardlessRequisitionRepositoryInterface
{
    public function __construct(GoCardlessRequisition $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForUser(int $userId, array $data): GoCardlessRequisition
    {
        $requisition = new GoCardlessRequisition($data);
        $requisition->user_id = $userId;
        $requisition->save();

        return $requisition;
    }

    public function findByReference(string $reference): ?GoCardlessRequisition
    {
        $requisition = $this->model->where('reference', $reference)->first();

        return $requisition instanceof GoCardlessRequisition ? $requisition : null;
    }

    public function findByRequisitionIdForUser(string $requisitionId, int $userId): ?GoCardlessRequisition
    {
        $requisition = $this->model->where('requisition_id', $requisitionId)
            ->where('user_id', $userId)
            ->first();

        return $requisition instanceof GoCardlessRequisition ? $requisition : null;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    public function upsertFromRemote(int $userId, array $remote): GoCardlessRequisition
    {
        $requisitionId = is_string($remote['id'] ?? null) ? $remote['id'] : '';
        $existing = $requisitionId !== '' ? $this->findByRequisitionIdForUser($requisitionId, $userId) : null;

        $goCardlessStatus = is_string($remote['status'] ?? null) ? $remote['status'] : null;
        $accounts = is_array($remote['accounts'] ?? null) ? array_values(array_filter($remote['accounts'], 'is_string')) : null;

        $data = [
            'gocardless_status' => $goCardlessStatus,
            'accounts' => $accounts,
        ];

        if (is_string($remote['institution_id'] ?? null) && $remote['institution_id'] !== '') {
            $data['institution_id'] = $remote['institution_id'];
        }
        if (is_string($remote['agreement'] ?? null) && $remote['agreement'] !== '') {
            $data['agreement_id'] = $remote['agreement'];
        }
        if (is_string($remote['link'] ?? null) && $remote['link'] !== '') {
            $data['link'] = $remote['link'];
        }

        // Locally terminal states (the user revoked it here, or it was superseded by a
        // newer link) must survive a refresh: GoCardless still reports superseded
        // requisitions as LN, which would otherwise resurrect them in the list.
        $localStatus = $existing?->status;
        $terminalLocally = $localStatus !== null && in_array(
            $localStatus,
            [GoCardlessRequisitionStatus::REVOKED, GoCardlessRequisitionStatus::REPLACED, GoCardlessRequisitionStatus::CANCELLED],
            true
        );

        if (! $terminalLocally) {
            $data['status'] = $goCardlessStatus !== null
                ? GoCardlessRequisitionStatus::fromGoCardless($goCardlessStatus)
                : ($localStatus ?? GoCardlessRequisitionStatus::PENDING);
        }

        if ($existing !== null) {
            $existing->update($data);

            return $existing;
        }

        $remoteReference = is_string($remote['reference'] ?? null) ? trim($remote['reference']) : '';

        return $this->createForUser($userId, array_merge($data, [
            'requisition_id' => $requisitionId,
            // The reference doubles as the signed-callback correlation key and is unique;
            // fall back to a fresh uuid when GoCardless has none for this requisition.
            'reference' => $remoteReference !== '' ? $remoteReference : (string) Str::uuid(),
            'status' => $data['status'] ?? GoCardlessRequisitionStatus::PENDING,
        ]));
    }

    /**
     * @return Collection<int, GoCardlessRequisition>
     */
    public function findByUser(int $userId): Collection
    {
        return $this->model->where('user_id', $userId)->get();
    }

    public function getActiveForInstitution(int $userId, string $institutionId): ?GoCardlessRequisition
    {
        $requisition = $this->model->where('user_id', $userId)
            ->where('institution_id', $institutionId)
            ->where('status', GoCardlessRequisitionStatus::LINKED->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $requisition instanceof GoCardlessRequisition ? $requisition : null;
    }

    /**
     * @return Collection<int, GoCardlessRequisition>
     */
    public function getExpiringBefore(CarbonInterface $threshold): Collection
    {
        return $this->model->where('status', GoCardlessRequisitionStatus::LINKED->value)
            ->whereNotNull('access_valid_until')
            ->where('access_valid_until', '<=', $threshold)
            ->get();
    }

    /**
     * @return Collection<int, GoCardlessRequisition>
     */
    public function getPollable(CarbonInterface $staleBefore, int $limit): Collection
    {
        return $this->model->whereIn('status', [
            GoCardlessRequisitionStatus::PENDING->value,
            GoCardlessRequisitionStatus::LINKED->value,
        ])
            ->where(function (Builder $query) use ($staleBefore) {
                $query->whereNull('last_checked_at')
                    ->orWhere('last_checked_at', '<', $staleBefore);
            })
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();
    }

    public function claimCallback(GoCardlessRequisition $requisition): bool
    {
        $now = now();

        $affected = $this->model->query()
            ->whereKey($requisition->getKey())
            ->whereNull('callback_completed_at')
            ->update(['callback_completed_at' => $now]);

        if ($affected === 1) {
            $requisition->setAttribute('callback_completed_at', $now);
            $requisition->syncOriginalAttribute('callback_completed_at');
        }

        return $affected === 1;
    }

    public function updateStatus(GoCardlessRequisition $requisition, GoCardlessRequisitionStatus $status, ?string $gocardlessStatus = null): void
    {
        $data = ['status' => $status];
        if ($gocardlessStatus !== null) {
            $data['gocardless_status'] = $gocardlessStatus;
        }

        $requisition->update($data);
    }

    public function markReplacedForInstitution(int $userId, string $institutionId, int $exceptId): int
    {
        return $this->model->where('user_id', $userId)
            ->where('institution_id', $institutionId)
            ->where('status', GoCardlessRequisitionStatus::LINKED->value)
            ->where('id', '!=', $exceptId)
            ->update(['status' => GoCardlessRequisitionStatus::REPLACED->value]);
    }

    public function userOwnsGoCardlessAccount(int $userId, string $gocardlessAccountId): bool
    {
        $requisitions = $this->model->where('user_id', $userId)->get(['accounts']);

        foreach ($requisitions as $requisition) {
            if ($requisition instanceof GoCardlessRequisition && $requisition->ownsGoCardlessAccount($gocardlessAccountId)) {
                return true;
            }
        }

        return false;
    }
}
