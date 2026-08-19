<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Widgets;

use App\Models\SimBrief;
use Illuminate\Support\Facades\Log;
use Modules\DisposableBasic\Widgets\ActiveBookings;

/**
 * Fix 4 — DisposableBasics Buchungs-Widget reisst die ganze Seite mit.
 *
 * Am 19.08.2026 hat `/arrilot/load-widget?name=DBasic::ActiveBookings`
 * dreimal HTTP 500 geliefert. Die Ursache liegt in der Ansicht
 * `DBasic::widgets.active_bookings`, die an zwei Stellen ungeschuetzt
 * dereferenziert — beide reproduziert (jeweils in einer zurueckgerollten
 * Transaktion auf Live):
 *
 *   1. `$booking->xml->times->est_out->__toString()`
 *      Der `xml`-Zugriff im Core-Model liefert NULL, wenn `ofp_xml` leer ist
 *      (`SimBrief::xml()` prueft `empty(...)` und gibt dann null zurueck).
 *      Ergebnis: "Call to a member function __toString() on null".
 *
 *   2. `route('DBasic.aircraft', [$booking->aircraft->registration])`
 *      `aircraft()` ist ein schlichtes `belongsTo` — ist die Maschine weich
 *      geloescht, ist die Beziehung null. Ergebnis: "Missing required
 *      parameter for [Route: DBasic.aircraft]". Bemerkenswert: direkt
 *      danach steht auf derselben Zeile zweimal `optional($booking->aircraft)`
 *      — der Autor wusste also, dass die Beziehung null sein kann, und hat
 *      genau die eine Stelle vergessen, die es nicht verzeiht.
 *
 * WARUM HIER UND NICHT IN DER ANSICHT:
 *
 * Eine gepatchte Fremddatei ist nach dem naechsten Modul-Update still wieder
 * kaputt — auf GSG schon zweimal passiert. Eine eigene Kopie der Ansicht
 * waere update-fest, wuerde aber schleichend von der Vorlage abdriften: wir
 * merken nie, wenn DisposableBasic die Ansicht erweitert.
 *
 * Deshalb wird nicht die Ansicht reparariert, sondern die ABFRAGE. Die zwei
 * Bedingungen, die hier dazukommen, sind ohnehin das, was die Abfrage von
 * Anfang an haette fordern muessen: eine Buchung ohne Briefing und eine
 * Buchung auf einer ausgemusterten Maschine sind keine "aktive Buchung".
 * Damit bleibt die Ansicht unangetastet und bekommt nur Zeilen, die sie
 * darstellen kann.
 *
 * ⚠ Nur der `simbrief`-Zweig ist betroffen. Im `bids`-Zweig stehen beide
 *   kritischen Zellen hinter `@if(!$bids)`, dort gibt es nichts zu holen —
 *   und die Vorlage wird fuer diesen Fall unveraendert aufgerufen.
 */
final class ActiveBookingsFix extends ActiveBookings
{
    public function run()
    {
        // Nur der eigene Zweig wird angefasst. Alles andere bleibt Vorlage.
        if (($this->config['source'] ?? 'simbrief') === 'bids') {
            return parent::run();
        }

        try {
            $this->melden();
        } catch (\Throwable $e) {
            // Das Protokoll darf das Widget nie kippen — es ist Beiwerk.
            Log::warning('CoreFixes: Buchungs-Widget konnte nicht protokollieren: ' . $e->getMessage());
        }

        $eagerLoad = ['flight.airline', 'flight.arr_airport', 'flight.dpt_airport', 'user', 'aircraft'];

        $bookings = SimBrief::with($eagerLoad)
            ->whereNotNull(['flight_id', 'aircraft_id'])
            ->whereNull('pirep_id')
            // Ohne Briefing kein ETD — und damit der Absturz oben.
            ->where('ofp_xml', '<>', '')
            // Ausgemusterte Maschine: `whereHas` beachtet das weiche Loeschen
            // der Aircraft-Tabelle, die Beziehung ist dann garantiert da.
            ->whereHas('aircraft')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('DBasic::widgets.active_bookings', [
            'active_bookings' => $bookings,
            'bids'            => false,
            'expire'          => setting('simbrief.expire_hours'),
            'is_visible'      => filled($bookings),
            'title'           => __('DBasic::widgets.active_sbrf'),
        ]);
    }

    /**
     * Sagen, was ausgelassen wurde.
     *
     * Eine Zeile stillschweigend zu verschlucken waere schlimmer als der
     * Absturz: dann fehlt eine Buchung in der Liste und niemand weiss, warum.
     * Deshalb landet jede uebersprungene Buchung im Protokoll, mit Kennung und
     * Grund — sichtbar, aber ohne Laerm, weil es der Normalfall nicht ist.
     */
    private function melden(): void
    {
        $auffaellig = SimBrief::query()
            ->whereNotNull(['flight_id', 'aircraft_id'])
            ->whereNull('pirep_id')
            ->where(static function ($q): void {
                $q->where('ofp_xml', '=', '')->orWhereDoesntHave('aircraft');
            })
            ->get(['id', 'user_id', 'aircraft_id', 'ofp_xml']);

        foreach ($auffaellig as $b) {
            Log::info('CoreFixes: Buchung im Widget uebersprungen', [
                'buchung'     => $b->id,
                'pilot'       => $b->user_id,
                'aircraft_id' => $b->aircraft_id,
                'grund'       => $b->ofp_xml === ''
                    ? 'kein OFP-XML gespeichert'
                    : 'Flugzeug weich geloescht',
            ]);
        }
    }
}
