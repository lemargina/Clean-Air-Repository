<?php

namespace App\Http\Controllers;

use App\Services\AirQuality\CleanestCityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AirQualityController extends Controller
{
    public function __construct(private CleanestCityService $service) {}

    private function dates(Request $request): array
    {
        $v = Validator::make($request->only('from', 'to'), ['from' => ['required', 'date_format:Y-m-d', 'before_or_equal:to'], 'to' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Europe/Sofia')->toDateString()]]);
        $v->validate();
        $coverage = $this->service->coverage();
        if ($coverage['from'] && ($request->string('from')->toString() < $coverage['from'] || $request->string('to')->toString() > $coverage['to'])) {
            throw ValidationException::withMessages(['from' => 'Периодът е извън наличната локална история.']);
        }

        return [$request->string('from')->toString(), $request->string('to')->toString()];
    }

    public function index(Request $request)
    {
        $result = null;
        $coverage = $this->service->coverage();
        if ($request->hasAny(['from', 'to'])) {
            [$from,$to] = $this->dates($request);
            $result = $this->service->calculate($from, $to);
        }

        return view('air', compact('result', 'coverage'));
    }

    public function ranking(Request $request)
    {
        [$from,$to] = $this->dates($request);

        return response()->json($this->service->calculate($from, $to));
    }

    public function city(Request $request, int $cityId)
    {
        abort_unless(DB::table('cities')->where('id', $cityId)->exists(), 404);
        [$from,$to] = $this->dates($request);
        $result = $this->service->calculate($from, $to);

        return response()->json(['city' => collect([...$result['ranking'], ...$result['excluded']])->firstWhere('city_id', $cityId), 'daily' => DB::table('city_daily_statistics')->where('city_id', $cityId)->whereBetween('date', [$from, $to])->orderBy('date')->get(), 'coverage' => $result['coverage'], 'methodology' => $result['methodology']]);
    }

    public function cities()
    {
        return response()->json(['data' => DB::table('cities')->orderBy('name_bg')->get(), 'attribution' => 'GeoNames, CC BY 4.0 — https://www.geonames.org/']);
    }

    public function coverage()
    {
        return response()->json($this->service->coverage());
    }
}
