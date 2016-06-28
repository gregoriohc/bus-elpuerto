<!DOCTYPE html>
<html>
    <head>
        <title>BUS</title>
        <script src="https://code.jquery.com/jquery-3.0.0.min.js" integrity="sha256-JmvOoLtYsmqlsWxa7mDSLMwa6dZ9rrIdtrrVYRnDRH0=" crossorigin="anonymous"></script>
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" integrity="sha384-1q8mTJOASx8j1Au+a5WDVnPi2lkFfwwEAa8hDDdjZlpLegxhjVME1fgjWPGmkzs7" crossorigin="anonymous">
        <style>
            html, body { height: 100%; margin: 0; padding: 0; }
            p { margin: 0; padding: 0; }
            #map { position:absolute; width:100%; top:102px; bottom:64px; }
            #controls { position:absolute; width:100%; height:102px; top:0; }
            #controls select { }
            #info { position:absolute; width:100%; height:64px; bottom:0; font-weight: bold; }
            #info p { margin-top: 6px; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        </style>
    </head>
    <body>
        <div id="controls">
            <select id="routes" class="form-control"></select>
            <select id="itineraries" class="form-control"></select>
            <select id="stops" class="form-control"></select>
        </div>
        <div id="info" class="bg-success">
            <p id="closestStop"></p>
            <p id="nextBus"></p>
        </div>
        <div id="map"></div>
        <script>
            var map;
            var bounds;
            var routePath;
            var routeStops;
            var routeBuses;
            var buses = [];
            var userMarker;
            var path;
            var markersStops = [];
            var markersBuses = [];
            var userClosestStopId = 0;
            var userClosestStopMarker;
            var userClosestStopDistance = 999999;
            var directionsDisplay;
            var directionsService;

            function initMap() {
                map = new google.maps.Map(document.getElementById("map"), {
                    center: new google.maps.LatLng(40.5472,12.282715),
                    zoom: 6,
                    mapTypeId: google.maps.MapTypeId.ROADMAP
                });

                path = new google.maps.Polyline();

                directionsDisplay = new google.maps.DirectionsRenderer();
                directionsService = new google.maps.DirectionsService();
                directionsDisplay.setMap(map);

                google.maps.event.addDomListener(window, "resize", function() {
                    var center = map.getCenter();
                    google.maps.event.trigger(map, "resize");
                    map.setCenter(center);
                });

                bounds = new google.maps.LatLngBounds();

                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(function(position) {
                        userMarker = new google.maps.Marker({
                            position: new google.maps.LatLng(position.coords.latitude, position.coords.longitude),
                            map: map,
                            title: 'Tu posición',
                            icon: '//maps.gstatic.com/intl/en_us/mapfiles/markers2/measle.png',
                        });
                        map.setCenter(userMarker.getPosition());
                    }, function() {
                        handleLocationError(true);
                    });
                } else {
                    // Browser doesn't support Geolocation
                    handleLocationError(false);
                }
            }

            function addStop(id, data) {
                var marker = new google.maps.Marker({
                    position: new google.maps.LatLng(data.coordinates[0], data.coordinates[1]),
                    map: map,
                    title: data.name,
                    icon: '//maps.gstatic.com/intl/en_us/mapfiles/markers2/measle_blue.png',
                });
                markersStops.push(marker);
                bounds.extend(marker.getPosition());

                if (undefined != userMarker) {
                    var d = distance(userMarker.getPosition(), marker.getPosition());
                    if (d < userClosestStopDistance) {
                        userClosestStopDistance = d;
                        userClosestStopId = id;
                        userClosestStopMarker = marker;
                    }
                }
            }

            function addPath(data) {
                var coordinates = [];

                for (var key in data) {
                    coordinates.push(new google.maps.LatLng(data[key][0], data[key][1]));
                }

                path = new google.maps.Polyline({
                    path: coordinates,
                    strokeColor: '#0000ff',
                    strokeOpacity: 0.7,
                    strokeWeight: 2
                });

                path.setMap(map);
            }

            function addBus(id, data) {
                var marker = new google.maps.Marker({
                    position: new google.maps.LatLng(data.coordinates[0], data.coordinates[1]),
                    map: map,
                    title: 'Bus',
                    icon: '//maps.google.com/mapfiles/ms/icons/bus.png',
                });
                buses.push({id: id, marker: marker});
                markersBuses.push(marker);
            }

            function handleLocationError(browserHasGeolocation) {
                //infoWindow.setPosition(pos);
                //infoWindow.setContent(browserHasGeolocation ?
                //        'Error: The Geolocation service failed.' :
                //        'Error: Your browser doesn\'t support geolocation.');
            }

            function getRoutes() {
                $.get("/data", { what: "routes" })
                        .done(function(data) {
                            var routesSelect = $('select#routes');
                            routesSelect.empty();
                            $.each(data, function(key, value) {
                                routesSelect.append('<option value=' + key + '>' + value.code + ' - ' + value.name + '</option>');
                            });
                            $("select#routes").val($("select#routes option:first").val()).trigger('change');
                        });
            }

            function getItineraries(route) {
                $.get("/data", { what: "itineraries", route: route })
                        .done(function(data) {
                            var routesSelect = $('select#itineraries');
                            routesSelect.empty();
                            $.each(data, function(key, value) {
                                routesSelect.append('<option value=' + key + '>' + value.name + '</option>');
                            });
                            $("select#itineraries").val($("select#itineraries option:first").val()).trigger('change');
                        });
            }

            function getStops(route, itinerary) {
                $.get("/data", { what: "stops", route: route, itinerary: itinerary })
                        .done(function(data) {
                            var routesSelect = $('select#stops');
                            routesSelect.empty();
                            $.each(data, function(key, value) {
                                routesSelect.append('<option value=' + key + '>' + value.name + '</option>');
                            });
                        });
            }

            function getTime(route, itinerary, stop) {
                var currentTime = new Date();
                var time = currentTime.getHours() + ':' + currentTime.getMinutes();
                $.get("/data", { what: "next_bus", route: route, itinerary: itinerary, stop: stop, time: time })
                        .done(function(data) {
                            var text = '';
                            if (data.type == 'estimated') {
                                text = 'El siguiente bus pasa aprox. en '+data.wait+' minutos';
                            } else if (data.type == 'theoretical') {
                                text = 'El siguiente bus pasa en teoría dentro de '+data.wait+' minutos';
                            } else {
                                text = 'No se sabe cuando pasa el siguiente bus';
                            }
                            $('#nextBus').html(text);
                        });
            }

            function getMap(route, itinerary) {
                $.get("/data", { what: "map", route: route, itinerary: itinerary })
                        .done(function(data) {
                            routePath = data.path;
                            routeStops = data.stops;
                            routeBuses = data.buses;
                            bounds = new google.maps.LatLngBounds();
                            userClosestStopId = 0;
                            userClosestStopMarker = null;
                            userClosestStopDistance = 999999;

                            for (var key in markersStops) {
                                markersStops[key].setMap(null);
                            }
                            markersStops = [];
                            for (var key in routeStops) {
                                addStop(key, routeStops[key]);
                            }

                            for (var key in markersBuses) {
                                markersBuses[key].setMap(null);
                            }
                            markersBuses = [];
                            for (var key in routeBuses) {
                                addBus(key, routeBuses[key]);
                            }

                            path.setMap(null);
                            addPath(routePath);

                            if (userClosestStopId !== 0) {
                                //userClosestStopMarker.setIcon('//storage.googleapis.com/support-kms-prod/SNP_2752129_en_v0');
                                $("select#stops").val(userClosestStopId).trigger('change');

                                bounds = new google.maps.LatLngBounds();
                                bounds.extend(userClosestStopMarker.getPosition());
                                bounds.extend(userMarker.getPosition());

                                directionsService.route({
                                    origin: userMarker.getPosition(),
                                    destination: userClosestStopMarker.getPosition(),
                                    travelMode: google.maps.TravelMode.WALKING
                                }, function(result, status) {
                                    if (status == google.maps.DirectionsStatus.OK) {
                                        directionsDisplay.setDirections(result);
                                        mins = Math.ceil(directionsDisplay.directions.routes[0].legs[0].duration.value/60);
                                        $('#closestStop').html('Parada mas cercana a '+mins+' minutos ('+routeStops[userClosestStopId].name+')');
                                    }
                                });
                            }

                            map.fitBounds(bounds);
                        });
            }

            function rad(x) {
                return x * Math.PI / 180;
            }

            function distance(p1, p2) {
                var R = 6378137; // Earth’s mean radius in meter
                var dLat = rad(p2.lat() - p1.lat());
                var dLong = rad(p2.lng() - p1.lng());
                var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                        Math.cos(rad(p1.lat())) * Math.cos(rad(p2.lat())) *
                        Math.sin(dLong / 2) * Math.sin(dLong / 2);
                var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
                var d = R * c;
                return d; // returns the distance in meter
            }

            $(document).ready(function() {
                $('select#routes').on('change', function() {
                    getItineraries($(this).val());
                });
                $('select#itineraries').on('change', function() {
                    getStops($("select#routes option:selected").val(), $(this).val());
                    getMap($("select#routes option:selected").val(), $(this).val());
                });
                $('select#stops').on('change', function() {
                    getTime($("select#routes option:selected").val(), $("select#itineraries option:selected").val(), $(this).val());
                });

                getRoutes();
            });
        </script>
        <script async defer
                src="//maps.googleapis.com/maps/api/js?key=AIzaSyDOnKZ6mFKo09Ui216_jjBmsSk6sNaZISM&callback=initMap">
        </script>
    </body>
</html>
