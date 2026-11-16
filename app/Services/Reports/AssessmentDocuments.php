<?php

namespace App\Services\Reports;

use App\Models\Entity;
use Barryvdh\DomPDF\Facade\Pdf;

/** Renders the assessment statement PDF as raw bytes, for attaching to an email. */
class AssessmentDocuments
{
    public function statementPdf(Entity $circuit, array $data, array $society, bool $closed, string $preparedBy): string
    {
        return Pdf::loadView('reports.assessment-statement', [
            'entity' => $circuit->loadMissing('parent'),
            'data' => $data,
            's' => $society,
            'closed' => $closed,
            'preparedBy' => $preparedBy,
            'preparedAt' => now(),
        ])->setPaper('a4', 'portrait')
            ->setOptions(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false])
            ->output();
    }
}