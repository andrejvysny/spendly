<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RecurringDetectionSetting;
use App\Models\RecurringGroup;
use App\Services\RecurringDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RecurringGroupController extends Controller
{
    public function __construct(
        private readonly RecurringDetectionService $recurringDetectionService
    ) {}

    /**
     * List recurring groups for the authenticated user. Filter by status (suggested, confirmed, or both).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $query = RecurringGroup::where('user_id', $user->id)
            ->with(['merchant', 'account', 'transactions' => fn ($q) => $q->orderBy('booked_date', 'desc')->limit(20)]);

        $status = $request->query('status');
        if ($status === 'suggested') {
            $query->suggested();
        } elseif ($status === 'confirmed') {
            $query->confirmed();
        } elseif ($status === 'dismissed') {
            $query->dismissed();
        }

        $groups = $query->orderBy('updated_at', 'desc')->get();

        $suggested = $groups->where('status', RecurringGroup::STATUS_SUGGESTED)->values();
        $confirmed = $groups->where('status', RecurringGroup::STATUS_CONFIRMED)->values();

        return response()->json([
            'data' => [
                'suggested' => $suggested,
                'confirmed' => $confirmed,
            ],
            'meta' => [
                'suggested_count' => $suggested->count(),
                'confirmed_count' => $confirmed->count(),
            ],
        ]);
    }

    /**
     * Confirm a suggested recurring group (link transactions and optionally add Recurring tag).
     */
    public function confirm(Request $request, RecurringGroup $recurringGroup): JsonResponse
    {
        Gate::authorize('update', $recurringGroup);

        $addTag = $request->boolean('add_recurring_tag', true);
        $this->recurringDetectionService->confirmGroup($recurringGroup, $addTag);

        return response()->json([
            'message' => 'Recurring group confirmed',
            'data' => $recurringGroup->fresh(['merchant', 'account', 'transactions']),
        ]);
    }

    /**
     * Dismiss a suggested recurring group.
     */
    public function dismiss(RecurringGroup $recurringGroup): JsonResponse
    {
        Gate::authorize('update', $recurringGroup);

        $this->recurringDetectionService->dismissGroup($recurringGroup);

        return response()->json([
            'message' => 'Recurring group dismissed',
        ]);
    }

    /**
     * Unlink a confirmed group from its transactions (optionally remove Recurring tag).
     */
    public function unlink(Request $request, RecurringGroup $recurringGroup): JsonResponse
    {
        Gate::authorize('update', $recurringGroup);

        $removeTag = $request->boolean('remove_recurring_tag', true);
        $this->recurringDetectionService->unlinkGroup($recurringGroup, $removeTag);

        return response()->json([
            'message' => 'Recurring group unlinked from transactions',
        ]);
    }

    /**
     * Analytics: monthly recurring total (sum of transactions linked to confirmed groups in the given month).
     */
    public function analytics(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $month = $request->query('month'); // Y-m
        $year = $request->query('year');
        if ($month !== null && $year !== null) {
            $parsed = \Carbon\Carbon::createFromFormat('Y-m', "{$year}-{$month}");
            $from = $parsed instanceof \Carbon\Carbon ? $parsed->copy()->startOfMonth() : now()->startOfMonth();
        } else {
            $from = now()->copy()->startOfMonth();
        }
        $to = $from->copy()->endOfMonth();

        $confirmedGroupIds = RecurringGroup::where('user_id', $user->id)
            ->where('status', RecurringGroup::STATUS_CONFIRMED)
            ->pluck('id')
            ->all();

        if ($confirmedGroupIds === []) {
            return response()->json([
                'data' => [
                    'period' => [
                        'from' => $from->toDateString(),
                        'to' => $to->toDateString(),
                    ],
                    'total_recurring' => 0.0,
                    'by_group' => [],
                ],
            ]);
        }

        $total = \App\Models\Transaction::whereIn('recurring_group_id', $confirmedGroupIds)
            ->whereBetween('booked_date', [$from, $to])
            ->sum('amount');

        $byGroup = RecurringGroup::where('user_id', $user->id)
            ->where('status', RecurringGroup::STATUS_CONFIRMED)
            ->with(['transactions' => fn ($q) => $q->whereBetween('booked_date', [$from, $to])])
            ->get()
            ->map(function (RecurringGroup $g) {
                $sum = $g->transactions->sum('amount');
                $periodTotal = round((float) (string) (is_numeric($sum) ? $sum : 0), 2);

                return [
                    'id' => $g->id,
                    'name' => $g->name,
                    'interval' => $g->interval,
                    'period_total' => $periodTotal,
                ];
            })
            ->filter(fn (array $r) => $r['period_total'] != 0)
            ->values();

        return response()->json([
            'data' => [
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'total_recurring' => (float) $total,
                'by_group' => $byGroup,
            ],
        ]);
    }

    /**
     * Get recurring detection settings for the authenticated user.
     */
    public function getSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $settings = RecurringDetectionSetting::forUser($user->id);

        return response()->json(['data' => $settings]);
    }

    /**
     * Update recurring detection settings.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'scope' => ['nullable', Rule::in([RecurringDetectionSetting::SCOPE_PER_ACCOUNT, RecurringDetectionSetting::SCOPE_PER_USER])],
            'group_by' => ['nullable', Rule::in([RecurringDetectionSetting::GROUP_BY_MERCHANT_ONLY, RecurringDetectionSetting::GROUP_BY_MERCHANT_AND_DESCRIPTION])],
            'amount_variance_type' => ['nullable', Rule::in([RecurringDetectionSetting::AMOUNT_VARIANCE_PERCENT, RecurringDetectionSetting::AMOUNT_VARIANCE_FIXED])],
            'amount_variance_value' => ['nullable', 'numeric', 'min:0'],
            'min_occurrences' => ['nullable', 'integer', 'min:2', 'max:10'],
            'run_after_import' => ['nullable', 'boolean'],
            'scheduled_enabled' => ['nullable', 'boolean'],
        ]);

        $settings = RecurringDetectionSetting::forUser($user->id);
        $settings->update(array_filter($validated, fn ($v) => $v !== null));

        return response()->json([
            'message' => 'Settings updated',
            'data' => $settings->fresh(),
        ]);
    }
}
