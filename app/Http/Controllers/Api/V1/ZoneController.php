<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ZoneResource;
use App\Models\Zone;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    public function index()
    {
        return ZoneResource::collection(Zone::orderBy('name')->get());
    }

    public function store(Request $request)
    {
        $request->validate(['name' => ['required', 'string', 'max:255', 'unique:zones,name']]);
        $zone = Zone::create(['name' => $request->name]);
        return (new ZoneResource($zone))->response()->setStatusCode(201);
    }

    public function update(Request $request, Zone $zone)
    {
        $request->validate(['name' => ['required', 'string', 'max:255', 'unique:zones,name,' . $zone->id]]);
        $zone->update(['name' => $request->name]);
        return (new ZoneResource($zone))->response();
    }

    public function destroy(Zone $zone)
    {
        $zone->delete();
        return response()->json(null, 204);
    }
}
