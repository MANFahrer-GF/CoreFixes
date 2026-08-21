# CoreFixes

Ein phpVMS-7-Modul, das Fixes für **Core-Bugs update-fest** hält.
*(English below — [jump](#corefixes-english))*

## Warum

Einen Core-Bug repariert man normalerweise, indem man die Datei unter `app/` ändert.
Das Problem: Jedes phpVMS-Update überschreibt sie, und der Bug ist still wieder da —
auf GSG ist genau das schon mehrfach passiert (`acars`-Fuel-Fillable, Block-Zeiten).

Dieses Modul fasst **keine einzige fremde Datei an**. Es hängt sich von außen ein — in die
Model-Events, oder (Fix 3) in den Service-Container — und korrigiert das Verhalten. Weder ein
phpVMS- noch ein Modul-Update kann ihm etwas anhaben.

Seit Fix 3 gilt dasselbe Prinzip auch für **Fremdmodule**, nicht nur für den Core.

## Enthaltene Fixes

### 1. PIREP-Block-Zeiten (`PirepBlockTimeObserver`)

**Symptom:** `pireps.block_off_time` bleibt für immer `NULL` — auf GSG bei *jedem*
AeroACARS-PIREP (0 % gefüllt; vmsACARS und smartCARS: 100 %). Module, die Flüge einem
Zeitfenster zuordnen, sehen dadurch nur die Ankunft: Ein Langstreckenflug, der im
Event-Fenster startet und danach landet, fällt aus der Wertung.

**Ursache:** phpVMS *will* die Zeiten selbst nachtragen (`PirepService::create()`):

```php
if (!$pirep->block_on_time)  { $pirep->block_on_time = $pirep->submitted_at ?: now(); }
if (!$pirep->block_off_time && $pirep->flight_time > 0) {
    $pirep->block_off_time = $pirep->block_on_time->subMinutes($pirep->flight_time);
}
```

Beide Guards feuern **nie**. Die Spalten sind mit `App\Casts\CarbonCast` gecastet, und
dessen `get()` macht aus einem `NULL`-Wert `new Carbon(null)` — also die **aktuelle
Uhrzeit**, die immer truthy ist. Der Core prüft damit nie auf „leer", sondern immer auf
„jetzt". Die Spalte bleibt in der Datenbank leer, während jeder Leser einen Zeitstempel
serviert bekommt, der schlicht der Moment des Lesens ist.

**Fix:** Ein `saving`-Observer macht dieselbe Reparatur, die der Core beabsichtigt —
prüft aber den **Rohwert** aus `getAttributes()` statt den vom Cast verfälschten Getter.
Läuft unabhängig davon, welcher ACARS-Client oder Code-Pfad den PIREP anlegt.

Konservativ: gesetzte Werte werden nie überschrieben; ohne Flugzeit wird nichts erfunden
(die Spalte bleibt dann leer, statt eine Zahl zu raten). Ein Fehler im Fix kann eine
PIREP-Einreichung nicht kippen — er wird geloggt und geschluckt.

### 2. Flight-Number-Hard-Block (`FlightNumberObserver`)

**Symptom:** Ein Personal-/Freiflug (z.B. das DisposableSpecial-Modul) kann mit
`flight_number = 0` gespeichert werden — das Formular lehnt nur ein *leeres* Feld ab,
eine „0" zählt als gültige Eingabe. Jeder Consumer der phpVMS-API (Admin-Oberfläche,
ACARS-Clients, Exporte) sieht danach eine bedeutungslose „0" statt eines echten
Identifiers, obwohl das Modul meist einen echten `callsign` (z.B. „7ME") gesetzt hat.

**Warum kein Core-Fix wie bei den Block-Zeiten:** `flights.flight_number` ist
`int(10) unsigned NOT NULL` — eine Normalisierung auf `null` würde den Save entweder
hart brechen oder (da dieser Server ohne `STRICT_TRANS_TABLES` läuft) klaglos wieder
auf `0` zurückfallen.

**Fix:** dieser Observer wirft eine Exception und blockiert damit den Save komplett,
sobald `flight_number <= 0` ist. Kein stilles `return false`: Das Formular, das diesen
Bug auslöst, prüft den Rückgabewert von `save()` nirgends und legt danach *unbedingt*
eine Buchung (`Bid`) gegen die Flight-ID an, inkl. Erfolgsmeldung — ein nur per
`return false` verworfener Save hätte also eine kaputte Buchung erzeugt, die wie ein
Erfolg aussieht. Die Exception bricht die gesamte Anfrage ab, *bevor* die Buchung
entsteht.

Die geworfene `FlightNumberInvalidException` definiert ihre eigene `render()` (ein
dokumentierter Laravel-Erweiterungspunkt — kein Core-File-Edit nötig) und zeigt dem
Piloten eine Meldung **in seiner tatsächlich eingestellten Sprache** (liest dieselbe
`SetActiveLanguage`-Middleware/`lang`-Cookie, die auch der Rest der Seite nutzt) statt
Laravels generischer, immer-englischer Standardfehlerseite.

Die zusätzliche Client-Reparatur bleibt bestehen und ist weiterhin die primäre
Abhilfe für bereits bestehende Fälle: `callsign` wird vor `flight_number` bevorzugt
angezeigt, genau wie phpVMS' eigener `Flight::atc()`-Accessor es bereits tut.

### 3. Wochen-Cron gegen das Platzhalterlimit (`DisposableCronFix`)

**Symptom:** `php artisan cron:weekly` bricht ab mit
`SQLSTATE[HY000] 1390 Prepared statement contains too many placeholders`. Der
Absturz landet ausschließlich im Log — nach außen fällt nichts auf. Auf GSG war
der Wochen-Cron dadurch vom **10.05.2026 bis 18.08.2026**, also rund 14 Wochen,
still tot.

**Ursache:** MySQL erlaubt höchstens **65.535 Platzhalter** je Anweisung.
DisposableSpecial baut an **zwei** Stellen eine Abfrage über eine komplette
Id-Liste:

```php
// CleanAcarsRecords() — hier 195.617 Ids
$records = Acars::withCount(['pirep'])->having('pirep_count', 0)->pluck('id')->toArray();
$acars   = Acars::whereIn('id', $records)->delete();

// CleanRelationships() — hier 189.525 Flüge
$flights = Flight::pluck('id')->toArray();
DB::table('flight_fare')->whereNotIn('flight_id', $flights)->delete();
```

Es ist also keine Einzelstelle, sondern eine **Bauart**: jedes `whereIn`/
`whereNotIn`, dessen Liste aus einem `pluck()` über eine wachsende Tabelle
kommt, kippt irgendwann. Wer nur die erste Stelle repariert, sieht den Cron
sofort an der zweiten sterben.

**Reichweite:** DisposableSpecial ist der **letzte** von fünf Listenern am
`CronWeekly`-Event, deshalb blieb der Schaden klein — mit ausgefallen ist nur
`CleanRelationships()`. Bei einem Listener weiter vorn wäre das anders. Die
Listener-Reihenfolge (`Event::getListeners(CronWeekly::class)`) gehört deshalb
zur Diagnose.

**Fix:** Beide Methoden werden überschrieben — `CleanAcarsRecords()` löscht in
Blöcken zu 1.000, `CleanRelationships()` nutzt eine `whereNotExists`-Unterabfrage
und damit gar keine Platzhalter mehr. Alles andere erbt unverändert.

Der `whereNotNull`-Riegel in der Unterabfrage ist **Bedeutung, keine Kosmetik**:
`NULL NOT IN (...)` ist in SQL unbekannt und traf damit nie zu. Ohne den Riegel
würden Zeilen mit leerem Fremdschlüssel neuerdings mitgelöscht — eine stille
Verhaltensänderung gegenüber dem Original.

**Warum im Container statt in der Moduldatei:** ein Patch an
`modules/DisposableSpecial/` ist beim nächsten Modul-Update weg, und der Fehler
käme still zurück — genau die Falle, wegen der es dieses Modul überhaupt gibt.
Alle vier Aufrufe im Listener holen den Dienst über
`app(DS_CronServices::class)`, also über den Container. Eine einzige Bindung
deckt sie ab, die Fremddatei bleibt unberührt.

**Selbstprüfung:** Der Fix hält den Prüfsummen-Stand der beiden Original-Methoden
fest und vergleicht ihn bei jedem Lauf. Ändert ein Modul-Update die Vorlage,
erscheint eine Warnung im Log — sonst würde dieser Override eine spätere
Korrektur von DisposableSpecial still verdecken. Die Prüfung fällt bewusst leise
aus, wenn sie selbst scheitert: ein Aufräum-Cron darf nicht an seiner eigenen
Selbstkontrolle sterben.

**Nicht angetastet:** die Auswahl der zu löschenden Zeilen. `withCount(['pirep'])`
zählt über die Beziehung, und `Pirep` nutzt SoftDeletes — soft-gelöschte PIREPs
gelten dem Modul also als weg, samt ihrer Positionsspuren. Das ist seit jeher so
und bleibt so.

⚠️ **Falle beim Trockenlauf:** Wer vorher zählen will, wieviel gelöscht würde,
muss **genau die Abfrage des Codes** nachbauen. Ein naheliegendes
`LEFT JOIN pireps` zählt anders (bei uns 195.617 statt 242.400), weil es
soft-gelöschte PIREPs als vorhanden ansieht.

## Achtung: der Cast-Bug bleibt bestehen

Das Modul sorgt dafür, dass die Spalten **gefüllt** werden. Der `CarbonCast` selbst ist
weiterhin kaputt: Liest irgendwo im System ein Datumsfeld, das `NULL` ist, bekommt es
„jetzt" zurück. Wer Code gegen phpVMS schreibt, darf `?? null` **nicht** als
Vorhandensein-Test benutzen — immer gegen `getRawOriginal()` / `getAttributes()` prüfen
oder auf Plausibilität testen.

## Installation

Modul nach `modules/CoreFixes` legen, in der Admin-Oberfläche aktivieren
(Admin → Module) und die Caches leeren. Keine Migration, keine Konfiguration,
keine Abhängigkeiten.

---

# CoreFixes (English)

A phpVMS 7 module that keeps fixes for **core bugs update-proof**.

## Why

The usual way to fix a core bug is to edit the file under `app/`. The problem: every phpVMS
update overwrites it and the bug is silently back. That has bitten us more than once.

This module touches **no third-party file at all**. It hooks in from the outside — into the model
events, or (fix 3) into the service container — and corrects the behaviour. Neither a phpVMS nor a
module update can undo it.

Since fix 3 the same principle covers **third-party modules**, not just the core.

## Included fixes

### 1. PIREP block times (`PirepBlockTimeObserver`)

**Symptom:** `pireps.block_off_time` stays `NULL` forever. On our install that was the case for
*every* PIREP filed by one particular ACARS client (0 % filled, while vmsACARS and smartCARS
filled it 100 % of the time). Modules that map a flight onto a time window therefore only ever
saw the arrival — a long-haul flight departing inside an event window but landing after it fell
out of the scoring.

**Root cause:** phpVMS *intends* to backfill the times itself (`PirepService::create()`):

```php
if (!$pirep->block_on_time)  { $pirep->block_on_time = $pirep->submitted_at ?: now(); }
if (!$pirep->block_off_time && $pirep->flight_time > 0) {
    $pirep->block_off_time = $pirep->block_on_time->subMinutes($pirep->flight_time);
}
```

Neither guard ever fires. Both columns are cast through `App\Casts\CarbonCast`, whose `get()`
turns a `NULL` value into `new Carbon(null)` — i.e. **the current time**, which is always truthy.
The core therefore never tests for "empty", it tests for "now". The column stays empty in the
database while every reader is served a timestamp that is merely the moment of reading.

**Fix:** a `saving` observer performs the very repair the core intends — but checks the **raw
value** from `getAttributes()` instead of the cast-corrupted getter. It runs regardless of which
ACARS client or code path created the PIREP.

Conservative by design: existing values are never overwritten, and without a flight time nothing
is invented (the column simply stays empty rather than carrying a guess). A failure inside the fix
can never break a PIREP submission — it is logged and swallowed.

### 2. Flight-number hard block (`FlightNumberObserver`)

**Symptom:** a Personal/Free flight (e.g. via the DisposableSpecial module) can be
saved with `flight_number = 0` — the form only rejects an *empty* field, a literal
"0" counts as valid input. Every consumer of the phpVMS API (admin UI, ACARS clients,
exports) then sees a meaningless "0" instead of a real identifier, even though the
module usually did set a real `callsign` (e.g. "7ME").

**Why not a core fix like the block-time one:** `flights.flight_number` is
`int(10) unsigned NOT NULL` — normalizing it to `null` would either hard-break the
save, or (since this server runs without `STRICT_TRANS_TABLES`) silently fall back to
`0` again anyway.

**Fix:** this observer throws an exception and blocks the save outright once
`flight_number <= 0`. Not a silent `return false`: the form that triggers this bug
never checks `save()`'s return value and unconditionally creates a booking (`Bid`)
against the flight ID afterward, plus a success message — a save merely dropped via
`return false` would have produced a broken booking that looks like a success. The
exception aborts the whole request *before* that booking is created.

The thrown `FlightNumberInvalidException` defines its own `render()` (a documented
Laravel extension point — no core-file edit needed) and shows the pilot a message
**in whatever language they've actually selected** (reads the same
`SetActiveLanguage` middleware / `lang` cookie the rest of the site uses) instead of
Laravel's generic, always-English default error page.

The client-side fix remains in place and is still the primary remedy for already-
existing cases: `callsign` is preferred over `flight_number` everywhere, exactly like
phpVMS's own `Flight::atc()` accessor already does.

### 3. Weekly cron vs. the placeholder limit (`DisposableCronFix`)

**Symptom:** `php artisan cron:weekly` aborts with
`SQLSTATE[HY000] 1390 Prepared statement contains too many placeholders`. The
crash only ever reaches the log — nothing is visible from the outside. On our
install the weekly cron was silently dead for about 14 weeks because of it.

**Root cause:** MySQL allows at most **65,535 placeholders** per statement.
DisposableSpecial builds a query over a complete ID list in **two** places:

```php
// CleanAcarsRecords() — 195,617 IDs here
$records = Acars::withCount(['pirep'])->having('pirep_count', 0)->pluck('id')->toArray();
$acars   = Acars::whereIn('id', $records)->delete();

// CleanRelationships() — 189,525 flights here
$flights = Flight::pluck('id')->toArray();
DB::table('flight_fare')->whereNotIn('flight_id', $flights)->delete();
```

This is not a single site but a **shape**: any `whereIn`/`whereNotIn` whose list
comes from a `pluck()` over a growing table will eventually tip over. Fix only
the first one and the cron dies at the second immediately.

**Blast radius:** DisposableSpecial is the **last** of five listeners on the
`CronWeekly` event, which kept the damage small — only `CleanRelationships()`
went down with it. A listener further up the chain would be a different story,
so the listener order (`Event::getListeners(CronWeekly::class)`) is part of the
diagnosis.

**Fix:** both methods are overridden — `CleanAcarsRecords()` deletes in chunks of
1,000, `CleanRelationships()` uses a `whereNotExists` subquery and therefore no
placeholders at all. Everything else is inherited unchanged.

The `whereNotNull` guard inside the subquery is **semantics, not cosmetics**:
`NULL NOT IN (...)` is unknown in SQL and therefore never matched. Without the
guard, rows with an empty foreign key would newly be deleted — a silent
behavioural change against the original.

**Why in the container rather than in the module file:** a patch to
`modules/DisposableSpecial/` is gone with the next module update and the bug
would silently return — precisely the trap this module exists for. All four call
sites in the listener resolve the service through `app(DS_CronServices::class)`,
i.e. through the container. A single binding covers them all and the third-party
file stays untouched.

**Self-check:** the fix records a checksum of both original methods and compares
it on every run. If a module update changes the template, a warning appears in
the log — otherwise this override would silently mask a later fix from
DisposableSpecial. The check fails quietly if it breaks itself: a cleanup cron
must not die on its own self-inspection.

**Left alone:** which rows are selected for deletion. `withCount(['pirep'])`
counts through the relation, and `Pirep` uses SoftDeletes — so the module treats
soft-deleted PIREPs as gone, along with their position trails. That has always
been the case and stays that way.

⚠️ **Dry-run trap:** if you want to count what would be deleted beforehand, build
**exactly the query the code uses**. An obvious `LEFT JOIN pireps` counts
differently (195,617 instead of 242,400 for us) because it treats soft-deleted
PIREPs as present.

## Careful: the cast bug itself remains

This module ensures the columns get **filled**. `CarbonCast` is still broken: read any date field
that is `NULL` anywhere in the system and you get "now" back. When writing code against phpVMS,
do **not** use `?? null` as an existence check — read `getRawOriginal()` / `getAttributes()`, or
test for plausibility.

## Installation

Drop the module into `modules/CoreFixes`, enable it under Admin → Modules, clear the caches.
No migration, no configuration, no dependencies.

## Fix 5 — doppelte ACARS-Positionen bei wiederholtem Senden

**Befund (21.08.2026, 6 GSG-Fluege geprueft):** jeder Flug trug 19–122 doppelte
Positionszeilen. Der parallele MQTT-Strom zum VPS-Recorder war bei denselben
Fluegen dublettenfrei (4.448 von 4.461 Punkten eindeutig) — die Erfassung war
sauber, es lag am Weg zu phpVMS.

**Kette:** Der ACARS-Client bricht einen Positions-POST ab (Timeout waehrend
einer Lastspitze). phpVMS merkt davon nichts, weil `ignore_user_abort` erst bei
Output greift und der Insert-Loop keinen erzeugt — es schreibt alle Zeilen und
antwortet 200. Der Client haelt den Batch fuer verloren und sendet erneut.

**Warum nicht einfach der Core-Zweig:** `AcarsController::acars_store` kennt den
Fall bereits — traegt eine Position ein `id`-Feld, ruft er
`Acars::updateOrInsert()`. Das ist aber der **Query-Builder**: keine Timestamps,
keine Casts, kein `$fillable`. Nachgemessen in der lokalen phpVMS-7.0.8-Sandbox:
`created_at` und `updated_at` bleiben **NULL**. Auf GSG sortieren sowohl der
Core-Track (`Pirep::acars()` → `orderBy('created_at')`) als auch das GsgLogbook
genau danach — der Core-Zweig haette die Zeitachse zerstoert.

**Fix:** derselbe Gedanke, nur mit Eloquent (`updateOrCreate`). Der Schluessel
enthaelt bewusst auch die `pirep_id`, sonst koennte ein Client mit einer fremden
`id` eine fremde Position ueberschreiben — der Endpunkt prueft nur, ob der PIREP
existiert und nicht storniert ist.

**Verifiziert** (lokale Sandbox, phpVMS 7.0.8, PHP 8.3):

| Fall | Ergebnis |
|---|---|
| Batch mit `id`, zweimal gesendet | 2 Zeilen bleiben 2 Zeilen |
| … `created_at` / `updated_at` | gesetzt |
| Gegenprobe: Modul deaktiviert | 1 Zeile, `created_at` **NULL** |
| Batch ohne `id`, zweimal | 2 Zeilen (Altverhalten unveraendert) |
| Fremde `id` eines anderen PIREP | fremde Zeile unveraendert |

**Achtung bei phpVMS-Updates:** `acars_store` ist eine Kopie der Core-Methode mit
einer geaenderten Zeile. Nach einem Core-Update gegen das Original vergleichen.
