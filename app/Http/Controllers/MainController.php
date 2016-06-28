<?php

namespace App\Http\Controllers;

use App\Services\ParserService;
use Illuminate\Http\Request;

use App\Http\Requests;

class MainController extends Controller
{
    function home()
    {
        $data = [];
        $data = $data + ['routes' => ParserService::getRoutes()];
        $data = $data + ['itineraries' => ParserService::getRouteItineraries(array_keys($data['routes'])[0])];
        $data = $data + ParserService::getRouteItineraryMap(array_keys($data['routes'])[0], array_keys($data['itineraries'])[0]);

        return view('home', $data);
    }

    function data(Request $request)
    {
        switch($request->get('what')) {
            case 'routes': {
                $data = ParserService::getRoutes();
                break;
            }
            case 'itineraries': {
                $data = ParserService::getRouteItineraries($request->get('route'));
                break;
            }
            case 'stops': {
                $data = ParserService::getRouteItineraryStops($request->get('route'), $request->get('itinerary'));
                break;
            }
            case 'map': {
                $data = ParserService::getRouteItineraryMap($request->get('route'), $request->get('itinerary'));
                break;
            }
            case 'next_bus': {
                $data = ParserService::getRouteItineraryStopNextBus($request->get('route'), $request->get('itinerary'), $request->get('stop'), $request->get('time'));
                break;
            }
            case 'bus_position': {
                $data = ParserService::getRouteItineraryBusPosition($request->get('route'), $request->get('itinerary'), $request->get('bus'));
                break;
            }
            default: {
                return response()->json(['error' => 404, 'message' => 'Not found'], 404);
            }
        }

        return response()->json($data);
    }
}
