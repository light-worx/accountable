<?php

namespace App\Support;

use App\Enums\CategoryKind;
use App\Enums\EntityType;
use App\Models\Category;
use App\Models\Entity;
use App\Models\Fund;
use Illuminate\Database\Eloquent\Model;

/**
 * Gives a brand-new society / circuit a sensible starting point so a new treasurer
 * never faces an empty screen. Societies and circuits get types linked to their annual return lines.
 */
final class DefaultSetup
{
    public static function apply(Entity $entity): void
    {
        // No audit-log noise and a single insert: starter data is not a "change" worth recording.
        Model::withoutEvents(function () use ($entity) {
            Fund::firstOrCreate(
                ['entity_id' => $entity->id, 'name' => 'General Fund'],
                ['is_general' => true],
            );

            $lists = match ($entity->type) {
                EntityType::Society => self::society(),
                EntityType::Circuit => self::circuit(),
                default => self::generic(),
            };

            $now = now();
            $rows = [];

            foreach ($lists as $kind => $items) {
                foreach ($items as $i => [$name, $code]) {
                    $rows[] = [
                        'entity_id' => $entity->id,
                        'kind' => $kind,
                        'name' => $name,
                        'return_line_code' => $code,
                        'sort_order' => ($i + 1) * 10,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            // Don't re-add a standard type if the entity already has a type linked to the same return line
            // (for example one the treasurer has renamed to simpler wording).
            $linked = Category::where('entity_id', $entity->id)->whereNotNull('return_line_code')
                ->get(['kind', 'return_line_code'])
                ->map(fn ($c) => $c->kind->value . '|' . $c->return_line_code)->all();

            $rows = array_values(array_filter($rows, fn ($r) => $r['return_line_code'] === null
                || ! in_array($r['kind'] . '|' . $r['return_line_code'], $linked, true)));
            Category::insertOrIgnore($rows);
        });
    }

    /** @return array<string,string> "kind|name" => line code, used to fill in blanks on existing entities */
    public static function standardCodes(EntityType $type): array
    {
        $lists = match ($type) {
            EntityType::Society => self::society(),
            EntityType::Circuit => self::circuit(),
            default => [],
        };

        $map = [];

        foreach ($lists as $kind => $items) {
            foreach ($items as [$name, $code]) {
                $map["{$kind}|{$name}"] = $code;
            }
        }

        return $map;
    }

    private static function society(): array
    {
        return [
            CategoryKind::Income->value => [
                ['Sunday offerings (cash collections)', '3.2.2'],
                ['Pledges / sacrificial giving / tithes', '3.2.1'],
                ['Baptismal, communion & poor fund offerings', '3.2.3'],
                ['Special projects, fundraising & thanksgiving', '3.2.4'],
                ['Ministerial Students Fund collections', '3.2.5'],
                ['Methodist Childrens Homes (Christmas Day)', '3.2.6'],
                ['M & E Fund collections', '3.2.7'],
                ['M & E Fund grant', '3.2.8'],
                ['MCO Mission Resource Fund', '3.2.9'],
                ['Donations & grants – local', '3.2.10'],
                ['Donations & grants – foreign', '3.2.12'],
                ['Interest earned', '3.3.1'],
                ['Dividends earned', '3.4.1'],
                ['Rental income (hall, manse)', '3.5.1'],
                ['Trading / business income', '3.5.2'],
                ['Assessments received from other sections', '3.1'],
                ['Sale of land & buildings', '3.8'],
                ['Other income', '3.7.1'],
            ],
            CategoryKind::Expense->value => [
                ['Assessment paid to circuit', '4.1'],
                ['Assessment paid to another society', '4.1.1'],
                ['Pastoral staff salaries', '4.2.1'],
                ['Pastoral staff pension / provident fund', '4.2.2'],
                ['Housing allowance / rent paid to minister', '4.2.3'],
                ['Minister’s travel allowance', '4.2.4'],
                ['Other pastoral allowances & gifts', '4.2.5'],
                ['Office staff salaries & wages', '4.3.1'],
                ['Youth / mission / children’s pastor costs', '4.3.2'],
                ['Printing, stationery & postage', '4.3.3'],
                ['Telephone, cellular & internet', '4.3.4'],
                ['Audit, accounting & bank charges', '4.3.5'],
                ['Office equipment rentals', '4.3.6'],
                ['Other administration expenses', '4.3.7'],
                ['Furniture & equipment acquired', '4.4.1'],
                ['Electricity, water, rates & rent of premises', '4.4.2'],
                ['Repairs & maintenance', '4.4.3'],
                ['Insurance (MCO property protection)', '4.4.4'],
                ['Loan repayments to the MCO (interest & capital)', '4.4.5'],
                ['Loan repayments to other institutions', '4.4.6'],
                ['Other building, furniture & equipment expenses', '4.4.7'],
                ['Capital expenditure – land & buildings', '4.4.8'],
                ['Organisations of the society', '4.5.1'],
                ['Wider Field (tithe to mission)', '4.5.2'],
                ['M & E Fund (tithe to mission)', '4.5.3'],
                ['Ministerial Students Fund', '4.5.4'],
                ['Methodist Childrens Homes', '4.5.5'],
                ['Other sundry payments', '4.5.6'],
                ['Gifts / donations to pastoral staff outside the circuit', '4.5.7'],
                ['Donations to other circuits / societies', '4.5.9'],
                ['Methodist Children & Youth Programmes', '4.5.13'],
            ],
        ];
    }

    private static function circuit(): array
    {
        return [
            CategoryKind::Income->value => [
                ['Assessments received from societies', '3.1'],
                ['Pledges / sacrificial giving / tithes', '3.2.1'],
                ['Collections (cash offerings as circuit income)', '3.2.2'],
                ['Baptismal & communion offerings (circuit income)', '3.2.3'],
                ['Special projects, fundraising & thanksgiving', '3.2.4'],
                ['Ministerial Students Fund collections', '3.2.5'],
                ['Methodist Childrens Homes (Christmas Day)', '3.2.6'],
                ['M & E Fund collections', '3.2.7'],
                ['M & E Fund grant', '3.2.8'],
                ['MCO Mission Resource Fund', '3.2.9'],
                ['Donations & grants – local', '3.2.10'],
                ['Donations & grants – foreign', '3.2.12'],
                ['Interest earned', '3.3.1'],
                ['Dividends earned', '3.4.1'],
                ['Rental income (hall, manse)', '3.5.1'],
                ['Trading / business income', '3.5.2'],
                ['Sale of land & buildings', '3.8'],
                ['Other income', '3.7.1'],
            ],
            CategoryKind::Expense->value => [
                ['Basic stipends', '4.1.1'],
                ['Additional stipends', '4.1.2'],
                ['Supply payments', '4.1.3'],
                ['Housing allowance / rent paid to minister', '4.1.4'],
                ['Other allowances to ministers & pastoral staff', '4.1.5'],
                ['Lay pastoral staff salaries & allowances', '4.1.6'],
                ['Furniture & equipment acquired', '4.2.1'],
                ['Office equipment rentals', '4.2.2'],
                ['Electricity, water, rates & rent of premises', '4.2.3'],
                ['Repairs & maintenance', '4.2.4'],
                ['Insurance (MCO property protection)', '4.2.5'],
                ['Other building, furniture & equipment expenses', '4.2.6'],
                ['Loan repayments to other institutions', '4.2.7'],
                ['Capital expenditure – land & buildings', '4.2.8'],
                ['Travel allowances (ministers)', '4.3.1'],
                ['Motor vehicle repairs, insurance & licences', '4.3.2'],
                ['Additional travelling', '4.3.3'],
                ['Synod / Conference travel & accommodation', '4.3.4'],
                ['Other circuit travelling', '4.3.5'],
                ['Loan repayments to the MCO (interest & capital)', '4.4.1'],
                ['Circuit assessments (to the MCO)', '4.4.2'],
                ['Medical subsidy', '4.4.3'],
                ['Stipend Augmentation Fund (SAF)', '4.4.4'],
                ['Supernumerary Fund / reserve contributions', '4.4.5'],
                ['District assessments (collected by the MCO)', '4.4.6'],
                ['District assessments (not collected by the MCO)', '4.4.6.1'],
                ['EMMU assessments', '4.4.7'],
                ['Methodist Children & Youth Unit (MCYU / DYA)', '4.4.8'],
                ['Mission Unit assessments (MU)', '4.4.9'],
                ['Connexional Bursary Fund (CBF)', '4.4.10'],
                ['Other payments to the MCO', '4.4.11'],
                ['Youth / mission / children’s pastor salaries & allowances', '4.5.1'],
                ['Stipends paid to youth / mission pastors & United Church ministers', '4.5.1.1'],
                ['Telephone, cellular & internet', '4.5.2'],
                ['Audit, accounting & bank charges', '4.5.3'],
                ['Printing, stationery & postage', '4.5.4'],
                ['Wider Field (tithe to mission)', '4.5.5'],
                ['M & E Fund (tithe to mission)', '4.5.6'],
                ['Ministerial Students Fund', '4.5.7'],
                ['Methodist Childrens Homes', '4.5.8'],
                ['Other administration & sundry', '4.5.9'],
                ['Gifts / donations to visiting pastoral staff', '4.5.11'],
                ['Donations to other circuits', '4.5.12'],
                ['Methodist Children & Youth Programmes', '4.5.13'],
            ],
        ];
    }

    private static function generic(): array
    {
        return [
            CategoryKind::Income->value => [
                ['Assessments received', null], ['Donations & grants', null], ['Interest earned', null], ['Other income', null],
            ],
            CategoryKind::Expense->value => [
                ['Stipends & allowances', null], ['Administration', null], ['Travel', null], ['Property & maintenance', null], ['Other payments', null],
            ],
        ];
    }
}