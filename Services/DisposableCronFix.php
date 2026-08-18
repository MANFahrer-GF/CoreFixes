<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Services;

use App\Models\Acars;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\DisposableSpecial\Services\DS_CronServices;
use ReflectionMethod;
use Throwable;

/**
 * Fix 3 — DisposableSpecials Wochen-Cron gegen das MySQL-Platzhalterlimit.
 *
 * MySQL nimmt hoechstens 65.535 Platzhalter je Anweisung. DisposableSpecial baut
 * an zwei Stellen ein whereIn/whereNotIn ueber eine KOMPLETTE Id-Liste:
 *
 *   CleanAcarsRecords()   whereIn('id', $records)  ueber alle verwaisten acars-Zeilen
 *   CleanRelationships()  whereNotIn('flight_id', $flights)  ueber ALLE Fluege
 *
 * Auf GSG waren das am 18.08.2026 195.617 bzw. 189.525 Ids. Beide Aufrufe
 * scheitern mit "SQLSTATE[HY000] 1390 Prepared statement contains too many
 * placeholders". Der Absturz landet nur im Log — der Wochen-Cron war dadurch
 * seit dem 10.05.2026 rund 14 Wochen still tot, ohne dass etwas auffiel.
 *
 * Warum hier und nicht in der Moduldatei: ein Patch an
 * modules/DisposableSpecial/ ist beim naechsten Modul-Update weg, und der
 * Fehler kaeme still zurueck. Alle vier Aufrufe im Listener laufen ueber
 * `app(DS_CronServices::class)`, also ueber den Container — eine Bindung
 * genuegt, die Fremddatei bleibt unberuehrt.
 *
 * Ueberschrieben wird nur das Loeschen; alles andere erbt unveraendert.
 *
 * @see \Modules\CoreFixes\Providers\CoreFixesServiceProvider
 */
final class DisposableCronFix extends DS_CronServices
{
    /**
     * Wieviele Ids je Anweisung. 1.000 laesst reichlich Luft zu den 65.535 und
     * haelt die einzelne Sperre kurz; 242.400 Zeilen brauchten damit 48 s.
     */
    private const CHUNK = 1000;

    /**
     * Stand der Original-Methoden, gegen den dieser Fix geprueft wurde
     * (Leerraum-normalisiertes sha1 des Quelltexts). Weicht er ab, hat ein
     * Modul-Update die Vorlage geaendert — dann muss jemand nachsehen, ob
     * dieser Override noch passt oder ueberfluessig geworden ist.
     */
    private const VETTED = [
        'CleanAcarsRecords'  => 'da688278cbc24bbb65c5c51576b836a4350161d8',
        'CleanRelationships' => 'a4bbc6a172e2067b8170e11c102187403eec938d',
    ];

    /**
     * Wie das Original, nur blockweise geloescht.
     *
     * Die Auswahl bleibt bewusst unveraendert: `withCount(['pirep'])` zaehlt
     * ueber die Beziehung, und Pirep nutzt SoftDeletes — soft-geloeschte PIREPs
     * gelten dem Modul also als weg. Das ist seit jeher so und wird hier NICHT
     * angetastet.
     */
    public function CleanAcarsRecords()
    {
        $this->WarnOnUpstreamDrift();

        $records = Acars::withCount(['pirep'])->having('pirep_count', 0)->pluck('id')->toArray();

        $acars = 0;
        foreach (array_chunk($records, self::CHUNK) as $chunk) {
            $acars += Acars::whereIn('id', $chunk)->delete();
        }

        if ($acars > 0) {
            Log::info('CoreFixes | Deleted '.$acars.' redundant records with no matching PIREP | acars');
        }
    }

    /**
     * Wie das Original, nur mit Unterabfrage statt Id-Liste.
     */
    public function CleanRelationships()
    {
        $checks = [
            ['flight_fare', 'flight_id', 'flights', 'FLIGHT', 'flight_fare'],
            ['flight_fare', 'fare_id', 'fares', 'FARE', 'flight_fare'],
            ['subfleet_fare', 'subfleet_id', 'subfleets', 'SUBFLEET', 'subfleet_fare'],
            ['subfleet_fare', 'fare_id', 'fares', 'FARE', 'subfleet_fare'],
            ['flight_subfleet', 'flight_id', 'flights', 'FLIGHT', 'flight_subfleets'],
            ['flight_subfleet', 'subfleet_id', 'subfleets', 'SUBFLEET', 'flight_subfleets'],
            ['subfleet_rank', 'rank_id', 'ranks', 'RANK', 'subfleet_rank'],
            ['subfleet_rank', 'subfleet_id', 'subfleets', 'SUBFLEET', 'subfleet_rank'],
            ['user_field_values', 'user_id', 'users', 'USER', 'user_field_values'],
            ['role_user', 'user_id', 'users', 'USER', 'role_user'],
            ['role_user', 'role_id', 'roles', 'ROLE', 'role_user'],
        ];

        foreach ($checks as [$table, $column, $parent, $label, $logname]) {
            $this->DeleteOrphans($table, $column, $parent, $label, $logname);
        }

        // Journale haengen polymorph am Nutzer, deshalb der zusaetzliche Filter.
        $this->DeleteOrphans('journals', 'morphed_id', 'users', 'USER', 'journals', static function ($query) {
            $query->where('morphed_type', 'LIKE', '%User');
        });
    }

    /**
     * Verwaiste Zeilen einer Tabelle loeschen, ohne Id-Liste.
     *
     * Der whereNotNull-Riegel ist Bedeutung, keine Kosmetik: 'NULL NOT IN (...)'
     * ist in SQL unbekannt und traf damit nie zu. Ohne den Riegel wuerden
     * Zeilen mit leerem Fremdschluessel neuerdings mitgeloescht — eine stille
     * Verhaltensaenderung gegenueber dem Original.
     */
    private function DeleteOrphans(
        string $table,
        string $column,
        string $parent_table,
        string $label,
        string $logname,
        ?callable $filter = null
    ): int {
        $query = DB::table($table)->whereNotNull($column)->whereNotExists(
            static function ($sub) use ($table, $column, $parent_table) {
                $sub->select(DB::raw(1))->from($parent_table)
                    ->whereColumn($parent_table.'.id', $table.'.'.$column);
            }
        );

        if ($filter !== null) {
            $filter($query);
        }

        $deleted = $query->delete();

        if ($deleted > 0) {
            Log::info('CoreFixes | Deleted '.$deleted.' redundant records with no matching '.$label.' | '.$logname);
        }

        return $deleted;
    }

    /**
     * Meldet, wenn ein Modul-Update die ueberschriebenen Original-Methoden
     * veraendert hat. Ohne das wuerde dieser Override eine spaetere Korrektur
     * von DisposableSpecial still verdecken.
     *
     * Faellt bewusst leise aus, wenn die Reflexion nicht klappt — ein
     * Aufraeum-Cron darf an seiner eigenen Selbstpruefung nicht sterben.
     */
    private function WarnOnUpstreamDrift(): void
    {
        foreach (self::VETTED as $method => $expected) {
            try {
                $ref = new ReflectionMethod(DS_CronServices::class, $method);
                $lines = file($ref->getFileName());

                if ($lines === false) {
                    continue;
                }

                $source = implode('', array_slice(
                    $lines,
                    $ref->getStartLine() - 1,
                    $ref->getEndLine() - $ref->getStartLine() + 1
                ));

                $actual = sha1((string) preg_replace('/\s+/', ' ', $source));

                if ($actual !== $expected) {
                    Log::warning(
                        'CoreFixes | DisposableSpecial::'.$method.'() hat sich geaendert '
                        .'(erwartet '.$expected.', ist '.$actual.'). Fix 3 in CoreFixes gegen die '
                        .'neue Vorlage pruefen — moeglicherweise ist der Override ueberfluessig.'
                    );
                }
            } catch (Throwable $e) {
                Log::warning('CoreFixes | Abgleich von '.$method.'() nicht moeglich: '.$e->getMessage());
            }
        }
    }
}
