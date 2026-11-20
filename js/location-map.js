// Initialize property maps and location pickers once Leaflet is available.
document.querySelectorAll('[data-location-picker], [data-property-map]').forEach((container) => {
    const latitudeInput = container.querySelector('[name="location_latitude"]');
    const longitudeInput = container.querySelector('[name="location_longitude"]');
    const latitude = Number(latitudeInput?.value || container.dataset.latitude);
    const longitude = Number(longitudeInput?.value || container.dataset.longitude);
    const hasLocation = Number.isFinite(latitude) && Number.isFinite(longitude);
    const defaultCenter = [6.3703, 2.3912];
    const map = L.map(container.querySelector('[data-map-canvas]')).setView(
        hasLocation ? [latitude, longitude] : defaultCenter,
        hasLocation ? 15 : 12
    );

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap',
    }).addTo(map);

    let marker = hasLocation ? L.marker([latitude, longitude]).addTo(map) : null;
    const status = container.querySelector('[data-map-status]');

    const setLocation = (point) => {
        const lat = point.lat.toFixed(7);
        const lng = point.lng.toFixed(7);
        if (latitudeInput && longitudeInput) {
            latitudeInput.value = lat;
            longitudeInput.value = lng;
        }
        if (marker) {
            marker.setLatLng(point);
        } else {
            marker = L.marker(point).addTo(map);
        }
        if (status) {
            status.textContent = `Position choisie : ${lat}, ${lng}`;
        }
    };

    if (latitudeInput && longitudeInput && hasLocation && status) {
        status.textContent = `Position choisie : ${latitudeInput.value}, ${longitudeInput.value}`;
    }

    if (latitudeInput && longitudeInput) {
        map.on('click', (event) => setLocation(event.latlng));
        container.querySelector('[data-use-current-location]')?.addEventListener('click', () => {
            if (!navigator.geolocation) {
                status.textContent = 'La géolocalisation n’est pas disponible sur cet appareil.';
                return;
            }
            status.textContent = 'Recherche de votre position…';
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    const point = L.latLng(position.coords.latitude, position.coords.longitude);
                    setLocation(point);
                    map.setView(point, 16);
                },
                () => {
                    status.textContent = 'Position inaccessible. Autorisez la localisation ou choisissez le point sur la carte.';
                },
                { enableHighAccuracy: true, timeout: 10000 }
            );
        });
    }
});
