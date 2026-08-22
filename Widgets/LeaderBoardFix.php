<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Widgets;

use Modules\DisposableBasic\Widgets\LeaderBoard;

/**
 * Fix 5 — die Bestenliste beschriftet einen Durchschnitt wie einen Bestwert.
 *
 * Gemeldet 22.08.2026: „Die Bestenlisten passen auch nicht, ich hatte eine
 * 100er Landung!" — und die Liste zeigte 90. Nachgerechnet stimmten beide
 * Zahlen: 100 war sein bester Flug im August (Flug 3109, 19.08.), 89,8 sein
 * Monatsdurchschnitt über neun Flüge. Alle drei Pilaten der Liste hatten
 * eine 100; den Ausschlag gibt der Schnitt.
 *
 * DER FEHLER STECKT IN DER BESCHRIFTUNG, NICHT IN DER ZAHL:
 *
 * `DB_StatServices::LeaderBoard()` rechnet bei `type = score` und `lrate`
 * mit `avg(...)`. Das Widget weiss das und liefert dafür extra
 * `column_title = DBasic::common.avg` („Durchschnitt"). Die THEME-EIGENE
 * Fassung der Ansicht (`layouts/SPTheme/modules/DisposableBasic/widgets/
 * leader_board.blade.php`) benutzt jedoch `$footer_type` als Spaltenkopf —
 * also „Ergebnis" — und wirft die richtige Überschrift weg.
 *
 * Verschärfend: direkt daneben stehen „Höchste/Niedrigste Landerate", die
 * tatsächlich Bestwerte zeigen. Ein Durchschnitt in derselben Wortwahl
 * daneben liest sich zwangsläufig als Bestwert.
 *
 * WARUM HIER UND NICHT IN DER ANSICHT:
 *
 * Die Theme-Datei gehört SPTheme und ist beim nächsten Theme-Update weg.
 * Deshalb wird nicht die Ansicht angefasst, sondern der Wert, den sie
 * anzeigt: bei Durchschnitts-Typen bekommt `footer_type` ein „Ø" davor.
 * Das wirkt in BEIDEN Fassungen der Ansicht — der eigenen des Moduls (die
 * `column_title` nutzt und ohnehin richtig war) und der des Themes.
 *
 * ⚠ Bewusst NUR das Voranstellen. Die Zahl, die Sortierung und die Auswahl
 *   bleiben unverändert — sie waren nie falsch.
 */
class LeaderBoardFix extends LeaderBoard
{
    /** Typen, deren Wert ein Durchschnitt ist (siehe DB_StatServices::LeaderBoard). */
    private const DURCHSCHNITT = ['score', 'lrate'];

    public function run()
    {
        $view = parent::run();

        $typ = (string) ($this->config['type'] ?? '');
        if (! in_array($typ, self::DURCHSCHNITT, true)) {
            return $view;
        }

        // parent::run() liefert eine View-Instanz; nur den einen Text ersetzen.
        if (! $view instanceof \Illuminate\Contracts\View\View) {
            return $view;
        }

        $daten = $view->getData();
        $kopf  = (string) ($daten['footer_type'] ?? '');

        // Idempotent: bei wiederholtem Rendern kein zweites Ø anhängen.
        if ($kopf !== '' && ! str_starts_with($kopf, 'Ø')) {
            $daten['footer_type'] = 'Ø ' . $kopf;
        }

        return view($view->name(), $daten);
    }
}
