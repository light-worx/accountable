<?php

namespace App\Support\Returns;

/**
 * The selectable income and expenditure lines of Schedule 4S, as printed on the 2025 form.
 * When the MCO issues a changed form, add a new year here and map the types again.
 */
final class Form4S implements ReturnForm
{
    public const YEAR = 2025;

    /** @return array<string,string> code => label */
    public static function income(): array
    {
        return [
            '3.1' => 'Assessments received from other sections',
            '3.2.1' => 'Voluntary pledge or sacrificial giving (including tithing)',
            '3.2.2' => 'Collections (cash offerings)',
            '3.2.3' => 'Baptismal & communion offerings / poor fund',
            '3.2.4' => 'Special projects / efforts / thanksgiving (functions, fetes, jumble sales etc.)',
            '3.2.5' => 'Ministerial Students Fund (Easter / Good Friday collections)',
            '3.2.6' => 'Methodist Childrens Homes (Christmas Day collections)',
            '3.2.7' => 'M & E Fund',
            '3.2.8' => 'M & E Fund grant',
            '3.2.9' => 'MCO Mission Resource Fund',
            '3.2.10' => 'Any other donations',
            '3.2.11' => 'Rhona collections',
            '3.2.12' => 'Donations / grants / subsidies – foreign',
            '3.3.1' => 'Interest earned – local',
            '3.3.2' => 'Interest earned – foreign',
            '3.4.1' => 'Dividends earned – local',
            '3.4.2' => 'Dividends earned – foreign',
            '3.5.1' => 'Rental income (e.g. hall, manse)',
            '3.5.2' => 'Trading / business income – other (specify)',
            '3.6' => 'Levies / membership fees (should not be applicable)',
            '3.7.1' => 'Other income (specify on page 3a)',
            '3.8' => 'Capital income – sale of land and buildings',
        ];
    }

    /** @return array<string,string> code => label */
    public static function expenditure(): array
    {
        return [
            '4.1' => 'Assessment paid to Circuit',
            '4.1.1' => 'Assessment paid to Society',
            '4.2.1' => 'Pastoral staff salaries (paid directly by society)',
            '4.2.2' => 'Pastoral staff pension / provident fund contributions',
            '4.2.3' => 'Housing allowance (home owned by minister) or rental paid directly to minister',
            '4.2.4' => 'Minister’s travel allowance (paid directly, not through the circuit)',
            '4.2.5' => 'Pastoral staff – other allowances, bonus, manse, book, cell, gifts, honorariums',
            '4.3.1' => 'Office staff salaries, wages, pension, UIF, PAYE & workmens compensation',
            '4.3.2' => 'Salaries and allowances for youth / mission / childrens / worship / visitation pastor',
            '4.3.3' => 'Printing, stationery & postage',
            '4.3.4' => 'Telephones / cellular & internet',
            '4.3.5' => 'Administration / accounting / audit fees / bank charges & interest paid',
            '4.3.6' => 'Office equipment rentals',
            '4.3.7' => 'Other admin expenses (specify on page 4a)',
            '4.4.1' => 'Furniture and equipment acquired',
            '4.4.2' => 'Electricity, water, rates, sanitation fees, rental of manse / premises',
            '4.4.3' => 'Repairs / maintenance of buildings and equipment',
            '4.4.4' => 'Insurance payments to MCO (property protection)',
            '4.4.5' => 'Interest and capital redemption – MCO loan repayments',
            '4.4.6' => 'Interest and capital redemption – loans from other institutions or individuals',
            '4.4.7' => 'Other building, furniture & equipment expenses (specify on page 4a)',
            '4.4.8' => 'Capital expenditure – land and buildings',
            '4.5.1' => 'To organisations of the society (mission groups etc.)',
            '4.5.2' => 'To Wider Field (tithe of income to mission work)',
            '4.5.3' => 'To M & E Fund (tithe of income to mission work)',
            '4.5.4' => 'To Ministerial Students Fund (Easter / Good Friday collections)',
            '4.5.5' => 'To Methodist Childrens Homes (Christmas Day collections)',
            '4.5.6' => 'Other sundry payments (specify on page 4b)',
            '4.5.7' => 'Gifts / donations / honorariums paid to pastoral staff not covered under 4.2 (specify on page 4c)',
            '4.5.8' => 'Rhona expenses (if part of the society bank account) (specify on page 4c)',
            '4.5.9' => 'Donations to other circuits for assessments / expenses',
            '4.5.13' => 'Methodist Children & Youth Programmes',
        ];
    }

    /** @return array<string,string> code => "3.2.2  Label", for dropdowns */
    public static function options(?string $kind): array
    {
        $lines = match ($kind) {
            'income' => self::income(),
            'expense' => self::expenditure(),
            default => [],
        };

        $options = [];
        foreach ($lines as $code => $label) {
            $options[(string) $code] = "{$code}  {$label}";
        }

        return $options;
    }

        /** Section 3 layout. h = heading, l = line, s = sub-total over the listed codes. */
    public static function incomeLayout(): array
    {
        $local = array_map(fn ($n) => "3.2.{$n}", range(1, 11));

        return array_merge(
            [['l', '3.1'], ['h', '3.2', 'Donations / grants / subsidies – local']],
            array_map(fn ($c) => ['l', $c], $local),
            [
                ['s', 'Sub-total', $local],
                ['l', '3.2.12'],
                ['h', '3.3', 'Interest earned'], ['l', '3.3.1'], ['l', '3.3.2'],
                ['h', '3.4', 'Dividends earned'], ['l', '3.4.1'], ['l', '3.4.2'],
                ['h', '3.5', 'Trading / business income'], ['l', '3.5.1'], ['l', '3.5.2'], ['s', 'Sub-total', ['3.5.1', '3.5.2']],
                ['l', '3.6'],
                ['h', '3.7', 'Other income'], ['l', '3.7.1'], ['s', 'Sub-total', ['3.7.1']],
                ['l', '3.8'],
            ],
        );
    }

    /** Section 4 layout. */
    public static function expenditureLayout(): array
    {
        $group = function (string $code, string $title, array $codes, ?string $subtotal) {
            $rows = array_merge([['h', $code, $title]], array_map(fn ($c) => ['l', $c], $codes));

            if ($subtotal !== null) {
                $rows[] = ['s', $subtotal, $codes];
            }

            return $rows;
        };

        return array_merge(
            $group('4.1', 'Assessment', ['4.1', '4.1.1'], null),
            $group('4.2', 'Personnel expenses (pastoral staff excluding youth pastors)', ['4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5'], 'Sub-total (must agree with Section 8)'),
            $group('4.3', 'Administration', ['4.3.1', '4.3.2', '4.3.3', '4.3.4', '4.3.5', '4.3.6', '4.3.7'], 'Sub-total'),
            $group('4.4', 'Buildings, furniture and equipment', ['4.4.1', '4.4.2', '4.4.3', '4.4.4', '4.4.5', '4.4.6', '4.4.7', '4.4.8'], 'Sub-total'),
            $group('4.5', 'Sundry payments', ['4.5.1', '4.5.2', '4.5.3', '4.5.4', '4.5.5', '4.5.6', '4.5.7', '4.5.8', '4.5.9', '4.5.13'], 'Sub-total'),
        );
    }

    public static function label(?string $code): ?string
    {
        return $code === null ? null : ((self::income() + self::expenditure())[$code] ?? null);
    }

        public static function code(): string
    {
        return '4S';
    }

    public static function title(): string
    {
        return 'Schedule 4S';
    }

    public static function specify(): array
    {
        return [
            '3.5.2' => 'Income page – Trading / business income: other (specify)',
            '3.7.1' => 'Page 3a – Other income',
            '4.3.7' => 'Page 4a – Other administration expenses',
            '4.4.7' => 'Page 4a – Other building, furniture & equipment expenses',
            '4.5.6' => 'Page 4b – Other sundry payments',
            '4.5.8' => 'Page 4c – Rhona expenses',
        ];
    }

    public static function pastoralCodes(): array
    {
        return ['4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5'];
    }

    public static function visitingPastoralCode(): string
    {
        return '4.5.7';
    }

    public static function visitingPastoralHeading(): string
    {
        return '4.5.7 / Page 4c – Gifts, donations and honorariums paid to pastoral staff not based in the circuit';
    }

    public static function pastoralReferenceLabel(): string
    {
        return 'Total as per 4.2 under Section 4';
    }
}