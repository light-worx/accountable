<?php

namespace App\Support\Returns;

/** What every annual return form (4S, 4C) must be able to tell the report builder. */
interface ReturnForm
{
    public static function code(): string;

    public static function title(): string;

    /** @return array<string,string> code => label */
    public static function income(): array;

    /** @return array<string,string> code => label */
    public static function expenditure(): array;

    public static function incomeLayout(): array;

    public static function expenditureLayout(): array;

    /** @return array<string,string> code => "3.2.2  Label", for dropdowns */
    public static function options(?string $kind): array;

    public static function label(?string $code): ?string;

    /** @return array<string,string> lines itemised on a "specify" page: code => where it appears */
    public static function specify(): array;

    /** @return list<string> lines listed by person in Section 8 */
    public static function pastoralCodes(): array;

    public static function visitingPastoralCode(): string;

    public static function visitingPastoralHeading(): string;

    public static function pastoralReferenceLabel(): string;
}