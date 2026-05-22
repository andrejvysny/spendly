<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionLabelingStatsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $total = $this->resource['total'] ?? 0;
        $labeled = $this->resource['labeled'] ?? 0;
        $progressPercent = $total > 0 ? round(($labeled / $total) * 100) : 0;

        return [
            'total' => $total,
            'labeled' => $labeled,
            'unlabeled' => $this->resource['unlabeled'] ?? 0,
            'flagged' => $this->resource['flagged'] ?? 0,
            'duplicates' => $this->resource['duplicates'] ?? 0,
            'progress_percent' => $progressPercent,
        ];
    }
}
