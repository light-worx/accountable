<?php

namespace App\Http\Controllers;

use App\Models\Entity;
use App\Services\Ledger\PeriodService;
use App\Services\Reports\PeriodStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\Reports\ReturnSchedule;
use App\Support\Returns\ReturnForms;

class ReportController extends Controller
{
    public function statement(Request $request, Entity $entity, string $period, PeriodStatement $statement, PeriodService $periods)
    {
        $user = $request->user();

        // Any role in the society or circuit may read its reports; nobody else.
        abort_unless($user && $user->canAccessTenant($entity) && $user->roleIn($entity), 403);

        [$from, $to, $title, $monthly] = $this->resolve($period);

        $data = $statement->build($entity, $from, $to);

        activity('report')->performedOn($entity)->causedBy($user)
            ->withProperties(['period' => $period])->log('management accounts generated');

        $pdf = Pdf::loadView('reports.statement', [
            'entity' => $entity->loadMissing('parent'),
            'title' => $title,
            'data' => $data,
            'closed' => $monthly ? $periods->isClosed($entity, $from) : null,
            'preparedBy' => $user->name,
            'preparedAt' => now(),
        ])->setPaper('a4', $data['budget'] ? 'landscape' : 'portrait')
            ->setOptions(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);

        return $pdf->stream(Str::slug($entity->name) . "-management-accounts-{$period}.pdf");
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable,2:string,3:bool} */
    private function resolve(string $period): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $m)) {
            $from = CarbonImmutable::create((int) $m[1], (int) $m[2], 1)->startOfDay();

            return [$from, $from->endOfMonth()->startOfDay(), $from->format('F Y'), true];
        }

        if (preg_match('/^year-(\d{4})$/', $period, $m)) {
            $from = CarbonImmutable::create((int) $m[1], 1, 1)->startOfDay();

            return [$from, $from->endOfYear()->startOfDay(), "Year ended 31 December {$m[1]}", false];
        }

        abort(404);
    }

    public function schedule(Request $request, Entity $entity, int $year, ReturnSchedule $return)
    {
        $user = $request->user();

        abort_unless($user && $user->canAccessTenant($entity) && $user->roleIn($entity), 403);
        abort_unless(ReturnForms::for($entity) !== null, 404); // districts have no return here yet
        abort_unless($year >= 2000 && $year <= now()->year + 1, 404);

        $data = $return->build($entity, $year);
        $code = $data['form']['code'];

        activity('report')->performedOn($entity)->causedBy($user)
            ->withProperties(['year' => $year, 'form' => $code])->log("Schedule {$code} capture guide generated");

        $pdf = Pdf::loadView('reports.return', [
            'entity' => $entity->loadMissing('parent'),
            'data' => $data,
            'preparedBy' => $user->name,
            'preparedAt' => now(),
        ])->setPaper('a4', 'portrait')
            ->setOptions(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);

        return $pdf->stream(Str::slug($entity->name) . "-{$code}-capture-guide-{$year}.pdf");
    }
}