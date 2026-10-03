import 'maplibre-gl/dist/maplibre-gl.css';

// maplibre-gl v6 ships named exports only (there is no default export) and is
// ~700 kB, so it is loaded ON DEMAND by the one screen that shows a map: a
// field employee opening the FJP list must not pay for it.
let maplibreModule = null;
let maplibrePromise = null;

function loadMapLibrary() {
    if (maplibreModule !== null) return Promise.resolve(maplibreModule);

    maplibrePromise ??= import('maplibre-gl').then((module) => {
        maplibreModule = module;

        return module;
    });

    return maplibrePromise;
}

/**
 * Customer registration component.
 *
 * Coordinates are captured from the device and are NEVER typed: the fields
 * exist only as hidden inputs bound to captured values. "[Refresh GPS]"
 * re-acquires because a first fix may be inaccurate.
 *
 * The map (MapLibre + CARTO raster tiles) exists so the employee can SEE that
 * the marker is where they physically are. Reverse geocoding only SUGGESTS
 * address text: it never moves the marker, and correcting the text never
 * changes the captured coordinates.
 */
export function customerRegistration(config = {}) {
    const round = (value, digits = 7) => Number(Number(value).toFixed(digits));

    const normalizeWeek = (value) => {
        if (value === null || value === undefined || value === '') return '';

        const numeric = Number(value);

        return Number.isNaN(numeric) ? '' : numeric;
    };

    const normalizeVisits = (visits) => {
        const rows = Array.isArray(visits) && visits.length > 0
            ? visits
            : [{ preferred_week: config.rotationWeek ?? '', preferred_day: config.todayWeekday ?? 1 }];

        return rows.map((row) => ({
            preferred_week: normalizeWeek(row.preferred_week),
            preferred_day: row.preferred_day === undefined || row.preferred_day === null || row.preferred_day === ''
                ? String(config.todayWeekday ?? 1)
                : String(row.preferred_day),
        }));
    };

    return {
        mode: config.mode || 'capture',
        dialCode: config.dialCode || '+234',
        country: config.country || '',

        // map / geocoder configuration
        tileUrl: config.tileUrl,
        attribution: config.attribution,
        zoom: Number(config.zoom || 16),
        reverseUrl: config.reverseUrl,

        // FJP select options
        weekOptions: config.weekOptions || [1, 2, 3, 4],
        weekdayLabels: config.weekdayLabels || {},
        rotationWeek: Number(config.rotationWeek || 1),
        todayWeekday: Number(config.todayWeekday || 0),

        // state
        lat: config.lat ? Number(config.lat) : null,
        lng: config.lng ? Number(config.lng) : null,
        accuracy: config.accuracy ? Number(config.accuracy) : null,
        gpsNote: '',
        gpsError: '',
        suggestionNote: '',
        submitting: false,
        phoneLocal: (config.phoneLocal || '').replace(/\D+/g, ''),

        address: config.address || '',
        city: config.city || '',
        state: config.state || '',
        postalCode: config.postalCode || '',
        autoFilled: {},

        visits: normalizeVisits(config.visits),

        map: null,
        marker: null,
        mapReady: false,

        init() {
            if (this.mode !== 'capture') {
                this.$nextTick(() => this.buildMap());
                return;
            }

            if (this.lat !== null && this.lng !== null) {
                this.$nextTick(() => this.buildMap());
                return;
            }

            this.$nextTick(() => this.buildMap());
            this.acquire(false);
        },

        // ---- GPS ------------------------------------------------------------

        acquire(manual = false) {
            if (!('geolocation' in navigator)) {
                this.gpsError = 'This device does not expose GPS to the browser. Use a phone with location enabled.';
                return;
            }

            this.gpsError = '';
            this.gpsNote = manual ? 'Refreshing GPS…' : 'Acquiring GPS…';

            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const coords = position.coords;

                    this.lat = round(coords.latitude);
                    this.lng = round(coords.longitude);
                    this.accuracy = Math.round(coords.accuracy);
                    this.gpsNote = 'Captured ' + new Date().toLocaleTimeString() + ' — verify the marker.';

                    this.placeMarker(true);
                    this.suggest();
                },
                (error) => {
                    this.gpsNote = '';
                    this.gpsError = error && error.code === error.PERMISSION_DENIED
                        ? 'Location permission is blocked. Allow location access and tap Refresh GPS.'
                        : 'GPS fix failed — move to an open area and tap Refresh GPS.';
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
            );
        },

        hasCoordinates() {
            return this.lat !== null && this.lng !== null;
        },

        coordsLabel() {
            return this.hasCoordinates()
                ? this.lat.toFixed(6) + ', ' + this.lng.toFixed(6)
                : 'Not captured yet';
        },

        accuracyLabel() {
            return this.accuracy === null ? '—' : '±' + this.accuracy + ' m';
        },

        // ---- map ------------------------------------------------------------

        async buildMap() {
            if (this.map !== null || !this.$refs.map) return;

            if (this.tileUrl === undefined || this.tileUrl === null) return;

            const maplibregl = await loadMapLibrary();

            if (this.map !== null) return; // a second call resolved first

            this.map = new maplibregl.Map({
                container: this.$refs.map,
                style: {
                    version: 8,
                    sources: {
                        basemap: {
                            type: 'raster',
                            tiles: [this.tileUrl],
                            tileSize: 256,
                            attribution: this.attribution || '',
                        },
                    },
                    layers: [{ id: 'basemap', type: 'raster', source: 'basemap' }],
                },
                center: this.hasCoordinates() ? [this.lng, this.lat] : [3.3792, 6.5244],
                zoom: this.zoom,
                attributionControl: { compact: true },
            });

            this.map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

            await this.map.once('load');
            this.mapReady = true;

            if (this.hasCoordinates()) this.placeMarker(false);
        },

        placeMarker(fly = false) {
            // buildMap() adds the marker after MapLibre has painted the map.
            if (!this.mapReady || this.map === null || maplibreModule === null || !this.hasCoordinates()) return;

            const maplibregl = maplibreModule;
            const position = [this.lng, this.lat];

            if (this.marker === null) {
                this.marker = new maplibregl.Marker({ color: '#006a64', draggable: false }).setLngLat(position).addTo(this.map);
            } else {
                this.marker.setLngLat(position);
            }

            if (fly) {
                this.map.easeTo({ center: position, zoom: this.zoom, duration: 600 });
            } else {
                this.map.jumpTo({ center: position, zoom: this.zoom });
            }
        },

        // ---- address suggestion ----------------------------------------------

        suggest() {
            if (!this.hasCoordinates() || !this.reverseUrl) return;

            const url = this.reverseUrl + '?latitude=' + this.lat + '&longitude=' + this.lng;

            fetch(url, { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    if (!data || data.ok !== true) {
                        this.suggestionNote = 'Address could not be detected from your position — fill it in manually.';

                        return;
                    }

                    this.fill('address', data.address);
                    this.fill('city', data.city);
                    this.fill('state', data.state);
                    this.fill('postalCode', data.postal_code);

                    this.suggestionNote = data.display_name
                        ? 'Suggested from your position: ' + data.display_name + ' — correct it if it is wrong.'
                        : 'Suggested from your position — correct it if it is wrong.';
                })
                .catch(() => {
                    this.suggestionNote = 'Address lookup is unavailable right now — fill it in manually.';
                });
        },

        /** Only fills an empty or previously auto-filled field (never overwrites a correction). */
        fill(field, value) {
            if (value === undefined || value === null || value === '') return;

            if (this[field] === '' || this.autoFilled[field] === true) {
                this[field] = String(value);
                this.autoFilled[field] = true;
            }
        },

        // ---- preferred visits -------------------------------------------------

        addVisit() {
            if (this.visits.length >= 8) return;

            this.visits.push({ preferred_week: this.rotationWeek, preferred_day: String(this.todayWeekday) });
        },

        removeVisit(index) {
            if (this.visits.length <= 1) return;

            this.visits.splice(index, 1);
        },
    };
}
