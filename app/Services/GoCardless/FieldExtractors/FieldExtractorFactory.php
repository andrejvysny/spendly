<?php

declare(strict_types=1);

namespace App\Services\GoCardless\FieldExtractors;

use App\Models\Account;

class FieldExtractorFactory
{
    /** @var array<string> */
    private const REVOLUT_IDS = [
        'REVOLUT_REVOGB21',
        'REVOLUT_REVOGB2L',
        'REVOLUT_REVOLT21',
    ];

    /** @var array<string> */
    private const SLSP_IDS = [
        'SLOVENSKÁ_SPORITEĽŇA_GIBASKBX',
        'SLSP_GIBASKBX',
        'GIBASKBX',
    ];

    public function make(Account $account): BankFieldExtractorInterface
    {
        $institutionId = $account->gocardless_institution_id;
        if ($institutionId !== null && $institutionId !== '') {
            $id = strtoupper(trim($institutionId));
            if (str_contains($id, 'REVOLUT') || in_array($id, self::REVOLUT_IDS, true)) {
                return new RevolutFieldExtractor;
            }
            if (str_contains($id, 'SLSP') || str_contains($id, 'GIBASKBX') || in_array($id, self::SLSP_IDS, true)) {
                return new SlspFieldExtractor;
            }
        }

        $bankName = strtolower((string) $account->bank_name);
        if ($bankName !== '' && str_contains($bankName, 'revolut')) {
            return new RevolutFieldExtractor;
        }
        if ($bankName !== '' && (str_contains($bankName, 'sporiteľňa') || str_contains($bankName, 'slsp'))) {
            return new SlspFieldExtractor;
        }

        return new GenericFieldExtractor;
    }
}
