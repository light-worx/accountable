<?php

namespace App\Support;

use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Models\Category;
use App\Models\Entity;
use App\Models\Fund;

/**
 * Gives a brand-new society / circuit a sensible starting point so a new treasurer
 * never faces an empty screen. return_line_code is deliberately left blank: it is to be
 * filled from the OFFICIAL 4S / 4C line list (see README), not guessed.
 */
final class DefaultSetup
{
    public static function apply(Entity $entity): void
    {
        Fund::firstOrCreate(
            ['entity_id' => $entity->id, 'name' => 'General Fund'],
            ['is_general' => true],
        );

        $lists = $entity->type === EntityType::Society ? self::society() : self::generic();

        foreach ($lists as $kind => $names) {
            foreach ($names as $i => $name) {
                Category::firstOrCreate(
                    ['entity_id' => $entity->id, 'kind' => $kind, 'name' => $name],
                    ['sort_order' => ($i + 1) * 10],
                );
            }
        }
    }

    /** Plain-language names loosely following the sections of the 4S. */
    private static function society(): array
    {
        return [
            CategoryKind::Income->value => [
                'Sunday offerings (cash collections)',
                'Pledges / sacrificial giving / tithes',
                'Baptismal, communion & poor fund offerings',
                'Special projects, fundraising & thanksgiving',
                'Ministerial Students Fund collections',
                'Methodist Childrens Homes (Christmas Day)',
                'M & E Fund collections',
                'Donations & grants – local',
                'Donations & grants – foreign',
                'Interest earned',
                'Dividends earned',
                'Rental income (hall, manse)',
                'Trading / business income',
                'Assessments received from other sections',
                'Other income',
            ],
            CategoryKind::Expense->value => [
                'Assessment paid to circuit',
                'Pastoral staff salaries',
                'Pastoral staff pension / provident fund',
                'Housing allowance / rent paid to minister',
                'Minister’s travel allowance',
                'Other pastoral allowances & gifts',
                'Youth / mission / children’s pastor costs',
                'Office staff salaries & wages',
                'Audit, accounting & bank charges',
                'Printing, stationery & postage',
                'Telephone, cellular & internet',
                'Electricity, water, rates & rent of premises',
                'Repairs & maintenance',
                'Insurance (MCO property protection)',
                'Furniture & equipment acquired',
                'Capital expenditure – land & buildings',
                'Wider Field (tithe to mission)',
                'M & E Fund (tithe to mission)',
                'Ministerial Students Fund',
                'Methodist Childrens Homes',
                'Organisations of the society',
                'Donations to other circuits / societies',
                'Loan repayments (interest & capital)',
                'Other sundry payments',
            ],
        ];
    }

    private static function generic(): array
    {
        return [
            CategoryKind::Income->value => ['Assessments received', 'Donations & grants', 'Interest earned', 'Other income'],
            CategoryKind::Expense->value => ['Stipends & allowances', 'Administration', 'Travel', 'Property & maintenance', 'Other payments'],
        ];
    }
}
