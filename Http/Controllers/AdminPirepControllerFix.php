<?php

declare(strict_types=1);

namespace Modules\CoreFixes\Http\Controllers;

use App\Http\Controllers\Admin\PirepController;
use App\Models\Enums\PirepState;
use App\Repositories\PirepRepository;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FIX 13 — `/admin/pireps/pending` antwortete mit HTTP 500.
 *
 * Der Kern ruft dort `findWhere(...)->orderBy(...)->paginate()`. `findWhere`
 * liefert aber schon eine fertige Collection, und die kennt kein `orderBy`
 * ("Method Illuminate\Database\Eloquent\Collection::orderBy does not exist").
 * Ausserdem filtert er auf die Spalte `status` (Flugphase) statt auf `state`
 * (Bearbeitungsstand), haette also selbst ohne den Absturz nichts gefunden.
 * Unveraendert so in phpVMS 7.0.11 und 7.0.13.
 *
 * Diese Ableitung ersetzt nur `pending` und baut die Abfrage wie `index`
 * im Kern: gleiche Beziehungen, gleiche Ansicht, nur auf wartende PIREPs
 * beschraenkt, neueste zuerst. Alles andere erbt sie.
 */
final class AdminPirepControllerFix extends PirepController
{
    public function pending(Request $request): View
    {
        // Das Repository des Kerns ist `private`, deshalb ein eigenes aus dem Container.
        $repo = app(PirepRepository::class);

        // phpVMS 7.0.13 bringt eine eigene, eingeschraenkte RequestCriteria mit; davor gab es nur die von Prettus.
        $criteria = class_exists(\App\Repositories\Criteria\RequestCriteria::class)
            ? \App\Repositories\Criteria\RequestCriteria::class
            : \Prettus\Repository\Criteria\RequestCriteria::class;
        $repo->pushCriteria(new $criteria($request));

        $pireps = $repo
            ->with(['airline', 'aircraft', 'dpt_airport', 'arr_airport', 'user'])
            ->whereOrder(['pireps.state' => PirepState::PENDING], 'pireps.created_at', 'desc')
            ->paginate();

        return view('admin.pireps.index', [
            'pireps' => $pireps,
        ]);
    }
}
