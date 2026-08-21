<?php

namespace Modules\CoreFixes\Http\Controllers;

use App\Events\AcarsUpdate;
use App\Exceptions\PirepNotFound;
use App\Http\Controllers\Api\AcarsController;
use App\Http\Requests\Acars\PositionRequest;
use App\Models\Acars;
use App\Models\Enums\AcarsType;
use App\Models\Pirep;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Fix 5 — doppelte ACARS-Positionen bei wiederholtem Senden.
 *
 * BEFUND (GSG, 21.08.2026, 6 Fluege geprueft): jeder Flug hatte 19-122
 * doppelte Positionszeilen. Nicht die Erfassung war schuld — der parallele
 * MQTT-Strom zum VPS-Recorder war bei denselben Fluegen dublettenfrei
 * (4.448 von 4.461 Punkten eindeutig) — sondern der Weg zu phpVMS:
 *
 *  1. Der ACARS-Client bricht einen Positions-POST ab (Timeout waehrend
 *     einer Lastspitze; gemessen: Median 1 s, p90 2 s, Maximum 11 s
 *     Verarbeitungszeit, dazu 1,3 s Bootstrap je Request).
 *  2. Der Server merkt davon nichts: `ignore_user_abort` wirkt erst bei
 *     Output, der Insert-Loop schreibt keinen — er laeuft durch, schreibt
 *     alle Zeilen und loggt HTTP 200.
 *  3. Der Client haelt den Batch fuer verloren und schickt ihn erneut.
 *  4. phpVMS legt neue Zeilen an, statt die vorhandenen zu erkennen.
 *
 * Der Core kennt fuer Schritt 4 bereits einen idempotenten Zweig: traegt
 * eine Position ein `id`-Feld, ruft er `Acars::updateOrInsert()`. Nur ist
 * das der **Query-Builder**, nicht Eloquent — er setzt keine Timestamps
 * (`created_at`/`updated_at` blieben NULL), wendet keine Casts an und
 * ignoriert `$fillable`. Auf GSG sortiert aber sowohl der Core-Track
 * (`Pirep::acars()` -> `orderBy('created_at')`) als auch das GsgLogbook
 * genau danach — der Kern-Zweig wuerde die Zeitachse zerstoeren.
 *
 * Dieser Fix ersetzt darum nur diese eine Entscheidung: bei gesetzter `id`
 * geht es ueber **Eloquent** (`updateOrCreate`), damit Timestamps, Casts
 * und `$fillable` genauso greifen wie beim normalen Anlegen. Alles andere
 * ist unveraendert aus `App\Http\Controllers\Api\AcarsController` von
 * phpVMS 7.0.8 uebernommen.
 *
 * ACHTUNG BEI PHPVMS-UPDATES: Die Methode ist eine Kopie. Sie faengt keine
 * Aenderungen auf, die der Core an `acars_store` vornimmt — nach einem
 * Core-Update also gegen das Original vergleichen.
 */
class AcarsControllerFix extends AcarsController
{
    public function acars_store(string $id, PositionRequest $request): JsonResponse
    {
        $pirep = Pirep::find($id);
        if (empty($pirep)) {
            throw new PirepNotFound($id);
        }

        $this->checkCancelled($pirep);

        $count = 0;
        $positions = $request->post('positions');
        foreach ($positions as $position) {
            $position['pirep_id'] = $id;
            $position['type'] = AcarsType::FLIGHT_PATH;

            if (isset($position['altitude'])) {
                if (!isset($position['altitude_agl'])) {
                    $position['altitude_agl'] = $position['altitude'];
                }

                if (!isset($position['altitude_msl'])) {
                    $position['altitude_msl'] = $position['altitude'];
                }

                unset($position['altitude']);
            }

            if (isset($position['sim_time'])) {
                if ($position['sim_time'] instanceof \DateTime) {
                    $position['sim_time'] = Carbon::instance($position['sim_time']);
                } else {
                    $position['sim_time'] = Carbon::createFromTimeString($position['sim_time']);
                }
            }

            if (isset($position['created_at'])) {
                if ($position['created_at'] instanceof \DateTime) {
                    $position['created_at'] = Carbon::instance($position['created_at']);
                } else {
                    $position['created_at'] = Carbon::createFromTimeString($position['created_at']);
                }
            }

            try {
                if (!empty($position['id'])) {
                    // HIER liegt der ganze Unterschied zum Core: Eloquent
                    // statt Query-Builder. Ein zweites Mal gesendete
                    // Position aktualisiert damit ihre Zeile, statt eine
                    // neue anzulegen — und behaelt trotzdem korrekte
                    // Timestamps, Casts und fillable-Filterung.
                    $key = $position['id'];
                    unset($position['id']);
                    // Der Schluessel enthaelt BEWUSST auch die pirep_id.
                    // Sonst koennte ein Client mit einer fremden `id` die
                    // Position eines fremden Fluges ueberschreiben — der
                    // Endpunkt prueft nur, ob der PIREP existiert und nicht
                    // storniert ist. Mit pirep_id im Schluessel trifft ein
                    // fremder Wert keine Zeile; der anschliessende Insert
                    // laeuft in den Primaerschluessel und wird als
                    // QueryException geloggt statt fremde Daten zu aendern.
                    Acars::updateOrCreate(
                        ['id' => $key, 'pirep_id' => $id],
                        $position
                    );
                } else {
                    $update = Acars::create($position);
                    $update->save();
                }

                $count++;
            } catch (QueryException $ex) {
                Log::info('Error on adding ACARS position: '.$ex->getMessage());
            }
        }

        $pirep->save();

        event(new AcarsUpdate($pirep, $pirep->position));

        return $this->message($count.' positions added', $count);
    }
}
