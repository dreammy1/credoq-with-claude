/**
 * AUDIT-FEATURE (closes the flagged "no Forms Builder settings UI for
 * addon field types" gap — see AUDIT.md §3.1): populates the Engine's
 * existing-but-previously-unused #cfs-addon-panel-wrap extension point
 * (window.credoqCustomFieldPanels / window.credoqLoadFieldPanel, wired in
 * credoq-engine's Admin/Forms_Page.php) for the seat_map field type.
 *
 * Everything here is additive to what auto-resolution already does
 * (Seat_Map_Field::on_submission() / FormField.jsx's SeatMapField still
 * work with zero settings configured, exactly as before) — this panel is
 * purely for the cases an admin wants to be explicit rather than rely on
 * "the plan is connected to exactly one event" being true.
 */
(function () {
	if (typeof window === 'undefined') return;

	window.credoqCustomFieldPanels = window.credoqCustomFieldPanels || {};
	window.credoqCustomFieldPanels['seat_map'] =
		'<div class="cfb-panel-card" style="margin-top:14px;">' +
		'  <div class="cfb-panel-header" onclick="cfbTogglePanel(\'csm\')">' +
		'    <div style="display:flex;align-items:center;gap:10px;">' +
		'      <span style="font-size:20px;">' + '\u{1F3DF}' + '</span>' +
		'      <div>' +
		'        <strong style="font-size:14px;">' + (window.credoqSeatsI18n ? window.credoqSeatsI18n.panelTitle : 'Seat Map') + '</strong>' +
		'        <p style="font-size:12px;color:#64748b;margin:2px 0 0;">' + (window.credoqSeatsI18n ? window.credoqSeatsI18n.panelSub : '') + '</p>' +
		'      </div>' +
		'    </div>' +
		'  </div>' +
		'  <div id="cfb-csm-body" class="cfb-panel-body">' +
		'    <div>' +
		'      <label class="cfs-label">' + (window.credoqSeatsI18n ? window.credoqSeatsI18n.planLabel : 'Seat plan') + '</label>' +
		'      <select id="csm-seat-plan-id"><option value="0">' + (window.credoqSeatsI18n ? window.credoqSeatsI18n.autoOption : 'Auto-detect') + '</option></select>' +
		'      <p class="description" style="font-size:11.5px;color:#8b96ad;margin-top:4px;">' + (window.credoqSeatsI18n ? window.credoqSeatsI18n.planHint : '') + '</p>' +
		'    </div>' +
		'    <div style="margin-top:14px;">' +
		'      <label class="cfs-label">' + (window.credoqSeatsI18n ? window.credoqSeatsI18n.eventLabel : 'Pin to event') + '</label>' +
		'      <select id="csm-event-id"><option value="0">' + (window.credoqSeatsI18n ? window.credoqSeatsI18n.autoOption : 'Auto-detect') + '</option></select>' +
		'      <p class="description" style="font-size:11.5px;color:#8b96ad;margin-top:4px;">' + (window.credoqSeatsI18n ? window.credoqSeatsI18n.eventHint : '') + '</p>' +
		'    </div>' +
		'  </div>' +
		'</div>';

	// Compose with any addon that registered a loader before us, so two
	// addons' panels can coexist instead of one silently clobbering the
	// other's callback.
	var previousLoader = window.credoqLoadFieldPanel;
	window.credoqLoadFieldPanel = function (type, field) {
		if (typeof previousLoader === 'function') previousLoader(type, field);
		if (type !== 'seat_map') return;

		var planSel  = document.getElementById('csm-seat-plan-id');
		var eventSel = document.getElementById('csm-event-id');
		if (!planSel || !eventSel) return;

		var opts = (window.credoqSeatsBuilderOptions || { plans: [], events: [] });

		function fill(select, list, current) {
			// Keep the existing "Auto-detect" option 0, append the rest.
			while (select.options.length > 1) select.remove(1);
			list.forEach(function (o) {
				var opt = document.createElement('option');
				opt.value = o.value;
				opt.textContent = o.label;
				if (String(current) === String(o.value)) opt.selected = true;
				select.appendChild(opt);
			});
			if (!current) select.value = '0';
		}

		fill(planSel, opts.plans, field.seat_plan_id || 0);
		fill(eventSel, opts.events, field.event_id || 0);

		// Direct-mutate the live field object on change — FIELDS[i] is
		// held by reference in the builder's own scope (same convention
		// as the builder's own cfs-cond-enabled/cfs-enable-wc listeners),
		// so this is picked up automatically whenever the form is saved.
		planSel.onchange = function () { field.seat_plan_id = parseInt(planSel.value, 10) || 0; };
		eventSel.onchange = function () { field.event_id = parseInt(eventSel.value, 10) || 0; };
	};
})();
