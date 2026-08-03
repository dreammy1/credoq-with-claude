import React, { useState, useCallback, useMemo, useRef, useEffect } from 'react';
import { DotLottieReact } from '@lottiefiles/dotlottie-react';
import Calendar from './Calendar.jsx';
import FormField, { SLOT_COLORS } from './FormField.jsx';
import { useAjax } from './useAjax.js';

function fmtDate(ymd) {
  if (!ymd) return '';
  const p = ymd.split('-');
  if (p.length !== 3) return ymd;
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return `${months[parseInt(p[1],10)-1]} ${parseInt(p[2],10)}, ${p[0]}`;
}

function fmtCurrency(currency, amount) {
  if (!amount || amount === 0) return <span className="cqw-pill-free">Free</span>;
  return `${currency} ${parseFloat(amount).toFixed(2)}`;
}

function WidgetBrand({ brand, compact = false }) {
  const name = (brand && brand.name) || 'CredoQ Premium V1';
  const logoUrl = (brand && brand.logo_url) || '';
  const lottieUrl = (brand && brand.lottie_url) || '';

  return (
    <div className={`cqw-widget-brand ${compact ? 'is-compact' : ''}`} title={name}>
      <div className="cqw-widget-brand-mark">
        {logoUrl ? (
          <img src={logoUrl} alt="" />
        ) : lottieUrl ? (
          <DotLottieReact src={lottieUrl} loop autoplay />
        ) : (
          <span>CQ</span>
        )}
      </div>
      {!compact && <div className="cqw-widget-brand-name">{name}</div>}
    </div>
  );
}

export default function BookingWidget({ config }) {
  const cfg      = config || {};
  const brand    = cfg.brand || {};
  const design   = cfg.design || {};
  const primaryBrandName = (brand && brand.name) || document.title.split('â€“')[0]?.trim() || 'Booking';
  const primaryLogoUrl = (brand && brand.logo_url) || '';
  const primaryLottieUrl = (brand && brand.lottie_url) || '';
  const fields   = useMemo(() => Array.isArray(cfg.fields) ? cfg.fields : [], [cfg.fields]);
  const currency = cfg.currency || 'USD';
  const strings  = cfg.strings  || {};
  const appointmentField = useMemo(() => fields.find(f => f.type === 'appointment') || null, [fields]);
  const memberCreditField = useMemo(() => fields.find(f => f.type === 'member_slot_credit') || null, [fields]);
  const presetServiceId = parseInt(appointmentField?.apt_preset_service || cfg.appointment_id || 0, 10) || 0;
  const presetProviderId = parseInt(appointmentField?.apt_preset_provider || 0, 10) || 0;
  const hasPresetService = !!presetServiceId;
  const hasPresetProvider = !!presetProviderId;

  const ajax = useAjax(cfg);
  const getMemberSlotCredits = ajax.getMemberSlotCredits;

  // ── theme ────────────────────────────────────────────────────
  const [theme, setTheme] = useState('dark');
  const toggleTheme = () => setTheme(t => t === 'dark' ? 'light' : 'dark');

  // ── booking state ────────────────────────────────────────────
  const [aptId,     setAptId]     = useState(presetServiceId || cfg.appointment_id || 0);
  const [basePrice, setBasePrice] = useState(cfg.base_price || 0);
  const [aptTitle,  setAptTitle]  = useState(cfg.appointment_title || '');
  const [aptLoc,    setAptLoc]    = useState(cfg.appointment_location || '');
  const [staffId,   setStaffId]   = useState(presetProviderId || 0);
  const [staffName, setStaffName] = useState('');

  // Service/Provider fetching state
  const [providers,     setProviders]     = useState(null);  // null=not loaded
  const [loadingProv,   setLoadingProv]   = useState(false);
  const [providerServices, setProviderServices] = useState(null);
  const [dateServices,     setDateServices]     = useState(null);

  // Date/Time
  const [selectedDate, setSelectedDate] = useState('');
  const [slots,        setSlots]         = useState(null);   // null=not loaded, []=none
  const [loadingSlots, setLoadingSlots]  = useState(false);
  const [selectedTime, setSelectedTime]  = useState('');
  const [datePrice,    setDatePrice]     = useState(null);
  const [isSpecialDate, setIsSpecialDate] = useState(false);

  const currentApt = useMemo(() => {
    const list = Array.isArray(cfg.appointments) ? cfg.appointments : [];
    return list.find(a => parseInt(a.id) === parseInt(aptId)) || {
      id: aptId,
      title: aptTitle || cfg.appointment_title || 'Service',
      location: aptLoc || cfg.appointment_location || '',
      base_price: basePrice,
      allow_multi_booking: cfg.allow_multi_booking || 0,
      multi_price_mode: cfg.multi_price_mode || 'slot',
      multi_day_rate: cfg.multi_day_rate || 0,
      max_schedules: cfg.max_schedules || 0,
      min_schedules: cfg.min_schedules || 1,
      visual_seats_enabled: cfg.visual_seats_enabled || 0,
      slot_interval: cfg.slot_interval || 30,
    };
  }, [cfg, aptId, aptTitle, aptLoc, basePrice]);

  // Multi-booking follows the selected appointment, not only the original shortcode.
  const isMulti  = parseInt(currentApt.allow_multi_booking || 0) === 1;
  const minSched = Math.max(1, parseInt(currentApt.min_schedules || 1));
  const maxSched = parseInt(currentApt.max_schedules || 0);
  const [bookedSlots, setBookedSlots] = useState([]);
  const activeSeatSchedule = useMemo(() => {
    if (!isMulti) {
      return { date: selectedDate, time: selectedTime, staffId };
    }
    const last = bookedSlots.length ? bookedSlots[bookedSlots.length - 1] : null;
    return {
      date: selectedDate || last?.date || '',
      time: selectedTime || last?.time || '',
      staffId: staffId || last?.staff_id || 0,
    };
  }, [isMulti, selectedDate, selectedTime, staffId, bookedSlots]);

  // Event calendar field state
  const [events, setEvents] = useState(null);
  const [loadingEvents, setLoadingEvents] = useState(false);
  const [eventNotice, setEventNotice] = useState(null);
  const [eventViewDate, setEventViewDate] = useState(() => {
    const d = new Date();
    return { year: d.getFullYear(), month: d.getMonth() };
  });
  const [eventModalDate, setEventModalDate] = useState('');
  const [selectedEvent, setSelectedEvent] = useState(null);
  const [eventQty, setEventQty] = useState(1);
  const [eventGuest, setEventGuest] = useState({ name: '', email: '', phone: '' });
  const [memberCreditData, setMemberCreditData] = useState({ credit_required: false, credit_amount: 1, plans: [] });
  const [loadingMemberCredits, setLoadingMemberCredits] = useState(false);

  // Form state (field values)
  const [formState, setFormState] = useState({});
  const [errors,    setErrors]    = useState({});

  // Submission
  const [submitting,   setSubmitting]   = useState(false);
  const [submitResult, setSubmitResult] = useState(null); // null | {success, message, redirect}
  const [notice,       setNotice]       = useState(null); // {type, msg}

  const formRef = useRef(null);
  // AUDIT-FIX (field type audit — file uploads in multi-step forms):
  // holds { [fieldName]: File } so a file picked on an earlier step
  // survives navigation to later steps even after its <input> unmounts.
  // See FormField.jsx's 'file' case and the submit handler below.
  const fileRef = useRef({});
  // reCAPTCHA: v2 widget container + the grecaptcha widget id once rendered.
  const recaptchaContainerRef = useRef(null);
  const recaptchaWidgetIdRef  = useRef(null);
  const [recaptchaReady, setRecaptchaReady] = useState(false);

  // ── reCAPTCHA: load Google's script once, when enabled ──────────
  // v2: loads the plain api.js (renders a visible checkbox widget later,
  //     via the recaptchaContainerRef effect below).
  // v3: loads api.js?render=SITE_KEY (invisible — grecaptcha.execute()
  //     is called per-submission instead of rendering a widget).
  useEffect(() => {
    const rc = cfg.recaptcha;
    if (!rc || !rc.enabled || !rc.site_key) return;
    if (window.grecaptcha) { setRecaptchaReady(true); return; }

    const existing = document.querySelector('script[data-credoq-recaptcha]');
    if (existing) {
      existing.addEventListener('load', () => setRecaptchaReady(true));
      return;
    }

    const script = document.createElement('script');
    script.src = rc.version === 'v3'
      ? `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(rc.site_key)}`
      : 'https://www.google.com/recaptcha/api.js';
    script.async = true;
    script.defer = true;
    script.dataset.credoqRecaptcha = '1';
    script.onload = () => setRecaptchaReady(true);
    document.head.appendChild(script);
  }, [cfg.recaptcha]);

  // ── reCAPTCHA v2: render the visible checkbox widget once both the
  //    script is ready AND its container div is mounted (Review step).
  useEffect(() => {
    const rc = cfg.recaptcha;
    if (!rc || !rc.enabled || rc.version !== 'v2') return;
    if (!recaptchaReady || !window.grecaptcha || !window.grecaptcha.render) return;
    if (!recaptchaContainerRef.current) return;
    if (recaptchaWidgetIdRef.current !== null) return; // already rendered
    try {
      recaptchaWidgetIdRef.current = window.grecaptcha.render(recaptchaContainerRef.current, {
        sitekey: rc.site_key,
      });
    } catch (e) { /* container not ready yet — effect re-runs on next render */ }
  });

  // Resolve a reCAPTCHA token right before submission. v2 reads the
  // checkbox widget's response; v3 executes invisibly per-submission.
  // Returns '' if reCAPTCHA isn't enabled (submission proceeds normally —
  // the backend only enforces the check when it's actually configured).
  const getRecaptchaToken = useCallback(async () => {
    const rc = cfg.recaptcha;
    if (!rc || !rc.enabled || !rc.site_key || !window.grecaptcha) return '';
    if (rc.version === 'v3') {
      try {
        return await window.grecaptcha.execute(rc.site_key, { action: 'submit' });
      } catch {
        return '';
      }
    }
    // v2
    if (recaptchaWidgetIdRef.current !== null && window.grecaptcha.getResponse) {
      return window.grecaptcha.getResponse(recaptchaWidgetIdRef.current) || '';
    }
    return '';
  }, [cfg.recaptcha]);


  // ── Flow mode (from appointment field or config) ──────────────
  const flowMode = useMemo(() => {
    if (appointmentField?.apt_flow_mode) return appointmentField.apt_flow_mode;
    return cfg.apt_flow_mode || 'service_first';
  }, [appointmentField, cfg.apt_flow_mode]);

  const hasAppointmentField = useMemo(() => fields.some(f => f.type === 'appointment'), [fields]);
  const hasProviderPicker   = useMemo(() => fields.some(f => f.type === 'provider_picker'), [fields]);
  const hasEventCalendar    = useMemo(() => fields.some(f => f.type === 'event_calendar'), [fields]);
  const hasMemberCreditField = !!memberCreditField;

  // Steps
  const steps = useMemo(() => buildSteps(fields), [fields, flowMode, hasPresetService, hasPresetProvider]);
  const [currentStep, setCurrentStep] = useState(0);
  const totalSteps = steps.length;

  useEffect(() => {
    if (!aptId) return;
    if (!hasAppointmentField && !hasProviderPicker) return;
    let cancelled = false;
    setLoadingProv(true);
    ajax.getProviders(aptId)
      .then(provs => { if (!cancelled) setProviders(provs); })
      .catch(() => { if (!cancelled) setProviders([]); })
      .finally(() => { if (!cancelled) setLoadingProv(false); });
    return () => { cancelled = true; };
  }, [aptId, hasAppointmentField, hasProviderPicker]);

  useEffect(() => {
    if (!hasMemberCreditField || !aptId) {
      setMemberCreditData({ credit_required: false, credit_amount: 1, plans: [] });
      return;
    }
    let cancelled = false;
    setLoadingMemberCredits(true);
    getMemberSlotCredits(cfg.form_id || 0, aptId)
      .then(data => {
        if (cancelled) return;
        const next = data || { credit_required: false, credit_amount: 1, plans: [] };
        setMemberCreditData(next);
        const fieldName = memberCreditField.name || 'member_slot_credit';
        setFormState(s => {
          const selected = s[fieldName];
          const stillAvailable = (next.plans || []).some(p => String(p.plan_id) === String(selected));
          if (!selected || stillAvailable) return s;
          const copy = { ...s };
          delete copy[fieldName];
          delete copy.credoq_selected_plan_id;
          return copy;
        });
      })
      .catch(() => {
        if (!cancelled) setMemberCreditData({ credit_required: false, credit_amount: 1, plans: [] });
      })
      .finally(() => { if (!cancelled) setLoadingMemberCredits(false); });
    return () => { cancelled = true; };
  }, [hasMemberCreditField, aptId, cfg.form_id, getMemberSlotCredits, memberCreditField]);

  useEffect(() => {
    if (hasPresetService) {
      const apt = (cfg.appointments || []).find(a => parseInt(a.id, 10) === presetServiceId);
      if (apt) {
        setAptId(apt.id);
        setBasePrice(parseFloat(apt.base_price) || 0);
        setAptTitle(apt.title || '');
        setAptLoc(apt.location || '');
      }
    }
    if (hasPresetProvider) {
      const provider = (cfg.providers || []).find(p => parseInt(p.id, 10) === presetProviderId);
      setStaffId(presetProviderId);
      setStaffName(provider?.name || '');
    }
  }, [hasPresetService, hasPresetProvider, presetServiceId, presetProviderId]);

  // FIX: Auto-advance past the provider step when providers loaded as empty array.
  // This covers: (1) service with no staff assigned, (2) staff table is empty.
  // We watch `providers` + `loadingProv` — when loading done and array is empty,
  // advance only if the current step IS the provider step.
  useEffect(() => {
    if (loadingProv) return;
    if (!Array.isArray(providers)) return;
    if (providers.length > 0) return;
    const step = steps[currentStep];
    if (!step || step.type !== 'provider') return;
    // Auto-advance to next step
    setCurrentStep(s => Math.min(s + 1, totalSteps - 1));
  }, [providers, loadingProv, currentStep, steps, totalSteps]);

  useEffect(() => {
    if (!hasEventCalendar || events !== null) return;
    let cancelled = false;
    setLoadingEvents(true);
    ajax.getEvents()
      .then(items => { if (!cancelled) setEvents(items); })
      .catch(() => { if (!cancelled) setEvents([]); })
      .finally(() => { if (!cancelled) setLoadingEvents(false); });
    return () => { cancelled = true; };
  }, [hasEventCalendar, events]);

  // ── Step definitions ────────────────────────────────────────
  // Returns an array of step objects: { label, substeps }
  // Each step has a type: 'appointment', 'fields', 'review', etc.
  function buildSteps(fields) {
    if (!fields.length) return [{ type: 'fields', label: 'Details', fields: [] }];

    // Check if we have step-break fields
    const hasStepBreaks = fields.some(f => f.type === 'step' || f.type === 'page_break');

    // Detect field types for special handling
    const hasAptField  = fields.some(f => f.type === 'appointment');
    const hasDatePick  = fields.some(f => f.type === 'date_picker');
    const hasTimePick  = fields.some(f => f.type === 'time_slot_picker');
    const hasSvcPick   = fields.some(f => f.type === 'service_picker');
    const hasProvPick  = fields.some(f => f.type === 'provider_picker');
    const hasEventCal  = fields.some(f => f.type === 'event_calendar');

    if (hasAptField) {
      const serviceStep = hasPresetService ? null : { type: 'service', label: 'Service', fields: [] };
      const providerStep = hasPresetProvider ? null : { type: 'provider', label: 'Provider', fields: [] };
      const datetimeStep = { type: 'datetime', label: 'Date & Time', fields: [] };
      let flowSteps = [serviceStep, providerStep, datetimeStep];
      if (flowMode === 'provider_first') {
        flowSteps = [providerStep, serviceStep, datetimeStep];
      } else if (flowMode === 'calendar_first') {
        flowSteps = [datetimeStep, serviceStep, providerStep];
      }
      return [
        ...flowSteps.filter(Boolean),
        ...buildFieldSteps(fields),
        { type: 'review', label: 'Review', fields: [] },
      ];
    }

    if (hasSvcPick || hasProvPick || hasDatePick || hasTimePick || hasEventCal) {
      // Standalone pickers — use step-breaks if present, else single page
      if (hasStepBreaks) {
        return [...buildFieldSteps(fields), { type: 'review', label: 'Review', fields: [] }];
      }
      return [
        { type: 'fields', label: 'Booking', fields },
        { type: 'review', label: 'Review',  fields: [] },
      ];
    }

    // Pure field form with step breaks
    if (hasStepBreaks) {
      return [...buildFieldSteps(fields), { type: 'review', label: 'Review', fields: [] }];
    }

    // No steps — single page
    return [
      { type: 'fields', label: 'Booking', fields },
      { type: 'review', label: 'Review',  fields: [] },
    ];
  }

  function buildFieldSteps(fields) {
    const groups = [];
    let current = { type: 'fields', label: 'Details', fields: [] };
    for (const f of fields) {
      if (f.type === 'step' || f.type === 'page_break') {
        if (current.fields.length) groups.push(current);
        current = { type: 'fields', label: f.step_title || 'Details', fields: [] };
      } else if (f.type !== 'appointment') {
        current.fields.push(f);
      }
    }
    if (current.fields.length) groups.push(current);
    return groups;
  }

  // ── Appointment / provider selection ──────────────────────────
  const allApts = useMemo(() => {
    // cfg.all_appointments if available, else single apt
    if (cfg.is_all_mode && Array.isArray(cfg.appointments)) return cfg.appointments;
    if (aptId) return [{ id: aptId, title: aptTitle || cfg.appointment_title || 'Service', base_price: basePrice, location: aptLoc }];
    return [];
  }, [cfg, aptId, aptTitle, basePrice, aptLoc]);

  async function selectService(apt) {
    setAptId(apt.id);
    setBasePrice(parseFloat(apt.base_price) || 0);
    setAptTitle(apt.title || '');
    setAptLoc(apt.location || '');
    if (flowMode !== 'calendar_first') setSelectedDate('');
    setSelectedTime('');
    setSlots(null);
    setProviders(null);
    if (!hasPresetProvider) {
      setStaffId(0);
      setStaffName('');
    }
    setProviderServices(null);
    setNotice(null);

    // Fetch providers if service_first flow
    if (flowMode === 'service_first') {
      setLoadingProv(true);
      try {
        const provs = await ajax.getProviders(apt.id);
        if (provs.length > 0) {
          setProviders(provs);
          setCurrentStep(s => Math.min(s + 1, totalSteps - 1));
        } else {
          setProviders([]);
          const providerStepVisible = steps.some(step => step.type === 'provider');
          setCurrentStep(s => Math.min(s + (providerStepVisible ? 2 : 1), totalSteps - 1));
        }
      } catch(e) {
        setProviders([]);
        const providerStepVisible = steps.some(step => step.type === 'provider');
        setCurrentStep(s => Math.min(s + (providerStepVisible ? 2 : 1), totalSteps - 1));
      } finally {
        setLoadingProv(false);
      }
    } else {
      setCurrentStep(s => Math.min(s + 1, totalSteps - 1));
    }
  }

  function selectProvider(prov) {
    setStaffId(prov.id);
    setStaffName(prov.name || '');
    setSelectedDate('');
    setSelectedTime('');
    setSlots(null);
    setDatePrice(null);
    setIsSpecialDate(false);
    setBookedSlots([]);
    if (flowMode === 'provider_first') {
      setProviderServices(null);
      ajax.getServicesForProvider(prov.id)
        .then(svcs => setProviderServices(svcs))
        .catch(() => setProviderServices([]));
    }
    setCurrentStep(s => Math.min(s + 1, totalSteps - 1));
  }

  // ── Date selection ────────────────────────────────────────────
  async function selectDate(date) {
    setSelectedDate(date);
    setSelectedTime('');
    setSlots(null);
    if (!aptId) {
      if (flowMode === 'calendar_first') {
        setDateServices(null);
        ajax.getServicesForDate(date)
          .then(svcs => setDateServices(svcs))
          .catch(() => setDateServices([]));
      }
      return;
    }
    setLoadingSlots(true);
    try {
      const data = await ajax.getTimeslots(aptId, date, staffId);
      if (data.success) {
        if (typeof data.data.date_price !== 'undefined') {
          setDatePrice(parseFloat(data.data.date_price));
        }
        setIsSpecialDate(!!data.data.is_special);
        setSlots(data.data.slots || []);
      } else {
        setSlots([]);
      }
    } catch(e) {
      setSlots([]);
    } finally {
      setLoadingSlots(false);
    }
  }

  // ── Time selection ────────────────────────────────────────────
  function selectTime(slot, options = {}) {
    if (isMulti) {
      addSlotToCart(slot, options);
    } else {
      setSelectedTime(slot.time);
    }
  }

  // ── Multi-booking cart ────────────────────────────────────────
  function computeSlotPrice() {
    const price = datePrice !== null ? datePrice : basePrice;
    if (currentApt.multi_price_mode === 'day' && currentApt.multi_day_rate > 0) {
      return parseFloat(currentApt.multi_day_rate);
    }
    return price;
  }

  function addSlotToCart(slot, options = {}) {
    if (maxSched > 0 && bookedSlots.length >= maxSched) {
      setNotice({ type: 'warn', msg: strings.multi_max_reached || 'Maximum selections reached' });
      return;
    }
    const already = bookedSlots.some(s => s.date === selectedDate && s.time === slot.time);
    if (already) return;
    const price = computeSlotPrice();
    const svcLabel = aptTitle ? `${aptTitle} — ` : '';
    setBookedSlots(prev => [...prev, {
      date: selectedDate,
      time: slot.time,
      price,
      label: `${svcLabel}${fmtDate(selectedDate)} · ${slot.time}`,
      appointment_id: aptId,
      staff_id: staffId,
    }]);
    if (!options.keepSelection) {
      setSelectedDate('');
      setSelectedTime('');
      setSlots(null);
    }
    setNotice(null);
  }

  function removeFromCart(idx) {
    setBookedSlots(prev => prev.filter((_,i) => i !== idx));
  }

  // ── Standalone WC checkout addon total (3-setting architecture) ─
  // Sums the "price" contribution of any field that has both
  // "Enable WC Checkout" and "Option value as price → add to WC grand
  // total" turned on. This mirrors the Engine's server-side
  // wc_contribution() so the displayed total matches what the backend
  // will add to the WooCommerce cart item.
  const wcAddonTotal = useMemo(() => {
    let total = 0;
    fields.forEach(field => {
      if (!field.enable_wc || !field.wc_option_price) return;
      const val = formState[field.name];
      if (field.type === 'checkbox') {
        (Array.isArray(val) ? val : []).forEach(v => {
          const n = parseFloat(v);
          if (!isNaN(n)) total += n;
        });
      } else if (field.type === 'select' || field.type === 'radio') {
        const n = parseFloat(val);
        if (!isNaN(n)) total += n;
      } else if (field.type === 'calculate') {
        const calcResult = formState[`__calc_${field.name}`];
        const n = parseFloat(calcResult);
        if (!isNaN(n)) total += n;
      }
    });
    return total;
  }, [fields, formState]);

  // ── Addon price from custom form fields (Bugs 2 & 3) ────────────
  // Iterates all fields and sums price contributions from:
  //   - checkbox  : each selected option's price (if option.price is set)
  //   - select/radio: selected option's price
  //   - calculate : result when field.add_to_total is truthy
  const fieldAddonTotal = useMemo(() => {
    let total = 0;
    fields.forEach(field => {
      const val = formState[field.name];
      if (field.type === 'checkbox' && Array.isArray(field.options)) {
        const selected = Array.isArray(val) ? val : [];
        field.options.forEach(opt => {
          const optKey = String(opt.key ?? opt.value ?? opt.label ?? '');
          if (selected.includes(optKey) && opt.price) {
            total += parseFloat(opt.price) || 0;
          }
        });
      } else if ((field.type === 'select' || field.type === 'radio') && Array.isArray(field.options)) {
        const selected = String(val ?? '');
        field.options.forEach(opt => {
          const optKey = String(opt.key ?? opt.value ?? opt.label ?? '');
          if (optKey === selected && opt.price) {
            total += parseFloat(opt.price) || 0;
          }
        });
      } else if (field.type === 'calculate' && field.add_to_total) {
        // calculate fields store their result in formState under __calc_<name>
        const calcResult = formState[`__calc_${field.name}`];
        if (calcResult !== undefined && !isNaN(parseFloat(calcResult))) {
          total += parseFloat(calcResult);
        }
      }
    });
    return total;
  }, [fields, formState]);

  // ── Seat map price total (Bug fix) ──────────────────────────────
  // A seat map's price REPLACES the flat per-slot base price rather
  // than adding to it — each seat's own price already defaults to the
  // service's base price when it has no override, so treating it as
  // an addon would double-count the base price. Kept as its own value
  // (not folded into fieldAddonTotal) for exactly that reason.
  const seatPriceTotal = useMemo(() => {
    let total = 0;
    let hasSelection = false;
    fields.forEach(field => {
      if (field.type !== 'seat_map') return;
      const t = formState[`__seat_total_${field.name}`];
      if (t !== undefined && !isNaN(parseFloat(t))) {
        total += parseFloat(t);
        const fv = formState[field.name];
        if (fv && fv.count > 0) hasSelection = true;
      }
    });
    return { total, hasSelection };
  }, [fields, formState]);

  // ── Grand total ───────────────────────────────────────────────
  const grandTotal = useMemo(() => {
    if (isMulti) {
      // Same rule as the single-slot branch below: when seats are
      // selected, their total REPLACES each slot's flat price rather than
      // adding to it. This form only has one seat_map field regardless of
      // how many dates were picked, so the seat total is charged once —
      // it isn't multiplied per extra date (picking several dates *and*
      // seats simultaneously is an edge case with no defined behavior
      // requested yet; treat the seat selection as the authoritative
      // price and don't double it).
      const slotsTotal = seatPriceTotal.hasSelection ? seatPriceTotal.total : bookedSlots.reduce((t,s) => t + s.price, 0);
      return slotsTotal + fieldAddonTotal + wcAddonTotal;
    }
    const effectivePrice = datePrice !== null ? datePrice : basePrice;
    const qty = parseInt(formState.__qty_multiplier) || 1;
    // Requested formula: schedule qty × seat qty × (per-seat price, which
    // is itself either the seat's own override or the service's base
    // price) = total. So when seats are selected, their sum REPLACES the
    // flat per-slot price rather than adding to it — each seat already
    // carries the base price by default, so adding both would double it.
    const base = seatPriceTotal.hasSelection ? (seatPriceTotal.total * qty) : (effectivePrice * qty);
    return base + fieldAddonTotal + wcAddonTotal;
  }, [isMulti, bookedSlots, datePrice, basePrice, formState, fieldAddonTotal, wcAddonTotal, seatPriceTotal]);

  // ── Keep __grand_total, __addon_total, __wc_total and __seat_price_total
  //    in sync ───────────────────────────────────────────────────
  // This ensures: (1) the total_price FormField displays the right value,
  // (2) the submission payload carries these for the backend (booking
  // total vs. standalone WC checkout total vs. the seat-based component
  // Booking_Service.php needs to replace its own base-price calculation).
  useEffect(() => {
    setFormState(s => {
      if (s.__grand_total === grandTotal && s.__addon_total === fieldAddonTotal && s.__wc_total === wcAddonTotal && s.__seat_price_total === seatPriceTotal.total && s.__has_seat_selection === (seatPriceTotal.hasSelection ? 1 : 0)) return s;
      return { ...s, __grand_total: grandTotal, __addon_total: fieldAddonTotal, __wc_total: wcAddonTotal, __seat_price_total: seatPriceTotal.total, __has_seat_selection: seatPriceTotal.hasSelection ? 1 : 0 };
    });
  }, [grandTotal, fieldAddonTotal, wcAddonTotal, seatPriceTotal]);

  const widgetStyle = useMemo(() => {
    const style = {};
    const isHex = v => typeof v === 'string' && /^#[0-9a-fA-F]{6}$/.test(v);
    const px = (v, min, max) => {
      const n = parseInt(v, 10);
      if (Number.isNaN(n)) return '';
      return `${Math.max(min, Math.min(max, n))}px`;
    };

    if (design.font_family && design.font_family !== 'inherit') {
      style.fontFamily = `${design.font_family}, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
    }
    const base = px(design.font_size_base, 10, 24);
    const label = px(design.font_size_label, 8, 20);
    const heading = px(design.font_size_heading, 14, 42);
    const radius = px(design.card_radius, 0, 40);
    const btnRadius = px(design.btn_radius, 0, 60);
    const padding = px(design.card_padding, 8, 48);

    if (base) style['--cq-font-base'] = base;
    if (label) style['--cq-font-label'] = label;
    if (heading) style['--cq-font-heading'] = heading;
    if (radius) style['--cq-radius'] = radius;
    if (btnRadius) style['--cq-btn-radius'] = btnRadius;
    if (padding) style['--cq-card-padding'] = padding;

    if (isHex(design.color_accent || design.color_primary)) style['--cq-accent'] = design.color_accent || design.color_primary;
    if (isHex(design.color_secondary)) style['--cq-accent-dark'] = design.color_secondary;
    if (isHex(design.color_border)) style['--cq-border'] = design.color_border;
    if (isHex(design.color_bg)) {
      style['--cq-bg-widget'] = design.color_bg;
      style['--cq-bg-panel'] = design.color_bg;
      style['--cq-bg-input'] = design.color_bg;
    }
    if (isHex(design.color_text)) {
      style['--cq-text-primary'] = design.color_text;
      style['--cq-text-sec'] = design.color_text;
    }
    return style;
  }, [design]);

  // ── Validation ────────────────────────────────────────────────
  function validateCurrentStep() {
    const step = steps[currentStep];
    if (!step) return true;

    const newErrs = {};
    let ok = true;

    if (step.type === 'service' && !aptId) {
      setNotice({ type: 'err', msg: 'Please select a service.' });
      return false;
    }
    if (step.type === 'provider' && providers && providers.length > 0 && !staffId) {
      setNotice({ type: 'err', msg: 'Please select a provider.' });
      return false;
    }
    if (step.type === 'datetime') {
      if (flowMode === 'calendar_first' && !aptId) {
        if (!selectedDate) { setNotice({ type: 'err', msg: strings.error_date || 'Please select a date.' }); return false; }
        return true;
      }
      if (isMulti) {
        if (bookedSlots.length < minSched) {
          setNotice({ type: 'err', msg: `Please add at least ${minSched} time slot${minSched > 1 ? 's' : ''}.` });
          return false;
        }
      } else {
        if (!selectedDate) { setNotice({ type: 'err', msg: strings.error_date || 'Please select a date.' }); return false; }
        if (!selectedTime) { setNotice({ type: 'err', msg: strings.error_time || 'Please select a time slot.' }); return false; }
      }
    }
    if (step.type === 'fields') {
      const stepFields = step.fields || [];
      for (const f of (step.fields || [])) {
        if (!f.required) continue;
        const cond = f.conditional || {};
        if (cond.enabled && cond.field_name) continue; // skip conditional
        const v = formState[f.name];
        const emptyObject = v && typeof v === 'object' && !Array.isArray(v) && !v.selected;
        if (!v || emptyObject || (Array.isArray(v) && v.length === 0) || String(v).trim() === '') {
          newErrs[f.name] = 'Required';
          ok = false;
        }
      }
      const hasBookingPickers = stepFields.some(f => ['service_picker','provider_picker','date_picker','time_slot_picker'].includes(f.type));
      if (hasBookingPickers) {
        if (stepFields.some(f => f.type === 'service_picker') && !aptId) {
          setNotice({ type: 'err', msg: 'Please select a service.' });
          return false;
        }
        const providerOptions = providers || (Array.isArray(cfg.providers) ? cfg.providers : []);
        if (stepFields.some(f => f.type === 'provider_picker') && providerOptions.length > 0 && !staffId) {
          setNotice({ type: 'err', msg: 'Please select a provider.' });
          return false;
        }
        if (stepFields.some(f => f.type === 'date_picker' || f.type === 'time_slot_picker')) {
          if (isMulti) {
            if (bookedSlots.length < minSched) {
              setNotice({ type: 'err', msg: `Please add at least ${minSched} schedule${minSched > 1 ? 's' : ''}.` });
              return false;
            }
          } else {
            if (!selectedDate) { setNotice({ type: 'err', msg: strings.error_date || 'Please select a date.' }); return false; }
            if (!selectedTime) { setNotice({ type: 'err', msg: strings.error_time || 'Please select a time slot.' }); return false; }
          }
        }
      }
      const creditField = stepFields.find(f => f.type === 'member_slot_credit');
      if (creditField && memberCreditData.credit_required) {
        const fieldName = creditField.name || 'member_slot_credit';
        if (!formState[fieldName]) {
          setNotice({ type: 'err', msg: 'Please select a membership plan credit.' });
          return false;
        }
      }
    }

    setErrors(newErrs);
    if (!ok) setNotice({ type: 'err', msg: strings.error_generic || 'Please fill required fields.' });
    return ok;
  }

  function goNext() {
    if (!validateCurrentStep()) return;
    setNotice(null);
    setCurrentStep(s => Math.min(s + 1, totalSteps - 1));
  }

  function goBack() {
    setNotice(null);
    setCurrentStep(s => Math.max(s - 1, 0));
  }

  // ── Submit ────────────────────────────────────────────────────
  async function handleSubmit() {
    if (!validateCurrentStep()) return;
    setSubmitting(true);
    setNotice(null);

    try {
      // reCAPTCHA: resolve the token (v3 executes invisibly here; v2
      // reads whatever the visible checkbox widget already produced).
      // No-op (returns '') when reCAPTCHA isn't enabled.
      const recaptchaToken = await getRecaptchaToken();
      if (cfg.recaptcha?.enabled && !recaptchaToken) {
        setSubmitting(false);
        setNotice({ type: 'err', msg: cfg.recaptcha.version === 'v2'
          ? 'Please complete the reCAPTCHA check.'
          : 'Security check failed. Please try again.' });
        return;
      }

      const fd = new FormData(formRef.current);
      fd.set('action',         'credoq_submit_booking');
      fd.set('nonce',          cfg.nonce || '');
      fd.set('appointment_id', aptId);
      fd.set('form_id',        cfg.form_id || 0);
      if (recaptchaToken) fd.set('form_data[recaptcha_token]', recaptchaToken);

      if (isMulti) {
        fd.set('is_multi_booking', '1');
        fd.set('schedules_json',   JSON.stringify(bookedSlots));
        if (bookedSlots[0]) {
          fd.set('selected_date', bookedSlots[0].date);
          fd.set('selected_time', bookedSlots[0].time);
        }
      } else {
        fd.set('selected_date', selectedDate);
        fd.set('selected_time', selectedTime);
      }

      if (staffId) fd.set('staff_id', staffId);
      if (memberCreditField) {
        const creditFieldName = memberCreditField.name || 'member_slot_credit';
        const selectedPlan = formState[creditFieldName] || formState.credoq_selected_plan_id || '';
        if (selectedPlan) fd.set('credoq_selected_plan_id', selectedPlan);
      }

      // Append formState values
      Object.entries(formState).forEach(([k, v]) => {
        // Skip internal UI keys EXCEPT the ones the backend needs.
        if (k.startsWith('__') && !['__addon_total', '__wc_total', '__seat_price_total', '__has_seat_selection'].includes(k)) return;
        if (k === 'credoq_selected_plan_id') return;
        if (Array.isArray(v)) {
          v.forEach(item => fd.append(`form_data[${k}][]`, item));
        } else if (v && typeof v === 'object') {
          Object.entries(v).forEach(([subKey, subVal]) => {
            fd.set(`form_data[${k}][${subKey}]`, subVal);
          });
        } else {
          fd.set(`form_data[${k}]`, v);
        }
      });

      // Send calculate field results as their actual field values so the
      // backend's Field_Calculate::price_contribution() and
      // wc_contribution() (standalone WC checkout) can read them.
      fields.forEach(field => {
        if (field.type === 'calculate' && (field.add_to_total || field.wc_option_price)) {
          const calcResult = formState[`__calc_${field.name}`];
          if (calcResult !== undefined) {
            fd.set(`form_data[${field.name}]`, calcResult);
          }
        }
      });

      // AUDIT-FIX (Bug 3 — "Invalid service" / "An error occurred" on
      // standalone Engine forms): tell submitBooking() whether this is
      // an Appointments-flow submission. Only forms that actually carry
      // an appointment/provider field should hit the Appointments REST
      // route (/credoq/v1/bookings); every other form — Contact Forms,
      // Surveys, Cost Estimators — must go through the Engine's own,
      // always-available admin-ajax credoq_submit_booking handler.
      const isAppointmentFlow = hasAppointmentField || hasProviderPicker;
      // AUDIT-FIX (field type audit — file uploads in multi-step forms):
      // formState only ever held the filename STRING for 'file' fields
      // (so it can be displayed/validated), not the binary itself. The
      // real File objects are captured in fileRef as they're picked,
      // independent of which step is currently mounted. Overwrite the
      // FormData entries here so the actual file reaches the backend
      // even if its step isn't the one visible at submit time.
      Object.entries(fileRef.current).forEach(([k, file]) => {
        if (file) fd.set(`form_data[${k}]`, file);
      });

      const data = await ajax.submitBooking(fd, isAppointmentFlow);

      if (data.success) {
        if (data.data && data.data.wc && data.data.redirect) {
          setNotice({ type: 'ok', msg: strings.redirecting || 'Redirecting to checkout…' });
          setTimeout(() => { window.location.href = data.data.redirect; }, 800);
        } else {
          setSubmitResult({ success: true, message: (data.data && data.data.message) || strings.success || 'Booking confirmed!' });
        }
      } else {
        setNotice({ type: 'err', msg: (data.data && data.data.message) || strings.error_generic || 'An error occurred.' });
      }
    } catch(e) {
      setNotice({ type: 'err', msg: strings.error_generic || 'Network error. Please try again.' });
    } finally {
      setSubmitting(false);
    }
  }

  function resetWidget() {
    setSubmitResult(null);
    setCurrentStep(0);
    setAptId(cfg.appointment_id || 0);
    setBasePrice(cfg.base_price || 0);
    setAptTitle(cfg.appointment_title || '');
    setAptLoc(cfg.appointment_location || '');
    setStaffId(0);
    setStaffName('');
    setSelectedDate('');
    setSelectedTime('');
    setSlots(null);
    setProviders(null);
    setProviderServices(null);
    setDateServices(null);
    setBookedSlots([]);
    setFormState({});
    setErrors({});
    setNotice(null);
    setDatePrice(null);
    setIsSpecialDate(false);
  }

  // ── Render helpers ────────────────────────────────────────────
  function renderSidebar() {
    return (
      <div className="cqw-sidebar">
        <div className="cqw-brand">
          <div className="cqw-brand-icon">
            {primaryLogoUrl ? (
              <img src={primaryLogoUrl} alt="" />
            ) : primaryLottieUrl ? (
              <DotLottieReact src={primaryLottieUrl} loop autoplay />
            ) : (
              primaryBrandName.slice(0,2).toUpperCase()
            )}
          </div>
          <div>
            <div className="cqw-brand-name">{document.title.split('–')[0]?.trim() || 'Booking'}</div>
            <div className="cqw-brand-sub">Online Booking</div>
          </div>
        </div>

        <div className="cqw-sb-steps">
          {steps.map((step, i) => {
            let cls = 'cqw-sb-step';
            if (i < currentStep)      cls += ' s-done';
            else if (i === currentStep) cls += ' s-active';
            return (
              <div key={i} className={cls}>
                <div className="cqw-sb-bubble">
                  {i < currentStep ? '✓' : <span>{i+1}</span>}
                </div>
                <div>
                  <div className="cqw-sb-title">{step.label}</div>
                  <div className="cqw-sb-val">
                    {i === 0 && aptTitle}
                    {i === 1 && staffName}
                    {step.type === 'datetime' && selectedDate && `${fmtDate(selectedDate)} ${selectedTime}`}
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        <div className="cqw-sb-bottom">
          <WidgetBrand brand={brand} />
          <div className="cqw-toggle-wrap">
            <label className="cqw-toggle-sw">
              <input type="checkbox" checked={theme === 'light'} onChange={toggleTheme} />
              <div className="cqw-toggle-track" />
              <div className="cqw-toggle-thumb">{theme === 'light' ? '☀️' : '🌙'}</div>
            </label>
            <span className="cqw-toggle-label">{theme === 'dark' ? 'Dark Mode' : 'Light Mode'}</span>
          </div>
        </div>
      </div>
    );
  }

  function renderProgress() {
    const pct = totalSteps > 1 ? Math.round(((currentStep + 1) / totalSteps) * 100) : 100;
    return (
      <div className="cqw-progress">
        <div className="cqw-progress-fill" style={{ width: pct + '%' }} />
      </div>
    );
  }

  function renderNotice() {
    if (!notice) return null;
    const cls = notice.type === 'err' ? 'cqw-notice-err' : notice.type === 'ok' ? 'cqw-notice-ok' : 'cqw-notice-warn';
    return <div className={`cqw-notice ${cls}`}>{notice.msg}</div>;
  }

  function renderBookedSlotList() {
    return (
      <div>
        <div className="cqw-panel-h" style={{marginBottom:8}}>
          {bookedSlots.length} schedule{bookedSlots.length !== 1 ? 's' : ''} selected
        </div>
        <div className="cqw-booked-list">
          {bookedSlots.map((s, i) => (
            <div key={`${s.appointment_id || aptId}-${s.staff_id || 0}-${s.date}-${s.time}`} className="cqw-booked-row">
              <span className="cqw-booked-dot" style={{background: SLOT_COLORS[i % SLOT_COLORS.length]}} />
              <span className="cqw-booked-label">{s.label}</span>
              <span className="cqw-booked-price">
                {s.price > 0 ? `${currency} ${s.price.toFixed(2)}` : 'Free'}
              </span>
              <button type="button" className="cqw-booked-rm" onClick={() => removeFromCart(i)}>x</button>
            </div>
          ))}
        </div>
      </div>
    );
  }

  // ── Service step ──────────────────────────────────────────────
  function renderServiceStep() {
    let apts = cfg.is_all_mode && Array.isArray(cfg.appointments)
      ? cfg.appointments
      : (aptId ? [currentApt] : (cfg.appointments || []));
    if (flowMode === 'provider_first' && staffId) {
      apts = providerServices || [];
    } else if (flowMode === 'calendar_first' && selectedDate) {
      apts = dateServices || [];
    }

    // Find the appointment field in fields
    const aptField = fields.find(f => f.type === 'appointment');
    const fieldLabel = aptField?.label || 'Select Service';

    if (loadingProv) return <div className="cqw-loading"><span className="cqw-spin" /> Loading…</div>;

    return (
      <div>
        <div className="cqw-panel-h">{fieldLabel}</div>
        <div className="cqw-panel-sub">Choose from our available services</div>
        {(flowMode === 'provider_first' && staffId && providerServices === null) && (
          <div className="cqw-loading"><span className="cqw-spin" /> Loading services...</div>
        )}
        {(flowMode === 'calendar_first' && selectedDate && dateServices === null) && (
          <div className="cqw-loading"><span className="cqw-spin" /> Loading services...</div>
        )}
        <div className="cqw-svc-list">
          {apts.map(apt => {
            const sel = parseInt(apt.id) === parseInt(aptId);
            return (
              <div key={apt.id} className={`cqw-svc-item${sel ? ' sel' : ''}`}
                   onClick={() => selectService(apt)}>
                <div className="cqw-svc-emoji">🗓️</div>
                <div style={{flex:1,minWidth:0}}>
                  <div className="cqw-svc-name">{apt.title}</div>
                  {apt.location && <div className="cqw-svc-dur">📍 {apt.location}</div>}
                </div>
                {parseFloat(apt.base_price) > 0
                  ? <span className="cqw-svc-price">{currency} {parseFloat(apt.base_price).toFixed(2)}</span>
                  : <span className="cqw-pill-free">Free</span>
                }
                {sel && <span className="cqw-svc-chk">✓</span>}
              </div>
            );
          })}
          {apts.length === 0 && aptId > 0 && (
            // Single appointment mode — show it and auto-select
            <div className="cqw-svc-item sel" onClick={() => {}}>
              <div className="cqw-svc-emoji">🗓️</div>
              <div style={{flex:1}}>
                <div className="cqw-svc-name">{aptTitle || 'Service'}</div>
              </div>
              {basePrice > 0 && <span className="cqw-svc-price">{currency} {basePrice.toFixed(2)}</span>}
              <span className="cqw-svc-chk">✓</span>
            </div>
          )}
        </div>
      </div>
    );
  }

  // ── Provider step ──────────────────────────────────────────────
  function renderProviderStep() {
    const providerOptions = aptId ? providers : (Array.isArray(cfg.providers) ? cfg.providers : []);

    // Still loading — show spinner
    if (loadingProv || providers === null) {
      return <div className="cqw-loading"><span className="cqw-spin" /> Loading providers…</div>;
    }

    // FIX: Loaded but empty — no staff assigned. Parent useEffect handles skip.
    if (Array.isArray(providerOptions) && providerOptions.length === 0) {
      return <div className="cqw-loading"><span className="cqw-spin" /> No provider required — continuing…</div>;
    }
    return (
      <div>
        <div className="cqw-panel-h">Select Provider</div>
        <div className="cqw-panel-sub">Choose your preferred specialist</div>
        <div className="cqw-staff-list">
          {providerOptions.map(p => {
            const sel = parseInt(p.id) === parseInt(staffId);
            return (
              <div key={p.id} className={`cqw-staff-item${sel ? ' sel' : ''}`}
                   onClick={() => selectProvider(p)}>
                {p.image
                  ? <div className="cqw-staff-av"><img src={p.image} alt={p.name} /></div>
                  : <div className="cqw-staff-av-init">{(p.name || 'P')[0].toUpperCase()}</div>
                }
                <div>
                  <div className="cqw-staff-nm">{p.name}</div>
                  {p.bio && <div className="cqw-staff-sub">{p.bio}</div>}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    );
  }

  // ── DateTime step ──────────────────────────────────────────────
  function renderDatetimeStep() {
    const effectivePrice = datePrice !== null ? datePrice : basePrice;
    const canAddMore = maxSched === 0 || bookedSlots.length < maxSched;

    return (
      <div>
        {isMulti && bookedSlots.length > 0 && (
          <div>
            <div className="cqw-panel-h" style={{marginBottom:8}}>
              {bookedSlots.length} slot{bookedSlots.length !== 1 ? 's' : ''} selected
            </div>
            <div className="cqw-booked-list">
              {bookedSlots.map((s, i) => (
                <div key={i} className="cqw-booked-row">
                  <span className="cqw-booked-dot" style={{background: SLOT_COLORS[i % SLOT_COLORS.length]}} />
                  <span className="cqw-booked-label">{s.label}</span>
                  <span className="cqw-booked-price">
                    {s.price > 0 ? `${currency} ${s.price.toFixed(2)}` : 'Free'}
                  </span>
                  <button type="button" className="cqw-booked-rm" onClick={() => removeFromCart(i)}>×</button>
                </div>
              ))}
            </div>
          </div>
        )}

        {(!isMulti || canAddMore) && (
          <>
            <div className="cqw-panel-h">
              {isMulti ? 'Add another date' : 'Select Date & Time'}
            </div>
            <div className="cqw-panel-sub">
              {isMulti ? 'Pick another date and time slot' : 'Choose your preferred date'}
            </div>

            <Calendar
              config={cfg}
              selectedDate={selectedDate}
              onSelect={selectDate}
              appointmentId={aptId}
              staffId={staffId}
              ajax={ajax}
              currency={currency}
            />

            {/* Time slots */}
            {selectedDate && (
              <div>
                <div className="cqw-times-hdr">
                  <span className="cqw-times-lbl">Available Times</span>
                  <span style={{display:'flex',alignItems:'center',gap:6}}>
                    {effectivePrice > 0 && <span>{fmtCurrency(currency, effectivePrice)} per slot</span>}
                    {isSpecialDate && (
                      <span className="cqw-special-badge">★ Special Price</span>
                    )}
                  </span>
                </div>
                {loadingSlots && <div className="cqw-loading"><span className="cqw-spin" /> Loading slots…</div>}
                {!loadingSlots && slots && slots.length === 0 && (
                  <div className="cqw-notice cqw-notice-warn">{strings.no_slots || 'No available slots for this date.'}</div>
                )}
                {!loadingSlots && slots && slots.length > 0 && (
                  <div className="cqw-times-grid">
                    {slots.map((slot, i) => {
                      const isFull   = slot.is_full === true || slot.is_full === 1 || slot.available === false;
                      const inCart   = isMulti && bookedSlots.some(s => s.date === selectedDate && s.time === slot.time);
                      const selected = !isMulti && selectedTime === slot.time;
                      if (inCart) {
                        return (
                          <button key={i} type="button" className="cqw-t-btn full" disabled>
                            {slot.time}
                            <span className="cap">Added</span>
                          </button>
                        );
                      }
                      if (isFull) {
                        return cfg.waiting_list_enabled ? (
                          <button key={i} type="button" className="cqw-t-btn"
                                  style={{borderColor:'#d97706',color:'#d97706'}}
                                  onClick={() => ajax.joinWaitlist(aptId, selectedDate, slot.time)
                                    .then(d => setNotice({ type: d.success ? 'ok' : 'err',
                                      msg: d.success ? (strings.waitlist_ok || 'Added to waitlist') : 'Error' }))
                                  }>
                            {slot.time}
                            <span className="cap">Waitlist</span>
                          </button>
                        ) : (
                          <button key={i} type="button" className="cqw-t-btn full" disabled>
                            {slot.time}
                            <span className="cap">{strings.capacity_full || 'Full'}</span>
                          </button>
                        );
                      }
                      return (
                        <button key={i} type="button"
                                className={`cqw-t-btn${selected ? ' sel' : ''}`}
                                onClick={() => selectTime(slot)}>
                          {slot.time}
                          {typeof slot.remaining !== 'undefined' && (
                            <span className="cap" style={{
                              color: selected ? 'rgba(255,255,255,.8)' :
                                (slot.remaining / (slot.capacity||1)) <= 0.25 ? '#dc2626' :
                                (slot.remaining / (slot.capacity||1)) <= 0.5  ? '#d97706' : '#16a34a'
                            }}>
                              {(strings.capacity_left || '{n} left').replace('{n}', slot.remaining)}
                            </span>
                          )}
                        </button>
                      );
                    })}
                  </div>
                )}
              </div>
            )}
          </>
        )}
      </div>
    );
  }

  // ── Generic fields step ────────────────────────────────────────
  function renderFieldsStep(step) {
    // Also render standalone picker fields
    const allFields = fields; // use all fields if step.fields empty
    const stepFields = (step.fields && step.fields.length) ? step.fields : allFields;

    return (
      <div>
        {stepFields.map((field, i) => {
          if (field.type === 'service_picker') return renderStandaloneServicePicker(field, i);
          if (field.type === 'provider_picker') return renderStandaloneProviderPicker(field, i);
          if (field.type === 'date_picker')     return renderStandaloneDatePicker(field, i);
          if (field.type === 'time_slot_picker') return renderStandaloneTimePicker(field, i);
          if (field.type === 'event_calendar')  return renderStandaloneEventCalendar(field, i);
          // AUDIT-FIX (architecture): member_slot_credit only goes through
          // the appointment-coupled renderer (renderMemberSlotCreditField)
          // when this form actually HAS an active appointment context
          // (aptId set) — i.e. redeeming a credit against a specific
          // booked slot, which genuinely needs that deep Appointments
          // coupling. With zero appointment context (Membership installed
          // standalone, no Appointments addon, or a non-appointment form),
          // it falls through to the generic <FormField> below, which uses
          // field._frontend — the descriptor Credoq Membership's own
          // Field_Slot_Credit::get_frontend_render() supplies. Previously
          // this was unconditionally intercepted here, so standalone
          // Membership credit fields were permanently stuck showing
          // "Please select a service first," never reaching the addon's
          // own (already-correct) rendering logic.
          if (field.type === 'member_slot_credit' && aptId) return renderMemberSlotCreditField(field, i);
          return (
            <FormField
              key={i}
              field={field}
              index={i}
              formState={formState}
              setFormState={setFormState}
              basePrice={datePrice !== null ? datePrice : basePrice}
              currency={currency}
              errors={errors}
              selectedDate={activeSeatSchedule.date}
              selectedTime={activeSeatSchedule.time}
              staffId={activeSeatSchedule.staffId}
              currentApt={currentApt}
              config={cfg}
              fileRef={fileRef}
            />
          );
        })}
      </div>
    );
  }

  // ── Standalone pickers ─────────────────────────────────────────
  function renderMemberSlotCreditField(field, i) {
    const fieldName = field.name || 'member_slot_credit';
    const plans = memberCreditData.plans || [];
    const selected = formState[fieldName] || '';
    const setPlan = (planId) => {
      setFormState(s => ({
        ...s,
        [fieldName]: String(planId),
        credoq_selected_plan_id: String(planId),
      }));
    };

    if (!aptId) {
      return (
        <div key={i} className="cqw-field">
          {field.label && <label>{field.label}{field.required && <span className="req"> *</span>}</label>}
          <div className="cqw-notice cqw-notice-warn">Please select a service first.</div>
        </div>
      );
    }

    if (loadingMemberCredits) {
      return (
        <div key={i} className="cqw-field">
          {field.label && <label>{field.label}{field.required && <span className="req"> *</span>}</label>}
          <div className="cqw-loading"><span className="cqw-spin" /> Loading member credits...</div>
        </div>
      );
    }

    if (!memberCreditData.credit_required) return null;

    return (
      <div key={i} className="cqw-field">
        {field.label && <label>{field.label}{field.required && <span className="req"> *</span>}</label>}
        {plans.length === 0 ? (
          <div className="cqw-notice cqw-notice-err">No active membership plan has available slot credit for this booking.</div>
        ) : (
          <div className="cqw-member-credit-list">
            {plans.map(plan => {
              const value = String(plan.plan_id);
              const checked = selected === value;
              return (
                <label key={plan.plan_id} className={`cqw-radio-opt cqw-member-credit-opt${checked ? ' sel' : ''}`}>
                  <input
                    type="radio"
                    name={`form_data[${fieldName}]`}
                    value={value}
                    checked={checked}
                    required={!!field.required || !!memberCreditData.credit_required}
                    onChange={() => setPlan(value)}
                  />
                  <span className="cqw-radio-dot" />
                  <span className="cqw-member-credit-body">
                    <span className="cqw-opt-lbl">{plan.plan_name}</span>
                    <span className="cqw-member-credit-meta">
                      {plan.remaining} credit{parseInt(plan.remaining, 10) === 1 ? '' : 's'} remaining
                      {plan.expires ? ` · Expires ${plan.expires}` : ''}
                    </span>
                  </span>
                </label>
              );
            })}
          </div>
        )}
      </div>
    );
  }

  function renderStandaloneServicePicker(field, i) {
    return (
      <div key={i} className="cqw-field">
        {field.label && <label>{field.label}{field.required && <span className="req"> *</span>}</label>}
        <div className="cqw-svc-list">
          {(cfg.appointments || []).map(apt => {
            const sel = parseInt(apt.id) === parseInt(aptId);
            return (
              <div key={apt.id} className={`cqw-svc-item${sel ? ' sel' : ''}`}
                   onClick={() => {
                     setAptId(apt.id);
                     setBasePrice(parseFloat(apt.base_price)||0);
                     setAptTitle(apt.title || '');
                     setAptLoc(apt.location || '');
                     setSelectedDate('');
                     setSelectedTime('');
                     setSlots(null);
                     setBookedSlots([]);
                     if (!hasPresetProvider) {
                       setStaffId(0);
                       setStaffName('');
                     }
                   }}>
                <div className="cqw-svc-emoji">🗓️</div>
                <div style={{flex:1}}><div className="cqw-svc-name">{apt.title}</div></div>
                {parseFloat(apt.base_price) > 0
                  ? <span className="cqw-svc-price">{currency} {parseFloat(apt.base_price).toFixed(2)}</span>
                  : <span className="cqw-pill-free">Free</span>
                }
                {sel && <span className="cqw-svc-chk">✓</span>}
              </div>
            );
          })}
        </div>
      </div>
    );
  }

  function renderStandaloneProviderPicker(field, i) {
    const providerOptions = providers || (Array.isArray(cfg.providers) ? cfg.providers : []);
    return (
      <div key={i} className="cqw-field">
        {field.label && <label>{field.label}{field.required && <span className="req"> *</span>}</label>}
        {loadingProv && <div className="cqw-loading"><span className="cqw-spin" /> Loading providers...</div>}
        <div className="cqw-staff-list">
          {providerOptions.map(p => {
            const sel = parseInt(p.id) === parseInt(staffId);
            return (
              <div key={p.id} className={`cqw-staff-item${sel ? ' sel' : ''}`}
                   onClick={() => {
                     setStaffId(p.id);
                     setStaffName(p.name||'');
                     setSelectedDate('');
                     setSelectedTime('');
                     setSlots(null);
                     setDatePrice(null);
                     setBookedSlots([]);
                   }}>
                {p.image
                  ? <div className="cqw-staff-av"><img src={p.image} alt={p.name} /></div>
                  : <div className="cqw-staff-av-init">{(p.name||'P')[0].toUpperCase()}</div>
                }
                <div>
                  <div className="cqw-staff-nm">{p.name}</div>
                  {p.bio && <div className="cqw-staff-sub">{p.bio}</div>}
                </div>
              </div>
            );
          })}
          {!loadingProv && providerOptions.length === 0 && (
            <div className="cqw-notice cqw-notice-warn">No providers available.</div>
          )}
        </div>
      </div>
    );
  }

  function renderStandaloneDatePicker(field, i) {
    return (
      <div key={i} className="cqw-field">
        {field.label && <label>{field.label}{field.required && <span className="req"> *</span>}</label>}
        <Calendar
          config={cfg}
          selectedDate={selectedDate}
          onSelect={selectDate}
          appointmentId={aptId}
          staffId={staffId}
          ajax={ajax}
        />
      </div>
    );
  }

  function renderStandaloneTimePicker(field, i) {
    const isConnected = parseInt(field.sp_connect || 0) === 1;
    const canAddMore = maxSched === 0 || bookedSlots.length < maxSched;
    if (isConnected && !selectedDate) {
      return (
        <div key={i} className="cqw-field">
          {field.label && <label>{field.label}</label>}
          {isMulti && bookedSlots.length > 0 && renderBookedSlotList()}
          <div style={{color:'var(--cq-text-muted)',fontSize:13,padding:'8px 0'}}>
            {canAddMore ? (field.placeholder || 'Please select a date first') : 'Maximum schedules selected'}
          </div>
        </div>
      );
    }
    return (
      <div key={i} className="cqw-field">
        {field.label && <label>{field.label}{field.required && <span className="req"> *</span>}</label>}
        {loadingSlots && <div className="cqw-loading"><span className="cqw-spin" /> Loading slots…</div>}
        {!loadingSlots && slots && slots.length === 0 && (
          <div style={{color:'var(--cq-text-muted)',fontSize:13}}>No available slots.</div>
        )}
        {!loadingSlots && slots && slots.length > 0 && canAddMore && (
          <div className="cqw-times-grid">
            {slots.map((slot, j) => {
              const isFull = slot.is_full || slot.available === false;
              const inCart = isMulti && bookedSlots.some(s => s.date === selectedDate && s.time === slot.time);
              const sel    = !isMulti && selectedTime === slot.time;
              return (
                <button key={`${selectedDate}-${slot.time}`} type="button"
                        className={`cqw-t-btn${sel ? ' sel' : ''}${(isFull || inCart) ? ' full' : ''}`}
                        disabled={isFull || inCart}
                        onClick={() => !isFull && selectTime(slot, { keepSelection: true })}>
                  <span>{slot.time}</span>
                  {inCart && <span className="cap">Added</span>}
                  {!inCart && typeof slot.remaining !== 'undefined' && (
                    <span className="cap">{(strings.capacity_left || '{n} left').replace('{n}', slot.remaining)}</span>
                  )}
                </button>
              );
            })}
          </div>
        )}
      </div>
    );
  }

  // ── Review step ────────────────────────────────────────────────
  function renderStandaloneEventCalendar(field, i) {
    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const ymd = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
    const eventsByDate = (events || []).reduce((map, evt) => {
      if (!evt.start) return map;
      const key = String(evt.start).slice(0, 10);
      if (!map[key]) map[key] = [];
      map[key].push(evt);
      return map;
    }, {});
    const first = new Date(eventViewDate.year, eventViewDate.month, 1);
    const cells = [];
    for (let e = 0; e < first.getDay(); e++) cells.push(null);
    for (let d = 1; d <= new Date(eventViewDate.year, eventViewDate.month + 1, 0).getDate(); d++) {
      const key = ymd(new Date(eventViewDate.year, eventViewDate.month, d));
      cells.push({ day: d, key, events: eventsByDate[key] || [] });
    }
    const modalEvents = eventModalDate ? (eventsByDate[eventModalDate] || []) : [];

    function shiftEventMonth(delta) {
      setEventViewDate(v => {
        const next = new Date(v.year, v.month + delta, 1);
        return { year: next.getFullYear(), month: next.getMonth() };
      });
    }

    async function submitSelectedEvent() {
      if (!selectedEvent) return;
      setEventNotice(null);
      setSubmitting(true);
      try {
        const data = await ajax.submitEventBooking(selectedEvent.id, eventQty, eventGuest);
        if (data.success) {
          if (data.data && data.data.redirect) {
            window.location.href = data.data.redirect;
            return;
          }
          setEventNotice({ type: 'ok', msg: (data.data && data.data.message) || 'Event booking confirmed.' });
          setEvents(null);
          setEventModalDate('');
          setSelectedEvent(null);
        } else {
          setEventNotice({ type: 'err', msg: data.data || 'Unable to book this event.' });
        }
      } catch(e) {
        setEventNotice({ type: 'err', msg: 'Network error. Please try again.' });
      } finally {
        setSubmitting(false);
      }
    }

    return (
      <div key={i} className="cqw-field">
        {field.label && <label>{field.label}{field.required && <span className="req"> *</span>}</label>}
        {eventNotice && (
          <div className={`cqw-notice ${eventNotice.type === 'ok' ? 'cqw-notice-ok' : 'cqw-notice-err'}`}>{eventNotice.msg}</div>
        )}
        {loadingEvents && <div className="cqw-loading"><span className="cqw-spin" /> Loading events...</div>}
        {!loadingEvents && events && events.length === 0 && <div className="cqw-notice cqw-notice-warn">No events available.</div>}
        {!loadingEvents && events && events.length > 0 && (
          <div className="cqw-event-cal">
            <div className="cqw-event-cal-h">
              <button type="button" className="cqw-cal-nav" onClick={() => shiftEventMonth(-1)}>‹</button>
              <strong>{monthNames[eventViewDate.month]} {eventViewDate.year}</strong>
              <button type="button" className="cqw-cal-nav" onClick={() => shiftEventMonth(1)}>›</button>
            </div>
            <div className="cqw-event-dow">{['SU','MO','TU','WE','TH','FR','SA'].map(d => <span key={d}>{d}</span>)}</div>
            <div className="cqw-event-grid">
              {cells.map((cell, idx) => !cell ? (
                <div key={`e-${idx}`} className="cqw-event-cell empty" />
              ) : (
                <button key={cell.key} type="button" className={`cqw-event-cell${cell.events.length ? ' has-events' : ''}`}
                        onClick={() => cell.events.length && setEventModalDate(cell.key)}>
                  <span>{cell.day}</span>
                  {cell.events.length > 0 && <b>{cell.events.length}</b>}
                </button>
              ))}
            </div>
          </div>
        )}
        {eventModalDate && (
          <div className="cqw-modal-backdrop" onClick={() => { setEventModalDate(''); setSelectedEvent(null); }}>
            <div className="cqw-event-modal" onClick={e => e.stopPropagation()}>
              <div className="cqw-event-modal-h">
                <strong>{fmtDate(eventModalDate)}</strong>
                <button type="button" onClick={() => { setEventModalDate(''); setSelectedEvent(null); }}>×</button>
              </div>
              {!selectedEvent ? (
                <div className="cqw-svc-list">
                  {modalEvents.map(evt => {
                    const remaining = evt.remaining;
                    const price = parseFloat(evt.price || 0);
                    const soldOut = remaining !== null && remaining !== undefined && parseInt(remaining, 10) <= 0;
                    return (
                      <div key={evt.id} className="cqw-svc-item">
                        <div className="cqw-svc-emoji">Cal</div>
                        <div style={{flex:1,minWidth:0}}>
                          <div className="cqw-svc-name">{evt.title}</div>
                          <div className="cqw-svc-dur">{evt.start ? new Date(evt.start).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : ''}</div>
                          {field.event_cal_capacity && remaining !== null && remaining !== undefined && <div className="cqw-svc-dur">{remaining} spots left</div>}
                        </div>
                        {price > 0 ? <span className="cqw-svc-price">{currency} {price.toFixed(2)}</span> : <span className="cqw-pill-free">Free</span>}
                        <button type="button" className="cqw-event-continue" disabled={soldOut} onClick={() => setSelectedEvent(evt)}>
                          {soldOut ? 'Full' : 'Continue'}
                        </button>
                      </div>
                    );
                  })}
                </div>
              ) : (
                <div className="cqw-event-form">
                  <div className="cqw-panel-h">{selectedEvent.title}</div>
                  <input type="number" min="1" value={eventQty} onChange={e => setEventQty(Math.max(1, parseInt(e.target.value, 10) || 1))} placeholder="Qty" />
                  <input type="text" value={eventGuest.name} onChange={e => setEventGuest(g => ({...g, name:e.target.value}))} placeholder="Name" />
                  <input type="email" value={eventGuest.email} onChange={e => setEventGuest(g => ({...g, email:e.target.value}))} placeholder="Email" />
                  <input type="tel" value={eventGuest.phone} onChange={e => setEventGuest(g => ({...g, phone:e.target.value}))} placeholder="Phone" />
                  <button type="button" className="cqw-btn-cont" disabled={submitting} onClick={submitSelectedEvent}>
                    {submitting ? 'Processing...' : 'Book Now'}
                  </button>
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    );

    async function bookEvent(eventId) {
      setEventNotice(null);
      setSubmitting(true);
      try {
        const data = await ajax.submitEventBooking(eventId, 1);
        if (data.success) {
          if (data.data && data.data.redirect) {
            window.location.href = data.data.redirect;
            return;
          }
          setEventNotice({ type: 'ok', msg: (data.data && data.data.message) || 'Event booking confirmed.' });
          setEvents(null);
        } else {
          setEventNotice({ type: 'err', msg: data.data || 'Unable to book this event.' });
        }
      } catch(e) {
        setEventNotice({ type: 'err', msg: 'Network error. Please try again.' });
      } finally {
        setSubmitting(false);
      }
    }

    return (
      <div key={i} className="cqw-field">
        {field.label && <label>{field.label}{field.required && <span className="req"> *</span>}</label>}
        {eventNotice && (
          <div className={`cqw-notice ${eventNotice.type === 'ok' ? 'cqw-notice-ok' : 'cqw-notice-err'}`}>{eventNotice.msg}</div>
        )}
        {loadingEvents && <div className="cqw-loading"><span className="cqw-spin" /> Loading events...</div>}
        {!loadingEvents && events && events.length === 0 && (
          <div className="cqw-notice cqw-notice-warn">No events available.</div>
        )}
        {!loadingEvents && events && events.length > 0 && (
          <div className="cqw-svc-list">
            {events.map(evt => {
              const props = evt.extendedProps || {};
              const remaining = props.remaining ?? evt.remaining;
              const price = parseFloat(props.price ?? evt.price ?? 0);
              const soldOut = remaining !== undefined && parseInt(remaining, 10) <= 0;
              return (
                <div key={evt.id} className="cqw-svc-item" style={{alignItems:'flex-start'}}>
                  <div className="cqw-svc-emoji">Cal</div>
                  <div style={{flex:1,minWidth:0}}>
                    <div className="cqw-svc-name">{evt.title}</div>
                    <div className="cqw-svc-dur">{evt.start ? new Date(evt.start).toLocaleString() : ''}</div>
                    {props.location && <div className="cqw-svc-dur">{props.location}</div>}
                    {field.event_cal_capacity && remaining !== undefined && (
                      <div className="cqw-svc-dur">{remaining} spots left</div>
                    )}
                  </div>
                  {price > 0 ? <span className="cqw-svc-price">{currency} {price.toFixed(2)}</span> : <span className="cqw-pill-free">Free</span>}
                  <button type="button" className="cqw-btn-cont" disabled={submitting || soldOut} onClick={() => bookEvent(evt.id)}>
                    {soldOut ? 'Full' : 'Book'}
                  </button>
                </div>
              );
            })}
          </div>
        )}
      </div>
    );
  }

  function renderReviewStep() {
    return (
      <div>
        <div className="cqw-panel-h">Review & Confirm</div>
        <div className="cqw-panel-sub">Please review your booking details</div>

        <div className="cqw-rv-card">
          <h3>Booking Summary</h3>
          {aptTitle && (
            <div className="cqw-rv-row">
              <span className="cqw-rv-lbl">Service</span>
              <span className="cqw-rv-val">{aptTitle}</span>
            </div>
          )}
          {staffName && (
            <div className="cqw-rv-row">
              <span className="cqw-rv-lbl">Provider</span>
              <span className="cqw-rv-val">{staffName}</span>
            </div>
          )}
          {aptLoc && (
            <div className="cqw-rv-row">
              <span className="cqw-rv-lbl">Location</span>
              <span className="cqw-rv-val">{aptLoc}</span>
            </div>
          )}
          {isMulti ? bookedSlots.map((s, i) => (
            <div key={i} className="cqw-rv-row" style={{borderLeft:`3px solid ${SLOT_COLORS[i % SLOT_COLORS.length]}`,paddingLeft:8}}>
              <span className="cqw-rv-lbl" style={{fontSize:11}}>{s.label}</span>
              <span className="cqw-rv-val">
                {seatPriceTotal.hasSelection
                  ? <span className="cqw-pill-seats">Seats selected</span>
                  : (s.price > 0 ? `${currency} ${s.price.toFixed(2)}` : 'Free')}
              </span>
            </div>
          )) : (
            <>
              {selectedDate && <div className="cqw-rv-row"><span className="cqw-rv-lbl">Date</span><span className="cqw-rv-val">{fmtDate(selectedDate)}</span></div>}
              {selectedTime && <div className="cqw-rv-row"><span className="cqw-rv-lbl">Time</span><span className="cqw-rv-val">{selectedTime}</span></div>}
            </>
          )}
        </div>

        {/* Seat breakdown — full detail (label, price, running total) for
            each seat_map field with an active selection. The generic
            "Your Details" loop below only ever shows a one-line "N
            seat(s)" summary for object-valued fields, which is why this
            gets its own card instead. Styled to match the other Review
            cards (Booking Summary, Your Details) rather than the darker
            live-panel look the field itself uses inline. */}
        {fields.filter(f => f.type === 'seat_map').map(f => {
          const details = formState[`__seat_details_${f.name}`];
          if (!Array.isArray(details) || !details.length) return null;
          const planLabel = formState[`__seat_plan_name_${f.name}`] || 'Seat Map';
          const seatTotal = details.reduce((t, d) => t + d.price, 0);
          return (
            <div className="cqw-rv-card" key={f.name}>
              <h3>{planLabel}</h3>
              <div className="cqw-rv-row">
                <span className="cqw-rv-lbl">Seats selected</span>
                <span className="cqw-rv-val">{details.length}</span>
              </div>
              {details.map(d => (
                <div className="cqw-rv-row" key={d.id}>
                  <span className="cqw-rv-lbl">{d.label}</span>
                  <span className="cqw-rv-val">{currency} {d.price.toFixed(2)}</span>
                </div>
              ))}
              <div className="cqw-rv-row" style={{fontWeight: 800, borderTop: '1px solid var(--cq-border)', paddingTop: 8, marginTop: 4}}>
                <span className="cqw-rv-lbl" style={{color: 'var(--cq-text-primary)'}}>Total</span>
                <span className="cqw-rv-val">{currency} {seatTotal.toFixed(2)}</span>
              </div>
            </div>
          );
        })}

        {/* Form field values */}
        {Object.entries(formState).filter(([k]) => !k.startsWith('__') && k !== 'credoq_selected_plan_id').length > 0 && (
          <div className="cqw-rv-card">
            <h3>Your Details</h3>
            {Object.entries(formState).filter(([k,v]) => !k.startsWith('__') && k !== 'credoq_selected_plan_id' && v && String(v).trim()).map(([k,v]) => {
              const fieldDef = fields.find(f => f.name === k);
              if (fieldDef?.type === 'seat_map') return null; // shown as its own breakdown card above
              const lbl = fieldDef?.label || k;
              let display = Array.isArray(v) ? v.join(', ') : String(v);
              if (v && typeof v === 'object' && !Array.isArray(v)) {
                display = v.count ? `${v.count} seat(s)` : '';
              }
              if (fieldDef?.type === 'member_slot_credit') {
                const plan = (memberCreditData.plans || []).find(p => String(p.plan_id) === String(v));
                if (plan) display = `${plan.plan_name} (${plan.remaining} credits remaining)`;
              }
              if (display.startsWith('data:')) return null; // signature
              return (
                <div key={k} className="cqw-rv-row">
                  <span className="cqw-rv-lbl">{lbl}</span>
                  <span className="cqw-rv-val">{display}</span>
                </div>
              );
            })}
          </div>
        )}

        {/* reCAPTCHA v2 visible checkbox widget (v3 is invisible — runs at submit time) */}
        {cfg.recaptcha?.enabled && cfg.recaptcha?.version === 'v2' && (
          <div className="cqw-rv-card">
            <div ref={recaptchaContainerRef} className="cqw-recaptcha-container" />
          </div>
        )}

        <div className="cqw-total-price">
          <span className="cqw-total-lbl">Total</span>
          <span className="cqw-total-val">
            {grandTotal > 0 ? `${currency} ${grandTotal.toFixed(2)}` : <span className="cqw-pill-free">Free</span>}
          </span>
        </div>
      </div>
    );
  }

  // ── Panel content ──────────────────────────────────────────────
  function renderStep() {
    const step = steps[currentStep];
    if (!step) return null;

    if (step.type === 'service')  return renderServiceStep();
    if (step.type === 'provider') return renderProviderStep();
    if (step.type === 'datetime') return renderDatetimeStep();
    if (step.type === 'review')   return renderReviewStep();
    if (step.type === 'fields')   return renderFieldsStep(step);
    return null;
  }

  // ── Footer button label ────────────────────────────────────────
  function continueLabel() {
    const step = steps[currentStep];
    const isLast = currentStep === totalSteps - 1;
    if (isLast) {
      if (submitting) return <><span className="cqw-spin" /> Processing…</>;
      if (isMulti && bookedSlots.length > 1) {
        return (strings.multi_confirm_n || 'Confirm {n} bookings').replace('{n}', bookedSlots.length);
      }
      return strings.submit || 'Confirm Booking';
    }
    return <>{strings.next || 'Continue'} ›</>;
  }

  // ── Success screen ────────────────────────────────────────────
  if (submitResult && submitResult.success) {
    return (
      <div
        className="cqw-root"
        data-theme={theme}
        data-btn-style={design.btn_style || 'filled'}
        data-slot-layout={design.slot_layout || 'grid'}
        data-calendar-style={design.calendar_style || 'default'}
        style={widgetStyle}
      >
        <div className="cqw-success">
          <div className="cqw-success-icon">✓</div>
          <h2>{strings.success || 'Booking Confirmed!'}</h2>
          <p>{submitResult.message}</p>
          <button type="button" className="cqw-success-again" onClick={resetWidget}>
            Book Again
          </button>
        </div>
      </div>
    );
  }

  const isLastStep = currentStep === totalSteps - 1;

  return (
    <div
      className="cqw-root"
      data-theme={theme}
      data-btn-style={design.btn_style || 'filled'}
      data-slot-layout={design.slot_layout || 'grid'}
      data-calendar-style={design.calendar_style || 'default'}
      style={widgetStyle}
    >
      {/* Hidden form for FormData serialization */}
      <form ref={formRef} style={{display:'none'}}>
        <input type="hidden" name="appointment_id" value={aptId} />
        <input type="hidden" name="form_id"        value={cfg.form_id || 0} />
        <input type="hidden" name="selected_date"  value={selectedDate} />
        <input type="hidden" name="selected_time"  value={selectedTime} />
        <input type="hidden" name="staff_id"       value={staffId} />
      </form>

      <div className="cqw-inner">
        {renderSidebar()}

        <div className="cqw-main">
          {/* Mobile top bar */}
          <div className="cqw-mob-bar">
            <div className="cqw-brand-icon" style={{width:32,height:32,fontSize:11,borderRadius:7,flexShrink:0}}>
              {primaryLogoUrl ? (
                <img src={primaryLogoUrl} alt="" />
              ) : primaryLottieUrl ? (
                <DotLottieReact src={primaryLottieUrl} loop autoplay />
              ) : (
                primaryBrandName.slice(0,2).toUpperCase()
              )}
            </div>
            <div>
              <div className="cqw-brand-name" style={{fontSize:13}}>{document.title.split('–')[0]?.trim() || 'Booking'}</div>
              <div className="cqw-brand-sub" style={{fontSize:10}}>Online Booking</div>
            </div>
            <WidgetBrand brand={brand} compact />
            <label className="cqw-toggle-sw" style={{marginLeft:8}}>
              <input type="checkbox" checked={theme === 'light'} onChange={toggleTheme} />
              <div className="cqw-toggle-track" />
              <div className="cqw-toggle-thumb">{theme === 'light' ? '☀️' : '🌙'}</div>
            </label>
          </div>

          {/* Panel */}
          <div className="cqw-panels">
            <div className="cqw-panel">
              {totalSteps > 1 && renderProgress()}
              {renderNotice()}
              {renderStep()}
            </div>
          </div>

          {/* Footer */}
          <div className="cqw-footer">
            <button
              type="button"
              className="cqw-btn-back"
              style={{ visibility: currentStep > 0 ? 'visible' : 'hidden' }}
              onClick={goBack}
            >
              ‹ {strings.prev || 'Back'}
            </button>

            <button
              type="button"
              className="cqw-btn-cont"
              disabled={submitting}
              onClick={isLastStep ? handleSubmit : goNext}
            >
              {continueLabel()}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
