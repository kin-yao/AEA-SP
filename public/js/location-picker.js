/* Map pin picker. Leaflet and OpenStreetMap tiles load from a CDN when first needed. */
(function () {
    var loading = null;

    function loadLeaflet() {
        if (window.L) { return Promise.resolve(); }
        if (loading) { return loading; }
        loading = new Promise(function (resolve, reject) {
            var css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css';
            document.head.appendChild(css);
            var js = document.createElement('script');
            js.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js';
            js.onload = resolve;
            js.onerror = function () { loading = null; reject(new Error('Leaflet failed to load')); };
            document.head.appendChild(js);
        });
        return loading;
    }

    function parse(text) {
        text = (text || '').trim();
        var m;
        if ((m = text.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/))) { return [+m[1], +m[2]]; }
        if ((m = text.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/))) { return [+m[1], +m[2]]; }
        if ((m = text.match(/[?&](?:q|ll|query|destination)=(-?\d+\.?\d*)(?:,|%2C)\s*(-?\d+\.?\d*)/i))) { return [+m[1], +m[2]]; }
        if ((m = text.match(/^(-?\d+\.?\d*)\s*[,\s]\s*(-?\d+\.?\d*)$/))) { return [+m[1], +m[2]]; }
        return null;
    }

    document.addEventListener('alpine:init', function () {
        Alpine.data('locationPicker', function (cfg) {
            return {
                lat: null, lng: null, map: null, marker: null, q: '', msg: '', busy: false,

                init: function () {
                    var l = parseFloat(this.$wire.get(cfg.lat));
                    var g = parseFloat(this.$wire.get(cfg.lng));
                    if (!isNaN(l) && !isNaN(g)) { this.lat = l; this.lng = g; }
                    var self = this;
                    loadLeaflet().then(function () { self.draw(); }).catch(function () {
                        self.msg = 'The map could not load. Paste a Google Maps link instead.';
                    });
                },

                draw: function () {
                    var self = this, has = this.lat !== null;
                    this.map = L.map(this.$refs.map, { scrollWheelZoom: false }).setView(has ? [this.lat, this.lng] : [-1.2921, 36.8219], has ? 16 : 6);
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(this.map);
                    this.map.on('click', function (e) { self.set(e.latlng.lat, e.latlng.lng, false); });
                    if (has) { this.place(); }
                },

                place: function () {
                    var self = this;
                    if (!this.map) { return; }
                    if (!this.marker) {
                        this.marker = L.marker([this.lat, this.lng], { draggable: true }).addTo(this.map);
                        this.marker.on('dragend', function () {
                            var p = self.marker.getLatLng();
                            self.set(p.lat, p.lng, false);
                        });
                    } else {
                        this.marker.setLatLng([this.lat, this.lng]);
                    }
                },

                set: function (lat, lng, zoom) {
                    this.lat = Math.round(lat * 1e7) / 1e7;
                    this.lng = Math.round(lng * 1e7) / 1e7;
                    this.$wire.set(cfg.lat, String(this.lat), false);
                    this.$wire.set(cfg.lng, String(this.lng), false);
                    this.msg = '';
                    this.place();
                    if (zoom && this.map) { this.map.setView([this.lat, this.lng], 16); }
                },

                clear: function () {
                    this.lat = null; this.lng = null;
                    this.$wire.set(cfg.lat, '', false);
                    this.$wire.set(cfg.lng, '', false);
                    if (this.marker && this.map) { this.map.removeLayer(this.marker); this.marker = null; }
                },

                find: function () {
                    var self = this, text = this.q.trim();
                    if (!text) { return; }
                    var p = parse(text);
                    if (p) { this.set(p[0], p[1], true); return; }
                    if (/^https?:\/\//i.test(text)) {
                        this.msg = 'Short links cannot be read. Open it, then paste the full address from the browser.';
                        return;
                    }
                    this.busy = true; this.msg = '';
                    fetch('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' + encodeURIComponent(text), { headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (rows) {
                            if (rows && rows.length) { self.set(parseFloat(rows[0].lat), parseFloat(rows[0].lon), true); }
                            else { self.msg = 'Nothing found. Tap the map instead.'; }
                        })
                        .catch(function () { self.msg = 'Search is unavailable. Tap the map instead.'; })
                        .finally(function () { self.busy = false; });
                },

                locate: function () {
                    var self = this;
                    if (!navigator.geolocation) { this.msg = 'This device cannot share its location.'; return; }
                    this.busy = true; this.msg = '';
                    navigator.geolocation.getCurrentPosition(function (pos) {
                        self.busy = false;
                        self.set(pos.coords.latitude, pos.coords.longitude, true);
                    }, function () {
                        self.busy = false;
                        self.msg = 'Location is blocked. Allow it in the browser, or tap the map.';
                    }, { enableHighAccuracy: true, timeout: 15000 });
                },

                link: function () {
                    return this.lat === null ? '#' : 'https://www.google.com/maps/search/?api=1&query=' + this.lat + ',' + this.lng;
                }
            };
        });
    });
})();
