# React Widget — Port Notes

This directory will hold the React source for the booking widget.

## To preserve your v9 widget

1. Copy your existing widget source from the v9 plugin into this directory:
   ```
   credoq-membership-plus-react-perfect-backup/react-widget/  →  credoq-engine/react-widget/
   ```

2. Copy the compiled bundle to `assets/js/booking-widget.min.js`:
   ```
   credoq-membership-plus-react-perfect-backup/assets/js/booking-widget.min.js
     →  credoq-engine/assets/js/booking-widget.min.js
   ```

3. Update the widget's API client to talk to the new REST routes:

   | v9 endpoint (admin-ajax)               | Engine endpoint (REST)              |
   |----------------------------------------|-------------------------------------|
   | `action=credoq_submit_booking`         | `POST /credoq/v1/submissions`       |
   | `action=credoq_get_timeslots`          | `POST /credoq/v1/appointments/slots` (Appointments addon) |
   | `action=credoq_get_form_schema`        | `GET  /credoq/v1/forms/{id}`        |

4. The config payload that the widget reads from `data-credoq-config` now contains:
   ```js
   {
     formId: 42,
     restUrl: "https://example.com/wp-json/credoq/v1/",
     restNonce: "abc123",
     guestNonce: "def456" | "",
     isLoggedIn: true,
     currentUser: { id, name, email } | null,
     locale: "en_US"
   }
   ```

5. Replace any direct queries to `wp_jet_appointments` (there should be none in
   the React source — they were server-side only — but verify).

## Build process

If your v9 widget uses Webpack/Vite, keep the same config but change the output
path to `../assets/js/booking-widget.min.js`. Don't change the React code itself —
your layout design is preserved.
