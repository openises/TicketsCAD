/**
 * Event Map Overlays — admin page logic (Phase 110 / GH #43).
 *
 * Drives map-overlays.php: upload form, overlay table (rename / enable /
 * delete), and the positioning editor — a Leaflet map with three corner
 * drag handles (TL blue / TR blue / BL blue) plus a centroid MOVE handle
 * (yellow). Dragging a corner reshapes the image via
 * MapImageOverlays layer.setAnchors(); dragging MOVE translates all
 * three anchors together. Save POSTs anchors + opacity to
 * api/map-image-overlays.php.
 *
 * Depends on: leaflet, map-prefs.js, map-defaults.js, map-image-overlays.js
 */
(function () {
    'use strict';

    var API = 'api/map-image-overlays.php';

    var csrfEl = document.getElementById('csrfToken');
    var CSRF = csrfEl ? csrfEl.value : '';

    var alertSlot   = document.getElementById('overlayAlertSlot');
    var tableBody   = document.getElementById('overlayTableBody');
    var uploadForm  = document.getElementById('overlayUploadForm');
    var uploadBtn   = document.getElementById('overlayUploadBtn');
    var editorCard  = document.getElementById('overlayEditorCard');
    var editorName  = document.getElementById('overlayEditorName');
    var opacityIn   = document.getElementById('overlayOpacity');
    var opacityVal  = document.getElementById('overlayOpacityVal');
    var saveBtn     = document.getElementById('overlayEditorSave');
    var cancelBtn   = document.getElementById('overlayEditorCancel');

    // ── Editor state ──────────────────────────────────────────────
    var editorMap = null;          // lazily created L.Map
    var editorReady = null;        // promise resolving once view is set
    var editing = null;            // { overlay, layer, anchors, handles }

    // ── Small helpers ─────────────────────────────────────────────

    function showAlert(kind, msg) {
        if (!alertSlot) return;
        var div = document.createElement('div');
        div.className = 'alert alert-' + kind + ' alert-dismissible fade show py-2';
        div.setAttribute('role', 'alert');
        var span = document.createElement('span');
        span.textContent = msg;
        div.appendChild(span);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-close';
        btn.setAttribute('data-bs-dismiss', 'alert');
        btn.setAttribute('aria-label', 'Close');
        div.appendChild(btn);
        alertSlot.innerHTML = '';
        alertSlot.appendChild(div);
        if (kind === 'success') {
            setTimeout(function () {
                if (div.parentNode) div.parentNode.removeChild(div);
            }, 4000);
        }
    }

    /** POST a JSON action to the API. cb(err, data). */
    function apiPost(payload, cb) {
        payload.csrf_token = CSRF;
        fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.error) { cb(data.error, null); return; }
                cb(null, data);
            })
            .catch(function () { cb('Network error', null); });
    }

    // ── Overlay table ─────────────────────────────────────────────

    function loadOverlays() {
        fetch(API, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.error) {
                    showAlert('danger', data.error);
                    renderTable([]);
                    return;
                }
                renderTable((data && data.overlays) || []);
            })
            .catch(function () {
                showAlert('danger', 'Failed to load overlays');
                renderTable([]);
            });
    }

    function renderTable(list) {
        tableBody.innerHTML = '';
        if (!list.length) {
            var tr0 = document.createElement('tr');
            var td0 = document.createElement('td');
            td0.colSpan = 6;
            td0.className = 'text-center text-body-secondary py-3';
            td0.textContent = 'No overlays yet — upload an event map above.';
            tr0.appendChild(td0);
            tableBody.appendChild(tr0);
            return;
        }
        list.forEach(function (ov) {
            var tr = document.createElement('tr');

            // Thumbnail
            var tdThumb = document.createElement('td');
            var img = document.createElement('img');
            img.className = 'overlay-thumb rounded border';
            img.src = ov.url;
            img.alt = '';
            tdThumb.appendChild(img);
            tr.appendChild(tdThumb);

            // Name
            var tdName = document.createElement('td');
            tdName.textContent = ov.name;
            tr.appendChild(tdName);

            // Opacity
            var tdOp = document.createElement('td');
            tdOp.textContent = (Math.round(ov.opacity * 100)) + '%';
            tr.appendChild(tdOp);

            // Positioned?
            var tdPos = document.createElement('td');
            var badge = document.createElement('span');
            var positioned = !!(ov.anchors && ov.anchors.tl && ov.anchors.tr && ov.anchors.bl);
            badge.className = 'badge ' + (positioned ? 'text-bg-success' : 'text-bg-warning');
            badge.textContent = positioned ? 'Yes' : 'Not yet';
            tdPos.appendChild(badge);
            tr.appendChild(tdPos);

            // Enabled toggle
            var tdEn = document.createElement('td');
            var switchWrap = document.createElement('div');
            switchWrap.className = 'form-check form-switch mb-0';
            var chk = document.createElement('input');
            chk.className = 'form-check-input';
            chk.type = 'checkbox';
            chk.checked = parseInt(ov.enabled, 10) === 1;
            chk.setAttribute('aria-label', 'Enabled');
            chk.addEventListener('change', function () {
                apiPost({ action: 'update', id: ov.id, enabled: chk.checked ? 1 : 0 }, function (err) {
                    if (err) {
                        showAlert('danger', err);
                        chk.checked = !chk.checked;
                    }
                });
            });
            switchWrap.appendChild(chk);
            tdEn.appendChild(switchWrap);
            tr.appendChild(tdEn);

            // Actions
            var tdAct = document.createElement('td');
            tdAct.className = 'text-end';
            var group = document.createElement('div');
            group.className = 'btn-group btn-group-sm';

            var btnPos = document.createElement('button');
            btnPos.type = 'button';
            btnPos.className = 'btn btn-outline-primary';
            btnPos.innerHTML = '<i class="bi bi-arrows-move me-1"></i>Position';
            btnPos.addEventListener('click', function () { openEditor(ov); });
            group.appendChild(btnPos);

            var btnRen = document.createElement('button');
            btnRen.type = 'button';
            btnRen.className = 'btn btn-outline-secondary';
            btnRen.textContent = 'Rename';
            btnRen.addEventListener('click', function () {
                var newName = window.prompt('New name for this overlay:', ov.name);
                if (newName === null) return;
                newName = newName.replace(/^\s+|\s+$/g, '');
                if (!newName) { showAlert('warning', 'Name cannot be empty'); return; }
                apiPost({ action: 'update', id: ov.id, name: newName }, function (err) {
                    if (err) { showAlert('danger', err); return; }
                    loadOverlays();
                });
            });
            group.appendChild(btnRen);

            var btnDel = document.createElement('button');
            btnDel.type = 'button';
            btnDel.className = 'btn btn-outline-danger';
            btnDel.textContent = 'Delete';
            btnDel.addEventListener('click', function () {
                if (!window.confirm('Delete overlay "' + ov.name + '"? The image file is removed too.')) return;
                apiPost({ action: 'delete', id: ov.id }, function (err) {
                    if (err) { showAlert('danger', err); return; }
                    if (editing && editing.overlay.id === ov.id) closeEditor();
                    showAlert('success', 'Overlay deleted');
                    loadOverlays();
                });
            });
            group.appendChild(btnDel);

            tdAct.appendChild(group);
            tr.appendChild(tdAct);
            tableBody.appendChild(tr);
        });
    }

    // ── Upload ────────────────────────────────────────────────────

    if (uploadForm) {
        uploadForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var fileIn = document.getElementById('overlayFile');
            var nameIn = document.getElementById('overlayName');
            if (!fileIn.files || !fileIn.files.length) {
                showAlert('warning', 'Choose a JPG, PNG or PDF file first');
                return;
            }
            var fd = new FormData();
            fd.append('action', 'create');
            fd.append('csrf_token', CSRF);
            fd.append('name', nameIn.value);
            fd.append('file', fileIn.files[0]);

            uploadBtn.disabled = true;
            fetch(API, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    uploadBtn.disabled = false;
                    if (data && data.error) { showAlert('danger', data.error); return; }
                    uploadForm.reset();
                    showAlert('success', 'Overlay uploaded — click Position to place it on the map.');
                    loadOverlays();
                })
                .catch(function () {
                    uploadBtn.disabled = false;
                    showAlert('danger', 'Upload failed — network error');
                });
        });
    }

    // ── Positioning editor ────────────────────────────────────────

    /** Lazily create the editor map; resolves once the view is set. */
    function ensureEditorMap() {
        if (editorReady) return editorReady;
        editorReady = new Promise(function (resolve) {
            editorMap = L.map('overlayEditorMap');
            var base = MapPrefs.addDefaultBasemap(editorMap);
            MapPrefs.addLayerControl(editorMap, { currentBase: base, includeWeather: false });
            MapDefaults.load().then(function (d) {
                editorMap.setView([d.lat, d.lng], d.zoom);
                resolve(editorMap);
            });
        });
        return editorReady;
    }

    /** Load the overlay image to read its natural dimensions. */
    function loadImageSize(url, cb) {
        var probe = new Image();
        probe.onload = function () { cb(probe.naturalWidth || 1, probe.naturalHeight || 1); };
        probe.onerror = function () { cb(1, 1); };
        probe.src = url;
    }

    /**
     * Default anchors for an unpositioned overlay: centered on the map
     * center, axis-aligned, spanning ~40% of the viewport width, aspect
     * from the image's natural size (clamped to 80% of viewport height).
     */
    function defaultAnchors(map, natW, natH) {
        var size = map.getSize();
        var cp = map.latLngToContainerPoint(map.getCenter());
        var tW = size.x * 0.4;
        var tH = tW * (natH / natW);
        if (tH > size.y * 0.8) {
            tH = size.y * 0.8;
            tW = tH * (natW / natH);
        }
        function toLL(x, y) {
            var ll = map.containerPointToLatLng(L.point(x, y));
            return { lat: ll.lat, lng: ll.lng };
        }
        return {
            tl: toLL(cp.x - tW / 2, cp.y - tH / 2),
            tr: toLL(cp.x + tW / 2, cp.y - tH / 2),
            bl: toLL(cp.x - tW / 2, cp.y + tH / 2)
        };
    }

    function handleIcon(extraClass) {
        return L.divIcon({
            className: '',
            html: '<div class="overlay-handle ' + (extraClass || '') + '"></div>',
            iconSize: [16, 16],
            iconAnchor: [8, 8]
        });
    }

    /** Centroid of the image parallelogram = midpoint of TR and BL. */
    function centroidLatLng(map, anchors) {
        var pTr = map.latLngToLayerPoint(L.latLng(anchors.tr.lat, anchors.tr.lng));
        var pBl = map.latLngToLayerPoint(L.latLng(anchors.bl.lat, anchors.bl.lng));
        return map.layerPointToLatLng(L.point((pTr.x + pBl.x) / 2, (pTr.y + pBl.y) / 2));
    }

    function openEditor(ov) {
        closeEditor(); // one overlay at a time

        editorCard.classList.remove('d-none');
        editorName.textContent = ov.name;
        opacityIn.value = String(ov.opacity || 0.7);
        opacityVal.textContent = Number(ov.opacity || 0.7).toFixed(2);

        ensureEditorMap().then(function (map) {
            map.invalidateSize();

            loadImageSize(ov.url, function (natW, natH) {
                var anchors;
                if (ov.anchors && ov.anchors.tl && ov.anchors.tr && ov.anchors.bl) {
                    // Deep copy so Cancel doesn't mutate the cached row.
                    anchors = {
                        tl: { lat: ov.anchors.tl.lat, lng: ov.anchors.tl.lng },
                        tr: { lat: ov.anchors.tr.lat, lng: ov.anchors.tr.lng },
                        bl: { lat: ov.anchors.bl.lat, lng: ov.anchors.bl.lng }
                    };
                    // Bring the artwork into view.
                    map.fitBounds(L.latLngBounds([
                        [anchors.tl.lat, anchors.tl.lng],
                        [anchors.tr.lat, anchors.tr.lng],
                        [anchors.bl.lat, anchors.bl.lng]
                    ]).pad(0.3));
                } else {
                    anchors = defaultAnchors(map, natW, natH);
                }

                var layer = MapImageOverlays.makeLayer(ov.url, anchors, {
                    opacity: parseFloat(opacityIn.value) || 0.7
                });
                layer.addTo(map);

                editing = { overlay: ov, layer: layer, anchors: anchors, handles: {} };

                // Corner handles: TL / TR / BL
                ['tl', 'tr', 'bl'].forEach(function (key) {
                    var m = L.marker([anchors[key].lat, anchors[key].lng], {
                        draggable: true,
                        icon: handleIcon(''),
                        zIndexOffset: 1000,
                        title: key.toUpperCase() + ' corner'
                    }).addTo(map);
                    m.on('drag', function () {
                        var ll = m.getLatLng();
                        editing.anchors[key] = { lat: ll.lat, lng: ll.lng };
                        editing.layer.setAnchors(editing.anchors);
                        repositionMoveHandle();
                    });
                    editing.handles[key] = m;
                });

                // MOVE handle at the centroid: translates all three anchors.
                var moveMarker = L.marker(centroidLatLng(map, anchors), {
                    draggable: true,
                    icon: handleIcon('overlay-handle-move'),
                    zIndexOffset: 1100,
                    title: 'Move overlay'
                }).addTo(map);
                var dragOrigin = null;   // latlng at dragstart
                var anchorsAtStart = null;
                moveMarker.on('dragstart', function () {
                    dragOrigin = moveMarker.getLatLng();
                    anchorsAtStart = {
                        tl: { lat: editing.anchors.tl.lat, lng: editing.anchors.tl.lng },
                        tr: { lat: editing.anchors.tr.lat, lng: editing.anchors.tr.lng },
                        bl: { lat: editing.anchors.bl.lat, lng: editing.anchors.bl.lng }
                    };
                });
                moveMarker.on('drag', function () {
                    if (!dragOrigin || !anchorsAtStart) return;
                    var cur = moveMarker.getLatLng();
                    var dLat = cur.lat - dragOrigin.lat;
                    var dLng = cur.lng - dragOrigin.lng;
                    ['tl', 'tr', 'bl'].forEach(function (key) {
                        editing.anchors[key] = {
                            lat: anchorsAtStart[key].lat + dLat,
                            lng: anchorsAtStart[key].lng + dLng
                        };
                        editing.handles[key].setLatLng([
                            editing.anchors[key].lat,
                            editing.anchors[key].lng
                        ]);
                    });
                    editing.layer.setAnchors(editing.anchors);
                });
                editing.handles.move = moveMarker;

                function repositionMoveHandle() {
                    moveMarker.setLatLng(centroidLatLng(map, editing.anchors));
                }
            });

            // Scroll the editor into view for long overlay lists.
            editorCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    function closeEditor() {
        if (editing) {
            if (editorMap) {
                if (editing.layer) editorMap.removeLayer(editing.layer);
                ['tl', 'tr', 'bl', 'move'].forEach(function (k) {
                    if (editing.handles[k]) editorMap.removeLayer(editing.handles[k]);
                });
            }
            editing = null;
        }
        editorCard.classList.add('d-none');
    }

    // Opacity slider live-updates the layer.
    if (opacityIn) {
        opacityIn.addEventListener('input', function () {
            var v = parseFloat(opacityIn.value) || 0.7;
            opacityVal.textContent = v.toFixed(2);
            if (editing && editing.layer) editing.layer.setOpacity(v);
        });
    }

    // Save anchors + opacity.
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            if (!editing) return;
            saveBtn.disabled = true;
            apiPost({
                action: 'update',
                id: editing.overlay.id,
                anchors: editing.anchors,
                opacity: parseFloat(opacityIn.value) || 0.7
            }, function (err) {
                saveBtn.disabled = false;
                if (err) { showAlert('danger', err); return; }
                showAlert('success', 'Overlay position saved');
                closeEditor();
                loadOverlays();
            });
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeEditor);
    }

    // ── Init ──────────────────────────────────────────────────────
    loadOverlays();
})();
