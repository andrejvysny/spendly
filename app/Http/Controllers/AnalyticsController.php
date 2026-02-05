<?php

namespace App\Http\Controllers;

use App\Contracts\Repositories\AccountRepositoryInterface;
use App\Contracts\Repositories\AnalyticsRepositoryInterface;
use App\Contracts\Repositories\CategoryRepositoryInterface;
use App\Http\Requests\AnalyticsRequest;
use Carbon\Carbon;
use Inertia\Inertia;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly AnalyticsRepositoryInterface $analyticsRepository
    ) {}

    public function index(AnalyticsRequest $request): \Inertia\Response
    {

        $selectedAccountIds = $request->getAccountIds();

        $user_accounts = $this->accountRepository->findByUser($this->getAuthUserId());
        $user_categories = $this->categoryRepository->findByUser($this->getAuthUserId());

        // If no accounts selected or invalid input, use all user accounts
        if (empty($selectedAccountIds)) {
            $accountIds = $user_accounts->pluck('id');
        } else {
            // Ensure we get only unique, valid account IDs that belong to the user
            $accountIds = collect($selectedAccountIds)
                ->unique()  // Remove duplicates
                ->filter(function ($id) use ($user_accounts) {
                    return $user_accounts->contains('id', $id);
                })
                ->values(); // Reset array keys to prevent issues
        }

        // Parse date range from request
        $dateRange = $this->parseDateRange($request);
        $startDate = $dateRange['start'];
        $endDate = $dateRange['end'];

        // Get data for analytics
        $accountIdsArray = $accountIds->toArray();
        $cashFlow = $this->analyticsRepository->getCashflow($accountIdsArray, $startDate, $endDate);
        $categorySpending = $this->analyticsRepository->getCategorySpending($accountIdsArray, $startDate, $endDate);
        $merchantSpending = $this->analyticsRepository->getMerchantSpending($accountIdsArray, $startDate, $endDate);

        return Inertia::render('Analytics/Index', [
            'accounts' => $user_accounts,
            'categories' => $user_categories,  // Add categories to the response
            'selectedAccountIds' => $accountIds->toArray(),
            'cashflow' => $cashFlow,
            'categorySpending' => $categorySpending,
            'merchantSpending' => $merchantSpending,
            'dateRange' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d'),
            ],
            'period' => $request->input('period', 'last_month'),
        ]);
    }

    /**
     * Parse date range from request.
     *
     * @return array{start: Carbon, end: Carbon}
     */
    private function parseDateRange(AnalyticsRequest $request): array
    {
        $period = $request->getPeriod();
        $customStart = $request->getStartDate();
        $customEnd = $request->getEndDate();
        $specificMonth = $request->getSpecificMonth();

        $now = Carbon::now();

        // Default to previous month
        $start = $now->copy()->subMonth()->startOfMonth();
        $end = $now->copy()->subMonth()->endOfMonth()->endOfDay();

        switch ($period) {
            case 'custom':
                if ($customStart && $customEnd) {
                    $start = Carbon::parse($customStart)->startOfDay();
                    $end = Carbon::parse($customEnd)->endOfDay();
                }
                break;
            case 'specific_month':
                if ($specificMonth) {
                    // Parse YYYY-MM format
                    $monthDate = Carbon::createFromFormat('Y-m', $specificMonth);
                    $start = $monthDate->copy()->startOfMonth();
                    $end = $monthDate->copy()->endOfMonth()->endOfDay();
                }
                break;
            case 'current_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth()->endOfDay();
                break;
            case 'last_3_months':
                $start = $now->copy()->subMonths(3)->startOfMonth();
                $end = $now->copy()->endOfMonth()->endOfDay();
                break;
            case 'last_6_months':
                $start = $now->copy()->subMonths(6)->startOfMonth();
                $end = $now->copy()->endOfMonth()->endOfDay();
                break;
            case 'current_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear()->endOfDay();
                break;
            case 'last_year':
                $start = $now->copy()->subYear()->startOfYear();
                $end = $now->copy()->subYear()->endOfYear()->endOfDay();
                break;
                // Default is last_month
        }

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

}
