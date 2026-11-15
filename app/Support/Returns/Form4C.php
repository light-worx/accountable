<?php

namespace App\Support\Returns;

/** The selectable income and expenditure lines of Schedule 4C, as printed on the 2025 form. */
final class Form4C implements ReturnForm
{
    public const YEAR = 2025;

    public static function code(): string
    {
        return '4C';
    }

    public static function title(): string
    {
        return 'Schedule 4C';
    }

    public static function income(): array
    {
        return [
            '3.1' => 'Assessments received (from societies or sections)',
            '3.2.1' => 'Voluntary pledge or sacrificial giving receipts (including tithing)',
            '3.2.2' => 'Collections (cash offerings as circuit income)',
            '3.2.3' => 'Baptismal & communion offerings (where treated as circuit income)',
            '3.2.4' => 'Special projects / efforts (functions, fetes, jumble sales etc.)',
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

    public static function expenditure(): array
    {
        return [
            '4.1.1' => 'Basic stipend (ministers, probationers, deacons, evangelists and biblewomen)',
            '4.1.2' => 'Additional stipends (ministers, probationers, deacons, evangelists and biblewomen)',
            '4.1.3' => 'Supply (payments to ministers / probationers / supernumeraries who temporarily assist)',
            '4.1.4' => 'Housing allowance (home owned by minister) or rental paid directly to minister',
            '4.1.5' => 'Other allowances (furniture, bonus, books, education, garden, manse etc.) to ministers, probationers, deacons, evangelists and biblewomen',
            '4.1.6' => 'Lay pastoral staff salaries and allowances',
            '4.2.1' => 'Furniture and equipment acquired',
            '4.2.2' => 'Office equipment rentals',
            '4.2.3' => 'Electricity, water, rates, sanitation fees, rental of manse / premises',
            '4.2.4' => 'Repairs, maintenance of buildings and equipment (including alarms, security etc.), garden maintenance and cleaning',
            '4.2.5' => 'Insurances, payments to MCO (property protection)',
            '4.2.6' => 'Other building, furniture & equipment expenses (specify on page 4a)',
            '4.2.7' => 'Interest and capital redemption – loan repayments to other institutions or individuals',
            '4.2.8' => 'Capital expenditure – land and buildings',
            '4.3.1' => 'Travel allowances (ministers / probationers)',
            '4.3.2' => 'Motor vehicle repairs, insurances, depreciation and licences',
            '4.3.3' => 'Additional travelling (ministers / probationers / deacons / evangelists / biblewomen)',
            '4.3.4' => 'Synod / Conference travelling and accommodation',
            '4.3.5' => 'Other circuit travelling (specify on page 4b)',
            '4.4.1' => 'Interest and capital redemption – MCO loan repayments',
            '4.4.2' => 'Circuit assessments (circuit)',
            '4.4.3' => 'Medical subsidy (medical)',
            '4.4.4' => 'Stipend Augmentation Fund assessment (SAF)',
            '4.4.5' => 'Circuit Supernumerary Fund (SF) / SF Reserve / Church Reserve contributions',
            '4.4.6' => 'District assessments collected by the MCO (Districts 7, 8 & 13 only)',
            '4.4.6.1' => 'District assessments not collected by the MCO (all other districts)',
            '4.4.7' => 'EMMU assessments',
            '4.4.8' => 'Methodist Children & Youth Unit assessments (MCYU / DYA)',
            '4.4.9' => 'Mission Unit assessments (MU)',
            '4.4.10' => 'Connexional Bursary Fund (CBF)',
            '4.4.11' => 'Other payments to the MCO (specify on page 4b)',
            '4.5.1' => 'Salaries, wages, pension / provident contributions & allowances for youth / mission / childrens / worship / visitation pastor etc.',
            '4.5.1.1' => 'Stipends and allowances paid to youth, mission, childrens, worship etc. and United Church minister',
            '4.5.2' => 'Telephones, cellular phones & internet expenses',
            '4.5.3' => 'Auditing, accounting fees, bank charges and interest paid',
            '4.5.4' => 'Printing, stationery & postage',
            '4.5.5' => 'To Wider Field (tithe of income to mission work)',
            '4.5.6' => 'To M & E Fund (tithe of income to mission work)',
            '4.5.7' => 'To Ministerial Students Fund',
            '4.5.8' => 'To Methodist Childrens Homes',
            '4.5.9' => 'Other administration & sundry (specify on page 4c)',
            '4.5.10' => 'Rhona expenses (specify on page 4d)',
            '4.5.11' => 'Donations, gifts, honorariums paid to visiting pastoral staff (not covered under 4.1) (specify on page 4d)',
            '4.5.12' => 'Donations to other circuits for assessments / expenses',
            '4.5.13' => 'Methodist Children & Youth Programmes',
        ];
    }

    /** Section 3 has the same structure as the 4S. */
    public static function incomeLayout(): array
    {
        return Form4S::incomeLayout();
    }

    public static function expenditureLayout(): array
    {
        $group = fn (string $code, string $title, array $codes): array => array_merge(
            [['h', $code, $title]],
            array_map(fn ($c) => ['l', $c], $codes),
            [['s', 'Sub-total', $codes]],
        );

        return array_merge(
            $group('4.1', 'Stipends and pastoral staff costs', ['4.1.1', '4.1.2', '4.1.3', '4.1.4', '4.1.5', '4.1.6']),
            $group('4.2', 'Buildings, furniture and equipment', ['4.2.1', '4.2.2', '4.2.3', '4.2.4', '4.2.5', '4.2.6', '4.2.7', '4.2.8']),
            $group('4.3', 'Circuit travelling', ['4.3.1', '4.3.2', '4.3.3', '4.3.4', '4.3.5']),
            $group('4.4', 'Methodist Connexional Office (other than stipends)', ['4.4.1', '4.4.2', '4.4.3', '4.4.4', '4.4.5', '4.4.6', '4.4.6.1', '4.4.7', '4.4.8', '4.4.9', '4.4.10', '4.4.11']),
            $group('4.5', 'Administration & sundry payments', ['4.5.1', '4.5.1.1', '4.5.2', '4.5.3', '4.5.4', '4.5.5', '4.5.6', '4.5.7', '4.5.8', '4.5.9', '4.5.10', '4.5.11', '4.5.12', '4.5.13']),
        );
    }

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

    public static function label(?string $code): ?string
    {
        return $code === null ? null : ((self::income() + self::expenditure())[$code] ?? null);
    }

    public static function specify(): array
    {
        return [
            '3.5.2' => 'Income page – Trading / business income: other (specify)',
            '3.7.1' => 'Page 3a – Other income',
            '4.2.6' => 'Page 4a – Other building, furniture & equipment expenses',
            '4.3.5' => 'Page 4b – Circuit travelling: other',
            '4.4.11' => 'Page 4b – MCO: other',
            '4.5.5' => 'Page 4b – To Wider Field',
            '4.5.9' => 'Page 4c – Administration & sundry: other',
            '4.5.10' => 'Page 4d – Rhona expenses',
        ];
    }

    public static function pastoralCodes(): array
    {
        return ['4.1.4', '4.1.5', '4.1.6'];
    }

    public static function visitingPastoralCode(): string
    {
        return '4.5.11';
    }

    public static function visitingPastoralHeading(): string
    {
        return '4.5.11 / Page 4d – Donations, gifts and honorariums paid to visiting pastoral staff (not covered under 4.1)';
    }

    public static function pastoralReferenceLabel(): string
    {
        return 'Total as per 4.1.4 / 5 / 6 under Section 4.1';
    }
}