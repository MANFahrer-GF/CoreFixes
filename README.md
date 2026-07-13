# CoreFixes

Ein phpVMS-7-Modul, das Fixes für **Core-Bugs update-fest** hält.
*(English below — [jump](#corefixes-english))*

## Warum

Einen Core-Bug repariert man normalerweise, indem man die Datei unter `app/` ändert.
Das Problem: Jedes phpVMS-Update überschreibt sie, und der Bug ist still wieder da —
auf GSG ist genau das schon mehrfach passiert (`acars`-Fuel-Fillable, Block-Zeiten).

Dieses Modul fasst **keine einzige Core-Datei an**. Es hängt sich von außen in die
Model-Events und korrigiert das Verhalten. Ein phpVMS-Update kann ihm nichts anhaben.

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

This module touches **no core file at all**. It hooks into the model events from the outside
and corrects the behaviour. A phpVMS update cannot undo it.

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

## Careful: the cast bug itself remains

This module ensures the columns get **filled**. `CarbonCast` is still broken: read any date field
that is `NULL` anywhere in the system and you get "now" back. When writing code against phpVMS,
do **not** use `?? null` as an existence check — read `getRawOriginal()` / `getAttributes()`, or
test for plausibility.

## Installation

Drop the module into `modules/CoreFixes`, enable it under Admin → Modules, clear the caches.
No migration, no configuration, no dependencies.
