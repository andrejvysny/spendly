<?php

declare(strict_types=1);

namespace App\Contracts\Repositories;

use App\Enums\GoCardlessRequisitionStatus;
use App\Models\GoCardlessRequisition;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface GoCardlessRequisitionRepositoryInterface
{
    /**
     * Create a requisition owned by the given user. The user_id is set
     * explicitly rather than accepted via $data, since
     * GoCardlessRequisition::$fillable excludes user_id to prevent
     * mass-assignment of ownership.
     *
     * @param  array<string, mixed>  $data
     */
    public function createForUser(int $userId, array $data): GoCardlessRequisition;

    public function findByReference(string $reference): ?GoCardlessRequisition;

    public function findByRequisitionIdForUser(string $requisitionId, int $userId): ?GoCardlessRequisition;

    /**
     * Create or refresh a local row from a raw GoCardless requisition payload.
     *
     * Only mirrors fields the remote is authoritative for; locally derived data
     * (access_valid_until, accepted_at, return_to, callback_completed_at) is never
     * cleared by a remote payload that omits it.
     *
     * @param  array<string, mixed>  $remote
     */
    public function upsertFromRemote(int $userId, array $remote): GoCardlessRequisition;

    /**
     * @return Collection<int, GoCardlessRequisition>
     */
    public function findByUser(int $userId): Collection;

    /**
     * The most recently created linked requisition for a user/institution pair.
     */
    public function getActiveForInstitution(int $userId, string $institutionId): ?GoCardlessRequisition;

    /**
     * Linked requisitions whose access expires at or before the threshold.
     *
     * @return Collection<int, GoCardlessRequisition>
     */
    public function getExpiringBefore(CarbonInterface $threshold): Collection;

    /**
     * Pending or linked requisitions due for a status poll: never checked, or
     * last checked before $staleBefore.
     *
     * @return Collection<int, GoCardlessRequisition>
     */
    public function getPollable(CarbonInterface $staleBefore, int $limit): Collection;

    /**
     * Atomically claim the callback for this requisition. Returns true only
     * for the caller that transitions callback_completed_at from null to
     * now(); concurrent/duplicate callbacks return false.
     */
    public function claimCallback(GoCardlessRequisition $requisition): bool;

    public function updateStatus(GoCardlessRequisition $requisition, GoCardlessRequisitionStatus $status, ?string $gocardlessStatus = null): void;

    /**
     * Mark all other linked requisitions for a user/institution as replaced.
     * Returns the number of rows affected.
     */
    public function markReplacedForInstitution(int $userId, string $institutionId, int $exceptId): int;

    /**
     * Whether any of the user's requisitions returned the given raw
     * GoCardless account ID. Scans in PHP rather than relying on JSON
     * containment operators, since those aren't portable across SQLite/MySQL/Postgres.
     */
    public function userOwnsGoCardlessAccount(int $userId, string $gocardlessAccountId): bool;
}
