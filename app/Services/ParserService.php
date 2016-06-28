<?php

namespace App\Services;

use Carbon\Carbon;
use GuzzleHttp\Client;

class ParserService
{
    const ENDPOINT = 'http://bus.elpuertodesantamaria.es/';

    /**
     * @return Client
     */
    public static function client()
    {
        return new Client([
            'base_uri' => self::ENDPOINT,
        ]);
    }

    public static function getRoutes()
    {
        $routes = [];

        $response = self::client()->request('POST', 'm/php/getdata.php', [
            'form_params' => [
                'exec_function' => 1,
            ],
        ]);

        $data = json_decode($response->getBody());

        foreach ($data as $item)
        {
            if ($item->idlinea == 0) continue;

            $routes[$item->idlinea] = [
                'code' => $item->linea,
                'name' => $item->desc_usuario,
            ];
        }

        return $routes;
    }

    public static function getRouteItineraries($routeId)
    {
        $itineraries = [];

        $response = self::client()->request('POST', 'm/php/getdata.php', [
            'form_params' => [
                'exec_function' => 2,
                'idline' => $routeId,
            ],
        ]);

        $data = json_decode($response->getBody());

        foreach ($data as $item)
        {
            if ($item->iditinerario == 0) continue;

            $itineraries[$item->iditinerario] = [
                'name' => $item->itinerario,
            ];
        }

        return $itineraries;
    }

    public static function getRouteItineraryStops($routeId, $itineraryId)
    {
        $stops = [];

        $response = self::client()->request('POST', 'm/php/getdata.php', [
            'form_params' => [
                'exec_function' => 2,
                'idline' => $routeId,
            ],
        ]);

        $data = json_decode($response->getBody());

        foreach ($data as $item)
        {
            if ($item->iditinerario != $itineraryId) continue;

            $stops[$item->idparada] = [
                'name' => $item->parada,
            ];
        }

        return $stops;
    }

    public static function getRouteItineraryStopNextBus($routeId, $itineraryId, $stopId, $currentTime = null)
    {
        $response = self::client()->request('POST', 'm/php/getdata.php', [
            'form_params' => [
                'exec_function' => 3,
                'idline' => $routeId,
                'iditinerario' => $itineraryId,
                'idparada' => $stopId,
            ],
        ]);

        $data = json_decode($response->getBody());
        //dd($data);

        $now = Carbon::now();
        if ($currentTime) {
            $now->setTimeFromTimeString($currentTime);
        }

        if ('teorica' == $data[0]->type) {
            $time = new Carbon($now->toDateString() . ' ' . $data[0]->realtime);
            $type = 'theoretical';
        } elseif ('real' == $data[0]->type) {
            $time = $now->copy();
            $minutes = preg_replace('/[^0-9.]+/', '', $data[0]->realtime);
            $time->addMinutes($minutes);
            $type = 'estimated';
        } else {
            $time = Carbon::now()->startOfDay();
            $type = 'unknown';
        }

        return [
            'time' => substr($time->toTimeString(), 0, 5),
            'wait' => $time->diffInMinutes($now),
            'type' => $type,
        ];
    }

    public static function getRouteItineraryBusPosition($routeId, $itineraryId, $busId)
    {
        $response = self::client()->request('POST', 'tiemporeal/ajax_posicionbus.php', [
            'form_params' => [
                'linea' => $routeId,
                'itinerario' => $itineraryId,
                'idbus' => $busId,
            ],
        ]);

        $data = (string) $response->getBody();

        return explode(':', $data);
    }

    public static function getRouteItineraryMap($routeId, $itineraryId)
    {
        $coords = [];
        $stops = [];
        $buses = [];

        $response = self::client()->request('POST', 'tiemporeal/mapa.php', [
            'form_params' => [
                'linea' => $routeId,
                'itinerario' => $itineraryId,
            ],
        ]);

        $html = (string) $response->getBody();
        $htmlLines = explode(PHP_EOL, $html);
        $htmlCoordsText = '';
        $htmlStopsCoords = [];
        $htmlStopsIds = [];
        $htmlStopsNames = [];
        $htmlBusIds = [];

        foreach ($htmlLines as $line) {
            if (false !== strpos($line, 'var coordLineas =')) {
                $htmlCoordsText = $line;
            }
            if (false !== strpos($line, "var centroLatLgn =")) {
                $htmlStopsCoords[] = $line;
            }
            if (false !== strpos($line, "{'linea': ".$routeId." , 'itinerario': ".$itineraryId." , 'parada':")) {
                $htmlStopsIds[] = $line;
            }
            if (false !== strpos($line, "$('#capa_mapa_info_") && false === strpos($line, "ERROR")) {
                $htmlStopsNames[] = $line;
            }
            if (false !== strpos($line, "{ 'idbus':")) {
                $htmlBusIds[] = $line;
            }
        }

        $htmlBusCoords = array_slice($htmlStopsCoords, count($htmlStopsIds));
        $htmlStopsCoords = array_slice($htmlStopsCoords, 0, count($htmlStopsIds));

        // Parse route itinerary path coords
        preg_match_all('/LatLng\((.*?)\)/i', $htmlCoordsText, $htmlCoords);
        $htmlCoords = $htmlCoords[1];
        foreach ($htmlCoords as $htmlCoord) {
            $coords[] = explode(',', $htmlCoord);
        }

        // Parse stops
        foreach ($htmlStopsIds as $key => $htmlStopsId) {
            preg_match("/{'linea': ".$routeId." , 'itinerario': ".$itineraryId." , 'parada': (.*?)}/i", $htmlStopsId, $matchId);
            $stopId = trim($matchId[1]);
            preg_match('/LatLng\((.*?)\)/i', $htmlStopsCoords[$key], $matchStop);
            $stopCoords = trim($matchStop[1]);
            preg_match('/html\(\'(.*?)\'/i', $htmlStopsNames[$key], $matchName);
            $stopName = trim($matchName[1]);
            $stopName = html_entity_decode(str_replace('<br>', '', $stopName));

            $stops[$stopId] = [
                'name' => $stopName,
                'coordinates' => explode(',', $stopCoords),
            ];
        }

        // Parse buses
        foreach ($htmlBusIds as $key => $htmlBusId) {
            preg_match("/'idbus':(.*?),/i", $htmlBusId, $matchId);
            $busId = trim($matchId[1]);
            preg_match('/LatLng\((.*?)\)/i', $htmlBusCoords[$key], $matchCoords);
            $busCoords = trim($matchCoords[1]);

            $buses[$busId] = [
                'coordinates' => explode(',', $busCoords),
            ];
        }

        return [
            'path' => $coords,
            'stops' => $stops,
            'buses' => $buses,
        ];
    }
}