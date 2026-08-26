<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Haengt den CARTO-Schluessel an jede Kachel-Anfrage der Karten.
 *
 * # Der Anlass (26.08.2026)
 *
 * CARTO verlangt seit diesem Tag einen Schluessel fuer seine Basiskarten.
 * Die RASTER-Kacheln — und nur die — tragen seither ein Wasserzeichen
 * „API KEY REQUIRED" quer ueber die Karte. Gemessen an derselben Kachel
 * ueber Deutschland: mit Schluessel sauber, ohne Schluessel beschriftet.
 *
 * Betroffen ist die PIREP-Karte des Themes. Sie waehlt ihre Karte ueber
 * `providers: {'CartoDB.Positron': {}}`, das phpVMS an `leaflet-providers`
 * weiterreicht — und dessen Katalog hat fuer CartoDB **keinen Platz fuer
 * einen Schluessel**:
 *
 *     CartoDB: { url: "http://{s}.basemaps.cartocdn.com/{variant}/{z}/{x}/{y}.png", … }
 *
 * Kein `{apikey}`, und ueber Optionen laesst sich die Vorlage nicht
 * erweitern. Den Katalog liegt in `public/assets/frontend/js/app.js` — eine
 * Core-Datei, die jedes Update ueberschreibt.
 *
 * # Warum an dieser Stelle
 *
 * Nicht die Adressen einzeln flicken, sondern die eine Stelle abfangen,
 * durch die JEDE Kachel geht: `L.TileLayer.prototype.getTileUrl`. Damit
 * ist es gleichgueltig, welche Blade-Datei die Karte baut, ob sie den
 * Anbieter-Katalog benutzt oder eine eigene Adresse setzt, und ob spaeter
 * eine weitere Karte dazukommt.
 *
 * Dieselbe Ueberlegung wie im AeroACARS-Client, wo MapLibre dafuer
 * `transformRequest` anbietet.
 *
 * # Warum in den `<head>`
 *
 * Das Theme laedt Leaflet erst im `<body>` (`vendor.js`), und die Karten
 * bauen sich danach in `@yield('scripts')` auf. Ein Skript am Seitenende
 * kaeme also ZU SPAET: Die ersten Kacheln waeren schon anguefordert und
 * traegen das Wasserzeichen, bis der Nutzer schwenkt.
 *
 * Deshalb wird im `<head>` ein Wachposten auf `window.L` gesetzt. Sobald
 * Leaflet sich dort eintraegt, greift der Fix — vor der ersten Karte.
 */
class CartoSchluessel
{
    /** Die Adressen, die einen Schluessel brauchen. */
    private const HOST = 'cartocdn.com';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $schluessel = self::schluessel();
        if ($schluessel === '') {
            return $response;
        }

        // Nur echte HTML-Seiten. Downloads, JSON-Antworten und
        // Weiterleitungen bleiben unangetastet.
        $typ = (string) $response->headers->get('Content-Type', '');
        if (! str_contains($typ, 'text/html')) {
            return $response;
        }
        $inhalt = $response->getContent();
        if (! is_string($inhalt) || ! str_contains($inhalt, '</head>')) {
            return $response;
        }

        // Nur EINMAL einsetzen, auch wenn eine Seite mehrere `</head>`
        // enthaelt (etwa in einem eingebetteten Beispiel).
        $response->setContent(
            self::einsetzenVor($inhalt, '</head>', self::skript($schluessel))
        );

        return $response;
    }

    /**
     * Woher der Schluessel kommt — in dieser Reihenfolge.
     *
     * 1. Die phpVMS-Einstellung. Dort schreibt ihn spaeter die Oberflaeche
     *    des LiveMap-Moduls hin; beide lesen denselben Wert.
     * 2. Die `.env`. Damit laesst er sich sofort hinterlegen, bevor es die
     *    Oberflaeche gibt — und bleibt gueltig, wenn sie kommt.
     *
     * Ohne beides greift der Fix nicht und die Seite bleibt unveraendert.
     */
    public static function schluessel(): string
    {
        // ⚠ Ein EINTRAG schlaegt die `.env` — auch ein leerer.
        //
        // Die erste Fassung nahm die `.env`, sobald die Einstellung leer
        // war. Damit liess sich der Schluessel ueber die Oberflaeche nicht
        // loeschen: Das Haekchen leerte die Einstellung, und im naechsten
        // Moment lieferte die `.env` ihn zurueck. Ein Loeschen, das nicht
        // loescht, ist schlimmer als keines — man haelt den Schluessel fuer
        // entfernt, waehrend er weiter auf jeder Seite steht.
        //
        // Deshalb der Wachwert: Gibt es die Zeile, gilt ihr Inhalt, auch
        // wenn er leer ist. Die `.env` greift nur, solange NIEMAND etwas
        // eingetragen hat — der Zustand vor der ersten Benutzung.
        if (function_exists('setting')) {
            $wachwert = '__COREFIXES_KEINE_ZEILE__';
            $wert = setting('acars.carto_api_key', $wachwert);
            if ($wert !== $wachwert) {
                return is_string($wert) ? trim($wert) : '';
            }
        }

        return trim((string) env('CARTO_API_KEY', ''));
    }

    /** Vor dem ERSTEN Vorkommen einsetzen. */
    private static function einsetzenVor(string $heuhaufen, string $marke, string $neu): string
    {
        $pos = strpos($heuhaufen, $marke);
        if ($pos === false) {
            return $heuhaufen;
        }

        return substr($heuhaufen, 0, $pos).$neu.substr($heuhaufen, $pos);
    }

    /**
     * Der Wachposten.
     *
     * Bewusst ohne Abhaengigkeiten und ohne moderne Syntax: Er laeuft vor
     * allem anderen, auch in aelteren Browsern, und ein Fehler hier wuerde
     * die ganze Seite kosten. Alles steht in `try`.
     */
    private static function skript(string $schluessel): string
    {
        $s = json_encode($schluessel, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $host = json_encode(self::HOST);

        return <<<HTML
<script>/* CoreFixes: CARTO-Schluessel an jede Kachel */
(function () {
  try {
    var SCHLUESSEL = {$s}, HOST = {$host}, gepatcht = false;
    function patch(L) {
      if (gepatcht || !L || !L.TileLayer || !L.TileLayer.prototype) return false;
      var alt = L.TileLayer.prototype.getTileUrl;
      if (typeof alt !== "function") return false;
      L.TileLayer.prototype.getTileUrl = function (coords) {
        var url = alt.call(this, coords);
        if (typeof url === "string" && url.indexOf(HOST) !== -1 && !/[?&]key=/.test(url)) {
          url += (url.indexOf("?") === -1 ? "?" : "&") + "key=" + encodeURIComponent(SCHLUESSEL);
        }
        return url;
      };
      gepatcht = true;
      return true;
    }
    if (patch(window.L)) return;
    var wert;
    try {
      Object.defineProperty(window, "L", {
        configurable: true,
        get: function () { return wert; },
        set: function (v) { wert = v; patch(v); }
      });
    } catch (e) { /* Eigenschaft nicht setzbar — der Notnagel unten greift. */ }
    document.addEventListener("DOMContentLoaded", function () { patch(window.L); });
    window.addEventListener("load", function () { patch(window.L); });
  } catch (e) { /* Eine Karte ohne Schluessel ist besser als eine kaputte Seite. */ }
})();</script>
HTML;
    }
}
